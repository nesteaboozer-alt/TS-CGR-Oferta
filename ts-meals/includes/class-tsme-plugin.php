<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Główny loader TS Hotel Meals.
 * Wersja 0.4 – kody + maile + meta-boks zamówienia + prosty dashboard.
 */
class TSME_Plugin {

    public static function init() {
        if ( ! class_exists( 'WooCommerce' ) ) {
            return;
        }

        self::includes();
        self::hooks();
    }

    protected static function includes() {
        require_once TSME_DIR . 'includes/class-tsme-admin-product.php';
        require_once TSME_DIR . 'includes/class-tsme-frontend.php';

        // Maile
        require_once TSME_DIR . 'includes/class-tsme-email.php';

        // Kody + prosty dashboard
        require_once TSME_DIR . 'includes/class-tsme-codes.php';
        require_once TSME_DIR . 'includes/class-tsme-admin-codes.php';

        // Meta-boks w zamówieniu
        require_once TSME_DIR . 'includes/class-tsme-admin-order.php';
    }

    protected static function hooks() {
        if ( is_admin() ) {
            TSME_Admin_Product::init();
            TSME_Admin_Codes::init();
            TSME_Admin_Order::init();
        }

        TSME_Frontend::init();
        TSME_Email::init();
        TSME_Codes::init();
    }
}
