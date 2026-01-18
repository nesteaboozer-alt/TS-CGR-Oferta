<?php
/**
 * Plugin Name: TS B2B Connector (Zintegrowany)
 * Description: Obsługa Partnerów B2B: separacja XOR produktów, blokady danych firmowych, dedykowany checkout B2B.
 * Version: 3.3.0
 * Author: TechSolver
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class TS_B2B_Connector {

    const B2B_ROLE      = 'b2b_partner';
    const META_B2B_ONLY = '_ts_b2b_only';

    // Slug dedykowanego checkoutu B2B (strona zostanie utworzona przy aktywacji, jeśli nie istnieje)
    const B2B_CHECKOUT_SLUG = 'b2b-checkout';
    const OPT_B2B_CHECKOUT_PAGE_ID = 'ts_b2b_checkout_page_id';

    public static function init() {
        // Auto-naprawa roli (admin)
        if ( is_admin() && ! get_role( self::B2B_ROLE ) ) {
            self::add_b2b_role();
        }

        register_activation_hook( __FILE__, array( __CLASS__, 'on_activate' ) );

        // 1) Produkty: flaga B2B-only
        add_action( 'woocommerce_product_options_general_product_data', array( __CLASS__, 'add_b2b_visibility_field' ) );
        add_action( 'woocommerce_process_product_meta', array( __CLASS__, 'save_b2b_visibility_field' ) );

        // 2) Mur produktowy (WP_Query)
        add_action( 'pre_get_posts', array( __CLASS__, 'filter_products_globally' ), 999999 );

        // 2b) Mur produktowy (WC API / shortcodes / blocks)
        add_filter( 'woocommerce_product_data_store_cpt_get_products_query', array( __CLASS__, 'filter_wc_get_products_query' ), 999999, 2 );
        add_filter( 'woocommerce_shortcode_products_query', array( __CLASS__, 'filter_wc_shortcode_query' ), 999999, 3 );
        add_filter( 'woocommerce_blocks_product_grid_query_args', array( __CLASS__, 'filter_wc_blocks_query' ), 999999, 2 );

        // 3) Mur produktowy: blokada direct link
        add_action( 'template_redirect', array( __CLASS__, 'block_direct_access' ) );

        // 4) Ostatnia linia obrony widoczności
        add_filter( 'woocommerce_product_is_visible', array( __CLASS__, 'check_final_visibility' ), 999999, 2 );

        // 5) Dane firmowe w panelu admina + twarda blokada edycji przez B2B
        add_action( 'show_user_profile', array( __CLASS__, 'add_admin_user_fields' ) );
        add_action( 'edit_user_profile', array( __CLASS__, 'add_admin_user_fields' ) );

        add_action( 'edit_user_profile_update', array( __CLASS__, 'save_admin_user_fields' ), 10, 1 );
        add_action( 'user_register', array( __CLASS__, 'save_admin_user_fields' ), 10, 1 );

        // Finalny zapis po wszystkim (naprawia problem: "Firma się nie zapisuje")
        add_action( 'profile_update', array( __CLASS__, 'finalize_admin_user_fields_save' ), 999, 2 );

        // Blokada prób zmiany meta billing_company/billing_nip przez partnera
        add_filter( 'update_user_metadata', array( __CLASS__, 'block_b2b_user_meta_updates' ), 10, 5 );

        // Blokada próby zmiany user_email przez partnera
        add_filter( 'wp_pre_insert_user_data', array( __CLASS__, 'block_b2b_user_email_update' ), 10, 3 );

        // 6) Checkout: readonly pola + dedykowany checkout B2B
        add_filter( 'woocommerce_billing_fields', array( __CLASS__, 'lock_b2b_fields' ), 1000, 1 );
        add_action( 'template_redirect', array( __CLASS__, 'redirect_checkout_to_b2b_checkout' ), 20 );

        // B2B: checkout URL ma wskazywać na /b2b-checkout
        add_filter( 'woocommerce_get_checkout_url', array( __CLASS__, 'filter_checkout_url_for_b2b' ), 9999, 1 );

        // B2B checkout: brak kuponów
        add_filter( 'woocommerce_coupons_enabled', array( __CLASS__, 'disable_coupons_for_b2b_checkout' ), 9999 );

        // B2B checkout: usuń "chcę fakturę" + dopnij readonly + wymuś pola company/nip
        add_filter( 'woocommerce_checkout_fields', array( __CLASS__, 'cleanup_checkout_fields_for_b2b' ), 9999, 1 );

        // B2B checkout: wymuś prefill wartości (firma/NIP/email) także w AJAX
        add_filter( 'woocommerce_checkout_get_value', array( __CLASS__, 'force_b2b_checkout_prefill_values' ), 9999, 2 );

        // B2B checkout: tylko 1 bramka płatności (nasza)
        add_filter( 'woocommerce_available_payment_gateways', array( __CLASS__, 'restrict_payment_gateways_for_b2b' ), 9999, 1 );

        // CSS: wymuś pokazanie pól company/nip i ukryj toggle faktury (tylko w checkout context)
        add_action( 'wp_head', array( __CLASS__, 'b2b_checkout_force_show_css' ), 9999 );

        // UI blokady w "Moje konto" (readonly) – kosmetyka
        add_action( 'wp_footer', array( __CLASS__, 'inject_b2b_readonly_js' ), 30 );

        // 7) Bramka płatności B2B
        add_filter( 'woocommerce_payment_gateways', array( __CLASS__, 'register_gateway' ) );

        // 8) Etap 3/4
        self::init_etap_3();
    }

    public static function on_activate() {
        self::add_b2b_role();
        self::ensure_b2b_checkout_page();
        flush_rewrite_rules();
    }

    public static function add_b2b_role() {
        if ( ! get_role( self::B2B_ROLE ) ) {
            add_role( self::B2B_ROLE, 'Partner B2B', array( 'read' => true, 'customer' => true ) );
        }
    }

    public static function is_strictly_b2b() {
        if ( ! is_user_logged_in() ) return false;
        $user = wp_get_current_user();
        if ( ! $user || $user->ID === 0 ) return false;

        // Admin nigdy nie jest "strictly b2b"
        if ( user_can( $user, 'manage_options' ) ) return false;

        return in_array( self::B2B_ROLE, (array) $user->roles, true );
    }

    private static function ensure_b2b_checkout_page() {
        $page_id = (int) get_option( self::OPT_B2B_CHECKOUT_PAGE_ID, 0 );
        if ( $page_id && get_post( $page_id ) ) return $page_id;

        $existing = get_page_by_path( self::B2B_CHECKOUT_SLUG );
        if ( $existing && ! empty( $existing->ID ) ) {
            update_option( self::OPT_B2B_CHECKOUT_PAGE_ID, (int) $existing->ID, false );
            return (int) $existing->ID;
        }

        $new_id = wp_insert_post( array(
            'post_title'   => 'Checkout B2B',
            'post_name'    => self::B2B_CHECKOUT_SLUG,
            'post_status'  => 'publish',
            'post_type'    => 'page',
            'post_content' => '[woocommerce_checkout]',
        ), true );

        if ( ! is_wp_error( $new_id ) ) {
            update_option( self::OPT_B2B_CHECKOUT_PAGE_ID, (int) $new_id, false );
            return (int) $new_id;
        }

        return 0;
    }

    private static function get_b2b_checkout_page_id() {
        $page_id = (int) get_option( self::OPT_B2B_CHECKOUT_PAGE_ID, 0 );
        if ( $page_id && get_post( $page_id ) ) return $page_id;
        return self::ensure_b2b_checkout_page();
    }

    private static function is_b2b_checkout_page() {
        $pid = self::get_b2b_checkout_page_id();
        return $pid ? is_page( $pid ) : false;
    }

    private static function is_checkout_context() {
        $is_checkout = function_exists('is_checkout') && is_checkout();
        $is_ajax     = function_exists('wp_doing_ajax') && wp_doing_ajax();
        return $is_checkout || $is_ajax;
    }

    public static function redirect_checkout_to_b2b_checkout() {
        if ( is_admin() || ( defined('DOING_AJAX') && DOING_AJAX ) ) return;
        if ( current_user_can( 'manage_options' ) ) return;

        $b2b_pid = self::get_b2b_checkout_page_id();
        if ( ! $b2b_pid ) return;

        if ( self::is_strictly_b2b() && function_exists('is_checkout') && is_checkout() && ! self::is_b2b_checkout_page() ) {
            wp_safe_redirect( get_permalink( $b2b_pid ) );
            exit;
        }

        if ( ! self::is_strictly_b2b() && self::is_b2b_checkout_page() ) {
            wp_safe_redirect( wc_get_checkout_url() );
            exit;
        }
    }

    private static function build_xor_b2b_meta_query_clause() {
        if ( self::is_strictly_b2b() ) {
            return array(
                'key'     => self::META_B2B_ONLY,
                'value'   => 'yes',
                'compare' => '=',
            );
        }

        return array(
            'relation' => 'OR',
            array(
                'key'     => self::META_B2B_ONLY,
                'value'   => 'yes',
                'compare' => '!=',
            ),
            array(
                'key'     => self::META_B2B_ONLY,
                'compare' => 'NOT EXISTS',
            ),
        );
    }

    public static function filter_checkout_url_for_b2b( $url ) {
        if ( is_admin() ) return $url;
        if ( current_user_can( 'manage_options' ) ) return $url;

        if ( self::is_strictly_b2b() ) {
            $pid = self::get_b2b_checkout_page_id();
            if ( $pid ) return get_permalink( $pid );
        }

        return $url;
    }

    public static function disable_coupons_for_b2b_checkout( $enabled ) {
        if ( is_admin() ) return $enabled;
        if ( current_user_can( 'manage_options' ) ) return $enabled;

        if ( ! self::is_strictly_b2b() ) return $enabled;

        if ( self::is_checkout_context() ) return false;

        return $enabled;
    }

    public static function cleanup_checkout_fields_for_b2b( $fields ) {
        if ( is_admin() ) return $fields;
        if ( current_user_can( 'manage_options' ) ) return $fields;

        if ( ! self::is_strictly_b2b() ) return $fields;
        if ( ! self::is_checkout_context() ) return $fields;

        // Usuń "Chcę otrzymać fakturę VAT"
        if ( isset( $fields['billing']['billing_want_invoice'] ) ) {
            unset( $fields['billing']['billing_want_invoice'] );
        }

        // WYMUSZENIE: jeśli wtyczka usuwa company/nip – dodaj je z powrotem
        if ( ! isset( $fields['billing']['billing_company'] ) ) {
            $fields['billing']['billing_company'] = array(
                'type'     => 'text',
                'label'    => 'Nazwa firmy',
                'required' => false,
                'class'    => array( 'form-row-wide' ),
                'priority' => 30,
            );
        }

        if ( ! isset( $fields['billing']['billing_nip'] ) ) {
            $fields['billing']['billing_nip'] = array(
                'type'     => 'text',
                'label'    => 'NIP',
                'required' => false,
                'class'    => array( 'form-row-wide' ),
                'priority' => 35,
            );
        }

        // Readonly w checkout
        foreach ( array( 'billing_company', 'billing_nip', 'billing_email' ) as $k ) {
            if ( isset( $fields['billing'][ $k ] ) ) {
                if ( empty( $fields['billing'][ $k ]['custom_attributes'] ) || ! is_array( $fields['billing'][ $k ]['custom_attributes'] ) ) {
                    $fields['billing'][ $k ]['custom_attributes'] = array();
                }
                $fields['billing'][ $k ]['custom_attributes']['readonly'] = 'readonly';
            }
        }

        return $fields;
    }

    public static function force_b2b_checkout_prefill_values( $value, $input ) {
        if ( is_admin() ) return $value;
        if ( current_user_can( 'manage_options' ) ) return $value;

        if ( ! self::is_strictly_b2b() ) return $value;
        if ( ! self::is_checkout_context() ) return $value;

        $uid = get_current_user_id();
        if ( ! $uid ) return $value;

        if ( $input === 'billing_company' ) {
            $v = get_user_meta( $uid, 'billing_company', true );
            return ( $v !== '' ) ? $v : $value;
        }

        if ( $input === 'billing_nip' ) {
            $v = get_user_meta( $uid, 'billing_nip', true );
            return ( $v !== '' ) ? $v : $value;
        }

        if ( $input === 'billing_email' ) {
            $u = get_userdata( $uid );
            if ( $u && ! empty( $u->user_email ) ) return $u->user_email;
        }

        return $value;
    }

    public static function restrict_payment_gateways_for_b2b( $gateways ) {
        if ( is_admin() ) return $gateways;
        if ( current_user_can( 'manage_options' ) ) return $gateways;

        if ( ! self::is_strictly_b2b() ) return $gateways;
        if ( ! self::is_checkout_context() ) return $gateways;

        foreach ( $gateways as $id => $gw ) {
            if ( $id !== 'ts_b2b_deferred' ) {
                unset( $gateways[ $id ] );
            }
        }

        return $gateways;
    }

    public static function filter_products_globally( $query ) {
        if ( is_admin() ) return;
        if ( current_user_can( 'manage_options' ) ) return;

        $pt = $query->get('post_type');
        $is_product_query = ( $pt === 'product' ) || ( is_array($pt) && in_array('product', $pt, true) );

        if ( ! $is_product_query ) {
            // WC czasem używa wc_query=product_query
            if ( ! isset($query->query_vars['wc_query']) || $query->query_vars['wc_query'] !== 'product_query' ) return;
        }

        $meta_query   = (array) $query->get( 'meta_query' );
        $meta_query[] = self::build_xor_b2b_meta_query_clause();

        $query->set( 'meta_query', $meta_query );
    }

    public static function filter_wc_get_products_query( $query, $query_vars ) {
        if ( is_admin() ) return $query;
        if ( current_user_can( 'manage_options' ) ) return $query;

        if ( empty( $query['meta_query'] ) || ! is_array( $query['meta_query'] ) ) {
            $query['meta_query'] = array();
        }
        $query['meta_query'][] = self::build_xor_b2b_meta_query_clause();
        return $query;
    }

    public static function filter_wc_shortcode_query( $query_args, $atts, $type ) {
        if ( is_admin() ) return $query_args;
        if ( current_user_can( 'manage_options' ) ) return $query_args;

        if ( empty( $query_args['meta_query'] ) || ! is_array( $query_args['meta_query'] ) ) {
            $query_args['meta_query'] = array();
        }
        $query_args['meta_query'][] = self::build_xor_b2b_meta_query_clause();
        return $query_args;
    }

    public static function filter_wc_blocks_query( $query_args, $block_instance ) {
        if ( is_admin() ) return $query_args;
        if ( current_user_can( 'manage_options' ) ) return $query_args;

        if ( empty( $query_args['meta_query'] ) || ! is_array( $query_args['meta_query'] ) ) {
            $query_args['meta_query'] = array();
        }
        $query_args['meta_query'][] = self::build_xor_b2b_meta_query_clause();
        return $query_args;
    }

    public static function block_direct_access() {
        if ( is_admin() || ! is_singular( 'product' ) ) return;
        if ( current_user_can( 'manage_options' ) ) return;

        $product_id  = get_queried_object_id();
        $is_b2b_prod = get_post_meta( $product_id, self::META_B2B_ONLY, true ) === 'yes';
        $is_b2b_user = self::is_strictly_b2b();

        // XOR: jeśli nie pasuje do roli, out
        if ( ( $is_b2b_prod && ! $is_b2b_user ) || ( ! $is_b2b_prod && $is_b2b_user ) ) {
            wp_redirect( home_url( '/' ) );
            exit;
        }
    }

    public static function check_final_visibility( $visible, $product_id ) {
        if ( is_admin() || current_user_can( 'manage_options' ) ) return $visible;

        $is_b2b_prod = get_post_meta( $product_id, self::META_B2B_ONLY, true ) === 'yes';
        $is_b2b_user = self::is_strictly_b2b();

        if ( $is_b2b_user ) return $is_b2b_prod ? $visible : false;
        return $is_b2b_prod ? false : $visible;
    }

    public static function lock_b2b_fields( $fields ) {
        if ( self::is_strictly_b2b() ) {
            foreach ( array( 'billing_company', 'billing_nip', 'billing_email' ) as $f ) {
                if ( isset( $fields[ $f ] ) ) {
                    if ( empty( $fields[ $f ]['custom_attributes'] ) || ! is_array( $fields[ $f ]['custom_attributes'] ) ) {
                        $fields[ $f ]['custom_attributes'] = array();
                    }
                    $fields[ $f ]['custom_attributes']['readonly'] = 'readonly';
                }
            }
        }
        return $fields;
    }

    public static function b2b_checkout_force_show_css() {
        if ( is_admin() ) return;
        if ( current_user_can( 'manage_options' ) ) return;
        if ( ! self::is_strictly_b2b() ) return;
        if ( ! self::is_checkout_context() ) return;

        echo '<style>
            #billing_company_field, #billing_nip_field { display:block !important; visibility:visible !important; }
            #billing_want_invoice_field, .apple-invoice-toggle { display:none !important; }
        </style>';
    }

    public static function add_admin_user_fields( $user ) {
        if ( ! current_user_can( 'edit_users' ) ) return;

        $user_id = is_object($user) ? (int) $user->ID : 0;
        $company = get_user_meta( $user_id, 'billing_company', true );
        $nip     = get_user_meta( $user_id, 'billing_nip', true );
        ?>
        <h3>Dane Partnera B2B (admin)</h3>
        <table class="form-table">
            <tr>
                <th><label for="ts_b2b_billing_company">Firma</label></th>
                <td><input type="text" id="ts_b2b_billing_company" name="ts_b2b_billing_company" value="<?php echo esc_attr( $company ); ?>" class="regular-text" /></td>
            </tr>
            <tr>
                <th><label for="ts_b2b_billing_nip">NIP</label></th>
                <td><input type="text" id="ts_b2b_billing_nip" name="ts_b2b_billing_nip" value="<?php echo esc_attr( $nip ); ?>" class="regular-text" /></td>
            </tr>
        </table>
        <?php
    }

    public static function save_admin_user_fields( $user_id ) {
        if ( ! current_user_can( 'edit_users' ) ) return;

        if ( isset( $_POST['ts_b2b_billing_company'] ) ) {
            update_user_meta( $user_id, 'billing_company', sanitize_text_field( wp_unslash( $_POST['ts_b2b_billing_company'] ) ) );
        }
        if ( isset( $_POST['ts_b2b_billing_nip'] ) ) {
            update_user_meta( $user_id, 'billing_nip', sanitize_text_field( wp_unslash( $_POST['ts_b2b_billing_nip'] ) ) );
        }
    }

    public static function finalize_admin_user_fields_save( $user_id, $old_user_data ) {
        if ( ! current_user_can( 'edit_users' ) ) return;

        if ( isset( $_POST['ts_b2b_billing_company'] ) ) {
            update_user_meta( $user_id, 'billing_company', sanitize_text_field( wp_unslash( $_POST['ts_b2b_billing_company'] ) ) );
        }
        if ( isset( $_POST['ts_b2b_billing_nip'] ) ) {
            update_user_meta( $user_id, 'billing_nip', sanitize_text_field( wp_unslash( $_POST['ts_b2b_billing_nip'] ) ) );
        }
    }

    public static function block_b2b_user_meta_updates( $check, $object_id, $meta_key, $meta_value, $prev_value ) {
        if ( is_admin() ) return $check;

        if ( current_user_can( 'manage_options' ) ) return $check;

        if ( ! is_user_logged_in() ) return $check;
        $current = get_current_user_id();
        if ( (int) $current !== (int) $object_id ) return $check;

        if ( self::is_strictly_b2b() && in_array( $meta_key, array( 'billing_company', 'billing_nip' ), true ) ) {
            // Zablokuj update – udaj sukces, ale nic nie zmieniaj
            return get_user_meta( $object_id, $meta_key, true );
        }

        return $check;
    }

    public static function block_b2b_user_email_update( $data, $update, $user_id ) {
        if ( is_admin() ) return $data;
        if ( current_user_can( 'manage_options' ) ) return $data;

        if ( ! $update ) return $data; // przy rejestracji nie blokujemy
        if ( ! is_user_logged_in() ) return $data;

        if ( self::is_strictly_b2b() && (int) get_current_user_id() === (int) $user_id ) {
            $u = get_userdata( $user_id );
            if ( $u && ! empty( $u->user_email ) ) {
                $data['user_email'] = $u->user_email;
            }
        }

        return $data;
    }

    public static function inject_b2b_readonly_js() {
        if ( ! self::is_strictly_b2b() ) return;
        if ( is_admin() ) return;

        if ( function_exists('is_account_page') && ! is_account_page() && ( ! function_exists('is_checkout') || ! is_checkout() ) ) return;
        ?>
        <script>
        (function(){
            try {
                var selectors = [
                    'input#account_email',
                    'input[name="account_email"]',
                    'input#billing_email',
                    'input[name="billing_email"]',
                    'input#billing_company',
                    'input[name="billing_company"]',
                    'input#billing_nip',
                    'input[name="billing_nip"]'
                ];
                selectors.forEach(function(sel){
                    document.querySelectorAll(sel).forEach(function(el){
                        el.setAttribute('readonly', 'readonly');
                        el.addEventListener('keydown', function(e){ e.preventDefault(); });
                    });
                });
            } catch(e) {}
        })();
        </script>
        <?php
    }

    public static function add_b2b_visibility_field() {
        woocommerce_wp_checkbox( array(
            'id'          => self::META_B2B_ONLY,
            'label'       => 'Tylko dla B2B (ukryj dla B2C)',
            'description' => 'Jeśli zaznaczone: produkt widoczny wyłącznie dla roli b2b_partner.',
        ) );
    }

    public static function save_b2b_visibility_field( $post_id ) {
        $val = isset( $_POST[ self::META_B2B_ONLY ] ) ? 'yes' : '';
        update_post_meta( $post_id, self::META_B2B_ONLY, $val );
    }

    public static function register_gateway( $gateways ) {
        $gateways[] = 'TS_B2B_Deferred_Gateway';
        return $gateways;
    }

    // --- Etap 3/4 ---

    public static function init_etap_3() {
        add_filter( 'woocommerce_account_menu_items', array( __CLASS__, 'add_b2b_menu_item' ) );
        add_action( 'init', array( __CLASS__, 'add_b2b_endpoint' ) );
        add_action( 'woocommerce_account_b2b-services_endpoint', array( __CLASS__, 'render_b2b_services_page' ) );
        add_action( 'wp_ajax_ts_b2b_cancel_meal', array( __CLASS__, 'ajax_handle_cancellation' ) );
    }

    public static function add_b2b_menu_item( $items ) {
        if ( self::is_strictly_b2b() ) {
            $logout = $items['customer-logout'] ?? null;
            if ( $logout !== null ) unset( $items['customer-logout'] );

            $items['b2b-services'] = 'Moje Usługi (B2B)';

            if ( $logout !== null ) $items['customer-logout'] = $logout;
        }
        return $items;
    }

    public static function add_b2b_endpoint() {
        add_rewrite_endpoint( 'b2b-services', EP_PAGES );
    }

    public static function render_b2b_services_page() {
        global $wpdb;
        $table = $wpdb->prefix . 'tsme_meal_codes';
        $services = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM $table
                 WHERE order_id IN (
                   SELECT post_id FROM {$wpdb->postmeta}
                   WHERE meta_key = '_customer_user' AND meta_value = %d
                 )
                 AND status != 'void'
                 ORDER BY stay_from ASC",
                get_current_user_id()
            ),
            ARRAY_A
        );

        echo '<h3>Twoje zarezerwowane posiłki</h3>';
        if ( ! $services ) { echo '<p>Brak aktywnych rezerwacji.</p>'; return; }

        echo '<table class="shop_table"><thead><tr><th>Usługa</th><th>Obiekt</th><th>Data</th><th>Akcja</th></tr></thead><tbody>';
        foreach ( $services as $row ) {
            $can_cancel = ( strtotime( $row['stay_from'] ) - time() > 7 * DAY_IN_SECONDS );
            echo '<tr><td>'.esc_html($row['meal_type']).'</td><td>'.esc_html($row['object_label']).'</td><td>'.esc_html($row['stay_from']).'</td><td>';
            if ( $can_cancel ) echo '<button class="button ts-cancel" data-id="'.(int)$row['id'].'">Anuluj</button>';
            else echo 'Po terminie';
            echo '</td></tr>';
        }
        echo '</tbody></table>';
        ?>
        <script>
        jQuery('.ts-cancel').on('click', function(){
            var $b = jQuery(this); if(!confirm('Anulować?')) return;
            $b.prop('disabled', true);
            jQuery.post('<?php echo esc_url( admin_url('admin-ajax.php') ); ?>', { action: 'ts_b2b_cancel_meal', code_id: $b.data('id') }, function(){ location.reload(); });
        });
        </script>
        <?php
    }

    public static function ajax_handle_cancellation() {
        if ( class_exists('TSME_Codes') ) {
            TSME_Codes::void_code_by_id( absint($_POST['code_id'] ?? 0) );
        }
        wp_send_json_success();
    }
}

// Rejestracja bramki
add_action( 'plugins_loaded', function() {
    if ( ! class_exists( 'WC_Payment_Gateway' ) ) return;

    class TS_B2B_Deferred_Gateway extends WC_Payment_Gateway {
        public function __construct() {
            $this->id           = 'ts_b2b_deferred';
            $this->method_title = 'Płatność B2B';
            $this->title        = 'Płatność na podstawie faktury';
            $this->init_form_fields();
            $this->init_settings();
            add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
        }

        public function init_form_fields() {
            $this->form_fields = array(
                'enabled' => array(
                    'title'   => 'Włącz',
                    'type'    => 'checkbox',
                    'default' => 'yes'
                )
            );
        }

        public function is_available() {
            return TS_B2B_Connector::is_strictly_b2b();
        }

        public function process_payment( $order_id ) {
            $order = wc_get_order( $order_id );
            $order->update_status( 'completed', 'B2B: Faktura zbiorcza.' );
            $order->add_meta_data( '_is_b2b_order', 'yes', true );
            $order->save();
            WC()->cart->empty_cart();
            return array( 'result' => 'success', 'redirect' => $this->get_return_url( $order ) );
        }
    }
}, 11 );

TS_B2B_Connector::init();
