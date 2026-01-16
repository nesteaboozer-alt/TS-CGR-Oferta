<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class TSBB_Front_List {

    public static function init() {
        add_shortcode( 'ts_product_list', array( __CLASS__, 'render' ) );
        add_action( 'wp_enqueue_scripts', array( __CLASS__, 'assets' ) );

        add_action( 'woocommerce_product_options_general_product_data', array( __CLASS__, 'add_custom_field' ) );
        add_action( 'woocommerce_process_product_meta', array( __CLASS__, 'save_custom_field' ) );
    }

    public static function add_custom_field() {
        echo '<div class="options_group">';
        woocommerce_wp_checkbox( array(
            'id'            => '_ts_front_featured',
            'label'         => __( 'Polecany produkt (Banner góra)', 'woocommerce' ),
            'description'   => __( 'Zaznacz, aby produkt pojawił się w sekcji Polecamy.', 'woocommerce' )
        ));
        woocommerce_wp_text_input( array(
            'id'          => '_ts_front_badge',
            'label'       => __( 'Tekst pastylki (Wstążka)', 'woocommerce' ),
            'placeholder' => 'np. NOWOŚĆ lub SPA',
            'description' => __( 'Tekst wyświetlany w rogu zdjęcia produktu.', 'woocommerce' )
        ));
        woocommerce_wp_text_input( array(
            'id'          => '_ts_front_price_label',
            'label'       => __( 'Etykieta Ceny (Front Lista)', 'woocommerce' ),
            'placeholder' => 'np. od 30,00 zł',
            'desc_tip'    => 'true',
            'description' => __( 'Ten tekst zastąpi standardową cenę na liście kafelków [ts_product_list].', 'woocommerce' )
        ));
        echo '</div>';
    }

    public static function save_custom_field( $post_id ) {
        update_post_meta( $post_id, '_ts_front_price_label', isset( $_POST['_ts_front_price_label'] ) ? sanitize_text_field( $_POST['_ts_front_price_label'] ) : '' );
        update_post_meta( $post_id, '_ts_front_featured', isset( $_POST['_ts_front_featured'] ) ? 'yes' : 'no' );
        update_post_meta( $post_id, '_ts_front_badge', isset( $_POST['_ts_front_badge'] ) ? sanitize_text_field( $_POST['_ts_front_badge'] ) : '' );
    }

    public static function assets() {
        wp_register_style( 'tsfl-style', plugin_dir_url( __FILE__ ) . 'style.css', array(), '1.4' );
        wp_register_script( 'tsfl-script', plugin_dir_url( __FILE__ ) . 'script.js', array('jquery'), '1.4', true );
    }

    public static function render( $atts ) {
        wp_enqueue_style( 'tsfl-style' );
        wp_enqueue_script( 'tsfl-script' );

        $json_path = plugin_dir_path( __FILE__ ) . 'config.json';
        $tabs = file_exists( $json_path ) ? json_decode( file_get_contents( $json_path ), true ) : array();
        
        $products = wc_get_products( array( 'limit' => -1, 'status' => 'publish' ) );
        
        // Wyznaczamy startowy slug (pierwsza zakładka), aby wyrenderować ją natychmiast w PHP
        $active_slug = ! empty( $tabs ) ? $tabs[0]['cat_slug'] : '';

        ob_start();
        ?>
        <div class="tsfl-wrapper">
            
            <?php 
            $featured_products = wc_get_products( array(
                'limit'      => 3,
                'status'     => 'publish',
                'meta_key'   => '_ts_front_featured',
                'meta_value' => 'yes'
            ) );

            if ( ! empty( $featured_products ) ) : ?>
                <div class="tsfl-recommended-section">
                    <div class="tsfl-section-header">
                        <span class="tsfl-section-subtitle">Wybrane dla Ciebie</span>
                        <h2 class="tsfl-section-title">Polecane usługi</h2>
                    </div>
                    <div class="tsfl-grid tsfl-featured-grid">
                        <?php foreach ( $featured_products as $product ) : 
                            $badge_text = get_post_meta( $product->get_id(), '_ts_front_badge', true );
                            $custom_label = get_post_meta( $product->get_id(), '_ts_front_price_label', true );
                            $price_html = $custom_label ? '<span class="tsfl-custom-label">' . esc_html($custom_label) . '</span>' : $product->get_price_html();
                            $img_id = $product->get_image_id();
                            $img_url = $img_id ? wp_get_attachment_image_url($img_id, 'large') : wc_placeholder_img_src();
                        ?>
                            <div class="tsfl-card is-featured">
                                <div class="tsfl-image-box">
                                    <?php if ( $badge_text ) : ?>
                                        <div class="tsfl-badge"><?php echo esc_html( $badge_text ); ?></div>
                                    <?php endif; ?>
                                    <img src="<?php echo esc_url( $img_url ); ?>" alt="">
                                </div>
                                <div class="tsfl-content">
                                    <h3 class="tsfl-title"><?php echo esc_html( $product->get_name() ); ?></h3>
                                    <div class="tsfl-price"><?php echo $price_html; ?></div>
                                    <a href="<?php echo esc_url( $product->get_permalink() ); ?>" class="tsfl-btn">KUP USŁUGĘ</a>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endif; ?> 
            
            <?php if ( ! empty( $tabs ) ) : ?>
                <div class="tsfl-tabs" id="ts-oferta-filtry">
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

            <div class="tsfl-grid tsfl-main-grid">
                <?php if ( ! empty( $products ) ) : ?>
                    <?php foreach ( $products as $product ) : ?>
                        <?php 
                            $terms = get_the_terms( $product->get_id(), 'product_cat' );
                            $cat_slugs = ($terms && !is_wp_error($terms)) ? wp_list_pluck($terms, 'slug') : array();
                            $cats_string = implode( ',', $cat_slugs );
                            
                            $img_id = $product->get_image_id();
                            $img_url = $img_id ? wp_get_attachment_image_url($img_id, 'large') : wc_placeholder_img_src();

                            $custom_label = get_post_meta( $product->get_id(), '_ts_front_price_label', true );
                            $price_html = $custom_label ? '<span class="tsfl-custom-label">' . esc_html($custom_label) . '</span>' : $product->get_price_html();

                            // PHP decyduje, czy kafelek jest widoczny na start
                            $is_visible = ( empty($active_slug) || in_array($active_slug, $cat_slugs) );
                        ?>
                        <div class="tsfl-card <?php echo $is_visible ? 'tsfl-is-visible' : ''; ?>" data-categories="<?php echo esc_attr( $cats_string ); ?>">
                            
                            <div class="tsfl-image-box">
                                <?php 
                                    $badge_text_list = get_post_meta( $product->get_id(), '_ts_front_badge', true );
                                    if ( $badge_text_list ) : 
                                ?>
                                    <div class="tsfl-badge"><?php echo esc_html( $badge_text_list ); ?></div>
                                <?php endif; ?>
                                <img src="<?php echo esc_url( $img_url ); ?>" loading="lazy" alt="">
                            </div>

                            <div class="tsfl-content">
                                <h3 class="tsfl-title"><?php echo esc_html( $product->get_name() ); ?></h3>
                                <div class="tsfl-price">
                                    <?php echo $price_html; ?>
                                </div>
                                <a href="<?php echo esc_url( $product->get_permalink() ); ?>" class="tsfl-btn">KUP USŁUGĘ</a>
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