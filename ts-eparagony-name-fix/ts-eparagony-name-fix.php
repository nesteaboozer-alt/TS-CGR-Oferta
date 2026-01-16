<?php
/**
 * Plugin Name: TS — E‑Paragony Name Fix + Debug (Balanced)
 * Description: Prefiks tylko dla e‑paragonu (wykrywanie: AJAX/URL + backtrace „eparagon”), z twardymi wykluczeniami (maile / zwykłe widoki) aby nie „rozlewało się” poza paragon.
 * Version: 1.1.3
 * Author: TechSolver
 * Requires at least: 6.0
 * Requires PHP: 7.4
 */

if (!defined('ABSPATH')) { exit; }

final class TS_Eparagony_Name_Fix_Balanced {
    const VERSION = '1.1.3';
    private static $logged_items = [];

    public static function init() {
        add_action('woocommerce_init', [__CLASS__, 'register_hooks']);
    }

    public static function register_hooks() {
        add_filter('woocommerce_order_item_get_name', [__CLASS__, 'filter_item_name'], 10, 2);
        // Celowo nie używamy woocommerce_order_item_name (HTML) — to potrafi powodować dublowanie i wpływać na maile.
        add_action('admin_notices', [__CLASS__, 'admin_notice_log_path']);
    }

    public static function admin_notice_log_path() {
        if (!is_admin() || !current_user_can('manage_woocommerce')) { return; }
        if (!function_exists('get_current_screen')) { return; }
        $screen = get_current_screen();
        if (!$screen) { return; }
        if (stripos((string)$screen->id, 'shop_order') === false) { return; }

        echo '<div class="notice notice-info"><p><strong>TS — E‑Paragony Debug</strong>: log: <code>' .
            esc_html(self::log_file()) . '</code></p></div>';
    }

    /** Twarde wykluczenia — żeby nie zmieniać nazw w mailach i standardowych renderach. */
    private static function hard_exclusions(): bool {
        // Renderowanie emaili WooCommerce
        if (did_action('woocommerce_email_header') > 0) { return true; }
        if (doing_action('woocommerce_email_order_details')) { return true; }
        if (doing_action('woocommerce_email_order_meta')) { return true; }

        // Front-end (konto klienta / podziękowania) — NIE ruszamy, chyba że to AJAX (np. eparagony może iść AJAXem)
        if (!is_admin() && !wp_doing_ajax()) { return true; }

        return false;
    }

    /**
     * Wykrywanie kontekstu e‑paragony:
     * - AJAX action zawiera eparagon/eparagony
     * - admin URL params (action/page/itp)
     * - backtrace: klasa/funkcja/plik zawiera „eparagon”
     */
    private static function is_eparagony_request(): bool {
        if (self::hard_exclusions()) { return false; }

        // 1) AJAX action
        if (wp_doing_ajax() && isset($_REQUEST['action'])) {
            $a = (string) $_REQUEST['action'];
            if (stripos($a, 'eparagon') !== false || stripos($a, 'eparagony') !== false) {
                return true;
            }
        }

        // 2) admin URL params
        if (is_admin()) {
            foreach (['action','page','tab','section'] as $k) {
                if (!empty($_GET[$k])) {
                    $v = (string) $_GET[$k];
                    if (stripos($v, 'eparagon') !== false || stripos($v, 'eparagony') !== false) {
                        return true;
                    }
                }
            }
        }

        // 3) backtrace
        if (function_exists('debug_backtrace')) {
            $bt = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 25);
            foreach ($bt as $frame) {
                if (!empty($frame['class']) && stripos((string)$frame['class'], 'eparagon') !== false) { return true; }
                if (!empty($frame['function']) && stripos((string)$frame['function'], 'eparagon') !== false) { return true; }
                if (!empty($frame['file']) && stripos((string)$frame['file'], 'eparagon') !== false) { return true; }
            }
        }

        return false;
    }

    /** Idempotencja: jeśli już ma prefiks [..] na początku, nie dokładamy kolejnego. */
    private static function already_prefixed(string $name): bool {
        return (bool) preg_match('/^\[[^\]]+\]\s+/u', $name);
    }

    private static function build_prefix($item): string {
        $sku = '';
        $product_id = 0;
        $variation_id = 0;

        if (is_object($item) && method_exists($item, 'get_product_id')) { $product_id = (int) $item->get_product_id(); }
        if (is_object($item) && method_exists($item, 'get_variation_id')) { $variation_id = (int) $item->get_variation_id(); }
        if (is_object($item) && method_exists($item, 'get_product')) {
            $product = $item->get_product();
            if ($product && method_exists($product, 'get_sku')) { $sku = (string) $product->get_sku(); }
        }

        if ($sku !== '') { return '[' . $sku . '] '; }
        if ($variation_id > 0) { return '[v' . $variation_id . '] '; }
        if ($product_id > 0) { return '[p' . $product_id . '] '; }
        if (is_object($item) && method_exists($item, 'get_id')) { return '[item' . (int)$item->get_id() . '] '; }
        return '[item] ';
    }

    /** Skróć do bezpiecznej długości (kasy często mają limit ~40 znaków). */
    private static function compact(string $text, int $max_len): string {
        $text = preg_replace('/\s+/u', ' ', trim($text));
        if (function_exists('mb_strlen') && function_exists('mb_substr')) {
            if (mb_strlen($text, 'UTF-8') > $max_len) {
                $text = mb_substr($text, 0, $max_len, 'UTF-8');
                $text = rtrim($text, " \t\n\r\0\x0B-–—");
            }
            return $text;
        }
        if (strlen($text) > $max_len) {
            $text = substr($text, 0, $max_len);
            $text = rtrim($text, " \t\n\r\0\x0B-–—");
        }
        return $text;
    }

    private static function log_dir(): string {
        $upload = wp_upload_dir();
        $dir = trailingslashit($upload['basedir']) . 'ts-eparagony-debug/';
        if (!is_dir($dir)) { wp_mkdir_p($dir); }
        return $dir;
    }

    private static function log_file(): string {
        return self::log_dir() . 'log-' . gmdate('Ymd') . '.log';
    }

    private static function write_log(string $line): void {
        @file_put_contents(self::log_file(), $line . PHP_EOL, FILE_APPEND);
    }

    private static function maybe_log_item($item, string $original_name, string $final_name, bool $ctx): void {
        $order_id = (is_object($item) && method_exists($item, 'get_order_id')) ? (int) $item->get_order_id() : 0;
        $item_id  = (is_object($item) && method_exists($item, 'get_id')) ? (int) $item->get_id() : 0;
        $key = $order_id . ':' . $item_id . ':' . ($ctx ? '1' : '0');
        if (isset(self::$logged_items[$key])) { return; }
        self::$logged_items[$key] = true;

        $qty = (is_object($item) && method_exists($item, 'get_quantity')) ? (float) $item->get_quantity() : 0;
        $line_total = (is_object($item) && method_exists($item, 'get_total')) ? (string) $item->get_total() : '';
        $line_tax   = (is_object($item) && method_exists($item, 'get_total_tax')) ? (string) $item->get_total_tax() : '';

        $product_id = (is_object($item) && method_exists($item, 'get_product_id')) ? (int) $item->get_product_id() : 0;
        $variation_id = (is_object($item) && method_exists($item, 'get_variation_id')) ? (int) $item->get_variation_id() : 0;
        $tax_class = (is_object($item) && method_exists($item, 'get_tax_class')) ? (string) $item->get_tax_class() : '';

        $sku = '';
        if (is_object($item) && method_exists($item, 'get_product')) {
            $p = $item->get_product();
            if ($p && method_exists($p, 'get_sku')) { $sku = (string) $p->get_sku(); }
        }

        $ts = gmdate('Y-m-d\TH:i:s\Z');
        $msg = sprintf(
            '[%s] EPARAGONY_CTX=%s order_id=%d item_id=%d product_id=%d variation_id=%d sku="%s" qty=%s tax_class="%s" line_total=%s line_tax=%s name_orig="%s" name_final="%s"',
            $ts, ($ctx ? 'YES' : 'NO'), $order_id, $item_id, $product_id, $variation_id, $sku, $qty, $tax_class, $line_total, $line_tax, $original_name, $final_name
        );
        self::write_log($msg);
    }

    public static function filter_item_name($name, $item) {
        $original = (string) $name;
        $ctx = self::is_eparagony_request();

        // log zawsze
        if (!$ctx) {
            self::maybe_log_item($item, $original, $original, false);
            return $original;
        }

        // brak dubli
        if (self::already_prefixed($original)) {
            self::maybe_log_item($item, $original, $original, true);
            return $original;
        }

        $final = self::compact(self::build_prefix($item) . $original, 38);
        self::maybe_log_item($item, $original, $final, true);
        return $final;
    }
}

add_action('plugins_loaded', ['TS_Eparagony_Name_Fix_Balanced', 'init']);
