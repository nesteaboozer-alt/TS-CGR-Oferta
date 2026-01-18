<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class TS_B2B_Checkout {

    public static function init() {
        // Checkout readonly (UI): firma / NIP / email
        add_filter( 'woocommerce_billing_fields', array( __CLASS__, 'lock_b2b_fields' ), 1000, 1 );

        // B2B checkout: brak kuponów
        add_filter( 'woocommerce_coupons_enabled', array( __CLASS__, 'disable_coupons_for_b2b_checkout' ), 9999 );

        // B2B checkout: usuń "chcę fakturę" + wymuś pola company/nip + readonly
        add_filter( 'woocommerce_checkout_fields', array( __CLASS__, 'cleanup_checkout_fields_for_b2b' ), 9999, 1 );

        // B2B checkout: prefill wartości (firma/NIP/email)
        add_filter( 'woocommerce_checkout_get_value', array( __CLASS__, 'force_b2b_checkout_prefill_values' ), 9999, 2 );

        // B2B checkout: tylko 1 bramka płatności (nasza)
        add_filter( 'woocommerce_available_payment_gateways', array( __CLASS__, 'restrict_payment_gateways_for_b2b' ), 9999, 1 );

        // CSS: pokaż company/nip + ukryj toggle faktury
        add_action( 'wp_head', array( __CLASS__, 'b2b_checkout_force_show_css' ), 9999 );
    }

    public static function lock_b2b_fields( $fields ) {
        if ( TS_B2B_Core::is_strictly_b2b() ) {
            foreach ( array( 'billing_company', 'billing_nip', 'billing_email' ) as $f ) {
                if ( isset( $fields[$f] ) ) {
                    if ( empty( $fields[$f]['custom_attributes'] ) || ! is_array( $fields[$f]['custom_attributes'] ) ) {
                        $fields[$f]['custom_attributes'] = array();
                    }
                    $fields[$f]['custom_attributes']['readonly'] = 'readonly';
                }
            }
        }
        return $fields;
    }

    public static function disable_coupons_for_b2b_checkout( $enabled ) {
        if ( is_admin() ) return $enabled;
        if ( current_user_can( 'manage_options' ) ) return $enabled;

        if ( ! TS_B2B_Core::is_strictly_b2b() ) return $enabled;
        if ( TS_B2B_Core::is_checkout_context() ) return false;

        return $enabled;
    }

    public static function cleanup_checkout_fields_for_b2b( $fields ) {
        if ( is_admin() ) return $fields;
        if ( current_user_can( 'manage_options' ) ) return $fields;

        if ( ! TS_B2B_Core::is_strictly_b2b() ) return $fields;
        if ( ! TS_B2B_Core::is_checkout_context() ) return $fields;

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

        if ( ! TS_B2B_Core::is_strictly_b2b() ) return $value;
        if ( ! TS_B2B_Core::is_checkout_context() ) return $value;

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

        if ( ! TS_B2B_Core::is_strictly_b2b() ) return $gateways;
        if ( ! TS_B2B_Core::is_checkout_context() ) return $gateways;

        foreach ( $gateways as $id => $gw ) {
            if ( $id !== 'ts_b2b_deferred' ) {
                unset( $gateways[ $id ] );
            }
        }

        return $gateways;
    }

    public static function b2b_checkout_force_show_css() {
        if ( is_admin() ) return;
        if ( current_user_can( 'manage_options' ) ) return;
        if ( ! TS_B2B_Core::is_strictly_b2b() ) return;
        if ( ! TS_B2B_Core::is_checkout_context() ) return;

        echo '<style>
            #billing_company_field, #billing_nip_field { display:block !important; visibility:visible !important; }
            #billing_want_invoice_field, .apple-invoice-toggle { display:none !important; }
        </style>';
    }
}
