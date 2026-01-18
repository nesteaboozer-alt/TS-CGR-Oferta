<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class TS_B2B_Gateway {

    public static function init() {
        // Rejestracja bramki
        add_filter( 'woocommerce_payment_gateways', array( __CLASS__, 'register_gateway' ) );

        // Klasa bramki musi powstać dopiero gdy WooCommerce załaduje WC_Payment_Gateway
        add_action( 'plugins_loaded', array( __CLASS__, 'register_gateway_class' ), 11 );
    }

    public static function register_gateway( $gateways ) {
        $gateways[] = 'TS_B2B_Deferred_Gateway';
        return $gateways;
    }

    public static function register_gateway_class() {
        if ( ! class_exists( 'WC_Payment_Gateway' ) ) return;
        if ( class_exists( 'TS_B2B_Deferred_Gateway' ) ) return;

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
                return TS_B2B_Core::is_strictly_b2b();
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
    }
}
