<?php
/**
 * Plugin Name: TechSolver - Bar rezeracji
 * Description: Pasek szybkiej rezerwacji
 * Version: 1.5
 * Author: TechSolver
 */

if ( ! defined( 'ABSPATH' ) ) exit;


require_once plugin_dir_path( __FILE__ ) . 'front-lista/front-lista.php';
class TSBB_Plugin {

    public static function init() {
        add_shortcode( 'ts_booking_bar', array( __CLASS__, 'render_bar' ) );
        add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
    }

 
    private static function get_pool_data() {
        $pool_products = wc_get_products( array( 'limit' => -1, 'status' => 'publish' ) );
        $pool_data = array();

        $target_cats   = array( 'Zabiegi', 'Basen', "Saunarium" ); 
        $target_places = array( 'Panorama', 'Czarna Perła', 'Biała Perła' ); 

        foreach ( $pool_products as $prod ) {
            if ( get_post_meta( $prod->get_id(), '_tsme_enabled', true ) === 'yes' ) continue;

            $terms = get_the_terms( $prod->get_id(), 'product_cat' );
            if ( ! $terms || is_wp_error( $terms ) ) continue;

            
            $product_cats = wp_list_pluck( $terms, 'name' );
            $product_cats = array_map( 'trim', $product_cats );

            
            $matching_cats = array_intersect( $target_cats, $product_cats );
            $matching_places = array_intersect( $target_places, $product_cats );

            if ( ! empty( $matching_cats ) && ! empty( $matching_places ) ) {
                foreach ( $matching_cats as $cat ) {
                    foreach ( $matching_places as $place ) {
                        $pool_data[ $cat ][ $place ][] = array(
                            'name' => $prod->get_name(),
                            'url'  => $prod->get_permalink()
                        );
                    }
                }
            }
        }
        return $pool_data;
    }

    public static function enqueue_assets() {
        wp_enqueue_style( 'tsbb-style', plugin_dir_url( __FILE__ ) . 'tsbb-style.css', array(), '1.5' );
        wp_enqueue_script( 'tsbb-script', plugin_dir_url( __FILE__ ) . 'tsbb-script.js', array( 'jquery' ), '1.5', true );

        
        $data = self::get_pool_data();
        wp_localize_script( 'tsbb-script', 'tsbbVars', array(
            'poolData' => $data
        ) );
    }

        public static function render_bar() {
        
        $meal_products = wc_get_products( array(
    'limit'      => -1,
    'status'     => array('publish'), // tylko opublikowane
    'meta_key'   => '_tsme_enabled',
    'meta_value' => 'yes',
) );

        $buildings = array();
        $meal_urls = array();

        // Główne nazwy budynków po kategoriach
        $known_building_names = array(
            'Panorama',
            'Czarna Perła',
            'Biała Perła',
            'Karczma Czarna Góra',
        );

        foreach ( $meal_products as $prod ) {

            $is_event      = false;
            $prod_buildings = array();

            // --- kategorie produktu: EVENT + budynki ---
            $terms = get_the_terms( $prod->get_id(), 'product_cat' );
            if ( ! empty( $terms ) && ! is_wp_error( $terms ) ) {
                foreach ( $terms as $term ) {
                    $slug_lower = strtolower( $term->slug );
                    $name_lower = strtolower( $term->name );

                    // EVENT – obsłuży też np. "Event (konkretna data)"
                    if ( strpos( $slug_lower, 'event' ) !== false || strpos( $name_lower, 'event' ) !== false ) {
                        $is_event = true;
                    }

                    // budynki po nazwach kategorii
                    foreach ( $known_building_names as $building_name ) {
                        if ( strtolower( $term->name ) === strtolower( $building_name ) ) {
                            $prod_buildings[]         = $building_name;
                            $buildings[ $building_name ] = $building_name; // globalna lista do dropdownu
                        }
                    }
                }
            }

            // zachowujemy dotychczasową logikę: macierz cen buduje globalną listę budynków
            $matrix = get_post_meta( $prod->get_id(), '_tsme_pricing_matrix', true );
            if ( ! empty( $matrix ) && is_array( $matrix ) ) {
                foreach ( $matrix as $row ) {
                    if ( ! empty( $row['name'] ) ) {
                        $buildings[ $row['name'] ] = $row['name'];
                    }
                }
            }

            $meal_urls[ $prod->get_id() ] = array(
                'name'      => $prod->get_name(),
                'url'       => $prod->get_permalink(),
                'is_event'  => $is_event,
                // budynki wynikające z kategorii (jedna / kilka / żadna)
                'buildings' => array_unique( $prod_buildings ),
            );
        }

        
        $pool_data = self::get_pool_data();

        
        
        $pool_cats_keys = array_keys( $pool_data );

        ob_start();
        ?>
        <div class="tsbb-container">
            <div class="tsbb-tabs">
                <button type="button" class="tsbb-tab active" data-target="meals">Wyżywienie</button>
                <button type="button" class="tsbb-tab" data-target="pool">Basen & SPA</button>
            </div>

            <div class="tsbb-content-box">
                <p class="tsbb-title">Szybki wybór</p>
                <div class="tsbb-panel active" id="tsbb-panel-meals">
                    <p class="tsbb-desc">Nocujesz w Czarna Góra Resort? Wybierz wyżywienie <strong>na miejscu</strong>, <strong>w formie nielimitowanego bufetu</strong> - tam gdzie nocujesz. <br> Wybierz obiekt i datę przyjazdu, aby przejść do zamówienia.</p>
                    <div class="tsbb-form-row">
                                                <div class="tsbb-field">
                            <span class="tsbb-label-small">Rodzaj</span>
                            <div class="tsbb-input-wrap">
                                <select id="tsbb-meal-id">
                                    <?php foreach ( $meal_urls as $id => $info ) : ?>
                                        <option
                                            value="<?php echo esc_url( $info['url'] ); ?>"
                                            data-event="<?php echo ! empty( $info['is_event'] ) ? '1' : '0'; ?>"
                                            data-buildings="<?php echo esc_attr( ! empty( $info['buildings'] ) ? implode( '|', $info['buildings'] ) : '' ); ?>"
                                        >
                                            <?php echo esc_html( $info['name'] ); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>

                        <div class="tsbb-field">
                            <span class="tsbb-label-small">Obiekt</span>
                            <div class="tsbb-input-wrap">
                                <select id="tsbb-meal-object">
                                    <option value="">Wybierz...</option>
                                    <?php foreach($buildings as $b): ?>
                                        <option value="<?php echo esc_attr($b); ?>"><?php echo esc_html($b); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                                                <div class="tsbb-field tsbb-meal-date-field">
                            <span class="tsbb-label-small">Data przyjazdu</span>

                            <div class="tsbb-input-wrap">
                                <input type="date" id="tsbb-meal-date">
                            </div>
                        </div>
                        <button type="button" id="tsbb-go-meals" class="tsbb-btn">ZOBACZ</button>
                    </div>
                </div>

                <div class="tsbb-panel" id="tsbb-panel-pool" style="display:none;">
                    <p class="tsbb-desc">Sprawdź dostępne zabiegi SPA lub karnety na basen, wskaż obiekt i wybierz interesujący Cię produkt.</p>
                    <div class="tsbb-form-row">
                        <div class="tsbb-field">
                            <span class="tsbb-label-small">Kategoria</span>
                            <div class="tsbb-input-wrap">
                                <select id="tsbb-pool-cat">
                                    <option value="">Wybierz...</option>
                                    <?php foreach($pool_cats_keys as $cat): ?>
                                        <option value="<?php echo esc_attr($cat); ?>"><?php echo esc_html($cat); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                        <div class="tsbb-field">
                            <span class="tsbb-label-small">Obiekt</span>
                            <div class="tsbb-input-wrap">
                                <select id="tsbb-pool-object" disabled>
                                    <option value="">Wybierz...</option>
                                </select>
                            </div>
                        </div>
                        <div class="tsbb-field" style="flex:1.5;">
                            <span class="tsbb-label-small">Usługa</span>
                            <div class="tsbb-input-wrap">
                                <select id="tsbb-pool-service" disabled>
                                    <option value="">Najpierw obiekt...</option>
                                </select>
                            </div>
                        </div>
                        <button type="button" id="tsbb-go-pool" class="tsbb-btn">PRZEJDŹ</button>
                    </div>
                </div>

            </div>
        </div>
        <?php
        return ob_get_clean();
    }
}
add_action('plugins_loaded', array('TSBB_Plugin', 'init'));