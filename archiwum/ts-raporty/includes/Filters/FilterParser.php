<?php
namespace TSR\Filters;

if (!defined('ABSPATH')) { exit; }

final class FilterParser {
    public static function parse_from_request(array $request): FilterDTO {
        $date_from = isset($request['date_from']) ? sanitize_text_field((string)$request['date_from']) : '';
        $date_to = isset($request['date_to']) ? sanitize_text_field((string)$request['date_to']) : '';

        // normalize dates (YYYY-MM-DD)
        $date_from = preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_from) ? $date_from : '';
        $date_to = preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_to) ? $date_to : '';

        $values_mode = isset($request['values_mode']) && in_array($request['values_mode'], ['gross', 'net'], true)
            ? $request['values_mode'] : 'gross';

        $invoice_mode = isset($request['invoice_mode']) && in_array($request['invoice_mode'], ['all', 'with', 'without'], true)
            ? $request['invoice_mode'] : 'all';

        $statuses = isset($request['statuses']) ? (array)$request['statuses'] : [];
        $statuses = array_values(array_filter(array_map('sanitize_text_field', $statuses)));

        $categories = isset($request['categories']) ? (array)$request['categories'] : [];
        $categories = array_values(array_filter(array_map('absint', $categories)));

        $payment_methods = isset($request['payment_methods']) ? (array)$request['payment_methods'] : [];
        $payment_methods = array_values(array_filter(array_map('sanitize_text_field', $payment_methods)));

        $product_ids_raw = isset($request['product_ids']) ? sanitize_text_field((string)$request['product_ids']) : '';
        $product_ids = [];
        if ($product_ids_raw !== '') {
            foreach (preg_split('/\s*,\s*/', $product_ids_raw) as $pid) {
                $pid = absint($pid);
                if ($pid > 0) { $product_ids[] = $pid; }
            }
        }

        $event_date_from = isset($request['event_date_from']) ? sanitize_text_field((string)$request['event_date_from']) : '';
        $event_date_to = isset($request['event_date_to']) ? sanitize_text_field((string)$request['event_date_to']) : '';
        $event_date_from = preg_match('/^\d{4}-\d{2}-\d{2}$/', $event_date_from) ? $event_date_from : '';
        $event_date_to = preg_match('/^\d{4}-\d{2}-\d{2}$/', $event_date_to) ? $event_date_to : '';
        $event_date_mode = isset($request['event_date_mode']) ? sanitize_key((string)$request['event_date_mode']) : 'all';
        if (!in_array($event_date_mode, ['all','with','without'], true)) { $event_date_mode = 'all'; }

        $buildings = isset($request['buildings']) ? (array)$request['buildings'] : [];
        $buildings = array_values(array_filter(array_map('sanitize_text_field', $buildings)));

        $product_names = isset($request['product_names']) ? (array)$request['product_names'] : [];
        $product_names = array_values(array_filter(array_map('sanitize_text_field', $product_names)));

        $origin_mode = isset($request['origin_mode']) && in_array($request['origin_mode'], ['all', 'web', 'admin'], true)
            ? $request['origin_mode'] : 'all';

        return new FilterDTO([
            'date_from' => $date_from,
            'date_to' => $date_to,
            'values_mode' => $values_mode,
            'invoice_mode' => $invoice_mode,
            'statuses' => $statuses,
            'categories' => $categories,
            'payment_methods' => $payment_methods,
            'product_ids' => $product_ids,
            'event_date_from' => $event_date_from,
            'event_date_to' => $event_date_to,
            'event_date_mode' => $event_date_mode,
            'buildings' => $buildings,
            'product_names' => $product_names,
            'origin_mode' => $origin_mode,
        ]);
    }
}
