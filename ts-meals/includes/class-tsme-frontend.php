<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Frontend TS Hotel Meals – formularz, koszyk, zamówienie.
 * Wersja 2.1: SCALONA - Wszystkie funkcje (Stare + Nowe).
 */
class TSME_Frontend {

    public static function init() {
        add_filter( 'template_include', array( __CLASS__, 'load_custom_template' ), 99 );
        // Assety i Formularz
        add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
        add_action( 'woocommerce_before_add_to_cart_button', array( __CLASS__, 'render_form' ) );

        // Koszyk i Zamówienie
        add_filter( 'woocommerce_add_cart_item_data', array( __CLASS__, 'add_cart_item_data' ), 10, 3 );
        add_filter( 'woocommerce_get_item_data', array( __CLASS__, 'display_item_data' ), 10, 2 );
        add_action( 'woocommerce_checkout_create_order_line_item', array( __CLASS__, 'add_order_item_meta' ), 10, 4 );

        // Ceny (Logika Sezonów)
        add_action( 'woocommerce_before_calculate_totals', array( __CLASS__, 'calculate_meal_price' ), 20, 1 );

        // AJAX
        add_action( 'wp_ajax_tsme_calculate_summary', array( __CLASS__, 'ajax_calculate_summary' ) );
        add_action( 'wp_ajax_nopriv_tsme_calculate_summary', array( __CLASS__, 'ajax_calculate_summary' ) );

        // Bezpieczeństwo / Blokady (Stare funkcje)
        add_filter( 'woocommerce_add_to_cart_validation', array( __CLASS__, 'block_external_add_to_cart_for_meals' ), 10, 6 );
        add_action( 'woocommerce_order_item_meta_end', array( __CLASS__, 'render_order_item_details' ), 10, 4 );
        
        // Blokada "Zamów ponownie"
        add_filter( 'woocommerce_my_account_my_orders_actions', array( __CLASS__, 'remove_order_again_action' ), 10, 2 );
        add_filter( 'woocommerce_valid_order_statuses_for_order_again', array( __CLASS__, 'disable_order_again_statuses' ), 10, 1 );
        
        // Redirecty
        add_filter( 'woocommerce_add_to_cart_redirect', array( __CLASS__, 'redirect_meal_from_non_product_page' ) );
    }

    public static function is_meal_product( $product_id ) {
        if ( ! $product_id ) return false;
        $enabled = get_post_meta( $product_id, TSME_Admin_Product::META_ENABLED, true );
        return $enabled === 'yes';
    }

    public static function enqueue_assets() {
        if ( ! function_exists( 'is_product' ) || ! is_product() ) {
            return;
        }

        $product_id = get_queried_object_id();
        if ( ! self::is_meal_product( $product_id ) ) {
            return;
        }

        // Wymuszenie wersji (timestamp) - Fix na cache mobile
        $ver = date("YmdHi"); 

                wp_enqueue_style( 'tsme-frontend', TSME_URL . 'assets/css/tsme-frontend.css', array(), $ver );
        wp_enqueue_script( 'tsme-frontend', TSME_URL . 'assets/js/tsme-frontend.js', array( 'jquery' ), $ver, true );

        // Typ posiłku (przyda się JS-owi, np. dla Eventu)
        $meal_type = get_post_meta( $product_id, TSME_Admin_Product::META_MEAL_TYPE, true );

        wp_localize_script( 'tsme-frontend', 'tsme_vars', array(
            'ajaxurl'    => admin_url( 'admin-ajax.php' ),
            'product_id' => $product_id,
            'cart_url'   => wc_get_cart_url(), // <--- TA LINIA JEST KLUCZOWA
            'meal_type'  => $meal_type,
        ) );
    }


    public static function render_form() {
        global $product;
        if ( ! $product instanceof WC_Product ) return;
        $product_id = $product->get_id();
        if ( ! self::is_meal_product( $product_id ) ) return;

        $pricing_matrix = get_post_meta( $product_id, TSME_Admin_Product::META_PRICING_MATRIX, true );
        wc_get_template(
            'tsme-meal-form.php',
            array(
                'product'        => $product,
                'meal_type'      => get_post_meta( $product_id, TSME_Admin_Product::META_MEAL_TYPE, true ),
                'pricing_matrix' => $pricing_matrix,
            ),
            '',
            TSME_DIR . 'templates/'
        );
    }

    public static function add_cart_item_data( $cart_item_data, $product_id, $variation_id ) {
        if ( ! self::is_meal_product( $product_id ) ) return $cart_item_data;
        $fields = array( 'object'=>'tsme_object', 'room_number'=>'tsme_room_number', 'stay_from'=>'tsme_stay_from', 'stay_to'=>'tsme_stay_to', 'adults'=>'tsme_adults', 'children'=>'tsme_children' );
        $data = array();
        foreach ( $fields as $key => $post_key ) {
            if ( isset( $_POST[ $post_key ] ) && $_POST[ $post_key ] !== '' ) {
                $data[ $key ] = sanitize_text_field( wp_unslash( $_POST[ $post_key ] ) );
            }
        }
        if ( ! empty( $data ) ) $cart_item_data['tsme_meal'] = $data;
        return $cart_item_data;
    }

    /**
     * AJAX: Oblicza cenę z walidacją dostępności (Sezony/Wyjątki).
     */
    public static function ajax_calculate_summary() {
        $product_id   = isset( $_POST['product_id'] ) ? absint( $_POST['product_id'] ) : 0;
        $object_name  = isset( $_POST['object'] ) ? sanitize_text_field( $_POST['object'] ) : '';
        $stay_from    = isset( $_POST['stay_from'] ) ? sanitize_text_field( $_POST['stay_from'] ) : '';
        $stay_to      = isset( $_POST['stay_to'] ) ? sanitize_text_field( $_POST['stay_to'] ) : '';
        $adults       = isset( $_POST['adults'] ) ? absint( $_POST['adults'] ) : 0;
        $children     = isset( $_POST['children'] ) ? absint( $_POST['children'] ) : 0;

        if ( ! $product_id || ! $object_name || ! $stay_from || ! $stay_to ) {
            wp_send_json_error( 'Brak danych.' );
        }

        $pricing_matrix = get_post_meta( $product_id, TSME_Admin_Product::META_PRICING_MATRIX, true );
        $building = null;
        if ( ! empty( $pricing_matrix ) ) {
            foreach ( $pricing_matrix as $row ) {
                if ( mb_strtolower( trim( $row['name'] ) ) === mb_strtolower( $object_name ) ) {
                    $building = $row;
                    break;
                }
            }
        }

        if ( ! $building ) wp_send_json_error( 'Nie znaleziono cennika dla wybranego obiektu.' );

        $seasons    = ! empty( $building['seasons'] ) ? $building['seasons'] : array();
        $exceptions = ! empty( $building['exceptions'] ) ? $building['exceptions'] : array();

        $start_ts = strtotime( $stay_from . ' 12:00:00' );
        $end_ts   = strtotime( $stay_to . ' 12:00:00' );
        if ( ! $start_ts || ! $end_ts || $end_ts < $start_ts ) wp_send_json_error( 'Błędny zakres dat.' );

        $total_price = 0;
        $current_ts  = $start_ts;
        $meal_type   = strtolower( (string) get_post_meta( $product_id, TSME_Admin_Product::META_MEAL_TYPE, true ) );
        
        // Tablica komunikatów (obiekty z ikoną i tekstem)
        $messages = array();

                // 1b. DODAJ KOMENTARZ EVENTU (jeśli produkt jest typu Event)
        $meal_type = get_post_meta( $product_id, TSME_Admin_Product::META_MEAL_TYPE, true );
        if ( 'event' === strtolower( (string) $meal_type ) ) {
            $event_comment = get_post_meta( $product_id, TSME_Admin_Product::META_EVENT_COMMENT, true );
            if ( ! empty( $event_comment ) ) {
                $messages[] = array(
                    'icon' => 'ℹ️',
                    'text' => $event_comment,
                );
            }
        }

        $days_counted = 0;
        $missing_offer = false;


        while ( $current_ts <= $end_ts ) {
            $is_served = false;
            $is_breakfast = ( strpos( $meal_type, 'sniada' ) !== false || strpos( $meal_type, 'breakfast' ) !== false );
            $is_dinner    = ( strpos( $meal_type, 'obiad' ) !== false || strpos( $meal_type, 'dinner' ) !== false );

            if ( $is_breakfast ) { if ( $current_ts > $start_ts && $current_ts <= $end_ts ) $is_served = true; } 
            elseif ( $is_dinner ) { if ( $current_ts >= $start_ts && $current_ts < $end_ts ) $is_served = true; } 
            else { $is_served = true; }

            if ( $is_served ) {
                $days_counted++;
                $day_adult = null; $day_child = null; $found = false;

                // A. Wyjątki
                foreach ( $exceptions as $ex ) {
                    $ex_s = strtotime( $ex['from'] . ' 00:00:00' );
                    $ex_e = strtotime( $ex['to'] . ' 23:59:59' );
                    if ( $current_ts >= $ex_s && $current_ts <= $ex_e ) {
                        $day_adult = (float)$ex['adult']; $day_child = (float)$ex['child'];
                        
                        // Dodaj komunikat wyjątku (unikamy duplikatów)
                        if(!empty($ex['msg'])) {
                            $key = md5($ex['msg']);
                            $exists = false;
                            foreach($messages as $m) { if($m['text'] === $ex['msg']) $exists = true; }
                            if(!$exists) {
                                $messages[] = array('icon' => 'ℹ️', 'text' => $ex['msg']);
                            }
                        }
                        $found = true; break;
                    }
                }

                // B. Sezony
                if ( ! $found ) {
                    foreach ( $seasons as $seas ) {
                        $ss_s = strtotime( $seas['from'] . ' 00:00:00' );
                        $ss_e = strtotime( $seas['to'] . ' 23:59:59' );
                        if ( $current_ts >= $ss_s && $current_ts <= $ss_e ) {
                            $day_adult = (float)$seas['adult']; $day_child = (float)$seas['child'];
                            $found = true; break;
                        }
                    }
                }

                if ( ! $found ) { $missing_offer = true; break; }

                $total_price += ( $adults * $day_adult ) + ( $children * $day_child );
            }
            $current_ts = strtotime( '+1 day', $current_ts );
        }

        if ( $missing_offer ) wp_send_json_error( 'Oferta na wybrany termin nie jest dostępna (brak cennika).' );

        if ( $days_counted == 0 ) {
            wp_send_json_success( array( 'price_html' => 'Brak posiłków w tym terminie', 'messages' => [] ) );
        } else {
            wp_send_json_success( array(
                'price_html' => wc_price( $total_price ),
                'messages'   => array_values( $messages ),
            ) );
        }
    }

    /**
     * Oblicza cenę w KOSZYKU (Sezony).
     */
    public static function calculate_meal_price( $cart ) {
        if ( is_admin() && ! defined( 'DOING_AJAX' ) ) return;

        foreach ( $cart->get_cart() as $cart_item ) {
            $product_id = $cart_item['data']->get_id();
            if ( ! self::is_meal_product( $product_id ) ) continue;
            if ( empty( $cart_item['tsme_meal'] ) ) continue;

            $data = $cart_item['tsme_meal'];
            $pricing_matrix = get_post_meta( $product_id, TSME_Admin_Product::META_PRICING_MATRIX, true );
            $building = null;
            if ( ! empty( $pricing_matrix ) ) {
                foreach ( $pricing_matrix as $row ) {
                    if ( mb_strtolower( trim( $row['name'] ) ) === mb_strtolower( trim($data['object']) ) ) {
                        $building = $row; break;
                    }
                }
            }
            if ( ! $building ) continue;

            $seasons = !empty($building['seasons']) ? $building['seasons'] : array();
            $exceptions = !empty($building['exceptions']) ? $building['exceptions'] : array();

            $start_ts = strtotime( $data['stay_from'] . ' 12:00:00' );
            $end_ts   = strtotime( $data['stay_to'] . ' 12:00:00' );
            if(!$start_ts || !$end_ts) continue;

            $total_price = 0;
            $current_ts = $start_ts;
            $meal_type = strtolower( (string) get_post_meta( $product_id, TSME_Admin_Product::META_MEAL_TYPE, true ) );
            $adults = (int)$data['adults'];
            $children = (int)$data['children'];

            while ( $current_ts <= $end_ts ) {
                $is_served = false;
                $is_breakfast = ( strpos( $meal_type, 'sniada' ) !== false || strpos( $meal_type, 'breakfast' ) !== false );
                $is_dinner    = ( strpos( $meal_type, 'obiad' ) !== false || strpos( $meal_type, 'dinner' ) !== false );

                if ( $is_breakfast ) { if($current_ts > $start_ts && $current_ts <= $end_ts) $is_served = true; }
                elseif ( $is_dinner ) { if($current_ts >= $start_ts && $current_ts < $end_ts) $is_served = true; }
                else { $is_served = true; }

                if ( $is_served ) {
                    $day_adult = null; $day_child = null; $found = false;
                    foreach($exceptions as $ex) {
                        if($current_ts >= strtotime($ex['from']) && $current_ts <= strtotime($ex['to'].' 23:59:59')) {
                            $day_adult = (float)$ex['adult']; $day_child = (float)$ex['child']; $found = true; break;
                        }
                    }
                    if(!$found) {
                        foreach($seasons as $s) {
                            if($current_ts >= strtotime($s['from']) && $current_ts <= strtotime($s['to'].' 23:59:59')) {
                                $day_adult = (float)$s['adult']; $day_child = (float)$s['child']; $found = true; break;
                            }
                        }
                    }
                    if($found) $total_price += ($adults * $day_adult) + ($children * $day_child);
                }
                $current_ts = strtotime('+1 day', $current_ts);
            }
            $cart_item['data']->set_price( $total_price );
        }
    }

    public static function display_item_data( $item_data, $cart_item ) {
        if ( empty( $cart_item['tsme_meal'] ) ) return $item_data;
        $data = $cart_item['tsme_meal'];
        
        // Standardowe pola
        $map = array(
            'object'      => __( 'Obiekt', 'ts-hotel-meals' ),
            'room_number' => __( 'Imię i nazwisko', 'ts-hotel-meals' ),
            'stay_from'   => __( 'Pobyt od', 'ts-hotel-meals' ),
            'stay_to'     => __( 'Pobyt do', 'ts-hotel-meals' ),
            'adults'      => __( 'Dorośli', 'ts-hotel-meals' ),
            'children'    => __( 'Dzieci 4–17', 'ts-hotel-meals' ),
        );
        foreach ( $map as $key => $label ) {
            if ( ! empty( $data[ $key ] ) ) {
                $item_data[] = array( 'name' => $label, 'value' => wc_clean( $data[ $key ] ) );
            }
        }

        if ( isset( $cart_item['tsme_meal'] ) ) {
            $pricing_matrix = get_post_meta( $cart_item['product_id'], TSME_Admin_Product::META_PRICING_MATRIX, true );
            $building = null;
            if ( $pricing_matrix ) { 
                foreach ( $pricing_matrix as $r ) {
                    if ( trim( $r['name'] ) == trim( $data['object'] ) ) { 
                        $building = $r; 
                        break; 
                    } 
                } 
            }
            
            // 1. OPIS BUDYNKU (DOMEK)
            if ( $building && ! empty( $building['description'] ) ) {
                $item_data[] = array(
                    'name'    => __( 'Info o obiekcie', 'ts-hotel-meals' ),
                    'value'   => $building['description'],
                    'display' => '🏠 ' . esc_html( $building['description'] )
                );
            }

                        // 2. KOMUNIKATY DAT (WYJĄTKI)
            if ( $building && ! empty( $building['exceptions'] ) ) {
                $s = strtotime( $data['stay_from'] . ' 12:00:00' ); 
                $e = strtotime( $data['stay_to'] . ' 12:00:00' );
                $msgs = array();
                
                foreach ( $building['exceptions'] as $ex ) {
                    if ( empty( $ex['msg'] ) ) continue;
                    $ex_s = strtotime( $ex['from'] ); 
                    $ex_e = strtotime( $ex['to'] . ' 23:59:59' );
                    
                    if ( $s <= $ex_e && $e >= $ex_s ) {
                        $msgs[ $ex['msg'] ] = $ex['msg'];
                    }
                }
                foreach ( $msgs as $m ) {
                    $item_data[] = array(
                        'name'    => 'Info', 
                        'value'   => $m, 
                        'display' => '<span style="display:inline-block; padding:3px 8px; background:#eef5ff; border-radius:6px; font-size:0.9em;">ℹ️ ' . esc_html( $m ) . '</span>'
                    );
                }
            }

            // 3. KOMENTARZ EVENTU – TAKI SAM STYL JAK WYJĄTKI
            $product_id = isset( $cart_item['product_id'] ) ? $cart_item['product_id'] : 0;
            if ( $product_id ) {
                $meal_type = get_post_meta( $product_id, TSME_Admin_Product::META_MEAL_TYPE, true );
                if ( 'event' === strtolower( (string) $meal_type ) ) {
                    $event_comment = get_post_meta( $product_id, TSME_Admin_Product::META_EVENT_COMMENT, true );
                    if ( ! empty( $event_comment ) ) {
                        $item_data[] = array(
                            'name'    => 'Info',
                            'value'   => $event_comment,
                            'display' => '<span style="display:inline-block; padding:3px 8px; background:#eef5ff; border-radius:6px; font-size:0.9em;">ℹ️ ' . esc_html( $event_comment ) . '</span>'
                        );
                    }
                }
            }
        }
        return $item_data;
    }


    public static function add_order_item_meta( $item, $cart_item_key, $values, $order ) {
        if ( empty( $values['tsme_meal'] ) ) {
            return;
        }

        $data = $values['tsme_meal'];

        // Pola podstawowe
        $fields = array( 'object', 'room_number', 'stay_from', 'stay_to', 'adults', 'children' );
        foreach ( $fields as $field ) {
            if ( isset( $data[ $field ] ) && $data[ $field ] !== '' ) {
                $item->add_meta_data( '_tsme_' . $field, $data[ $field ], true );
            }
        }

        // Pobieranie dodatkowych danych z macierzy
        $product_id     = $item->get_product_id();
        $pricing_matrix = get_post_meta( $product_id, TSME_Admin_Product::META_PRICING_MATRIX, true );
        $building       = null;

        if ( is_array( $pricing_matrix ) && isset( $data['object'] ) ) {
            foreach ( $pricing_matrix as $row ) {
                if ( isset( $row['name'] ) && $row['name'] === $data['object'] ) {
                    $building = $row;
                    break;
                }
            }
        }

        if ( $building ) {
            // 1. Opis budynku
            if ( ! empty( $building['description'] ) ) {
                $item->add_meta_data( '_tsme_building_desc', $building['description'], true );
            }

            // 2. Komunikaty z wyjątków + komentarz Eventu
            $msgs = array();

            if ( ! empty( $building['exceptions'] ) && ! empty( $data['stay_from'] ) && ! empty( $data['stay_to'] ) ) {
                $s = strtotime( $data['stay_from'] . ' 12:00:00' );
                $e = strtotime( $data['stay_to'] . ' 12:00:00' );

                foreach ( $building['exceptions'] as $ex ) {
                    if ( empty( $ex['msg'] ) || empty( $ex['from'] ) || empty( $ex['to'] ) ) {
                        continue;
                    }

                    $ex_s = strtotime( $ex['from'] );
                    $ex_e = strtotime( $ex['to'] . ' 23:59:59' );

                    if ( $s <= $ex_e && $e >= $ex_s ) {
                        $msgs[ $ex['msg'] ] = $ex['msg'];
                    }
                }
            }

            // Dodatkowy komentarz dla typu "Event"
            $meal_type = get_post_meta( $product_id, TSME_Admin_Product::META_MEAL_TYPE, true );
            if ( 'event' === strtolower( (string) $meal_type ) ) {
                $event_comment = get_post_meta( $product_id, TSME_Admin_Product::META_EVENT_COMMENT, true );
                if ( ! empty( $event_comment ) ) {
                    $msgs[ $event_comment ] = $event_comment;
                }
            }

            if ( ! empty( $msgs ) ) {
                $item->add_meta_data( '_tsme_messages', array_values( $msgs ), true );
            }
        }
    }


    // 4. Stare funkcje bezpieczeństwa i redirecty
    public static function block_external_add_to_cart_for_meals($passed, $product_id, $quantity, $variation_id = 0, $variations = array(), $cart_item_data = array()) {
        if(!$passed) return false;
        if(!self::is_meal_product($product_id)) return $passed;
        if(!empty($cart_item_data['tsme_meal']) || isset($_POST['tsme_object'])) return $passed;
        wc_add_notice(__('Posiłki hotelowe muszą być dodane z poziomu strony produktu.', 'ts-hotel-meals'), 'error');
        return false;
    }

    public static function redirect_meal_from_non_product_page($url) { 
        if(!isset($_REQUEST['add-to-cart'])) return $url;
        $pid = absint($_REQUEST['add-to-cart']);
        if(self::is_meal_product($pid) && !isset($_POST['tsme_room_number'])) return get_permalink($pid);
        return $url;
    }

    public static function render_order_item_details( $item_id, $item, $order, $plain_text = false ) {
        $object = $item->get_meta( '_tsme_object', true );
        if ( ! $object ) return;

        $room      = $item->get_meta( '_tsme_room_number', true );
        $stay_from = $item->get_meta( '_tsme_stay_from', true );
        $stay_to   = $item->get_meta( '_tsme_stay_to', true );
        $adults    = $item->get_meta( '_tsme_adults', true );   // Pobieramy
        $children  = $item->get_meta( '_tsme_children', true ); // Pobieramy
        $code      = $item->get_meta( '_tsme_code', true );
        $b_desc    = $item->get_meta( '_tsme_building_desc', true ); // Nowe pole

        if ( $plain_text ) {
            echo "\n Obiekt: $object" . ($b_desc ? " ($b_desc)" : "") . ", Pokój: $room \n";
            echo " Pobyt: $stay_from – $stay_to \n";
            echo " Osoby: " . ($adults ? $adults : 0) . " dor., " . ($children ? $children : 0) . " dz.\n";
            if ( $code ) echo " Kod: $code \n";
        } else {
            ?>
            <div class="tsme-order-details" style="font-size:12px;color:#555;margin-top:5px;">
                <div>
                    <strong>Obiekt:</strong> <?php echo esc_html( $object ); ?>
                    <?php if ( $b_desc ) : ?>
                        <br><em style="color:#666;">🏠 <?php echo esc_html( $b_desc ); ?></em>
                    <?php endif; ?>
                </div>
                
                <div><strong>Gość:</strong> <?php echo esc_html( $room ); ?></div>
                
                <div><strong>Pobyt:</strong> <?php echo esc_html( $stay_from . ' – ' . $stay_to ); ?></div>
                
                <div>
                    <strong>Osoby:</strong> 
                    <?php echo esc_html( ($adults ? $adults : 0) . ' dor., ' . ($children ? $children : 0) . ' dz.' ); ?>
                </div>

                <?php if ( $code ) : ?>
                    <div style="margin-top:3px;"><strong>Kod awaryjny:</strong> <?php echo esc_html( $code ); ?></div>
                <?php endif; ?>
            </div>
            <?php
        }
    }

    public static function remove_order_again_action($actions, $order) {
        if(isset($actions['order-again'])) unset($actions['order-again']);
        return $actions;
    }
    public static function disable_order_again_statuses($statuses) { return array(); }
    /**
 * Wymusza własny szablon pliku dla produktów Posiłków (Layout Apple).
 */
public static function load_custom_template( $template ) {
    if ( is_singular( 'product' ) ) {
        $product_id = get_queried_object_id();
        if ( self::is_meal_product( $product_id ) ) {
            $new_template = TSME_DIR . 'templates/single-product-meal.php';
            if ( file_exists( $new_template ) ) {
                return $new_template;
            }
        }
        
    }
    return $template;
}

}