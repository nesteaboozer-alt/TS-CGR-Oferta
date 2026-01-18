<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class TS_B2B_Product_Wall {

    public static function init() {
        // 1) Checkbox w produkcie
        add_action( 'woocommerce_product_options_general_product_data', array( __CLASS__, 'add_b2b_visibility_field' ) );
        add_action( 'woocommerce_process_product_meta', array( __CLASS__, 'save_b2b_visibility_field' ) );

        // 2) Mur produktowy: WP_Query
        add_action( 'pre_get_posts', array( __CLASS__, 'filter_products_globally' ), 999999 );

        // 2b) Mur produktowy: WC API / shortcodes / blocks
        add_filter( 'woocommerce_product_data_store_cpt_get_products_query', array( __CLASS__, 'filter_wc_get_products_query' ), 999999, 2 );
        add_filter( 'woocommerce_shortcode_products_query', array( __CLASS__, 'filter_wc_shortcode_query' ), 999999, 3 );
        add_filter( 'woocommerce_blocks_product_grid_query_args', array( __CLASS__, 'filter_wc_blocks_query' ), 999999, 2 );

        // 3) Blokada direct link
        add_action( 'template_redirect', array( __CLASS__, 'block_direct_access' ) );

        // 4) Ostatnia linia obrony
        add_filter( 'woocommerce_product_is_visible', array( __CLASS__, 'check_final_visibility' ), 999999, 2 );
    }

    private static function build_xor_b2b_meta_query_clause() {
        if ( TS_B2B_Core::is_strictly_b2b() ) {
            // B2B: tylko B2B
            return array(
                'key'     => TS_B2B_Core::META_B2B_ONLY,
                'value'   => 'yes',
                'compare' => '=',
            );
        }

        // B2C/Gość: wszystko oprócz B2B (lub bez meta)
        return array(
            'relation' => 'OR',
            array(
                'key'     => TS_B2B_Core::META_B2B_ONLY,
                'value'   => 'yes',
                'compare' => '!=',
            ),
            array(
                'key'     => TS_B2B_Core::META_B2B_ONLY,
                'compare' => 'NOT EXISTS',
            ),
        );
    }

    public static function filter_products_globally( $query ) {
        if ( is_admin() ) return;
        if ( current_user_can( 'manage_options' ) ) return;

        $pt = $query->get('post_type');
        $is_product_query = ( $pt === 'product' ) || ( is_array($pt) && in_array('product', $pt, true) );

        if ( ! $is_product_query ) {
            // WC czasem używa wc_query=product_query
            if ( ! isset($query->query_vars['wc_query']) || $query->query_vars['wc_query'] !== 'product_query' ) return;
        }

        $meta_query   = (array) $query->get( 'meta_query' );
        $meta_query[] = self::build_xor_b2b_meta_query_clause();
        $query->set( 'meta_query', $meta_query );
    }

    public static function filter_wc_get_products_query( $query, $query_vars ) {
        if ( is_admin() ) return $query;
        if ( current_user_can( 'manage_options' ) ) return $query;

        if ( empty( $query['meta_query'] ) || ! is_array( $query['meta_query'] ) ) {
            $query['meta_query'] = array();
        }
        $query['meta_query'][] = self::build_xor_b2b_meta_query_clause();
        return $query;
    }

    public static function filter_wc_shortcode_query( $query_args, $atts, $type ) {
        if ( is_admin() ) return $query_args;
        if ( current_user_can( 'manage_options' ) ) return $query_args;

        if ( empty( $query_args['meta_query'] ) || ! is_array( $query_args['meta_query'] ) ) {
            $query_args['meta_query'] = array();
        }
        $query_args['meta_query'][] = self::build_xor_b2b_meta_query_clause();
        return $query_args;
    }

    public static function filter_wc_blocks_query( $query_args, $block_instance ) {
        if ( is_admin() ) return $query_args;
        if ( current_user_can( 'manage_options' ) ) return $query_args;

        if ( empty( $query_args['meta_query'] ) || ! is_array( $query_args['meta_query'] ) ) {
            $query_args['meta_query'] = array();
        }
        $query_args['meta_query'][] = self::build_xor_b2b_meta_query_clause();
        return $query_args;
    }

    public static function block_direct_access() {
        if ( is_admin() || ! is_singular( 'product' ) ) return;
        if ( current_user_can( 'manage_options' ) ) return;

        $product_id  = get_queried_object_id();
        $is_b2b_prod = get_post_meta( $product_id, TS_B2B_Core::META_B2B_ONLY, true ) === 'yes';
        $is_b2b_user = TS_B2B_Core::is_strictly_b2b();

        // XOR: jeśli nie pasuje do roli, out
        if ( ( $is_b2b_prod && ! $is_b2b_user ) || ( ! $is_b2b_prod && $is_b2b_user ) ) {
            wp_redirect( home_url( '/' ) );
            exit;
        }
    }

    public static function check_final_visibility( $visible, $product_id ) {
        if ( is_admin() || current_user_can( 'manage_options' ) ) return $visible;

        $is_b2b_prod = get_post_meta( $product_id, TS_B2B_Core::META_B2B_ONLY, true ) === 'yes';
        $is_b2b_user = TS_B2B_Core::is_strictly_b2b();

        if ( $is_b2b_user ) return $is_b2b_prod ? $visible : false;
        return $is_b2b_prod ? false : $visible;
    }

    public static function add_b2b_visibility_field() {
        if ( ! function_exists('woocommerce_wp_checkbox') ) return;

        woocommerce_wp_checkbox( array(
            'id'          => TS_B2B_Core::META_B2B_ONLY,
            'label'       => 'Tylko dla B2B (ukryj dla B2C)',
            'description' => 'Jeśli zaznaczone: produkt widoczny wyłącznie dla roli b2b_partner.',
        ) );
    }

    public static function save_b2b_visibility_field( $post_id ) {
        $val = isset( $_POST[ TS_B2B_Core::META_B2B_ONLY ] ) ? 'yes' : '';
        update_post_meta( $post_id, TS_B2B_Core::META_B2B_ONLY, $val );
    }
}
