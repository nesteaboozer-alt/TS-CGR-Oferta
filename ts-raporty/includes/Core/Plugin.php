<?php
namespace TSR\Core;

use TSR\Admin\AdminPage;

if (!defined('ABSPATH')) { exit; }

final class Plugin {
    private static $instance = null;

    public static function instance(): self {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        $this->init();
    }

    private function init(): void {
        AdminPage::init();

        add_action('admin_enqueue_scripts', function($hook) {
            if (strpos((string)$hook, 'ts-raporty') === false) { return; }

                        wp_enqueue_style('tsr-admin', TSR_PLUGIN_URL . 'assets/admin.css', [], TSR_PLUGIN_VERSION);
            wp_enqueue_script('tsr-admin', TSR_PLUGIN_URL . 'assets/admin.js', ['jquery'], TSR_PLUGIN_VERSION, true);
        });
    }
}
