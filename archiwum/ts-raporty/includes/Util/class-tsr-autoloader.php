<?php
namespace TSR\Util;

if (!defined('ABSPATH')) { exit; }

class Autoloader {
    public static function init(): void {
        spl_autoload_register([__CLASS__, 'autoload']);
    }

    public static function autoload(string $class): void {
        if (strpos($class, 'TSR\\') !== 0) { return; }

        $path = str_replace('TSR\\', '', $class);
        $path = str_replace('\\', DIRECTORY_SEPARATOR, $path);
        $file = TSR_PLUGIN_DIR . 'includes' . DIRECTORY_SEPARATOR . $path . '.php';

        if (file_exists($file)) {
            require_once $file;
        }
    }
}
