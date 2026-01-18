<?php
/**
 * Plugin Name: TS Raporty (WooCommerce)
 * Description: Operacyjne raporty WooCommerce: transakcje per pozycja, agregacje, posiłki (ts-meals), karnety (ts-karnety) + eksport CSV.
 * Version: 1.2.0
 * Author: TechSolver
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * Text Domain: ts-raporty
 */

if (!defined('ABSPATH')) { exit; }

define('TSR_PLUGIN_VERSION', '1.2.0');
define('TSR_PLUGIN_FILE', __FILE__);
define('TSR_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('TSR_PLUGIN_URL', plugin_dir_url(__FILE__));

require_once TSR_PLUGIN_DIR . 'includes/Util/class-tsr-autoloader.php';
\TSR\Util\Autoloader::init();

add_action('plugins_loaded', function() {
    if (!class_exists('WooCommerce')) { return; }
    \TSR\Core\Plugin::instance();
});

/**
 * Pancerne sprawdzanie harmonogramu.
 * Odpala się przy ładowaniu wtyczki, upewniając się, że zadanie widnieje w systemie.
 */
add_action('init', function() {
    if (!wp_next_scheduled('tsr_daily_report_cron')) {
        // Ustawiamy na 01:00 rano
        $time = strtotime('01:00:00');
        // Jeśli 01:00 już minęła dzisiaj, ustawiamy na jutro
        if ($time < time()) {
            $time += DAY_IN_SECONDS;
        }
        wp_schedule_event($time, 'daily', 'tsr_daily_report_cron');
    }
});

register_deactivation_hook(__FILE__, function() {
    wp_clear_scheduled_hook('tsr_daily_report_cron');
});

add_action('tsr_daily_report_cron', [\TSR\Core\DailyReporter::class, 'send_report']);

// Linki wyzwalające test i produkcję ręcznie
add_action('init', function() {
    if (isset($_GET['run_prod_report'])) { // Ręczne wywołanie raportu za wczoraj
        \TSR\Core\DailyReporter::send_report();
        die('Raport produkcyjny wysłany.');
    }
    if (isset($_GET['run_test_report'])) { // Testowy zakres 01.12 - 13.12
        \TSR\Core\DailyReporterTest::run_test();
    }
});