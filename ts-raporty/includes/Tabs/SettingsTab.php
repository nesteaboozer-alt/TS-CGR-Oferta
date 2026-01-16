<?php
namespace TSR\Tabs;

use TSR\Filters\FilterDTO;

if (!defined('ABSPATH')) { exit; }

final class SettingsTab implements TabInterface {
    public function get_key(): string { return 'settings'; }
    public function get_title(): string { return __('Ustawienia', 'ts-raporty'); }

    public function render_filters(FilterDTO $filters): string { return ''; }

    // POPRAWKA: Musi zwracać klucz 'rows'
    public function get_rows(FilterDTO $filters, int $page, int $per_page): array { 
        return ['rows' => []]; 
    }
    
    public function count(FilterDTO $filters): int { return 0; }
    public function get_csv_headers(): array { return []; }
    public function get_export_rows(FilterDTO $filters): iterable { return []; }

    public function render_table(array $rows): string {
        $msg = '';
        if (isset($_POST['tsr_save_settings']) && check_admin_referer('tsr_settings_action')) {
            update_option('tsr_report_emails', sanitize_text_field($_POST['tsr_report_emails']));
            $msg = '<div class="updated"><p>Ustawienia zapisane.</p></div>';
        }

        $emails = get_option('tsr_report_emails', get_option('admin_email'));
        ob_start();
        echo $msg; // Wyświetlamy komunikat wewnątrz kontenera tabeli
        ?>
        <div class="card" style="max-width: 800px; padding: 20px; margin-top: 20px; border: 1px solid #ccc; box-shadow: none;">
            <h2 style="margin-top:0;">Konfiguracja Odbiorców Raportów</h2>
            <form method="post">
                <?php wp_nonce_field('tsr_settings_action'); ?>
                <table class="form-table">
                    <tr>
                        <th scope="row"><label for="tsr_report_emails">E-maile (po przecinku)</label></th>
                        <td>
                            <input name="tsr_report_emails" type="text" id="tsr_report_emails" value="<?php echo esc_attr($emails); ?>" class="large-text" style="width: 100%;" placeholder="np. biuro@wp.pl, szef@wp.pl">
                            <p class="description">Na te adresy o 1:00 w nocy zostanie wysłany raport z podsumowaniem sprzedaży.</p>
                        </td>
                    </tr>
                </table>
                <p class="submit">
                    <input type="submit" name="tsr_save_settings" id="submit" class="button button-primary" value="Zapisz ustawienia">
                </p>
            </form>
        </div>
        <?php
        return ob_get_clean();
    }
}