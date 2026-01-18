<?php
namespace TSR\Core;

use TSR\Filters\FilterDTO;

if (!defined('ABSPATH')) { exit; }

final class DailyReporterTest {

    public static function run_test() {
        $from = '2025-12-01';
        $to   = '2026-01-13';
        
        $f = new FilterDTO();
        $f->date_from = $from;
        $f->date_to   = $to;
        $f->statuses  = ['completed', 'processing'];
        $f->values_mode = 'gross';

        // Korzystamy z logiki z DailyReporter, żeby nie powielać kodu
        $agg = DailyReporter::get_aggregated_data($f);
        
        if (empty($agg['data'])) {
            die("Brak danych w wybranym zakresie $from - $to");
        }

        $file_path = DailyReporter::generate_csv($agg['data'], 'test-raport-grudzien');
        $body = DailyReporter::get_html_body($agg['data'], $agg['total'], $from, $to);
        
        // Lista e-maili do testów
        $emails = [
            'sudri2010@gmail.com',
            'bartosz.walicki@cgresort.pl',
            'patryk.sudrawski@cgresort.pl'
        ];
        
        // Naprawa encji HTML i twardych spacji w tytule maila
        $total_formatted = html_entity_decode(strip_tags(wc_price($agg['total'])), ENT_QUOTES, 'UTF-8');
        $total_formatted = str_replace("\xc2\xa0", ' ', $total_formatted);

        $subject = "TEST RAPORT ($from - $to) | Suma: " . $total_formatted;

        // Wysyłka do listy adresatów
        $sent = wp_mail($emails, $subject, $body, ['Content-Type: text/html; charset=UTF-8'], [$file_path]);
        unlink($file_path);
        
        $recipients = implode(', ', $emails);
        die($sent ? "Test wysłany na: $recipients" : "Błąd wysyłki testu");
    }
}