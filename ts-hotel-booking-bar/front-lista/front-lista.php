<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class TSBB_Front_List {

    public static function init() {
        // Shortcode i assety
        add_shortcode( 'ts_product_list', array( __CLASS__, 'render' ) );
        add_action( 'wp_enqueue_scripts', array( __CLASS__, 'assets' ) );

        // Pola w Adminie Produktu (Cena Front)
        add_action( 'woocommerce_product_options_general_product_data', array( __CLASS__, 'add_custom_field' ) );
        add_action( 'woocommerce_process_product_meta', array( __CLASS__, 'save_custom_field' ) );
    }

    /**
     * Dodaje pole w edycji produktu (Zakładka Ustawienia główne)
     */
    public static function add_custom_field() {
        echo '<div class="options_group">';
        woocommerce_wp_text_input( array(
            'id'          => '_ts_front_price_label',
            'label'       => __( 'Etykieta Ceny (Front Lista)', 'woocommerce' ),
            'placeholder' => 'np. od 30,00 zł',
            'desc_tip'    => 'true',
            'description' => __( 'Ten tekst zastąpi standardową cenę na liście kafelków [ts_product_list].', 'woocommerce' )
        ));
        echo '</div>';
    }

    /**
     * Zapisuje pole
     */
    public static function save_custom_field( $post_id ) {
        $val = isset( $_POST['_ts_front_price_label'] ) ? sanitize_text_field( $_POST['_ts_front_price_label'] ) : '';
        update_post_meta( $post_id, '_ts_front_price_label', $val );
    }

    public static function assets() {
        wp_register_style( 'tsfl-style', plugin_dir_url( __FILE__ ) . 'style.css', array(), '1.2' );
        wp_register_script( 'tsfl-script', plugin_dir_url( __FILE__ ) . 'script.js', array('jquery'), '1.2', true );
    }

    public static function render( $atts ) {
        wp_enqueue_style( 'tsfl-style' );
        wp_enqueue_script( 'tsfl-script' );

        // 1. Konfiguracja zakładek
        $json_path = plugin_dir_path( __FILE__ ) . 'config.json';
        $tabs = array();
        if ( file_exists( $json_path ) ) {
            $tabs = json_decode( file_get_contents( $json_path ), true );
        }

        // 2. Produkty
        $products = wc_get_products( array( 'limit' => -1, 'status' => 'publish' ) );

        ob_start();
        ?>
        <div class="tsfl-wrapper">
            
            <?php if ( ! empty( $tabs ) ) : ?>
                <div class="tsfl-tabs">
                    <?php foreach ( $tabs as $index => $tab ) : ?>
                        <button 
                            class="tsfl-tab <?php echo $index === 0 ? 'active' : ''; ?>" 
                            data-cat="<?php echo esc_attr( $tab['cat_slug'] ); ?>"
                        >
                            <?php echo esc_html( $tab['label'] ); ?>
                        </button>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <div class="tsfl-grid">
                <?php if ( ! empty( $products ) ) : ?>
                    <?php foreach ( $products as $product ) : ?>
                        <?php 
                            // Kategorie dla filtra JS
                            $terms = get_the_terms( $product->get_id(), 'product_cat' );
                            $cat_slugs = ($terms && !is_wp_error($terms)) ? wp_list_pluck($terms, 'slug') : array();
                            $cats_string = implode( ',', $cat_slugs );
                            
                            // Obrazek
                            $img_id = $product->get_image_id();
                            $img_url = $img_id ? wp_get_attachment_image_url($img_id, 'large') : wc_placeholder_img_src();

                            // CENA vs ETYKIETA
                            $custom_label = get_post_meta( $product->get_id(), '_ts_front_price_label', true );
                            $price_html = $custom_label ? '<span class="tsfl-custom-label">' . esc_html($custom_label) . '</span>' : $product->get_price_html();
                        ?>
                        <div class="tsfl-card" data-categories="<?php echo esc_attr( $cats_string ); ?>">
                            
                            <div class="tsfl-image-box">
                                <img src="<?php echo esc_url( $img_url ); ?>" loading="lazy" alt="">
                            </div>

                            <div class="tsfl-content">
                                <h3 class="tsfl-title"><?php echo esc_html( $product->get_name() ); ?></h3>
                                
                                <div class="tsfl-price">
                                    <?php echo $price_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                                </div>

                                <a href="<?php echo esc_url( $product->get_permalink() ); ?>" class="tsfl-btn">
                                    KUP USŁUGĘ
                                </a>
                            </div>

                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>

        </div>
        <?php
        return ob_get_clean();
    }
}

TSBB_Front_List::init();