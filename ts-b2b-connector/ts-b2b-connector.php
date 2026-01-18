<?php
/**
 * Plugin Name: TS B2B Connector
 * Description: Obsługa Partnerów B2B: Precyzyjna separacja ról, specjalne ceny i płatności odroczone.
 * Version: 1.1.0
 * Author: TechSolver
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class TS_B2B_Connector {

    const B2B_ROLE = 'b2b_partner';

    public static function init() {
        register_activation_hook( __FILE__, array( __CLASS__, 'add_b2b_role' ) );

        // Zarządzanie cenami w adminie
        add_action( 'woocommerce_product_options_general_product_data', array( __CLASS__, 'add_b2b_price_field' ) );
        add_action( 'woocommerce_product_after_variable_attributes', array( __CLASS__, 'add_b2b_variation_price_field' ), 10, 3 );
        add_action( 'woocommerce_process_product_meta', array( __CLASS__, 'save_b2b_price_field' ) );
        add_action( 'woocommerce_save_product_variation', array( __CLASS__, 'save_b2b_variation_price_field' ), 10, 2 );

        // Silnik nadpisywania cen
        // Silnik nadpisywania cen (rozszerzony o warianty i zakresy)
        add_filter( 'woocommerce_product_get_price', array( __CLASS__, 'apply_b2b_price' ), 100, 2 );
        add_filter( 'woocommerce_product_variation_get_price', array( __CLASS__, 'apply_b2b_price' ), 100, 2 );
        add_filter( 'woocommerce_product_variation_get_regular_price', array( __CLASS__, 'apply_b2b_price' ), 100, 2 );
        add_filter( 'woocommerce_variation_prices_price', array( __CLASS__, 'apply_b2b_price' ), 100, 2 );
        add_filter( 'woocommerce_variation_prices_regular_price', array( __CLASS__, 'apply_b2b_price' ), 100, 2 );

        // Wyłączenie cache cen wariantów dla B2B (kluczowe dla wariantów!)
        add_filter( 'woocommerce_get_variation_prices_hash', array( __CLASS__, 'b2b_variation_prices_hash' ), 100, 1 );

        // Hardening profilu B2B
        add_filter( 'woocommerce_billing_fields', array( __CLASS__, 'lock_b2b_billing_fields' ), 100, 1 );
        add_filter( 'woocommerce_checkout_fields', array( __CLASS__, 'lock_b2b_checkout_fields' ), 110, 1 );

        // Zarządzanie danymi firmy w profilu użytkownika (Admin) - NOWOŚĆ
        add_action( 'show_user_profile', array( __CLASS__, 'add_admin_user_b2b_fields' ) );
        add_action( 'edit_user_profile', array( __CLASS__, 'add_admin_user_b2b_fields' ) );
        add_action( 'user_new_form', array( __CLASS__, 'add_admin_user_b2b_fields' ) );
        
        add_action( 'personal_options_update', array( __CLASS__, 'save_admin_user_b2b_fields' ) );
        add_action( 'edit_user_profile_update', array( __CLASS__, 'save_admin_user_b2b_fields' ) );
        add_action( 'user_register', array( __CLASS__, 'save_admin_user_b2b_fields' ) );

        // Rejestracja bramki płatności
        add_filter( 'woocommerce_payment_gateways', array( __CLASS__, 'register_gateway' ) );

        // ETAP 3: Inicjalizacja panelu zarządzania usługami
        self::init_etap_3();

        // ETAP 2: Style wymuszające widoczność pól B2B w Checkout
        add_action( 'wp_head', array( __CLASS__, 'b2b_checkout_styles' ) );
        
    }

    public static function add_b2b_role() {
        if ( ! get_role( self::B2B_ROLE ) ) {
            add_role( self::B2B_ROLE, 'Partner B2B', array(
                'read' => true,
                'customer' => true,
            ) );
        }
    }

    public static function add_b2b_price_field() {
        echo '<div class="options_group" style="background:#f0f9ff; border-left:4px solid #007cba; padding: 1px 0;">';
        woocommerce_wp_text_input( array(
            'id' => '_b2b_price',
            'label' => 'Cena B2B (zł)',
            'description' => 'Cena widoczna wyłącznie dla Partnerów B2B.',
            'desc_tip' => true,
            'type' => 'number',
            'custom_attributes' => array( 'step' => '0.01', 'min' => '0' )
        ) );
        echo '</div>';
    }

    public static function add_b2b_variation_price_field( $loop, $variation_data, $variation ) {
        woocommerce_wp_text_input( array(
            'id' => '_b2b_price[' . $loop . ']',
            'label' => 'Cena B2B (zł)',
            'value' => get_post_meta( $variation->ID, '_b2b_price', true ),
            'type' => 'number',
            'custom_attributes' => array( 'step' => '0.01', 'min' => '0' )
        ) );
    }

    public static function save_b2b_price_field( $post_id ) {
        if ( isset( $_POST['_b2b_price'] ) ) {
            update_post_meta( $post_id, '_b2b_price', sanitize_text_field( $_POST['_b2b_price'] ) );
        }
    }

    public static function save_b2b_variation_price_field( $variation_id, $i ) {
        if ( isset( $_POST['_b2b_price'][$i] ) ) {
            update_post_meta( $variation_id, '_b2b_price', sanitize_text_field( $_POST['_b2b_price'][$i] ) );
        }
    }

    public static function apply_b2b_price( $price, $product ) {
        if ( ! is_user_logged_in() ) return $price;
        $user = wp_get_current_user();
        if ( ! in_array( self::B2B_ROLE, (array) $user->roles ) ) return $price;

        $b2b_price = get_post_meta( $product->get_id(), '_b2b_price', true );
        return ( is_numeric( $b2b_price ) && $b2b_price > 0 ) ? $b2b_price : $price;
    }

/**
     * Zmienia hash cen wariantów, aby WooCommerce generował osobny cache dla Partnerów B2B.
     */
    public static function b2b_variation_prices_hash( $hash ) {
        if ( is_user_logged_in() ) {
            $user = wp_get_current_user();
            if ( in_array( self::B2B_ROLE, (array) $user->roles ) ) {
                $hash[] = 'b2b_partner_price';
            }
        }
        return $hash;
    }

    public static function register_gateway( $gateways ) {
        $gateways[] = 'TS_B2B_Deferred_Gateway';
        return $gateways;
    }
    /**
     * Blokuje edycję kluczowych pól w panelu "Moje Konto" dla Partnera B2B.
     */
    public static function lock_b2b_billing_fields( $fields ) {
        if ( ! is_user_logged_in() ) return $fields;
        $user = wp_get_current_user();
        if ( in_array( self::B2B_ROLE, (array) $user->roles ) ) {
            $fields_to_lock = array( 'billing_company', 'billing_email', 'billing_nip' );
            foreach ( $fields_to_lock as $field_id ) {
                if ( isset( $fields[$field_id] ) ) {
                    $fields[$field_id]['custom_attributes']['readonly'] = 'readonly';
                    $fields[$field_id]['description'] = 'Dane zablokowane dla konta B2B. Skontaktuj się z administratorem, aby je zmienić.';
                }
            }
        }
        return $fields;
    }

    /**
     * Blokuje edycję kluczowych pól podczas Checkoutu dla Partnera B2B.
     */
    public static function lock_b2b_checkout_fields( $fields ) {
        if ( ! is_user_logged_in() ) return $fields;
        $user = wp_get_current_user();
        if ( in_array( self::B2B_ROLE, (array) $user->roles ) ) {
            $fields_to_lock = array( 'billing_company', 'billing_email', 'billing_nip' );
            foreach ( $fields_to_lock as $field_id ) {
                if ( isset( $fields['billing'][$field_id] ) ) {
                    $fields['billing'][$field_id]['custom_attributes']['readonly'] = 'readonly';
                }
            }
        }
        return $fields;
    }

    // --- ETAP 3: LOGIKA PANELU I ANULACJI (7 DNI) ---

    public static function init_etap_3() {
        add_filter( 'woocommerce_account_menu_items', array( __CLASS__, 'add_b2b_menu_item' ) );
        add_action( 'init', array( __CLASS__, 'add_b2b_endpoint' ) );
        add_action( 'woocommerce_account_b2b-services_endpoint', array( __CLASS__, 'render_b2b_services_page' ) );
        add_action( 'wp_ajax_ts_b2b_cancel_meal', array( __CLASS__, 'ajax_handle_cancellation' ) );
    }

    public static function add_b2b_menu_item( $items ) {
        if ( is_user_logged_in() && in_array( self::B2B_ROLE, (array) wp_get_current_user()->roles ) ) {
            $logout = $items['customer-logout'];
            unset( $items['customer-logout'] );
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
        $user_id = get_current_user_id();
        $table = $wpdb->prefix . 'tsme_meal_codes';

        $services = $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM $table WHERE order_id IN (
                SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_customer_user' AND meta_value = %d
            ) AND status != 'void' ORDER BY stay_from ASC",
            $user_id
        ), ARRAY_A );

        echo '<h3>Twoje zarezerwowane posiłki</h3>';
        echo '<p>Możesz anulować posiłki maksymalnie na <strong>7 dni</strong> przed datą rozpoczęcia.</p>';

        if ( ! $services ) {
            echo '<p>Brak aktywnych usług.</p>';
            return;
        }

        echo '<table class="woocommerce-orders-table shop_table shop_table_responsive my_account_orders">';
        echo '<thead><tr><th>Usługa</th><th>Obiekt/Pokój</th><th>Data</th><th>Akcje</th></tr></thead><tbody>';

        foreach ( $services as $row ) {
            $deadline = strtotime( $row['stay_from'] ) - ( 7 * DAY_IN_SECONDS );
            $can_cancel = ( time() < $deadline );

            echo '<tr><td>' . esc_html($row['meal_type']) . '</td><td>' . esc_html($row['object_label']) . '</td><td>' . esc_html($row['stay_from']) . '</td><td>';
            if ( $can_cancel ) {
                echo '<button class="button ts-b2b-cancel-btn" data-id="'.(int)$row['id'].'" data-item-id="'.(int)$row['order_item_id'].'">Anuluj</button>';
            } else {
                echo '<span style="color:#999; font-size:0.9em;">Po terminie</span>';
            }
            echo '</td></tr>';
        }
        echo '</tbody></table>';
        ?>
        <script>
        jQuery(function($){
            $('.ts-b2b-cancel-btn').on('click', function(){
                if(!confirm('Anulować tę usługę?')) return;
                var $btn = $(this);
                $btn.prop('disabled', true).text('...');
                $.post('<?php echo admin_url('admin-ajax.php'); ?>', {
                    action: 'ts_b2b_cancel_meal',
                    code_id: $btn.data('id'),
                    item_id: $btn.data('item-id')
                }, function(res) {
                    if (res.success) $btn.closest('tr').fadeOut();
                    else alert('Błąd: ' + res.data);
                });
            });
        });
        </script>
        <?php
    }

    public static function ajax_handle_cancellation() {
        if ( ! is_user_logged_in() ) wp_send_json_error('Brak logowania');
        $code_id = isset($_POST['code_id']) ? absint($_POST['code_id']) : 0;
        if ( $code_id && class_exists('TSME_Codes') ) {
            TSME_Codes::void_code_by_id( $code_id );
            wp_send_json_success();
        }
        wp_send_json_error('Błąd anulacji');
    }

    /**
     * Wyświetla pola NIP i Firma w edycji i dodawaniu użytkownika.
     */
    public static function add_admin_user_b2b_fields( $user ) {
        // Pobieramy wartości jeśli edytujemy istniejącego usera
        $user_id = is_object($user) ? $user->ID : 0;
        $company = $user_id ? get_user_meta( $user_id, 'billing_company', true ) : '';
        $nip     = $user_id ? get_user_meta( $user_id, 'billing_nip', true ) : '';
        
        // Nagłówek sekcji
        $title = is_object($user) ? 'Dane Partnera B2B' : 'Dane Firmowe (B2B)';
        ?>
        <h3><?php echo esc_html($title); ?></h3>
        <table class="form-table">
            <tr>
                <th><label for="billing_company">Nazwa firmy</label></th>
                <td>
                    <input type="text" name="billing_company" id="billing_company" value="<?php echo esc_attr( $company ); ?>" class="regular-text" />
                </td>
            </tr>
            <tr>
                <th><label for="billing_nip">NIP</label></th>
                <td>
                    <input type="text" name="billing_nip" id="billing_nip" value="<?php echo esc_attr( $nip ); ?>" class="regular-text" />
                    <p class="description">Wprowadź NIP firmy dla rozliczeń zbiorczych B2B.</p>
                </td>
            </tr>
        </table>
        <?php
    }

    /**
     * Zapisuje dane NIP i Firma z profilu admina.
     */
    public static function save_admin_user_b2b_fields( $user_id ) {
        if ( ! current_user_can( 'edit_user', $user_id ) ) {
            return false;
        }

        if ( isset( $_POST['billing_company'] ) ) {
            update_user_meta( $user_id, 'billing_company', sanitize_text_field( $_POST['billing_company'] ) );
        }
        if ( isset( $_POST['billing_nip'] ) ) {
            update_user_meta( $user_id, 'billing_nip', sanitize_text_field( $_POST['billing_nip'] ) );
        }
    }
    /**
     * Wymusza widoczność NIP i Firmy oraz ukrywa zbędny checkbox faktury dla Partnera B2B.
     */
    public static function b2b_checkout_styles() {
        if ( is_checkout() && is_user_logged_in() && in_array( self::B2B_ROLE, (array) wp_get_current_user()->roles ) ) {
            echo '<style>
                .apple-invoice-toggle, #billing_want_invoice_field { display: none !important; }
                #billing_company_field, #billing_nip_field { display: block !important; opacity: 0.85; }
                #billing_company_field label .optional-text, #billing_nip_field label .optional-text { display: none !important; }
            </style>';
        }
    }
}




add_action( 'plugins_loaded', function() {
    if ( ! class_exists( 'WC_Payment_Gateway' ) ) return;

    class TS_B2B_Deferred_Gateway extends WC_Payment_Gateway {
        public function __construct() {
            $this->id = 'ts_b2b_deferred';
            $this->method_title = 'Płatność B2B (Faktura Zbiorcza)';
            $this->title = 'Na podstawie faktury (płatność odroczona)';
            $this->has_fields = false;
            $this->init_form_fields();
            $this->init_settings();
            add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
        }

        public function init_form_fields() {
            $this->form_fields = array( 'enabled' => array( 'title' => 'Włącz/Wyłącz', 'type' => 'checkbox', 'default' => 'yes' ) );
        }

        public function is_available() {
            if ( ! is_user_logged_in() ) return false;
            return in_array( TS_B2B_Connector::B2B_ROLE, (array) wp_get_current_user()->roles );
        }

        public function process_payment( $order_id ) {
            $order = wc_get_order( $order_id );
            $order->update_status( 'completed', 'Zamówienie Partnera B2B - faktura zbiorcza.' );
            $order->add_meta_data( '_is_b2b_order', 'yes', true );
            $order->add_meta_data( '_b2b_deferred_payment', 'yes', true );
            $order->save();
            WC()->cart->empty_cart();
            return array( 'result' => 'success', 'redirect' => $this->get_return_url( $order ) );
        }
    }
}, 11 );

TS_B2B_Connector::init();