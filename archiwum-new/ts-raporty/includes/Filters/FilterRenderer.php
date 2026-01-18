<?php
namespace TSR\Filters;

if (!defined('ABSPATH')) { exit; }

final class FilterRenderer {
    public static function render(FilterDTO $f, array $extra_fields = []): string {
        $statuses = wc_get_order_statuses();
        $terms = get_terms([
            'taxonomy' => 'product_cat',
            'hide_empty' => false,
        ]);
        if (is_wp_error($terms)) { $terms = []; }

        $gateways = WC()->payment_gateways() ? WC()->payment_gateways()->payment_gateways() : [];

ob_start();
        ?>
        <form method="get" class="tsr-filter-form" style="background:#fff; padding:20px; border:1px solid #ccd0d4; margin-bottom:20px; border-radius:4px;">
            <input type="hidden" name="page" value="ts-raporty" />
            <input type="hidden" name="tab" value="<?php echo esc_attr($extra_fields['tab_key'] ?? 'transactions'); ?>" />

            <div class="tsr-filter-grid" style="display: flex; flex-wrap: wrap; gap: 20px; align-items: flex-start;">
                
                <div style="display:flex; gap:10px;">
                    <div class="tsr-filter-group">
                        <label style="display:block; font-weight:bold; margin-bottom:5px;"><?php esc_html_e('Od:', 'ts-raporty'); ?></label>
                        <input type="date" name="date_from" value="<?php echo esc_attr($f->date_from); ?>" style="width:140px;" />
                    </div>
                    <div class="tsr-filter-group">
                        <label style="display:block; font-weight:bold; margin-bottom:5px;"><?php esc_html_e('Do:', 'ts-raporty'); ?></label>
                        <input type="date" name="date_to" value="<?php echo esc_attr($f->date_to); ?>" style="width:140px;" />
                    </div>
                </div>

                <div class="tsr-filter-group">
                    <label style="display:block; font-weight:bold; margin-bottom:5px;"><?php esc_html_e('Wartości', 'ts-raporty'); ?></label>
                    <select name="values_mode" style="width:100px;">
                        <option value="gross" <?php selected($f->values_mode, 'gross'); ?>><?php esc_html_e('Brutto', 'ts-raporty'); ?></option>
                        <option value="net" <?php selected($f->values_mode, 'net'); ?>><?php esc_html_e('Netto', 'ts-raporty'); ?></option>
                    </select>
                </div>

                <div class="tsr-filter-group">
                    <label style="display:block; font-weight:bold; margin-bottom:5px;"><?php esc_html_e('Faktura (NIP)', 'ts-raporty'); ?></label>
                    <select name="invoice_mode" style="width:180px;">
                        <option value="all" <?php selected($f->invoice_mode, 'all'); ?>><?php esc_html_e('Wszystkie', 'ts-raporty'); ?></option>
                        <option value="with" <?php selected($f->invoice_mode, 'with'); ?>><?php esc_html_e('Tylko z fakturą (NIP)', 'ts-raporty'); ?></option>
                        <option value="without" <?php selected($f->invoice_mode, 'without'); ?>><?php esc_html_e('Bez faktury (brak NIP)', 'ts-raporty'); ?></option>
                    </select>
                </div>

                <div class="tsr-filter-group">
                    <label style="display:block; font-weight:bold; margin-bottom:5px;"><?php esc_html_e('Pochodzenie:', 'ts-raporty'); ?></label>
                    <select name="origin_mode" style="width:180px;">
                        <option value="all" <?php selected($f->origin_mode, 'all'); ?>><?php esc_html_e('Wszystkie pochodzenia', 'ts-raporty'); ?></option>
                        <option value="web" <?php selected($f->origin_mode, 'web'); ?>><?php esc_html_e('Sklep (WWW)', 'ts-raporty'); ?></option>
                        <option value="admin" <?php selected($f->origin_mode, 'admin'); ?>><?php esc_html_e('Panel Administratora', 'ts-raporty'); ?></option>
                    </select>
                </div>

                <div style="display:flex; gap:20px; flex-wrap:wrap; width:100%;">
                    <div class="tsr-filter-group">
                        <label style="display:block; font-weight:bold; margin-bottom:5px;"><?php esc_html_e('Statusy zamówień', 'ts-raporty'); ?></label>
                        <select multiple size="5" name="statuses[]" style="width:200px; height:100px;">
                            <?php foreach ($statuses as $key => $label): ?>
                                <option value="<?php echo esc_attr($key); ?>" <?php echo in_array($key, $f->statuses, true) ? 'selected' : ''; ?>>
                                    <?php echo esc_html($label); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="tsr-filter-group">
                        <label style="display:block; font-weight:bold; margin-bottom:5px;"><?php esc_html_e('Kategorie produktów', 'ts-raporty'); ?></label>
                        <select multiple size="5" name="categories[]" style="width:200px; height:100px;">
                            <?php foreach ($terms as $t): ?>
                                <option value="<?php echo esc_attr((string)$t->term_id); ?>" <?php echo in_array((int)$t->term_id, $f->categories, true) ? 'selected' : ''; ?>>
                                    <?php echo esc_html($t->name); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="tsr-filter-group">
                        <label style="display:block; font-weight:bold; margin-bottom:5px;"><?php esc_html_e('Metody płatności', 'ts-raporty'); ?></label>
                        <select multiple size="5" name="payment_methods[]" style="width:200px; height:100px;">
                            <?php foreach ($gateways as $gw): ?>
                                <option value="<?php echo esc_attr($gw->id); ?>" <?php echo in_array($gw->id, $f->payment_methods, true) ? 'selected' : ''; ?>>
                                    <?php echo esc_html($gw->get_title()); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="tsr-filter-group">
    <label style="display:block; font-weight:bold; margin-bottom:5px;"><?php esc_html_e('Produkty (nazwa):', 'ts-raporty'); ?></label>
    <select multiple size="5" name="product_names[]" style="width:250px; height:100px;">
    <?php 
    $all_products = wc_get_products(['limit' => -1, 'status' => 'publish']);
    foreach ($all_products as $p): 
        $p_name = $p->get_name();
        $is_selected = in_array($p_name, (array)$f->product_names, true) ? 'selected' : '';
    ?>
        <option value="<?php echo esc_attr($p_name); ?>" <?php echo $is_selected; ?>>
            <?php echo esc_html($p_name); ?>
        </option>
    <?php endforeach; ?>
</select>
</div>
<?php if (!empty($extra_fields['tab_key']) && $extra_fields['tab_key'] === 'meals'): ?>
<div style="display:flex; gap:15px; width:100%; border-top:1px solid #eee; padding-top:15px; margin-top:5px;">
    <div class="tsr-filter-group">
        <label style="display:block; font-weight:bold; margin-bottom:5px;"><?php esc_html_e('Budynki:', 'ts-raporty'); ?></label>
        <select multiple size="5" name="buildings[]" style="width:200px; height:100px;">
            <?php 
            global $wpdb;
            $b_list = $wpdb->get_col("SELECT DISTINCT building FROM {$wpdb->prefix}tsme_meal_codes WHERE building != ''");
            if ($b_list) {
                foreach ($b_list as $b): ?>
                    <option value="<?php echo esc_attr($b); ?>" <?php echo in_array($b, $f->buildings, true) ? 'selected' : ''; ?>>
                        <?php echo esc_html($b); ?>
                    </option>
                <?php endforeach;
            } ?>
        </select>
    </div>
</div>
<?php endif; ?>
                <?php if (!empty($extra_fields['tab_key']) && $extra_fields['tab_key'] === 'passes'): ?>
                <div style="display:flex; gap:15px; width:100%; border-top:1px solid #eee; padding-top:15px; margin-top:5px;">
                    <div class="tsr-filter-group">
                        <label style="display:block; font-weight:bold;"><?php esc_html_e('Użycie od:', 'ts-raporty'); ?></label>
                        <input type="date" name="event_date_from" value="<?php echo esc_attr($f->event_date_from); ?>" />
                    </div>
                    <div class="tsr-filter-group">
                        <label style="display:block; font-weight:bold;"><?php esc_html_e('Użycie do:', 'ts-raporty'); ?></label>
                        <input type="date" name="event_date_to" value="<?php echo esc_attr($f->event_date_to); ?>" />
                    </div>
                    <div class="tsr-filter-group">
                        <label style="display:block; font-weight:bold;"><?php esc_html_e('Filtr daty użycia', 'ts-raporty'); ?></label>
                        <select name="event_date_mode">
                            <option value="all" <?php selected($f->event_date_mode, 'all'); ?>><?php esc_html_e('Wszystkie', 'ts-raporty'); ?></option>
                            <option value="with" <?php selected($f->event_date_mode, 'with'); ?>><?php esc_html_e('Tylko z datą', 'ts-raporty'); ?></option>
                            <option value="without" <?php selected($f->event_date_mode, 'without'); ?>><?php esc_html_e('Tylko bez daty', 'ts-raporty'); ?></option>
                        </select>
                    </div>
                </div>
                <?php endif; ?>
            </div>

            <div class="tsr-filter-actions" style="margin-top:20px; display:flex; gap:10px;">
                <button class="button button-primary" type="submit"><?php esc_html_e('Zastosuj filtry', 'ts-raporty'); ?></button>
                <a class="button" href="<?php echo esc_url(add_query_arg(['page' => 'ts-raporty', 'tab' => ($extra_fields['tab_key'] ?? 'transactions')], admin_url('admin.php'))); ?>">
                    <?php esc_html_e('Wyczyść filtry', 'ts-raporty'); ?>
                </a>
            </div>
        </form>
        <script>
        jQuery(document).ready(function($) {
            // Skrypt pozwalający na multiselect bez Ctrl
            $('.tsr-filter-form select[multiple] option').mousedown(function(e) {
                e.preventDefault();
                $(this).prop('selected', !$(this).prop('selected'));
                return false;
            });
        });
        </script>
        <?php
        return (string)ob_get_clean();
    }
}
