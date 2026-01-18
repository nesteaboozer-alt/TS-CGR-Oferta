<?php
/**
 * Plugin Name: TS Hotel Meals
 * Description: Sprzedaż śniadań i obiadokolacji dla hoteli na WooCommerce (TS), bez własnego typu produktu.
 * Version: 0.2.0
 * Author: TechSolver
 * Text Domain: ts-hotel-meals
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! defined( 'TSME_VER' ) ) {
    define( 'TSME_VER', '0.2.0' );
    define( 'TSME_FILE', __FILE__ );
    define( 'TSME_DIR', plugin_dir_path( __FILE__ ) );
    define( 'TSME_URL', plugin_dir_url( __FILE__ ) );
}

require_once TSME_DIR . 'includes/class-tsme-plugin.php';

// Start pluginu po załadowaniu wtyczek.
add_action( 'plugins_loaded', array( 'TSME_Plugin', 'init' ) );
