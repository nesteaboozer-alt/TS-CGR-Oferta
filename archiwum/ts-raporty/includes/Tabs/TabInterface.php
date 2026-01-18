<?php
namespace TSR\Tabs;

use TSR\Filters\FilterDTO;

if (!defined('ABSPATH')) { exit; }

interface TabInterface {
    public function get_key(): string;
    public function get_title(): string;

    /** @return array{rows: array<int, array<string,mixed>>} */
    public function get_rows(FilterDTO $filters, int $page, int $per_page): array;

    public function count(FilterDTO $filters): int;

    /** @return iterable<int, array<int, string>> */
    public function get_export_rows(FilterDTO $filters): iterable;

    /** @return array<int, string> */
    public function get_csv_headers(): array;

    public function render_filters(FilterDTO $filters): string;

    /** @param array<int, array<string,mixed>> $rows */
    public function render_table(array $rows): string;
}
