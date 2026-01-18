<?php
/**
 * Plugin Name: TS B2B Connector
 * Description: Obsługa Partnerów B2B: Precyzyjna separacja ról, specjalne ceny i płatności odroczone.
 * Version: 1.1.0
 * Author: TechSolver
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class TS_B2B_Connector {

    // Nazwa roli, na której opieramy całą logikę B2B
    const B2B_ROLE = 'b2b_partner';

    public static function init() {
        // Rejestracja roli przy aktywacji
        register_activation_hook( __FILE__, array( __CLASS__, 'add_b2b_role' ) );

        // Dodanie pola ceny B2B w edycji produktu
        add_action( 'woocommerce_product_options_pricing', array( __CLASS__, 'add_b2b_price_field' ) );
        add_action( 'woocommerce_product_after_variable_attributes', array( __CLASS__, 'add_b2b_variation_price_field' ), 10, 3 );
        add_action( 'woocommerce_process_product_meta', array( __CLASS__, 'save_b2b_price_field' ) );
        add_action( 'woocommerce_save_product_variation', array( __CLASS__, 'save_b2b_variation_price_field' ), 10, 2 );

        // Silnik nadpisywania cen - TYLKO DLA ROLI B2B
        add_filter( 'woocommerce_product_get_price', array( __CLASS__, 'apply_b2b_price' ), 100, 2 );
        add_filter( 'woocommerce_product_variation_get_price', array( __CLASS__, 'apply_b2b_price' ), 100, 2 );

        // Rejestracja bramki płatności
        add_filter( 'woocommerce_payment_gateways', array( __CLASS__, 'register_gateway' ) );
    }

    public static function add_b2b_role() {
        if ( ! get_role( self::B2B_ROLE ) ) {
            add_role( self::B2B_ROLE, 'Partner B2B', array(
                'read' => true,
                'customer' => true,
            ) );
        }
    }

    // --- POLA ADMINA ---

    public static function add_b2b_price_field() {
        woocommerce_wp_text_input( array(
            'id' => '_b2b_price',
            'label' => 'Cena B2B (zł)',
            'description' => 'Cena widoczna wyłącznie dla zalogowanych użytkowników z rolą Partner B2B.',
            'desc_tip' => true,
            'type' => 'number',
            'custom_attributes' => array( 'step' => '0.01', 'min' => '0' )
        ) );
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

    // --- LOGIKA CENOWA (PRECYZYJNA) ---

    public static function apply_b2b_price( $price, $product ) {
        if ( ! is_user_logged_in() ) {
            return $price;
        }

        $user = wp_get_current_user();
        // Sprawdzamy czy użytkownik ma rolę B2B. is_user_logged_in to za mało.
        if ( ! in_array( self::B2B_ROLE, (array) $user->roles ) ) {
            return $price;
        }

        $b2b_price = get_post_meta( $product->get_id(), '_b2b_price', true );
        
        // Jeśli cena B2B jest ustawiona, nadpisujemy standardową
        if ( is_numeric( $b2b_price ) && $b2b_price > 0 ) {
            return $b2b_price;
        }

        return $price;
    }

    public static function register_gateway( $gateways ) {
        $gateways[] = 'TS_B2B_Deferred_Gateway';
        return $gateways;
    }
}

// Inicjalizacja bramki płatności
add_action( 'plugins_loaded', function() {
    if ( ! class_exists( 'WC_Payment_Gateway' ) ) return;

    class TS_B2B_Deferred_Gateway extends WC_Payment_Gateway {
        public function __construct() {
            $this->id = 'ts_b2b_deferred';
            $this->method_title = 'Płatność B2B (Faktura Zbiorcza)';
            $this->method_description = 'Metoda widoczna TYLKO dla Partnerów B2B. Pozwala na realizację zamówienia bez wpłaty.';
            $this->title = 'Na podstawie faktury (płatność odroczona)';
            $this->has_fields = false;

            $this->init_form_fields();
            $this->init_settings();

            add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
        }

        public function init_form_fields() {
            $this->form_fields = array(
                'enabled' => array( 'title' => 'Włącz/Wyłącz', 'type' => 'checkbox', 'default' => 'yes' )
            );
        }

        // PRECYZYJNE ukrywanie bramki
        public function is_available() {
            if ( ! is_user_logged_in() ) return false;
            $user = wp_get_current_user();
            return in_array( TS_B2B_Connector::B2B_ROLE, (array) $user->roles );
        }

        public function process_payment( $order_id ) {
            $order = wc_get_order( $order_id );
            
            // Logika statusu:completed wyzwala Etap 1 (generowanie kodów)
            $order->update_status( 'completed', 'Zamówienie B2B rozliczone fakturą zbiorczą.' );
            
            // Oznaczamy zamówienie metadanymi dla przyszłych raportów B2B
            $order->add_meta_data( '_is_b2b_order', 'yes', true );
            $order->add_meta_data( '_b2b_deferred_payment', 'yes', true );
            $order->save();

            WC()->cart->empty_cart();

            return array(
                'result'   => 'success',
                'redirect' => $this->get_return_url( $order ),
            );
        }
    }
}, 11);

TS_B2B_Connector::init();