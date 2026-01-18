<?php
namespace TSR\Util;

if (!defined('ABSPATH')) { exit; }

final class Format {
    public static function money(float $value): string {
        return wc_price($value);
    }

    public static function date(?\WC_DateTime $dt): string {
        if (!$dt) { return ''; }
        return $dt->date_i18n('Y-m-d H:i');
    }
}
