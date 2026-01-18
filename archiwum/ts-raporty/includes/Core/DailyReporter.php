<?php
namespace TSR\Core;

use TSR\Filters\FilterDTO;
use TSR\Query\WCOrderStream;

if (!defined('ABSPATH')) { exit; }

final class DailyReporter {

    public static function send_report() {
        $yesterday = date('Y-m-d', strtotime('-1 day'));
        
        $f = new FilterDTO();
        $f->date_from = $yesterday;
        $f->date_to   = $yesterday;
        $f->statuses  = ['completed', 'processing'];
        $f->values_mode = 'gross';

        $agg = self::get_aggregated_data($f);
        if (empty($agg['data'])) return;

        $file_path = self::generate_csv($agg['data'], 'raport-' . $yesterday);
        $body = self::get_html_body($agg['data'], $agg['total'], $yesterday, $yesterday);
        
        $emails_raw = get_option('tsr_report_emails', get_option('admin_email'));
        $to = array_filter(array_map('trim', explode(',', $emails_raw)), 'is_email');
        
        if (empty($to)) { $to = get_option('admin_email'); }
        
        $total_formatted = html_entity_decode(strip_tags(wc_price($agg['total'])), ENT_QUOTES, 'UTF-8');
        $total_formatted = str_replace("\xc2\xa0", ' ', $total_formatted);
        
        $subject = "Raport Sprzedaży $yesterday | Suma: " . $total_formatted;

        wp_mail($to, $subject, $body, ['Content-Type: text/html; charset=UTF-8'], [$file_path]);
        if (file_exists($file_path)) { unlink($file_path); }
    }

    public static function get_aggregated_data(FilterDTO $f) {
        $agg = [];
        $total_sum = 0;

        foreach (WCOrderStream::orders($f) as $order) {
            foreach ($order->get_items('line_item') as $item) {
                $product = $item->get_product();
                if (!$product) continue;

                $name = $item->get_name();
                $qty  = (float)$item->get_quantity();
                $val  = (float)$item->get_total() + (float)$item->get_total_tax();

                $type = 'Pozostałe';
                if ($product->get_meta('_tsme_enabled', true) === 'yes') { $type = 'Posiłek'; }
                elseif ($product->get_meta('_ts_ticket_type', true)) { $type = 'Karnet'; }

                $building = $item->get_meta('_tsme_object', true);
                if (empty($building)) {
                    $ln = mb_strtolower($name);
                    if (strpos($ln, 'panorama') !== false) $building = 'Panorama';
                    elseif (strpos($ln, 'czarna perła') !== false) $building = 'Czarna Perła';
                    elseif (strpos($ln, 'biała perła') !== false) $building = 'Biała Perła';
                    else $building = 'Nieokreślony';
                }

                $key = $type . '_' . $product->get_id() . '_' . $building;
                if (!isset($agg[$key])) {
                    $agg[$key] = ['type' => $type, 'id' => $product->get_id(), 'name' => $name, 'building' => $building, 'qty' => 0, 'sum' => 0];
                }
                $agg[$key]['qty'] += $qty;
                $agg[$key]['sum'] += $val;
                $total_sum += $val;
            }
        }
        
        // DODATEK: Sortowanie po budynku i typie dla ładniejszego maila
        usort($agg, function($a, $b) {
            if ($a['building'] === $b['building']) return strcmp($a['type'], $b['type']);
            return strcmp($a['building'], $b['building']);
        });

        return ['data' => $agg, 'total' => $total_sum];
    }

    public static function generate_csv($data, $filename) {
        $upload_dir = wp_upload_dir();
        $path = $upload_dir['basedir'] . '/' . $filename . '.csv';
        $fh = fopen($path, 'w');
        fprintf($fh, chr(0xEF).chr(0xBB).chr(0xBF));
        fputcsv($fh, ['Sekcja', 'ID Produktu', 'Produkt', 'Budynek', 'Ilość', 'Wartość Brutto'], ';');
        foreach ($data as $row) {
            fputcsv($fh, [$row['type'], $row['id'], $row['name'], $row['building'], $row['qty'], number_format($row['sum'], 2, ',', '')], ';');
        }
        fclose($fh);
        return $path;
    }

    public static function get_html_body($data, $total, $from, $to) {
        ob_start();
        ?>
        <div style="font-family: sans-serif; color: #333; max-width: 800px;">
            <h2>Raport sprzedaży: <?php echo ($from === $to) ? $from : "$from - $to"; ?></h2>
            <table style="width: 100%; border-collapse: collapse; margin-top: 20px;">
                <thead>
                    <tr style="background: #222; color: #fff; text-align: left;">
                        <th style="padding: 10px; border: 1px solid #444;">Budynek / Typ</th>
                        <th style="padding: 10px; border: 1px solid #444;">Produkt</th>
                        <th style="padding: 10px; border: 1px solid #444; text-align: center;">Ilość</th>
                        <th style="padding: 10px; border: 1px solid #444; text-align: right;">Suma</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($data as $r): ?>
                    <tr>
                        <td style="padding: 10px; border: 1px solid #eee;"><strong><?php echo esc_html($r['building']); ?></strong><br><small><?php echo $r['type']; ?></small></td>
                        <td style="padding: 10px; border: 1px solid #eee;"><?php echo esc_html($r['name']); ?></td>
                        <td style="padding: 10px; border: 1px solid #eee; text-align: center;"><?php echo $r['qty']; ?></td>
                        <td style="padding: 10px; border: 1px solid #eee; text-align: right;"><?php echo wc_price($r['sum']); ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <div style="margin-top: 20px; padding: 20px; background: #222; color: #fff; text-align: right; font-size: 20px;">
                <strong>ŁĄCZNIE: <?php echo strip_tags(wc_price($total)); ?></strong>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }
}