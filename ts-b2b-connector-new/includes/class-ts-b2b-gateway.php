<?php
if ( ! defined( 'ABSPATH' ) ) exit;

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
                'enabled' => array( 'title' => 'Włącz', 'type' => 'checkbox', 'default' => 'yes' )
            );
        }

        public function is_available() {
            // Używamy TS_B2B_Core zamiast self
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

    // Rejestracja w WooCommerce
    add_filter( 'woocommerce_payment_gateways', function( $gateways ) {
        $gateways[] = 'TS_B2B_Deferred_Gateway';
        return $gateways;
    });
}, 11 );