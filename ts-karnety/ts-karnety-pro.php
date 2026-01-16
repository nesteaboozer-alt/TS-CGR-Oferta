<?php
/**
 * Plugin Name: TS Karnety Pro
 * Description: Połączona wersja: logika TS Karnety (Final) + dashboard kasjera TS Backoffice w jednej wtyczce.
 * Version: 1.0.0
 * Author: TechSolver
 * Text Domain: ts-karnety-pro
 */

if (!defined('ABSPATH')) exit;

// Główne stałe wtyczki
define('TSKP_VER', '1.0.0');
define('TSKP_FILE', __FILE__);
define('TSKP_DIR', plugin_dir_path(__FILE__));
define('TSKP_URL', plugin_dir_url(__FILE__));

// Kompatybilność: stare stałe TS Karnety (Final)
if (!defined('TSKF_VER')) {
    define('TSKF_VER', '0.4.5');
    define('TSKF_FILE', TSKP_FILE);
    define('TSKF_DIR', TSKP_DIR);
    define('TSKF_URL', TSKP_URL);
}

// Kompatybilność: stare stałe TS Backoffice Dashboard
if (!defined('TSB_VER')) {
    define('TSB_VER', '0.3.7');
    define('TSB_FILE', TSKP_FILE);
    define('TSB_DIR', TSKP_DIR);
    define('TSB_URL', TSKP_URL);
}

// Ładowanie klas TS Karnety
require_once TSKP_DIR.'includes/class-tskf-helpers.php';
require_once TSKP_DIR.'includes/class-tskf-tickets.php';
require_once TSKP_DIR.'includes/class-tskf-engine.php';
require_once TSKP_DIR.'includes/class-tskf-productmeta.php';
require_once TSKP_DIR.'includes/class-tskf-orderhooks.php';
require_once TSKP_DIR.'includes/class-tskf-email.php';
require_once TSKP_DIR.'includes/class-tskf-admin.php';

// Ładowanie dashboardu kasjera
require_once TSKP_DIR.'includes/class-tsb-plugin.php';

// Hooki aktywacji i migracji (skopiowane z TS Karnety Final)

register_activation_hook(TSKF_FILE, ['TSKF_Tickets','activate']);

add_action('plugins_loaded', function(){
    global $wpdb;
    $table = TSKF_Tickets::table();
    $cols  = $wpdb->get_col("DESC $table", 0);
    if ($cols) {
        if (!in_array('treatment_date',$cols, true)) {
            $wpdb->query("ALTER TABLE $table ADD COLUMN treatment_date DATETIME NULL AFTER updated_at");
        }
        if (!in_array('treatment_client',$cols, true)) {
            $wpdb->query("ALTER TABLE $table ADD COLUMN treatment_client VARCHAR(255) NULL AFTER treatment_date");
        }
    }
});

add_action('plugins_loaded', function(){
    if (! class_exists('WooCommerce')) return;
    TSKF_ProductMeta::init();
    TSKF_OrderHooks::init();
    TSKF_Admin::init();
});


add_action('plugins_loaded', function(){
    if (!class_exists('WooCommerce')) return;
    if (!class_exists('TSKF_Tickets')) return;
    TSB_Plugin::init();
});
