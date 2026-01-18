<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class TS_B2B_Core {
    const B2B_ROLE      = 'b2b_partner';
    const META_B2B_ONLY = '_ts_b2b_only';
    const B2B_CHECKOUT_SLUG = 'b2b-checkout';
    const OPT_B2B_CHECKOUT_PAGE_ID = 'ts_b2b_checkout_page_id';

    public static function init() {
        if ( is_admin() && ! get_role( self::B2B_ROLE ) ) {
            self::add_b2b_role();
        }

        // Produkty: flaga i widoczność
        add_action( 'woocommerce_product_options_general_product_data', array( __CLASS__, 'add_b2b_visibility_field' ) );
        add_action( 'woocommerce_process_product_meta', array( __CLASS__, 'save_b2b_visibility_field' ) );
        add_action( 'pre_get_posts', array( __CLASS__, 'filter_products_globally' ), 999999 );
        add_filter( 'woocommerce_product_data_store_cpt_get_products_query', array( __CLASS__, 'filter_wc_get_products_query' ), 999999, 2 );
        add_filter( 'woocommerce_shortcode_products_query', array( __CLASS__, 'filter_wc_shortcode_query' ), 999999, 3 );
        add_filter( 'woocommerce_blocks_product_grid_query_args', array( __CLASS__, 'filter_wc_blocks_query' ), 999999, 2 );
        add_action( 'template_redirect', array( __CLASS__, 'block_direct_access' ) );
        add_filter( 'woocommerce_product_is_visible', array( __CLASS__, 'check_final_visibility' ), 999999, 2 );

        // Admin User Fields
        add_action( 'show_user_profile', array( __CLASS__, 'add_admin_user_fields' ) );
        add_action( 'edit_user_profile', array( __CLASS__, 'add_admin_user_fields' ) );
        add_action( 'edit_user_profile_update', array( __CLASS__, 'save_admin_user_fields' ) );
        add_action( 'user_register', array( __CLASS__, 'save_admin_user_fields' ) );
        add_action( 'profile_update', array( __CLASS__, 'finalize_admin_user_fields_save' ), 999, 2 );

        // Blokady meta i email
        add_filter( 'update_user_metadata', array( __CLASS__, 'block_b2b_user_meta_updates' ), 10, 5 );
        add_filter( 'wp_pre_insert_user_data', array( __CLASS__, 'block_b2b_user_email_update' ), 10, 3 );

        // Checkout logic
        add_filter( 'woocommerce_billing_fields', array( __CLASS__, 'lock_b2b_fields' ), 1000, 1 );
        add_action( 'template_redirect', array( __CLASS__, 'redirect_checkout_to_b2b_checkout' ), 20 );
        add_filter( 'woocommerce_get_checkout_url', array( __CLASS__, 'filter_checkout_url_for_b2b' ), 9999, 1 );
        add_filter( 'woocommerce_coupons_enabled', array( __CLASS__, 'disable_coupons_for_b2b_checkout' ), 9999 );
        add_filter( 'woocommerce_checkout_fields', array( __CLASS__, 'cleanup_checkout_fields_for_b2b' ), 9999, 1 );
        add_filter( 'woocommerce_checkout_get_value', array( __CLASS__, 'force_b2b_checkout_prefill_values' ), 9999, 2 );
        add_filter( 'woocommerce_available_payment_gateways', array( __CLASS__, 'restrict_payment_gateways_for_b2b' ), 9999, 1 );

// Wymuszenie powiązania zamówienia z userem (B2B) – żeby wc_get_orders działało zawsze
add_action( 'woocommerce_checkout_create_order', array( __CLASS__, 'force_b2b_customer_id_on_order' ), 10, 2 );

// CSS/JS
add_action( 'wp_head', array( __CLASS__, 'b2b_checkout_force_show_css' ), 9999 );

        add_action( 'wp_footer', array( __CLASS__, 'inject_b2b_readonly_js' ), 30 );
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
        return ( $page_id && get_post( $page_id ) ) ? $page_id : self::ensure_b2b_checkout_page();
    }

    private static function is_b2b_checkout_page() {
        $pid = self::get_b2b_checkout_page_id();
        return $pid ? is_page( $pid ) : false;
    }

    private static function is_checkout_context() {
        return (function_exists('is_checkout') && is_checkout()) || (function_exists('wp_doing_ajax') && wp_doing_ajax());
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
            return array( 'key' => self::META_B2B_ONLY, 'value' => 'yes', 'compare' => '=' );
        }
        return array(
            'relation' => 'OR',
            array( 'key' => self::META_B2B_ONLY, 'value' => 'yes', 'compare' => '!=' ),
            array( 'key' => self::META_B2B_ONLY, 'compare' => 'NOT EXISTS' ),
        );
    }

    public static function filter_checkout_url_for_b2b( $url ) {
        if ( is_admin() || current_user_can( 'manage_options' ) ) return $url;
        if ( self::is_strictly_b2b() ) {
            $pid = self::get_b2b_checkout_page_id();
            if ( $pid ) return get_permalink( $pid );
        }
        return $url;
    }

    public static function disable_coupons_for_b2b_checkout( $enabled ) {
        if ( is_admin() || current_user_can( 'manage_options' ) || ! self::is_strictly_b2b() ) return $enabled;
        return self::is_checkout_context() ? false : $enabled;
    }

    public static function cleanup_checkout_fields_for_b2b( $fields ) {
        if ( is_admin() || current_user_can( 'manage_options' ) || ! self::is_strictly_b2b() || ! self::is_checkout_context() ) return $fields;
        if ( isset( $fields['billing']['billing_want_invoice'] ) ) unset( $fields['billing']['billing_want_invoice'] );
        
        $req_fields = array(
            'billing_company' => array('label' => 'Nazwa firmy', 'priority' => 30),
            'billing_nip'     => array('label' => 'NIP', 'priority' => 35)
        );

        foreach($req_fields as $key => $data) {
            if ( ! isset( $fields['billing'][$key] ) ) {
                $fields['billing'][$key] = array(
                    'type' => 'text', 'label' => $data['label'], 'required' => false,
                    'class' => array( 'form-row-wide' ), 'priority' => $data['priority'],
                );
            }
        }

        foreach ( array( 'billing_company', 'billing_nip', 'billing_email' ) as $k ) {
            if ( isset( $fields['billing'][ $k ] ) ) {
                $fields['billing'][ $k ]['custom_attributes']['readonly'] = 'readonly';
            }
        }
        return $fields;
    }

    public static function force_b2b_checkout_prefill_values( $value, $input ) {
        if ( is_admin() || current_user_can( 'manage_options' ) || ! self::is_strictly_b2b() || ! self::is_checkout_context() ) return $value;
        $uid = get_current_user_id();
        if ( ! $uid ) return $value;

        if ( in_array($input, array('billing_company', 'billing_nip')) ) {
            $v = get_user_meta( $uid, $input, true );
            return ( $v !== '' ) ? $v : $value;
        }
        if ( $input === 'billing_email' ) {
            $u = get_userdata( $uid );
            if ( $u && ! empty( $u->user_email ) ) return $u->user_email;
        }
        return $value;
    }

    public static function restrict_payment_gateways_for_b2b( $gateways ) {
        if ( is_admin() || current_user_can( 'manage_options' ) || ! self::is_strictly_b2b() || ! self::is_checkout_context() ) return $gateways;
        foreach ( $gateways as $id => $gw ) {
            if ( $id !== 'ts_b2b_deferred' ) unset( $gateways[ $id ] );
        }
        return $gateways;
    }
public static function force_b2b_customer_id_on_order( $order, $data ) {
    // tylko dla B2B
    if ( ! self::is_strictly_b2b() ) return;

    $user_id = get_current_user_id();
    if ( ! $user_id ) return;

    if ( (int) $order->get_customer_id() !== (int) $user_id ) {
        $order->set_customer_id( $user_id );
    }
}

    public static function filter_products_globally( $query ) {
        if ( is_admin() || current_user_can( 'manage_options' ) ) return;
        $pt = $query->get('post_type');
        if ( $pt !== 'product' && (!is_array($pt) || !in_array('product', $pt)) ) {
            if ( ! isset($query->query_vars['wc_query']) || $query->query_vars['wc_query'] !== 'product_query' ) return;
        }
        $meta_query = (array) $query->get( 'meta_query' );
        $meta_query[] = self::build_xor_b2b_meta_query_clause();
        $query->set( 'meta_query', $meta_query );
    }

    public static function filter_wc_get_products_query( $query, $query_vars ) {
        if ( is_admin() || current_user_can( 'manage_options' ) ) return $query;
        $query['meta_query'][] = self::build_xor_b2b_meta_query_clause();
        return $query;
    }

    public static function filter_wc_shortcode_query( $query_args, $atts, $type ) {
        if ( is_admin() || current_user_can( 'manage_options' ) ) return $query_args;
        $query_args['meta_query'][] = self::build_xor_b2b_meta_query_clause();
        return $query_args;
    }

    public static function filter_wc_blocks_query( $query_args, $block_instance ) {
        if ( is_admin() || current_user_can( 'manage_options' ) ) return $query_args;
        $query_args['meta_query'][] = self::build_xor_b2b_meta_query_clause();
        return $query_args;
    }

    public static function block_direct_access() {
        if ( is_admin() || ! is_singular( 'product' ) || current_user_can( 'manage_options' ) ) return;
        $product_id = get_queried_object_id();
        $is_b2b_prod = get_post_meta( $product_id, self::META_B2B_ONLY, true ) === 'yes';
        $is_b2b_user = self::is_strictly_b2b();
        if ( ( $is_b2b_prod && ! $is_b2b_user ) || ( ! $is_b2b_prod && $is_b2b_user ) ) {
            wp_redirect( home_url( '/' ) );
            exit;
        }
    }

    public static function check_final_visibility( $visible, $product_id ) {
        if ( is_admin() || current_user_can( 'manage_options' ) ) return $visible;
        $is_b2b_prod = get_post_meta( $product_id, self::META_B2B_ONLY, true ) === 'yes';
        $is_b2b_user = self::is_strictly_b2b();
        return $is_b2b_user ? ($is_b2b_prod ? $visible : false) : ($is_b2b_prod ? false : $visible);
    }

    public static function lock_b2b_fields( $fields ) {
        if ( self::is_strictly_b2b() ) {
            foreach ( array( 'billing_company', 'billing_nip', 'billing_email' ) as $f ) {
                if ( isset( $fields[ $f ] ) ) $fields[ $f ]['custom_attributes']['readonly'] = 'readonly';
            }
        }
        return $fields;
    }

    public static function b2b_checkout_force_show_css() {
        if ( is_admin() || current_user_can( 'manage_options' ) || ! self::is_strictly_b2b() || ! self::is_checkout_context() ) return;
        echo '<style>#billing_company_field, #billing_nip_field { display:block !important; visibility:visible !important; } #billing_want_invoice_field, .apple-invoice-toggle { display:none !important; }</style>';
    }

    public static function add_admin_user_fields( $user ) {
        if ( ! current_user_can( 'edit_users' ) ) return;
        $user_id = is_object($user) ? (int) $user->ID : 0;
        $company = get_user_meta( $user_id, 'billing_company', true );
        $nip     = get_user_meta( $user_id, 'billing_nip', true );
        ?>
        <h3>Dane Partnera B2B (admin)</h3>
        <table class="form-table">
            <tr><th><label for="ts_b2b_billing_company">Firma</label></th><td><input type="text" id="ts_b2b_billing_company" name="ts_b2b_billing_company" value="<?php echo esc_attr( $company ); ?>" class="regular-text" /></td></tr>
            <tr><th><label for="ts_b2b_billing_nip">NIP</label></th><td><input type="text" id="ts_b2b_billing_nip" name="ts_b2b_billing_nip" value="<?php echo esc_attr( $nip ); ?>" class="regular-text" /></td></tr>
        </table>
        <?php
    }

    public static function save_admin_user_fields( $user_id ) {
        if ( ! current_user_can( 'edit_users' ) ) return;
        if ( isset( $_POST['ts_b2b_billing_company'] ) ) update_user_meta( $user_id, 'billing_company', sanitize_text_field( wp_unslash( $_POST['ts_b2b_billing_company'] ) ) );
        if ( isset( $_POST['ts_b2b_billing_nip'] ) ) update_user_meta( $user_id, 'billing_nip', sanitize_text_field( wp_unslash( $_POST['ts_b2b_billing_nip'] ) ) );
    }

    public static function finalize_admin_user_fields_save( $user_id, $old_user_data ) {
        self::save_admin_user_fields($user_id);
    }

    public static function block_b2b_user_meta_updates( $check, $object_id, $meta_key, $meta_value, $prev_value ) {
        if ( is_admin() || current_user_can( 'manage_options' ) || ! is_user_logged_in() ) return $check;
        if ( (int) get_current_user_id() === (int) $object_id && self::is_strictly_b2b() && in_array( $meta_key, array( 'billing_company', 'billing_nip' ), true ) ) {
            return get_user_meta( $object_id, $meta_key, true );
        }
        return $check;
    }

    public static function block_b2b_user_email_update( $data, $update, $user_id ) {
        if ( is_admin() || current_user_can( 'manage_options' ) || ! $update || ! is_user_logged_in() ) return $data;
        if ( self::is_strictly_b2b() && (int) get_current_user_id() === (int) $user_id ) {
            $u = get_userdata( $user_id );
            if ( $u && ! empty( $u->user_email ) ) $data['user_email'] = $u->user_email;
        }
        return $data;
    }

    public static function inject_b2b_readonly_js() {
        if ( ! self::is_strictly_b2b() || is_admin() ) return;
        if ( function_exists('is_account_page') && ! is_account_page() && ( ! function_exists('is_checkout') || ! is_checkout() ) ) return;
        ?>
        <script>
        (function(){
            var selectors = ['input#account_email','input[name="account_email"]','input#billing_email','input[name="billing_email"]','input#billing_company','input[name="billing_company"]','input#billing_nip','input[name="billing_nip"]'];
            selectors.forEach(function(sel){
                document.querySelectorAll(sel).forEach(function(el){ el.setAttribute('readonly', 'readonly'); el.addEventListener('keydown', function(e){ e.preventDefault(); }); });
            });
        })();
        </script>
        <?php
    }

    public static function add_b2b_visibility_field() {
        woocommerce_wp_checkbox( array( 'id' => self::META_B2B_ONLY, 'label' => 'Tylko dla B2B (ukryj dla B2C)', 'description' => 'Jeśli zaznaczone: produkt widoczny wyłącznie dla roli b2b_partner.' ) );
    }

    public static function save_b2b_visibility_field( $post_id ) {
        update_post_meta( $post_id, self::META_B2B_ONLY, isset( $_POST[ self::META_B2B_ONLY ] ) ? 'yes' : '' );
    }
}