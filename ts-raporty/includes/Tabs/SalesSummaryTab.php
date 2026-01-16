<?php
namespace TSR\Tabs;

use TSR\Filters\FilterDTO;
use TSR\Filters\FilterRenderer;
use TSR\Query\WCOrderStream;

if (!defined('ABSPATH')) { exit; }

final class SalesSummaryTab implements TabInterface {

    public function get_key(): string { return 'sales'; }
    public function get_title(): string { return __('Sprzedaż ogólna', 'ts-raporty'); }

    public function render_filters(FilterDTO $filters): string {
        return FilterRenderer::render($filters, ['tab_key' => $this->get_key()]);
    }

    public function get_rows(FilterDTO $filters, int $page, int $per_page): array {
        $all = $this->aggregate($filters);
        $rows = array_values($all);

        // sort by value desc
        usort($rows, function($a, $b){
            return $b['total_value_raw'] <=> $a['total_value_raw'];
        });

        $offset = ($page - 1) * $per_page;
        $slice = array_slice($rows, $offset, $per_page);

        return ['rows' => $slice];
    }

    public function count(FilterDTO $filters): int {
        $all = $this->aggregate($filters);
        return count($all);
    }

    public function get_csv_headers(): array {
        return [
            'ID produktu',
            'Produkt',
            'Ilość',
            'Wartość'
        ];
    }

    public function get_export_rows(FilterDTO $filters): iterable {
        $rows = array_values($this->aggregate($filters));
        usort($rows, function($a, $b){ return $b['total_value_raw'] <=> $a['total_value_raw']; });

        foreach ($rows as $r) {
            yield [
                (string)$r['product_id'],
                (string)$r['product_name'],
                (string)$r['qty_sum'],
                (string)wc_format_decimal((float)$r['total_value_raw'], 2),
            ];
        }
    }

    public function render_table(array $rows): string {
        ob_start();
        ?>
        <table class="widefat fixed striped">
            <thead>
                <tr>
                    <th><?php esc_html_e('Produkt', 'ts-raporty'); ?></th>
                    <th><?php esc_html_e('Suma ilości', 'ts-raporty'); ?></th>
                    <th><?php esc_html_e('Suma wartości', 'ts-raporty'); ?></th>
                </tr>
            </thead>
            <tbody>
            <?php if (empty($rows)): ?>
                <tr><td colspan="3"><?php esc_html_e('Brak danych dla wybranych filtrów.', 'ts-raporty'); ?></td></tr>
            <?php else: foreach ($rows as $r): ?>
                <tr>
                    <td><?php echo esc_html($r['product_name']); ?> (<?php echo esc_html($r['product_id']); ?>)</td>
                    <td><?php echo esc_html($r['qty_sum']); ?></td>
                    <td><?php echo wc_price((float)$r['total_value_raw']); ?></td>
                </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>
        <?php
        return (string)ob_get_clean();
    }

    /** @return array<int, array<string,mixed>> indexed by product_id */
    private function aggregate(FilterDTO $f): array {
        $agg = [];

        foreach (WCOrderStream::orders($f) as $order) {
            foreach ($order->get_items('line_item') as $item) {
                $product = $item->get_product();
                $product_id = $product ? $product->get_id() : (int)$item->get_product_id();
                $product_name = $item->get_name();

                if (!empty($f->product_ids) && !in_array((int)$product_id, $f->product_ids, true)) {
                    continue;
                }

                if (!empty($f->categories)) {
                    $cats = $product ? $product->get_category_ids() : [];
                    $ok = false;
                    foreach ($cats as $cid) {
                        if (in_array((int)$cid, $f->categories, true)) { $ok = true; break; }
                    }
                    if (!$ok) { continue; }
                }

                $qty = (float)$item->get_quantity();
                if ($qty <= 0) { $qty = 1; }

                $net = (float)$item->get_total();
                $tax = (float)$item->get_total_tax();
                $gross = $net + $tax;
                $value = ($f->values_mode === 'net') ? $net : $gross;

                if (!isset($agg[$product_id])) {
                    $agg[$product_id] = [
                        'product_id' => $product_id,
                        'product_name' => $product_name,
                        'qty_sum' => 0.0,
                        'total_value_raw' => 0.0,
                    ];
                }

                $agg[$product_id]['qty_sum'] += $qty;
                $agg[$product_id]['total_value_raw'] += $value;
            }
        }

        // format qty as string
        foreach ($agg as $pid => $row) {
            $agg[$pid]['qty_sum'] = (string)$row['qty_sum'];
        }

        return $agg;
    }
}
