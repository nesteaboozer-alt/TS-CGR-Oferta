<?php
namespace TSR\Tabs;

use TSR\Filters\FilterDTO;
use TSR\Filters\FilterRenderer;
use TSR\Query\WCOrderStream;

if (!defined('ABSPATH')) { exit; }

final class MealsTab implements TabInterface {

    public function get_key(): string { return 'meals'; }
    public function get_title(): string { return __('Posiłki (zakupione)', 'ts-raporty'); }

    public function render_filters(FilterDTO $filters): string {
        return FilterRenderer::render($filters, ['tab_key' => $this->get_key()]);
    }

    public function get_rows(FilterDTO $filters, int $page, int $per_page): array {
        $offset = ($page - 1) * $per_page;
        $rows = [];
        $i = 0;

        foreach ($this->stream_meal_rows($filters) as $row) {
            if ($i >= $offset && count($rows) < $per_page) { $rows[] = $row; }
            $i++;
            if (count($rows) >= $per_page && $i >= ($offset + $per_page)) { break; }
        }

        return ['rows' => $rows];
    }

    public function count(FilterDTO $filters): int {
        $c = 0;
        foreach ($this->stream_meal_rows($filters) as $_) { $c++; }
        return $c;
    }

    public function get_csv_headers(): array {
        return [
            'ID zamówienia',
            'Data',
            'Status',
            'Metoda płatności',
            'NIP',
            'Produkt',
            'Ilość',
            'Wartość',
            'Obiekt',
            'Pokój',
            'Pobyt od',
            'Pobyt do',
            'Dorośli',
            'Dzieci',
            'Kod posiłku'
        ];
    }

    public function get_export_rows(FilterDTO $filters): iterable {
        foreach ($this->stream_meal_rows($filters) as $r) {
            yield [
                (string)$r['order_id'],
                (string)$r['order_date'],
                (string)$r['status'],
                (string)$r['payment_method'],
                (string)$r['invoice_nip'],
                (string)$r['product_id'],
                (string)$r['product_name'],
                (string)$r['qty'],
                (string)$r['line_total_raw'],
                (string)$r['object'],
                (string)$r['building'],
                (string)$r['room_number'],
                (string)$r['stay_from'],
                (string)$r['stay_to'],
                (string)$r['adults'],
                (string)$r['children'],
                (string)$r['meal_code'],
            ];
        }
    }

    public function render_table(array $rows): string {
        ob_start();
        ?>
        <table class="widefat fixed striped">
            <thead>
                <tr>
                    <th><?php esc_html_e('Zamówienie', 'ts-raporty'); ?></th>
                    <th><?php esc_html_e('Pochodzenie', 'ts-raporty'); ?></th>
                    <th><?php esc_html_e('Data', 'ts-raporty'); ?></th>
                    <th><?php esc_html_e('Produkt', 'ts-raporty'); ?></th>
                    <th><?php esc_html_e('Ilość', 'ts-raporty'); ?></th>
                    <th><?php esc_html_e('Wartość', 'ts-raporty'); ?></th>
                    <th><?php esc_html_e('Obiekt / Budynek', 'ts-raporty'); ?></th>
                    <th><?php esc_html_e('Pokój', 'ts-raporty'); ?></th>
                    <th><?php esc_html_e('Pobyt', 'ts-raporty'); ?></th>
                    <th><?php esc_html_e('Osoby', 'ts-raporty'); ?></th>
                    <th><?php esc_html_e('Kod', 'ts-raporty'); ?></th>
                </tr>
            </thead>
            <tbody>
            <?php if (empty($rows)): ?>
                <tr><td colspan="10"><?php esc_html_e('Brak danych dla wybranych filtrów.', 'ts-raporty'); ?></td></tr>
            <?php else: foreach ($rows as $r): ?>
                <tr>
                    <td>#<?php echo esc_html($r['order_id']); ?></td>
                    <td>
                        <?php 
                        $via = strtolower((string)$r['created_via']);
                        echo ($via === 'admin' || $via === 'manual' || !$via) ? '<span style="color:#f59e0b; font-weight:bold;">Panel administratora</span>' : 'Bezpośrednie';
                        ?>
                    </td>
                    <td><?php echo esc_html($r['order_date']); ?></td>
                    <td><?php echo esc_html($r['product_name']); ?> (<?php echo esc_html($r['product_id']); ?>)</td>
                    <td><?php echo esc_html($r['qty']); ?></td>
                    <td><?php echo wc_price((float)$r['line_total_raw']); ?></td>
                    <td><?php echo esc_html($r['object']); ?><?php echo $r['building'] ? ' / ' . esc_html($r['building']) : ''; ?></td>
                    <td><?php echo esc_html($r['room_number']); ?></td>
                    <td><?php echo esc_html($r['stay_from']); ?> – <?php echo esc_html($r['stay_to']); ?></td>
                    <td><?php echo esc_html($r['adults']); ?>/<?php echo esc_html($r['children']); ?></td>
                    <td><?php echo esc_html($r['meal_code']); ?></td>
                </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>
        <?php
        return (string)ob_get_clean();
    }

    /** @return \Generator<int, array<string,mixed>> */
    private function stream_meal_rows(FilterDTO $f): \Generator {
        foreach (WCOrderStream::orders($f) as $order) {
            $order_id = $order->get_id();
            $order_date = $order->get_date_created() ? $order->get_date_created()->date_i18n('Y-m-d H:i') : '';
            $status = $order->get_status();
            $pm = $order->get_payment_method();
            $nip = (string)$order->get_meta('_billing_nip', true);

            foreach ($order->get_items('line_item') as $item) {
                $product = $item->get_product();
                if (!$product) { continue; }

                // Meals are products with _tsme_enabled = 'yes'
                $is_meal = (string)$product->get_meta('_tsme_enabled', true) === 'yes';
                if (!$is_meal) { continue; }

                $product_id = $product->get_id();
                $product_name = $item->get_name();

                if (!empty($f->product_ids) && !in_array((int)$product_id, $f->product_ids, true)) { continue; }
                if (!empty($f->categories)) {
                    $cats = $product->get_category_ids();
                    $ok = false;
                    foreach ($cats as $cid) { if (in_array((int)$cid, $f->categories, true)) { $ok = true; break; } }
                    if (!$ok) { continue; }
                }

                $qty = (float)$item->get_quantity();
                if ($qty <= 0) { $qty = 1; }

                $net = (float)$item->get_total();
                $tax = (float)$item->get_total_tax();
                $gross = $net + $tax;
                $value = ($f->values_mode === 'net') ? $net : $gross;

                $get_meta = function(string $key) use ($item) {
                    $v = $item->get_meta($key, true);
                    return is_scalar($v) ? (string)$v : '';
                };

                yield [
                    'order_id' => $order_id,
                    'order_date' => $order_date,
                    'status' => $status,
                    'payment_method' => $pm,
                    'invoice_nip' => $nip,
                    'product_id' => $product_id,
                    'product_name' => $product_name,
                    'qty' => (string)$qty,
                    'line_total_raw' => (float)$value,
                    'object' => $get_meta('_tsme_object'),
                    'building' => $get_meta('_tsme_building'),
                    'room_number' => $get_meta('_tsme_room_number'),
                    'stay_from' => $get_meta('_tsme_stay_from'),
                    'stay_to' => $get_meta('_tsme_stay_to'),
                    'adults' => $get_meta('_tsme_adults'),
                    'children' => $get_meta('_tsme_children'),
                    'meal_code' => $get_meta('_tsme_code'),
                    'created_via' => (string)$order->get_created_via(), // DODAJ TO
                ];
            }
        }
    }
}
