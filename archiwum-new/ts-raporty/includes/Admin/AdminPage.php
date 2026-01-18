<?php
namespace TSR\Admin;

use TSR\Filters\FilterParser;
use TSR\Tabs\TabRegistry;

if (!defined('ABSPATH')) { exit; }

final class AdminPage {
    public static function init(): void {
        add_action('admin_menu', [__CLASS__, 'register_menu']);
        add_action('admin_post_tsr_export_csv', [__CLASS__, 'handle_export_csv']);
    }

    public static function register_menu(): void {
        add_menu_page(
            __('TS Raporty', 'ts-raporty'),
            __('TS Raporty', 'ts-raporty'),
            'manage_woocommerce',
            'ts-raporty',
            [__CLASS__, 'render'],
            'dashicons-chart-bar',
            56
        );
    }

    public static function render(): void {
        if (!current_user_can('manage_woocommerce')) { wp_die(__('Brak uprawnień.', 'ts-raporty')); }

        $tab_key = isset($_GET['tab']) ? sanitize_key((string)$_GET['tab']) : 'transactions';
        $registry = TabRegistry::instance();
        $tab = $registry->get($tab_key);
        if (!$tab) { $tab_key = 'transactions'; $tab = $registry->get($tab_key); }

        $filters = FilterParser::parse_from_request($_GET);

        $page = isset($_GET['paged']) ? max(1, (int)$_GET['paged']) : 1;
        $per_page = isset($_GET['per_page']) ? max(10, min(500, (int)$_GET['per_page'])) : 50;

        $result = $tab->get_rows($filters, $page, $per_page);
        $total = $tab->count($filters);

        ?>
        <div class="wrap tsr-wrap">
            <h1><?php echo esc_html__('TS Raporty', 'ts-raporty'); ?></h1>

            <h2 class="nav-tab-wrapper">
                <?php foreach ($registry->all() as $key => $t): ?>
                    <?php $url = add_query_arg(['page' => 'ts-raporty', 'tab' => $key], admin_url('admin.php')); ?>
                    <a class="nav-tab <?php echo $key === $tab_key ? 'nav-tab-active' : ''; ?>" href="<?php echo esc_url($url); ?>">
                        <?php echo esc_html($t->get_title()); ?>
                    </a>
                <?php endforeach; ?>
            </h2>

            <div class="tsr-filters">
                <?php echo $tab->render_filters($filters); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
            </div>

            <div class="tsr-meta-bar">
                <div class="tsr-count">
                    <?php echo esc_html(sprintf(__('Liczba rekordów: %d', 'ts-raporty'), (int)$total)); ?>
                </div>
                <div class="tsr-export">
                    <?php
                        $export_url = wp_nonce_url(
                            add_query_arg(array_merge($_GET, [
                                'action' => 'tsr_export_csv',
                                'tsr_tab' => $tab_key,
                                'page' => 'ts-raporty',
                            ]), admin_url('admin-post.php')),
                            'tsr_export_csv'
                        );
                    ?>
                    <a class="button button-primary" href="<?php echo esc_url($export_url); ?>">
                        <?php echo esc_html(sprintf(__('Eksport CSV (eksportuje %d rekordów)', 'ts-raporty'), (int)$total)); ?>
                    </a>
                </div>
            </div>

            <div class="tsr-table-wrap">
                <?php echo $tab->render_table($result['rows']); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
            </div>

            <?php echo self::render_pagination($tab_key, $page, $per_page, $total); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
        </div>
        <?php
    }

    private static function render_pagination(string $tab_key, int $page, int $per_page, int $total): string {
        $total_pages = (int)ceil($total / $per_page);
        if ($total_pages <= 1) { return ''; }

        $base_args = $_GET;
        $base_args['page'] = 'ts-raporty';
        $base_args['tab'] = $tab_key;
        $base_args['per_page'] = $per_page;

        $html = '<div class="tablenav"><div class="tablenav-pages">';

        $prev = max(1, $page - 1);
        $next = min($total_pages, $page + 1);

        $html .= '<span class="pagination-links">';
        $html .= sprintf(
            '<a class="prev-page button %s" href="%s">&lsaquo;</a>',
            $page <= 1 ? 'disabled' : '',
            esc_url(add_query_arg(array_merge($base_args, ['paged' => $prev]), admin_url('admin.php')))
        );
        $html .= sprintf(
            '<span class="paging-input">%d / <span class="total-pages">%d</span></span>',
            (int)$page,
            (int)$total_pages
        );
        $html .= sprintf(
            '<a class="next-page button %s" href="%s">&rsaquo;</a>',
            $page >= $total_pages ? 'disabled' : '',
            esc_url(add_query_arg(array_merge($base_args, ['paged' => $next]), admin_url('admin.php')))
        );
        $html .= '</span></div></div>';

        return $html;
    }

    public static function handle_export_csv(): void {
        if (!current_user_can('manage_woocommerce')) { wp_die(__('Brak uprawnień.', 'ts-raporty')); }
        check_admin_referer('tsr_export_csv');

        $tab_key = isset($_GET['tsr_tab']) ? sanitize_key((string)$_GET['tsr_tab']) : 'transactions';
        $registry = TabRegistry::instance();
        $tab = $registry->get($tab_key);
        if (!$tab) { wp_die(__('Nieznana zakładka.', 'ts-raporty')); }

        $filters = FilterParser::parse_from_request($_GET);

        $filename = 'ts-raporty-' . $tab_key . '-' . gmdate('Y-m-d_H-i-s') . '.csv';
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename=' . $filename);
        header('Pragma: no-cache');
        header('Expires: 0');

        $out = fopen('php://output', 'w');
        if (!$out) { wp_die('Cannot open output'); }

        // UTF-8 BOM for Excel compatibility
        fwrite($out, chr(0xEF) . chr(0xBB) . chr(0xBF));

        $headers = $tab->get_csv_headers();
        fputcsv($out, $headers, ';');

        foreach ($tab->get_export_rows($filters) as $row) {
            fputcsv($out, $row, ';');
        }

        fclose($out);
        exit;
    }
}
