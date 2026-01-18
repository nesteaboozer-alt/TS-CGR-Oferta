<?php
/**
 * Plugin Name: TS B2B Connector (Zintegrowany)
 * Description: Obsługa Partnerów B2B: separacja XOR produktów, blokady danych firmowych, dedykowany checkout B2B.
 * Version: 3.3.0
 * Author: TechSolver
 */

if ( ! defined( 'ABSPATH' ) ) exit;

// Core + moduły
require_once __DIR__ . '/includes/class-ts-b2b-core.php';
require_once __DIR__ . '/includes/class-ts-b2b-product-wall.php';
require_once __DIR__ . '/includes/class-ts-b2b-profile-lock.php';
require_once __DIR__ . '/includes/class-ts-b2b-checkout.php';
require_once __DIR__ . '/includes/class-ts-b2b-services.php';
require_once __DIR__ . '/includes/class-ts-b2b-gateway.php';

// Aktywacja
register_activation_hook( __FILE__, array( 'TS_B2B_Core', 'on_activate' ) );

// Init
add_action( 'plugins_loaded', function() {
    TS_B2B_Core::init();
    TS_B2B_Product_Wall::init();
    TS_B2B_Profile_Lock::init();
    TS_B2B_Checkout::init();
    TS_B2B_Services::init();

    // Gateway (musi wystartować po załadowaniu WC_Payment_Gateway)
    TS_B2B_Gateway::init();
}, 11 );
