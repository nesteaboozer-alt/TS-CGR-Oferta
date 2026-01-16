<?php
namespace TSR\Tabs;

use TSR\Filters\FilterDTO;
use TSR\Filters\FilterRenderer;
use TSR\Query\WCOrderStream;
use TSR\Util\Format;

if (!defined('ABSPATH')) { exit; }

final class TransactionsTab implements TabInterface {

    public function get_key(): string { return 'transactions'; }
    public function get_title(): string { return __('Transakcje', 'ts-raporty'); }

    public function render_filters(FilterDTO $filters): string {
        return FilterRenderer::render($filters, ['tab_key' => $this->get_key()]);
    }

    public function get_rows(FilterDTO $filters, int $page, int $per_page): array {
        $offset = ($page - 1) * $per_page;
        $rows = [];
        $i = 0;

        foreach ($this->stream_item_rows($filters) as $row) {
            if ($i >= $offset && count($rows) < $per_page) {
                $rows[] = $row;
            }
            $i++;
            if (count($rows) >= $per_page && $i >= ($offset + $per_page)) {
                // We can break only if we don't need exact total here.
                // Total is counted separately.
                break;
            }
        }

        return ['rows' => $rows];
    }

    public function count(FilterDTO $filters): int {
        $c = 0;
        foreach ($this->stream_item_rows($filters) as $_) { $c++; }
        return $c;
    }

    public function get_csv_headers(): array {
        return [
            'Pochodzenie', 'Faktura', 'ID zamówienia', 'Data', 'Status', 'Metoda płatności', 
            'NIP', 'Klient', 'ID produktu', 'Produkt', 'Ilość', 'Wartość'
        ];
    }

    public function get_export_rows(FilterDTO $filters): iterable {
        foreach ($this->stream_item_rows($filters) as $row) {
            $via = strtolower((string)$row['created_via']);
            $origin = ($via === 'admin' || $via === 'manual' || !$via) ? 'Panel administratora' : (($via === 'checkout') ? 'Bezpośrednie' : $via);

            yield [
                $origin,
                (!empty($row['invoice_nip']) ? 'TAK' : 'NIE'),
                (string)$row['order_id'],
                (string)$row['order_date'],
                (string)$row['status'],
                (string)$row['payment_method'],
                '="' . (string)$row['invoice_nip'] . '"',
                (string)$row['customer'],
                (string)$row['product_id'],
                (string)$row['product_name'],
                (string)$row['qty'],
                str_replace('.', ',', (string)$row['line_total_raw']),
            ];
        }
    }

    public function render_table(array $rows): string {
        ob_start();
        ?>
        <table class="widefat fixed striped">
            <thead>
                <tr>
                    <th><?php esc_html_e('Pochodzenie', 'ts-raporty'); ?></th>
                    <th><?php esc_html_e('Faktura', 'ts-raporty'); ?></th>
                    <th><?php esc_html_e('Zamówienie', 'ts-raporty'); ?></th>
                    <th><?php esc_html_e('Data', 'ts-raporty'); ?></th>
                    <th><?php esc_html_e('Status', 'ts-raporty'); ?></th>
                    <th><?php esc_html_e('Płatność', 'ts-raporty'); ?></th>
                    <th><?php esc_html_e('NIP', 'ts-raporty'); ?></th>
                    <th><?php esc_html_e('Klient', 'ts-raporty'); ?></th>
                    <th><?php esc_html_e('Produkt', 'ts-raporty'); ?></th>
                    <th><?php esc_html_e('Ilość', 'ts-raporty'); ?></th>
                    <th><?php esc_html_e('Wartość jedn.', 'ts-raporty'); ?></th>
                    <th><?php esc_html_e('Wartość pozycji', 'ts-raporty'); ?></th>
                </tr>
            </thead>
            <tbody>
            <?php if (empty($rows)): ?>
                <tr><td colspan="10"><?php esc_html_e('Brak danych dla wybranych filtrów.', 'ts-raporty'); ?></td></tr>
            <?php else: foreach ($rows as $r): 
                // 1. Ujednolicona logika etykiet pochodzenia (zgodna z Twoim WC)
                $via = strtolower((string)$r['created_via']);
                if ($via === 'admin' || $via === 'manual' || empty($via)) {
                    $origin_html = '<span style="color:#f59e0b; font-weight:bold;">' . __('Panel administratora', 'ts-raporty') . '</span>';
                } elseif ($via === 'checkout') {
                    $origin_html = __('Bezpośrednie', 'ts-raporty'); // To co widzisz w WC
                } else {
                    $origin_html = esc_html(ucfirst($via));
                }

                $invoice_text = !empty($r['invoice_nip']) ? __('Tak', 'ts-raporty') : __('Nie', 'ts-raporty');
                $invoice_style = !empty($r['invoice_nip']) ? 'font-weight:bold; color:#16a34a;' : 'opacity:0.5;';
            ?>
                <tr>
                    <td><?php echo $origin_html; ?></td> <td style="<?php echo $invoice_style; ?>"><?php echo esc_html($invoice_text); ?></td> <td>#<?php echo esc_html($r['order_id']); ?></td> <td><?php echo esc_html($r['order_date']); ?></td> <td><?php echo esc_html($r['status']); ?></td>
                    <td><?php echo esc_html($r['payment_method']); ?></td>
                    <td><?php echo esc_html($r['invoice_nip']); ?></td>
                    <td><?php echo esc_html($r['customer']); ?></td>
                    <td><?php echo esc_html($r['product_name']); ?> (<?php echo esc_html($r['product_id']); ?>)</td>
                    <td><?php echo esc_html($r['qty']); ?></td>
                    <td><?php echo $r['unit_value']; ?></td>
                    <td><?php echo $r['line_total']; ?></td>
                </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>
        <?php
        return (string)ob_get_clean();
    }

    /** @return \Generator<int, array<string,mixed>> */
    private function stream_item_rows(FilterDTO $f): \Generator {
        foreach (WCOrderStream::orders($f) as $order) {
            $order_id = $order->get_id();
            $order_date = Format::date($order->get_date_created());
            $status = $order->get_status();
            $pm = $order->get_payment_method();
            $nip = (string)$order->get_meta('_billing_nip', true);
            $customer = trim($order->get_billing_first_name() . ' ' . $order->get_billing_last_name());

            foreach ($order->get_items('line_item') as $item_id => $item) {
                $product = $item->get_product();
                $product_id = $product ? $product->get_id() : (int)$item->get_product_id();
                $product_name = $item->get_name();

                // 1. Filtracja po Nazwach Produktów (Spójność z bazą danych)
                if (!empty($f->product_names)) {
                    // Sprawdzamy nazwę zapisaną w zamówieniu (tak jak robiliśmy to w SQL wyżej)
                    if (!in_array($product_name, (array)$f->product_names, true)) {
                        continue;
                    }
                }

                // 2. Filtracja po Kategoriach
                if (!empty($f->categories)) {
                    $item_product_id = $item->get_product_id();
                    $item_cats = wp_get_post_terms($item_product_id, 'product_cat', ['fields' => 'ids']);
                    if (is_wp_error($item_cats) || empty(array_intersect($item_cats, (array)$f->categories))) {
                        continue;
                    }
                }

                $qty = (float)$item->get_quantity();
                if ($qty <= 0) { $qty = 1; }

                $line_total_net = (float)$item->get_total();
                $line_tax = (float)$item->get_total_tax();
                $line_total_gross = $line_total_net + $line_tax;

                $line_total = ($f->values_mode === 'net') ? $line_total_net : $line_total_gross;
                $unit = $line_total / $qty;

                yield [
                    'order_id' => $order_id,
                    'order_date' => $order_date,
                    'status' => $status,
                    'payment_method' => $pm,
                    'invoice_nip' => $nip,
                    'customer' => $customer,
                    'product_id' => $product_id,
                    'product_name' => $product_name,
                    'qty' => (string)$qty,
                    'unit_value' => Format::money((float)$unit),
                    'line_total' => Format::money((float)$line_total),
                    'unit_value_raw' => (string)wc_format_decimal((float)$unit, 2),
                    'line_total_raw' => (string)wc_format_decimal((float)$line_total, 2),
                    'created_via' => (string)$order->get_created_via(), // Wymuszamy string
                ];
            }
        }
    }
}
