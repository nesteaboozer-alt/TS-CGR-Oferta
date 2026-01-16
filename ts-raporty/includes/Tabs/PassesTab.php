<?php
namespace TSR\Tabs;

use TSR\Filters\FilterDTO;
use TSR\Filters\FilterRenderer;
use TSR\Query\WCOrderStream;

if (!defined('ABSPATH')) { exit; }

final class PassesTab implements TabInterface {

    public function get_key(): string { return 'passes'; }
    public function get_title(): string { return __('Karnety (zużyte / wygasłe)', 'ts-raporty'); }

    public function render_filters(FilterDTO $filters): string {
        return FilterRenderer::render($filters, ['tab_key' => $this->get_key()]);
    }

    public function get_rows(FilterDTO $filters, int $page, int $per_page): array {
        $offset = ($page - 1) * $per_page;
        $rows = [];
        $i = 0;

        foreach ($this->stream_pass_rows($filters) as $row) {
            if ($i >= $offset && count($rows) < $per_page) { $rows[] = $row; }
            $i++;
            if (count($rows) >= $per_page && $i >= ($offset + $per_page)) { break; }
        }

        return ['rows' => $rows];
    }

    public function count(FilterDTO $filters): int {
        $c = 0;
        foreach ($this->stream_pass_rows($filters) as $_) { $c++; }
        return $c;
    }

    public function get_csv_headers(): array {
        return [
            'ID karnetu', 'Kod', 'ID zamówienia', 'Data zakupu', 'Status zamówienia', 'Metoda płatności', 'NIP',
            'ID produktu', 'Produkt', 'Wartość brutto / kod',
            'Typ', 'Wejścia (łącznie)', 'Wejścia (pozostało)', 'Okres od', 'Okres do',
            'Status karnetu', 'Klasyfikacja', 'Data użycia/wygaśnięcia'
        ];
    }

public function get_export_rows(FilterDTO $f): iterable {
        foreach ($this->stream_pass_rows($f) as $r) {
            yield [
                $r['ticket_id'],
                // Dodajemy cudzysłów i separator, aby wymusić tekst w Excelu
                '="' . (string)$r['code'] . '"', 
                $r['order_id'],
                $r['order_date'],
                $r['order_status'],
                $r['payment_method'],
                '="' . (string)$r['invoice_nip'] . '"',
                $r['product_id'],
                $r['product_name'],
                str_replace('.', ',', (string)$r['gross_value_raw']), // Zamiana kropki na przecinek dla polskiego Excela
                $r['ticket_type'],
                $r['entries_total'],
                $r['entries_left'],
                $r['period_started_at'],
                $r['period_expires_at'],
                $r['status'],
                $r['classification'],
                $r['event_date'], // Ta wartość musi być wyliczona w stream_pass_rows
            ];
        }
    }

    public function render_table(array $rows): string {
        ob_start();
        ?>
        <table class="widefat fixed striped">
            <thead>
                <tr>
                    <th><?php esc_html_e('Kod', 'ts-raporty'); ?></th>
                    <th><?php esc_html_e('Pochodzenie', 'ts-raporty'); ?></th>
                    <th><?php esc_html_e('Zamówienie', 'ts-raporty'); ?></th>
                    <th><?php esc_html_e('Data zakupu', 'ts-raporty'); ?></th>
                    <th><?php esc_html_e('NIP', 'ts-raporty'); ?></th>
                    <th><?php esc_html_e('Produkt', 'ts-raporty'); ?></th>
                    <th><?php esc_html_e('Wartość brutto / kod', 'ts-raporty'); ?></th>
                    <th><?php esc_html_e('Wejścia', 'ts-raporty'); ?></th>
                    <th><?php esc_html_e('Okres', 'ts-raporty'); ?></th>
                    <th><?php esc_html_e('Status', 'ts-raporty'); ?></th>
                    <th><?php esc_html_e('Data użycia/wygaśnięcia', 'ts-raporty'); ?></th>
                    <th><?php esc_html_e('Klasyfikacja', 'ts-raporty'); ?></th>
                </tr>
            </thead>
            <tbody>
            <?php if (empty($rows)): ?>
                <tr><td colspan="9"><?php esc_html_e('Brak danych dla wybranych filtrów.', 'ts-raporty'); ?></td></tr>
            <?php else: foreach ($rows as $r): ?>
                <tr>
                    <td><?php echo esc_html($r['code']); ?></td>
                    <td>
                        <?php 
                        $via = strtolower((string)$r['created_via']);
                        echo ($via === 'admin' || $via === 'manual' || !$via) ? '<span style="color:#f59e0b; font-weight:bold;">Panel administratora</span>' : 'Bezpośrednie';
                        ?>
                    </td>
                    <td>#<?php echo esc_html($r['order_id']); ?></td>
                    <td><?php echo esc_html($r['order_date']); ?></td>
                    <td><?php echo esc_html($r['invoice_nip']); ?></td>
                    <td><?php echo esc_html($r['product_name']); ?> (<?php echo esc_html($r['product_id']); ?>)</td>
                    <td><?php echo $r['gross_value']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></td>
                    <td><?php echo esc_html($r['entries_left']); ?>/<?php echo esc_html($r['entries_total']); ?></td>
                    <td><?php echo esc_html($r['period_started_at']); ?> – <?php echo esc_html($r['period_expires_at']); ?></td>
                    <td><?php echo esc_html($r['status']); ?></td>
                    <td><?php echo esc_html($r['event_date']); ?></td>
                    <td><?php echo esc_html($r['classification']); ?></td>
                </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>
        <?php
        return (string)ob_get_clean();
    }

    /** @return \Generator<int, array<string,mixed>> */
    private function stream_pass_rows(FilterDTO $f): \Generator {
        global $wpdb;
        $tickets_table = $wpdb->prefix . 'ts_tickets';

        // If table doesn't exist, return nothing.
        $exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $tickets_table));
        if ($exists !== $tickets_table) { return; }

        // Pull tickets (we'll filter by order filters via WC orders).
        $tickets = $wpdb->get_results("SELECT * FROM {$tickets_table} ORDER BY id DESC", ARRAY_A);
        if (empty($tickets)) { return; }

        // Build a quick index of orders that pass shared filters to avoid repeated order loads.
        $allowed_orders = [];
        foreach (WCOrderStream::orders($f) as $order) {
            $allowed_orders[$order->get_id()] = $order;
        }

        $now_ts = time();

        foreach ($tickets as $t) {
            $order_id = (int)($t['order_id'] ?? 0);
            if (!$order_id || !isset($allowed_orders[$order_id])) { continue; }

            $order = $allowed_orders[$order_id];

            $ticket_id = (int)($t['id'] ?? 0);
            $code = (string)($t['code'] ?? '');
            $product_id = (string)($t['product_id'] ?? '');
            $order_item_id = (int)($t['order_item_id'] ?? 0);
            $ticket_type = (string)($t['ticket_type'] ?? '');
            $entries_total = (int)($t['entries_total'] ?? 0);
            $entries_left = (int)($t['entries_left'] ?? 0);
            $status = (string)($t['status'] ?? '');

            $period_started_at = (string)($t['period_started_at'] ?? '');
            $period_expires_at = (string)($t['period_expires_at'] ?? '');

            $order_date = $order->get_date_created() ? $order->get_date_created()->date_i18n('Y-m-d H:i') : '';
            $order_status = $order->get_status();
            $pm = $order->get_payment_method();
            $nip = (string)$order->get_meta('_billing_nip', true);

            // Resolve product name + value per code (unit price from the original order item).
            $product_name = '';
            $gross_value_raw = '';
            $gross_value_html = '';
            if ($order_item_id > 0) {
                $item = $order->get_item($order_item_id);
                if ($item && $item instanceof \WC_Order_Item_Product) {
                    $product_name = (string)$item->get_name();
                    $qty = (float)$item->get_quantity();
                    if ($qty <= 0) { $qty = 1; }
                    $net = (float)$item->get_total();
                    $tax = (float)$item->get_total_tax();
                    $unit_gross = ($net + $tax) / $qty;
                    $gross_value_raw = (string)wc_format_decimal($unit_gross, 2);
                    $gross_value_html = wc_price($unit_gross, ['currency' => $order->get_currency()]);
                }
            }
            if ($product_name === '') {
                // Fallback if order_item_id is missing.
                $product_name = $product_id ? (string)get_the_title((int)$product_id) : '';
            }
            if ($gross_value_html === '') {
                $gross_value_html = wc_price(0, ['currency' => $order->get_currency()]);
                $gross_value_raw = '0.00';
            }

            // Classification logic: exhausted / expired / initial_expired
            $classification = '';

            $is_exhausted = ($status === 'exhausted') || ($entries_left <= 0 && $entries_total > 0);
            $is_expired_period = false;
            if (!empty($period_expires_at)) {
                $exp_ts = strtotime($period_expires_at);
                if ($exp_ts && $exp_ts < $now_ts) { $is_expired_period = true; }
            }

            $is_initial_expired = false;
            if (!$is_exhausted && !$is_expired_period) {
                // initial expired: never used + no period started + purchase older than 90 days
                $never_used = ($entries_left === $entries_total) && empty($period_started_at);
                if ($never_used && $order->get_date_created()) {
                    $purchase_ts = $order->get_date_created()->getTimestamp();
                    if ($purchase_ts + (90 * DAY_IN_SECONDS) < $now_ts) {
                        $is_initial_expired = true;
                    }
                }
            }

            $event_date = '';
            if ($is_exhausted) { 
                $classification = 'exhausted';
                $event_date = (string)($t['last_checked_at'] ?? '');
            } elseif ($is_expired_period) { 
                $classification = 'expired';
                $event_date = $period_expires_at;
            } elseif ($is_initial_expired) { 
                $classification = 'initial_expired';
                // Dla przeterminowanych (90 dni) wyliczamy datę graniczną
                if ($order->get_date_created()) {
                    $event_date = date('Y-m-d H:i:s', $order->get_date_created()->getTimestamp() + (90 * DAY_IN_SECONDS));
                }
            } else { continue; }

            // Filtracja po dacie zdarzenia (jeśli ustawiona w filtrach)
            if (!empty($f->event_date_from) || !empty($f->event_date_to)) {
                if (empty($event_date)) { continue; }
                $evt_ts = strtotime($event_date);
                if (!empty($f->event_date_from) && $evt_ts < strtotime($f->event_date_from . ' 00:00:00')) { continue; }
                if (!empty($f->event_date_to) && $evt_ts > strtotime($f->event_date_to . ' 23:59:59')) { continue; }
            }

            // Product filter (by product_id in ticket row)
            if (!empty($f->product_ids) && !in_array((int)$product_id, $f->product_ids, true)) { continue; }

            yield [
                'ticket_id' => $ticket_id,
                'code' => $code,
                'order_id' => $order_id,
                'order_date' => $order_date,
                'order_status' => $order_status,
                'payment_method' => $pm,
                'invoice_nip' => $nip,
                'product_id' => $product_id,
                'product_name' => $product_name,
                'gross_value' => $gross_value_html,
                'gross_value_raw' => $gross_value_raw,
                'ticket_type' => $ticket_type,
                'entries_total' => (string)$entries_total,
                'entries_left' => (string)$entries_left,
                'period_started_at' => $period_started_at,
                'period_expires_at' => $period_expires_at,
                'status' => $status,
                'classification' => $classification,
                'event_date' => $event_date,
                'created_via' => (string)$order->get_created_via(), // DODAJ TO
            ];
        }
    }
}
