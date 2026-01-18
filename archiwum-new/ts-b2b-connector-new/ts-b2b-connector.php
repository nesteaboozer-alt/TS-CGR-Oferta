<?php
/**
 * Plugin Name: TS B2B Connector (Zintegrowany - Modular)
 * Description: Obsługa Partnerów B2B: separacja XOR produktów, blokady danych firmowych, dedykowany checkout B2B.
 * Version: 3.3.0
 * Author: TechSolver
 */

if ( ! defined( 'ABSPATH' ) ) exit;

// Definicja ścieżki
define( 'TS_B2B_PATH', plugin_dir_path( __FILE__ ) );

// Ładowanie klas
require_once TS_B2B_PATH . 'includes/class-ts-b2b-core.php';
require_once TS_B2B_PATH . 'includes/class-ts-b2b-gateway.php';
require_once TS_B2B_PATH . 'includes/class-ts-b2b-services.php';

// Rejestracja hooka aktywacji (musi być w pliku głównym)
register_activation_hook( __FILE__, array( 'TS_B2B_Core', 'on_activate' ) );

// Start wtyczki
add_action( 'plugins_loaded', function() {
    TS_B2B_Core::init();
    TS_B2B_Services::init();
});