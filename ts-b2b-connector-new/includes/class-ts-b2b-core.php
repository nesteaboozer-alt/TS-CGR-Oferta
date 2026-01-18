<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class TS_B2B_Core {

    const B2B_ROLE      = 'b2b_partner';
    const META_B2B_ONLY = '_ts_b2b_only';

    const B2B_CHECKOUT_SLUG = 'b2b-checkout';
    const OPT_B2B_CHECKOUT_PAGE_ID = 'ts_b2b_checkout_page_id';

    public static function init() {
        // Auto-naprawa roli w panelu
        if ( is_admin() && ! get_role( self::B2B_ROLE ) ) {
            self::add_b2b_role();
        }

        // Redirect checkoutu (B2B -> dedykowany, B2C -> standard)
        add_action( 'template_redirect', array( __CLASS__, 'redirect_checkout_to_b2b_checkout' ), 20 );

        // B2B: checkout URL ma wskazywać na /b2b-checkout (żeby <form action> było poprawne)
        add_filter( 'woocommerce_get_checkout_url', array( __CLASS__, 'filter_checkout_url_for_b2b' ), 9999, 1 );
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

    /**
     * Pancerne sprawdzenie roli.
     */
    public static function is_strictly_b2b() {
        if ( ! is_user_logged_in() ) return false;
        $user = wp_get_current_user();
        if ( ! $user || $user->ID === 0 ) return false;

        // Admin nigdy nie jest "strictly b2b"
        if ( user_can( $user, 'manage_options' ) ) return false;

        return in_array( self::B2B_ROLE, (array) $user->roles, true );
    }

    public static function is_checkout_context() {
        $is_checkout = function_exists('is_checkout') && is_checkout();
        $is_ajax     = function_exists('wp_doing_ajax') && wp_doing_ajax();
        return $is_checkout || $is_ajax;
    }

    /**
     * Ustalenie ID strony checkoutu B2B (tworzy jeśli nie istnieje).
     */
    public static function ensure_b2b_checkout_page() {
        $page_id = (int) get_option( self::OPT_B2B_CHECKOUT_PAGE_ID, 0 );
        if ( $page_id && get_post( $page_id ) ) return $page_id;

        // Jeśli już istnieje po slugu – podepnij
        $existing = get_page_by_path( self::B2B_CHECKOUT_SLUG );
        if ( $existing && ! empty( $existing->ID ) ) {
            update_option( self::OPT_B2B_CHECKOUT_PAGE_ID, (int) $existing->ID, false );
            return (int) $existing->ID;
        }

        // Utwórz stronę
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

    public static function get_b2b_checkout_page_id() {
        $page_id = (int) get_option( self::OPT_B2B_CHECKOUT_PAGE_ID, 0 );
        if ( $page_id && get_post( $page_id ) ) return $page_id;
        return self::ensure_b2b_checkout_page();
    }

    public static function is_b2b_checkout_page() {
        $pid = self::get_b2b_checkout_page_id();
        return $pid ? is_page( $pid ) : false;
    }

    /**
     * Redirecty checkoutu:
     * - B2B: standard checkout -> /b2b-checkout
     * - B2C/Gość: /b2b-checkout -> standard checkout
     */
    public static function redirect_checkout_to_b2b_checkout() {
        if ( is_admin() || ( defined('DOING_AJAX') && DOING_AJAX ) ) return;
        if ( current_user_can( 'manage_options' ) ) return;

        $b2b_pid = self::get_b2b_checkout_page_id();
        if ( ! $b2b_pid ) return;

        // B2B wchodzi na standardowy checkout => przekieruj na B2B checkout
        if ( self::is_strictly_b2b() && function_exists('is_checkout') && is_checkout() && ! self::is_b2b_checkout_page() ) {
            wp_safe_redirect( get_permalink( $b2b_pid ) );
            exit;
        }

        // B2C/Gość wchodzi na B2B checkout => przekieruj na standardowy checkout
        if ( ! self::is_strictly_b2b() && self::is_b2b_checkout_page() ) {
            if ( function_exists('wc_get_checkout_url') ) {
                wp_safe_redirect( wc_get_checkout_url() );
                exit;
            }
        }
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
}
