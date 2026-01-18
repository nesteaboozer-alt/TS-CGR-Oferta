<?php
/**
 * Plugin Name: TS B2B Connector
 * Description: Obsługa Partnerów B2B: Szczelna separacja produktów, płatności odroczone i zablokowany profil.
 * Version: 2.4.0
 * Author: TechSolver
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class TS_B2B_Connector {

    const B2B_ROLE = 'b2b_partner';
    const META_B2B_ONLY = '_ts_b2b_only';

    public static function init() {
        register_activation_hook( __FILE__, array( __CLASS__, 'add_b2b_role' ) );

        // 1. ZARZĄDZANIE W ADMINIE
        add_action( 'woocommerce_product_options_general_product_data', array( __CLASS__, 'add_b2b_visibility_field' ) );
        add_action( 'woocommerce_process_product_meta', array( __CLASS__, 'save_b2b_visibility_field' ) );

        // 2. MUR PRODUKTOWY (WIDOCZNOŚĆ)
        // Filtr dla zapytań o listy produktów (Sklep, Kategorie, Szukaj)
        add_action( 'woocommerce_product_query', array( __CLASS__, 'filter_products_by_b2b_role' ), 999 );
        
        // Zabezpieczenie przed wejściem bezpośrednim przez link (Single Product)
        add_filter( 'woocommerce_product_is_visible', array( __CLASS__, 'check_final_visibility' ), 999, 2 );

        // 3. PROFIL I DANE ZABLOKOWANE
        add_filter( 'woocommerce_billing_fields', array( __CLASS__, 'lock_b2b_fields' ), 1000, 1 );
        add_action( 'show_user_profile', array( __CLASS__, 'add_admin_user_fields' ) );
        add_action( 'edit_user_profile', array( __CLASS__, 'add_admin_user_fields' ) );
        add_action( 'edit_user_profile_update', array( __CLASS__, 'save_admin_user_fields' ) );
        add_action( 'user_new_form', array( __CLASS__, 'add_admin_user_fields' ) );
        add_action( 'user_register', array( __CLASS__, 'save_admin_user_fields' ) );

        // 4. BRAMKA I PANEL ZARZĄDZANIA
        add_filter( 'woocommerce_payment_gateways', array( __CLASS__, 'register_gateway' ) );
        add_action( 'wp_head', array( __CLASS__, 'b2b_checkout_styles' ), 30 );

        self::init_etap_3();
    }

    public static function add_b2b_role() {
        if ( ! get_role( self::B2B_ROLE ) ) {
            add_role( self::B2B_ROLE, 'Partner B2B', array( 'read' => true, 'customer' => true ) );
        }
    }

    /**
     * Pomocnicza funkcja: Sprawdza czy aktualny użytkownik jest Partnerem B2B.
     */
    public static function is_strictly_b2b() {
        if ( ! is_user_logged_in() ) return false;
        $user = wp_get_current_user();
        return in_array( self::B2B_ROLE, (array) $user->roles );
    }

    public static function add_b2b_visibility_field() {
        echo '<div class="options_group" style="background:#fff9e6; border-left:4px solid #ffba00; padding: 1px 0;">';
        woocommerce_wp_checkbox( array(
            'id'            => self::META_B2B_ONLY,
            'label'         => 'Tylko dla B2B',
            'description'   => 'Produkt będzie widoczny WYŁĄCZNIE dla zalogowanych Partnerów B2B.',
        ) );
        echo '</div>';
    }

    public static function save_b2b_visibility_field( $post_id ) {
        $is_b2b = isset( $_POST[self::META_B2B_ONLY] ) ? 'yes' : 'no';
        update_post_meta( $post_id, self::META_B2B_ONLY, $is_b2b );
    }

    /**
     * Filtracja zapytań o produkty (Sklep, Kategorie).
     */
    public static function filter_products_by_b2b_role( $query ) {
        if ( is_admin() || current_user_can( 'manage_options' ) ) return;

        $meta_query = (array) $query->get( 'meta_query' );

        if ( self::is_strictly_b2b() ) {
            // PARTNER B2B: Widzi TYLKO produkty B2B
            $meta_query[] = array(
                'key'     => self::META_B2B_ONLY,
                'value'   => 'yes',
                'compare' => '='
            );
        } else {
            // GOŚĆ / B2C: Widzi tylko produkty, które NIE są oznaczone jako B2B
            $meta_query[] = array(
                'relation' => 'OR',
                array( 'key' => self::META_B2B_ONLY, 'value' => 'yes', 'compare' => '!=' ),
                array( 'key' => self::META_B2B_ONLY, 'compare' => 'NOT EXISTS' )
            );
        }

        $query->set( 'meta_query', $meta_query );
    }

    /**
     * Zabezpieczenie strony pojedynczego produktu (Mur 404).
     */
    public static function check_final_visibility( $visible, $product_id ) {
        if ( is_admin() || current_user_can( 'manage_options' ) ) return $visible;

        $is_b2b_product = get_post_meta( $product_id, self::META_B2B_ONLY, true ) === 'yes';
        $is_b2b_user = self::is_strictly_b2b();

        // Jeśli user B2B -> widzi tylko produkty B2B
        if ( $is_b2b_user ) return $is_b2b_product;

        // Jeśli user B2C/Gość -> widzi tylko produkty nie-B2B
        return ! $is_b2b_product;
    }

    public static function lock_b2b_fields( $fields ) {
        if ( self::is_strictly_b2b() ) {
            foreach ( array( 'billing_company', 'billing_nip', 'billing_email' ) as $f ) {
                if ( isset( $fields[$f] ) ) $fields[$f]['custom_attributes']['readonly'] = 'readonly';
            }
        }
        return $fields;
    }

    public static function add_admin_user_fields( $user ) {
        $user_id = is_object($user) ? $user->ID : 0;
        ?>
        <h3>Dane Partnera B2B</h3>
        <table class="form-table">
            <tr><th><label>Firma</label></th><td><input type="text" name="billing_company" value="<?php echo esc_attr( get_user_meta( $user_id, 'billing_company', true ) ); ?>" class="regular-text" /></td></tr>
            <tr><th><label>NIP</label></th><td><input type="text" name="billing_nip" value="<?php echo esc_attr( get_user_meta( $user_id, 'billing_nip', true ) ); ?>" class="regular-text" /></td></tr>
        </table>
        <?php
    }

    public static function save_admin_user_fields( $user_id ) {
        if ( isset( $_POST['billing_company'] ) ) update_user_meta( $user_id, 'billing_company', sanitize_text_field( $_POST['billing_company'] ) );
        if ( isset( $_POST['billing_nip'] ) ) update_user_meta( $user_id, 'billing_nip', sanitize_text_field( $_POST['billing_nip'] ) );
    }

    public static function b2b_checkout_styles() {
        if ( is_checkout() && self::is_strictly_b2b() ) {
            echo '<style>.apple-invoice-toggle, #billing_want_invoice_field { display: none !important; } #billing_company_field, #billing_nip_field { display: block !important; opacity: 0.9; } .woocommerce-checkout { background: #fff !important; }</style>';
        }
    }

    public static function register_gateway( $gateways ) {
        $gateways[] = 'TS_B2B_Deferred_Gateway';
        return $gateways;
    }

    public static function init_etap_3() {
        add_filter( 'woocommerce_account_menu_items', array( __CLASS__, 'add_b2b_menu_item' ) );
        add_action( 'init', array( __CLASS__, 'add_b2b_endpoint' ) );
        add_action( 'woocommerce_account_b2b-services_endpoint', array( __CLASS__, 'render_b2b_services_page' ) );
        add_action( 'wp_ajax_ts_b2b_cancel_meal', array( __CLASS__, 'ajax_handle_cancellation' ) );
    }

    public static function add_b2b_menu_item( $items ) {
        if ( self::is_strictly_b2b() ) {
            $logout = $items['customer-logout']; unset( $items['customer-logout'] );
            $items['b2b-services'] = 'Moje Usługi (B2B)';
            $items['customer-logout'] = $logout;
        }
        return $items;
    }

    public static function add_b2b_endpoint() {
        add_rewrite_endpoint( 'b2b-services', EP_PAGES );
    }

    public static function render_b2b_services_page() {
        global $wpdb;
        $table = $wpdb->prefix . 'tsme_meal_codes';
        $services = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM $table WHERE order_id IN (SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_customer_user' AND meta_value = %d) AND status != 'void' ORDER BY stay_from ASC", get_current_user_id() ), ARRAY_A );
        echo '<h3>Twoje zarezerwowane posiłki</h3>';
        if ( ! $services ) { echo '<p>Brak aktywnych rezerwacji.</p>'; return; }
        echo '<table class="shop_table"><thead><tr><th>Usługa</th><th>Obiekt</th><th>Data</th><th>Akcja</th></tr></thead><tbody>';
        foreach ( $services as $row ) {
            $can_cancel = ( strtotime( $row['stay_from'] ) - time() > 7 * DAY_IN_SECONDS );
            echo '<tr><td>'.esc_html($row['meal_type']).'</td><td>'.esc_html($row['object_label']).'</td><td>'.esc_html($row['stay_from']).'</td><td>';
            if ( $can_cancel ) echo '<button class="button ts-cancel" data-id="'.(int)$row['id'].'">Anuluj</button>';
            else echo 'Brak możliwości';
            echo '</td></tr>';
        }
        echo '</tbody></table>';
        ?>
        <script>
        jQuery('.ts-cancel').on('click', function(){
            var $b = jQuery(this); if(!confirm('Anulować?')) return;
            $b.prop('disabled', true);
            jQuery.post('<?php echo admin_url('admin-ajax.php'); ?>', { action: 'ts_b2b_cancel_meal', code_id: $b.data('id') }, function(){ location.reload(); });
        });
        </script>
        <?php
    }

    public static function ajax_handle_cancellation() {
        if ( class_exists('TSME_Codes') ) TSME_Codes::void_code_by_id( absint($_POST['code_id']) );
        wp_send_json_success();
    }
}

add_action( 'plugins_loaded', function() {
    if ( ! class_exists( 'WC_Payment_Gateway' ) ) return;
    class TS_B2B_Deferred_Gateway extends WC_Payment_Gateway {
        public function __construct() {
            $this->id = 'ts_b2b_deferred'; $this->method_title = 'Płatność B2B'; $this->title = 'Na podstawie faktury (B2B)';
            $this->init_form_fields(); $this->init_settings();
            add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
        }
        public function init_form_fields() { $this->form_fields = array('enabled' => array('title' => 'Włącz', 'type' => 'checkbox', 'default' => 'yes')); }
        public function is_available() { return TS_B2B_Connector::is_strictly_b2b(); }
        public function process_payment( $order_id ) {
            $order = wc_get_order( $order_id ); $order->update_status( 'completed', 'B2B: Faktura zbiorcza.' );
            $order->add_meta_data( '_is_b2b_order', 'yes', true ); $order->save();
            WC()->cart->empty_cart(); return array( 'result' => 'success', 'redirect' => $this->get_return_url( $order ) );
        }
    }
}, 11 );

TS_B2B_Connector::init();