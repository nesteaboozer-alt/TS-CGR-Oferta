<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Zarządzanie produktem TS Hotel Meals.
 * Wersja 2.0: Sezony (High/Low) + Wyjątki + Blokada braku oferty.
 */
class TSME_Admin_Product {

    const META_ENABLED        = '_tsme_enabled';
    const META_MEAL_TYPE      = '_tsme_meal_type';
    const META_PRICING_MATRIX = '_tsme_pricing_matrix';

    // Ustawienia typu "Event"
    const META_EVENT_DATE_FROM = '_tsme_event_date_from';
    const META_EVENT_DATE_TO   = '_tsme_event_date_to';
    const META_EVENT_COMMENT   = '_tsme_event_comment';


    public static function init() {
        add_filter( 'woocommerce_product_data_tabs', array( __CLASS__, 'add_product_tab' ) );
        add_action( 'woocommerce_product_data_panels', array( __CLASS__, 'render_product_panel' ) );
        add_action( 'woocommerce_admin_process_product_object', array( __CLASS__, 'save_product_meta' ) );
        add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_admin_assets' ) );
    }

    public static function enqueue_admin_assets() {
        wp_enqueue_script( 'jquery-ui-datepicker' );
        wp_enqueue_style( 'jquery-ui-style', '//ajax.googleapis.com/ajax/libs/jqueryui/1.9.0/themes/base/jquery-ui.css' );
    }

    public static function add_product_tab( $tabs ) {
        $tabs['tsme_meals'] = array(
            'label'    => __( 'TS Hotel Meals', 'ts-hotel-meals' ),
            'target'   => 'tsme_meals_data',
            'class'    => array(),
            'priority' => 80,
        );
        return $tabs;
    }

    public static function render_product_panel() {
        global $post;

        $product_id = $post ? $post->ID : 0;
        $enabled    = get_post_meta( $product_id, self::META_ENABLED, true );
        $meal_type  = get_post_meta( $product_id, self::META_MEAL_TYPE, true );

        // Pola typu "Event"
        $event_date_from = get_post_meta( $product_id, self::META_EVENT_DATE_FROM, true );
        $event_date_to   = get_post_meta( $product_id, self::META_EVENT_DATE_TO, true );
        $event_comment   = get_post_meta( $product_id, self::META_EVENT_COMMENT, true );
        
        // Struktura v2.0:
        // [ index => [ 'name' => 'Panorama', 'seasons' => [...], 'exceptions' => [...] ] ]
        $pricing = get_post_meta( $product_id, self::META_PRICING_MATRIX, true );
        if ( empty( $pricing ) || ! is_array( $pricing ) ) {
            $pricing = array();
        }

        if ( empty( $meal_type ) ) {
            $meal_type = 'breakfast';
        }
        ?>

        <div id="tsme_meals_data" class="panel woocommerce_options_panel">
            <div class="options_group">
                <?php
                woocommerce_wp_checkbox( array(
                    'id'          => self::META_ENABLED,
                    'label'       => __( 'Aktywuj TS Hotel Meals', 'ts-hotel-meals' ),
                    'value'       => $enabled === 'yes' ? 'yes' : 'no',
                ) );

                                 woocommerce_wp_select( array(
                    'id'          => self::META_MEAL_TYPE,
                    'label'       => __( 'Rodzaj posiłku', 'ts-hotel-meals' ),
                    'options'     => array(
                        'breakfast'      => __( 'Śniadanie', 'ts-hotel-meals' ),
                        'dinner'         => __( 'Obiadokolacja', 'ts-hotel-meals' ),
                        'other'          => __( 'Inny', 'ts-hotel-meals' ),
                        'event'          => __( 'Event (konkretna data)', 'ts-hotel-meals' ),
                    ),
                    'value'       => $meal_type,
                ) );
                ?>
            </div>

            <div class="options_group tsme-event-settings">
                <p class="form-field">
                    <label for="<?php echo esc_attr( self::META_EVENT_DATE_FROM ); ?>">
                        <?php esc_html_e( 'Data realizacji usługi (od)', 'ts-hotel-meals' ); ?>
                    </label>
                    <input type="date"
                           class="short"
                           id="<?php echo esc_attr( self::META_EVENT_DATE_FROM ); ?>"
                           name="<?php echo esc_attr( self::META_EVENT_DATE_FROM ); ?>"
                           value="<?php echo esc_attr( $event_date_from ); ?>" />
                </p>

                <p class="form-field">
                    <label for="<?php echo esc_attr( self::META_EVENT_DATE_TO ); ?>">
                        <?php esc_html_e( 'Data realizacji usługi (do)', 'ts-hotel-meals' ); ?>
                    </label>
                    <input type="date"
                           class="short"
                           id="<?php echo esc_attr( self::META_EVENT_DATE_TO ); ?>"
                           name="<?php echo esc_attr( self::META_EVENT_DATE_TO ); ?>"
                           value="<?php echo esc_attr( $event_date_to ); ?>" />
                    <span class="description">
                        <?php esc_html_e( 'Pozostaw puste, jeśli event trwa tylko jeden dzień.', 'ts-hotel-meals' ); ?>
                    </span>
                </p>

                <p class="form-field">
                    <label for="<?php echo esc_attr( self::META_EVENT_COMMENT ); ?>">
                        <?php esc_html_e( 'Komentarz do eventu', 'ts-hotel-meals' ); ?>
                    </label>
                    <textarea
                        class="short"
                        rows="2"
                        id="<?php echo esc_attr( self::META_EVENT_COMMENT ); ?>"
                        name="<?php echo esc_attr( self::META_EVENT_COMMENT ); ?>"><?php echo esc_textarea( $event_comment ); ?></textarea>
                    <span class="description">
                        <?php esc_html_e( 'Wyświetla się przy podsumowaniu i w zamówieniu podobnie jak komunikaty z wyjątków.', 'ts-hotel-meals' ); ?>
                    </span>
                </p>
            </div>

            <script type="text/javascript">
                jQuery(function($){
                    function tsmeToggleEventSettings() {
                        var val = $('#<?php echo esc_js( self::META_MEAL_TYPE ); ?>').val();
                        $('.tsme-event-settings').toggle( val === 'event' );
                    }
                    tsmeToggleEventSettings();
                    $(document).on('change', '#<?php echo esc_js( self::META_MEAL_TYPE ); ?>', tsmeToggleEventSettings);
                });
            </script>

            <div class="options_group" style="padding: 10px 20px;">
                <h3><?php esc_html_e( 'Konfiguracja Cen (Sezony + Wyjątki)', 'ts-hotel-meals' ); ?></h3>


                <p style="margin-bottom:20px; color:#666;">
                    <?php esc_html_e( 'Definiuj ceny w oparciu o Sezony (np. Niski/Wysoki). Jeśli data nie pokrywa się z żadnym sezonem, klient zobaczy komunikat o braku oferty.', 'ts-hotel-meals' ); ?>
                </p>
                
                <div id="tsme-buildings-container">
                    <?php 
                    if ( ! empty( $pricing ) ) : 
                        foreach ( $pricing as $b_index => $building ) :
                            self::render_building_row( $b_index, $building );
                        endforeach;
                    endif; 
                    ?>
                </div>
                
                <button type="button" class="button button-primary" id="tsme-add-building" style="margin-top:15px;">
                    <?php esc_html_e( '+ Dodaj nowy budynek', 'ts-hotel-meals' ); ?>
                </button>
            </div>

            <script type="text/template" id="tmpl-tsme-building">
                <?php self::render_building_row( '{{INDEX}}', array( 'name' => '', 'seasons' => array(), 'exceptions' => array() ) ); ?>
            </script>

            <script type="text/template" id="tmpl-tsme-season">
                <?php self::render_season_row( '{{B_INDEX}}', '{{S_INDEX}}', array( 'from' => '', 'to' => '', 'adult' => '', 'child' => '' ) ); ?>
            </script>

            <script type="text/template" id="tmpl-tsme-exception">
                <?php self::render_exception_row( '{{B_INDEX}}', '{{E_INDEX}}', array( 'from' => '', 'to' => '', 'adult' => '', 'child' => '', 'msg' => '' ) ); ?>
            </script>

            <style>
                .tsme-building-box { background: #fff; border: 1px solid #c3c4c7; padding: 20px; margin-bottom: 20px; box-shadow: 0 1px 2px rgba(0,0,0,.05); }
                .tsme-building-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px; padding-bottom: 10px; border-bottom: 2px solid #f0f0f1; }
                .tsme-building-title input { font-weight: 600; font-size: 14px; padding: 6px; width: 300px; }
                .tsme-section-header { font-weight: 600; color: #2271b1; margin: 15px 0 8px; display: block; font-size: 13px; text-transform: uppercase; letter-spacing: 0.5px; }
                .tsme-table { width: 100%; border-collapse: collapse; background: #fcfcfc; border: 1px solid #e5e5e5; }
                .tsme-table th { text-align: left; padding: 8px; font-size: 11px; color: #646970; background: #f6f7f7; border-bottom: 1px solid #e5e5e5; }
                .tsme-table td { padding: 8px; border-bottom: 1px solid #f0f0f1; vertical-align: middle; }
                .tsme-input-date { width: 110px !important; }
                .tsme-input-price { width: 80px !important; }
                .tsme-input-full { width: 100%; }
            </style>

            <script type="text/javascript">
            jQuery(document).ready(function($){
                function initDatepickers() { $('.tsme-datepicker').datepicker({ dateFormat: 'yy-mm-dd' }); }
                initDatepickers();

                $('#tsme-add-building').on('click', function(){
                    var index = $('#tsme-buildings-container .tsme-building-box').length;
                    var html = $('#tmpl-tsme-building').html().replace(/{{INDEX}}/g, index);
                    $('#tsme-buildings-container').append(html);
                });

                $(document).on('click', '.tsme-remove-building', function(){
                    if(confirm('Usunąć budynek?')) $(this).closest('.tsme-building-box').remove();
                });

                $(document).on('click', '.tsme-add-season', function(){
                    var $box = $(this).closest('.tsme-building-box');
                    var bIdx = $box.data('index');
                    var sIdx = $box.find('.tsme-season-row').length;
                    var html = $('#tmpl-tsme-season').html().replace(/{{B_INDEX}}/g, bIdx).replace(/{{S_INDEX}}/g, sIdx);
                    $box.find('.tsme-seasons-table tbody').append(html);
                    initDatepickers();
                });

                $(document).on('click', '.tsme-add-exception', function(){
                    var $box = $(this).closest('.tsme-building-box');
                    var bIdx = $box.data('index');
                    var eIdx = $box.find('.tsme-exception-row').length;
                    var html = $('#tmpl-tsme-exception').html().replace(/{{B_INDEX}}/g, bIdx).replace(/{{E_INDEX}}/g, eIdx);
                    $box.find('.tsme-exceptions-table tbody').append(html);
                    initDatepickers();
                });

                $(document).on('click', '.tsme-remove-row', function(){ $(this).closest('tr').remove(); });
            });
            </script>
        </div>
        <?php
    }

    private static function render_building_row( $index, $data ) {
        // Pobieramy opis, jeśli istnieje
        $desc = isset($data['description']) ? $data['description'] : '';
        ?>
        <div class="tsme-building-box" data-index="<?php echo esc_attr( $index ); ?>">
            <div class="tsme-building-header">
                <div class="tsme-building-title" style="display:flex; gap:15px; width:100%;">
                    <div style="flex:1;">
                        <label style="display:block;font-size:10px;color:#666;">Nazwa Budynku</label>
                        <input type="text" name="tsme_pricing[<?php echo $index; ?>][name]" value="<?php echo esc_attr( $data['name'] ); ?>" placeholder="np. Panorama" style="width:100%;" />
                    </div>
                    <div style="flex:2;">
                        <label style="display:block;font-size:10px;color:#666;">Opis / Komentarz (widoczny dla klienta)</label>
                        <input type="text" name="tsme_pricing[<?php echo $index; ?>][description]" value="<?php echo esc_attr( $desc ); ?>" placeholder="np. Budynek główny z basenem" style="width:100%;" />
                    </div>
                </div>
                <button type="button" class="button tsme-remove-building" style="margin-left:10px;">Usuń</button>
            </div>

            <span class="tsme-section-header">1. Sezony (Ceny Podstawowe)</span>
            <table class="tsme-table tsme-seasons-table">
                <thead>
                    <tr>
                        <th>Od daty</th><th>Do daty</th><th>Cena (Dor.)</th><th>Cena (Dz.)</th><th style="width:30px;"></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if(!empty($data['seasons'])) foreach($data['seasons'] as $s_idx => $seas) self::render_season_row($index, $s_idx, $seas); ?>
                </tbody>
            </table>
            <button type="button" class="button button-small tsme-add-season" style="margin-top:5px;">+ Dodaj sezon</button>

            <span class="tsme-section-header" style="margin-top:20px;">2. Wyjątki (Święta, Majówki)</span>
            <table class="tsme-table tsme-exceptions-table">
                <thead>
                    <tr>
                        <th>Od daty</th><th>Do daty</th><th>Cena (Dor.)</th><th>Cena (Dz.)</th><th>Komunikat</th><th style="width:30px;"></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if(!empty($data['exceptions'])) foreach($data['exceptions'] as $e_idx => $ex) self::render_exception_row($index, $e_idx, $ex); ?>
                </tbody>
            </table>
            <button type="button" class="button button-small tsme-add-exception" style="margin-top:5px;">+ Dodaj wyjątek</button>
        </div>
        <?php
    }

    private static function render_season_row( $b_idx, $s_idx, $data ) {
        ?>
        <tr class="tsme-season-row">
            <td><input type="text" class="tsme-datepicker tsme-input-date" name="tsme_pricing[<?php echo $b_idx; ?>][seasons][<?php echo $s_idx; ?>][from]" value="<?php echo esc_attr($data['from']); ?>" placeholder="Start" /></td>
            <td><input type="text" class="tsme-datepicker tsme-input-date" name="tsme_pricing[<?php echo $b_idx; ?>][seasons][<?php echo $s_idx; ?>][to]" value="<?php echo esc_attr($data['to']); ?>" placeholder="Koniec" /></td>
            <td><input type="number" step="0.01" class="tsme-input-price" name="tsme_pricing[<?php echo $b_idx; ?>][seasons][<?php echo $s_idx; ?>][adult]" value="<?php echo esc_attr($data['adult']); ?>" /></td>
            <td><input type="number" step="0.01" class="tsme-input-price" name="tsme_pricing[<?php echo $b_idx; ?>][seasons][<?php echo $s_idx; ?>][child]" value="<?php echo esc_attr($data['child']); ?>" /></td>
            <td><span class="dashicons dashicons-trash tsme-remove-row" style="cursor:pointer; color:#a00;"></span></td>
        </tr>
        <?php
    }

    private static function render_exception_row( $b_idx, $e_idx, $data ) {
        ?>
        <tr class="tsme-exception-row">
            <td><input type="text" class="tsme-datepicker tsme-input-date" name="tsme_pricing[<?php echo $b_idx; ?>][exceptions][<?php echo $e_idx; ?>][from]" value="<?php echo esc_attr($data['from']); ?>" /></td>
            <td><input type="text" class="tsme-datepicker tsme-input-date" name="tsme_pricing[<?php echo $b_idx; ?>][exceptions][<?php echo $e_idx; ?>][to]" value="<?php echo esc_attr($data['to']); ?>" /></td>
            <td><input type="number" step="0.01" class="tsme-input-price" name="tsme_pricing[<?php echo $b_idx; ?>][exceptions][<?php echo $e_idx; ?>][adult]" value="<?php echo esc_attr($data['adult']); ?>" /></td>
            <td><input type="number" step="0.01" class="tsme-input-price" name="tsme_pricing[<?php echo $b_idx; ?>][exceptions][<?php echo $e_idx; ?>][child]" value="<?php echo esc_attr($data['child']); ?>" /></td>
            <td><input type="text" class="tsme-input-full" name="tsme_pricing[<?php echo $b_idx; ?>][exceptions][<?php echo $e_idx; ?>][msg]" value="<?php echo esc_attr($data['msg']); ?>" placeholder="Komunikat" /></td>
            <td><span class="dashicons dashicons-trash tsme-remove-row" style="cursor:pointer; color:#a00;"></span></td>
        </tr>
        <?php
    }

    public static function save_product_meta( $product ) {
        $product_id = $product->get_id();

        $enabled = ( isset( $_POST[ self::META_ENABLED ] ) && 'yes' === $_POST[ self::META_ENABLED ] ) ? 'yes' : 'no';
        update_post_meta( $product_id, self::META_ENABLED, $enabled );

        if ( isset( $_POST[ self::META_MEAL_TYPE ] ) ) {
            update_post_meta(
                $product_id,
                self::META_MEAL_TYPE,
                sanitize_text_field( $_POST[ self::META_MEAL_TYPE ] )
            );
        }

        // Zapis pól Event
        $event_from    = isset( $_POST[ self::META_EVENT_DATE_FROM ] ) ? sanitize_text_field( $_POST[ self::META_EVENT_DATE_FROM ] ) : '';
        $event_to      = isset( $_POST[ self::META_EVENT_DATE_TO ] ) ? sanitize_text_field( $_POST[ self::META_EVENT_DATE_TO ] ) : '';
        $event_comment = isset( $_POST[ self::META_EVENT_COMMENT ] ) ? wp_kses_post( $_POST[ self::META_EVENT_COMMENT ] ) : '';

        update_post_meta( $product_id, self::META_EVENT_DATE_FROM, $event_from );
        update_post_meta( $product_id, self::META_EVENT_DATE_TO, $event_to );
        update_post_meta( $product_id, self::META_EVENT_COMMENT, $event_comment );

        if ( isset( $_POST['tsme_pricing'] ) && is_array( $_POST['tsme_pricing'] ) ) {

            $clean_matrix = array();
            foreach($_POST['tsme_pricing'] as $b_raw) {
                $name = sanitize_text_field($b_raw['name']);
                if(empty($name)) continue;

                // --- NOWE POLE ---
                $desc = isset($b_raw['description']) ? sanitize_text_field($b_raw['description']) : '';

                $building = array(
                    'name' => $name, 
                    'description' => $desc, // Zapisujemy opis
                    'seasons' => array(), 
                    'exceptions' => array()
                );

                if(!empty($b_raw['seasons'])) {
                    foreach($b_raw['seasons'] as $s) {
                        if(empty($s['from']) || empty($s['to'])) continue;
                        $building['seasons'][] = array(
                            'from' => sanitize_text_field($s['from']), 'to' => sanitize_text_field($s['to']),
                            'adult' => wc_format_decimal($s['adult']), 'child' => wc_format_decimal($s['child'])
                        );
                    }
                }
                if(!empty($b_raw['exceptions'])) {
                    foreach($b_raw['exceptions'] as $e) {
                        if(empty($e['from']) || empty($e['to'])) continue;
                        $building['exceptions'][] = array(
                            'from' => sanitize_text_field($e['from']), 'to' => sanitize_text_field($e['to']),
                            'adult' => wc_format_decimal($e['adult']), 'child' => wc_format_decimal($e['child']),
                            'msg' => sanitize_text_field($e['msg'])
                        );
                    }
                }
                $clean_matrix[] = $building;
            }
            update_post_meta($product_id, self::META_PRICING_MATRIX, $clean_matrix);
        } else {
            update_post_meta($product_id, self::META_PRICING_MATRIX, array());
        }
    }
}