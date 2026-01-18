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

        // Zmieniony hook na general_product_data dla lepszej widoczności
        add_action( 'woocommerce_product_options_general_product_data', array( __CLASS__, 'add_b2b_price_field' ) );
        add_action( 'woocommerce_product_after_variable_attributes', array( __CLASS__, 'add_b2b_variation_price_field' ), 10, 3 );
        
        add_action( 'woocommerce_process_product_meta', array( __CLASS__, 'save_b2b_price_field' ) );
        add_action( 'woocommerce_save_product_variation', array( __CLASS__, 'save_b2b_variation_price_field' ), 10, 2 );

        add_filter( 'woocommerce_product_get_price', array( __CLASS__, 'apply_b2b_price' ), 100, 2 );
        add_filter( 'woocommerce_product_variation_get_price', array( __CLASS__, 'apply_b2b_price' ), 100, 2 );

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

    public static function register_gateway( $gateways ) {
        $gateways[] = 'TS_B2B_Deferred_Gateway';
        return $gateways;
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
            $order->update_status( 'completed', 'Zamówienie Partnera B2B - płatność odroczona.' );
            $order->add_meta_data( '_is_b2b_order', 'yes', true );
            $order->save();
            WC()->cart->empty_cart();
            return array( 'result' => 'success', 'redirect' => $this->get_return_url( $order ) );
        }
    }
}, 11 );

TS_B2B_Connector::init();