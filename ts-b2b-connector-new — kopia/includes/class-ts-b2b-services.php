<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class TS_B2B_Services {

    const ENDPOINT = 'b2b-services';
    const AJAX_ACTION_CANCEL = 'ts_b2b_cancel_meal';
    const NONCE_ACTION_CANCEL = 'ts_b2b_cancel_meal';

    public static function init() {
        add_filter( 'woocommerce_account_menu_items', array( __CLASS__, 'add_b2b_menu_item' ) );
        add_action( 'init', array( __CLASS__, 'add_b2b_endpoint' ) );
        add_action( 'woocommerce_account_' . self::ENDPOINT . '_endpoint', array( __CLASS__, 'render_b2b_services_page' ) );

        add_action( 'wp_ajax_' . self::AJAX_ACTION_CANCEL, array( __CLASS__, 'ajax_handle_cancellation' ) );
        // Brak nopriv – anulować mogą tylko zalogowani
    }

    /**
     * Bezpiecznik roli – wspiera TS_B2B_Core::is_strictly_b2b() jeśli istnieje,
     * a jak nie, to sprawdza rolę bezpośrednio.
     */
    private static function is_strictly_b2b() {
        if ( class_exists( 'TS_B2B_Core' ) && method_exists( 'TS_B2B_Core', 'is_strictly_b2b' ) ) {
            return TS_B2B_Core::is_strictly_b2b();
        }
        if ( ! is_user_logged_in() ) return false;
        if ( current_user_can( 'manage_options' ) ) return false;
        $u = wp_get_current_user();
        return ( $u && in_array( 'b2b_partner', (array) $u->roles, true ) );
    }

    public static function add_b2b_menu_item( $items ) {
        if ( self::is_strictly_b2b() ) {
            $logout = $items['customer-logout'] ?? null;
            if ( $logout !== null ) unset( $items['customer-logout'] );

            $items[ self::ENDPOINT ] = 'Moje Usługi (B2B)';

            if ( $logout !== null ) $items['customer-logout'] = $logout;
        }
        return $items;
    }

    public static function add_b2b_endpoint() {
    add_rewrite_endpoint( self::ENDPOINT, EP_ROOT | EP_PAGES );
}


    public static function render_b2b_services_page() {
        if ( ! self::is_strictly_b2b() ) {
    echo '<p>Brak dostępu.</p>';
    return;
}

    if ( ! function_exists('wc_get_orders') ) {
        echo '<p>WooCommerce nie jest aktywne.</p>';
        return;
    }

    $user_id = get_current_user_id();
    if ( ! $user_id ) {
        echo '<p>Musisz być zalogowany.</p>';
        return;
    }

    // 1) Pobierz ID zamówień użytkownika (działa z HPOS i bez HPOS)
    $order_ids = wc_get_orders(array(
        'customer_id' => $user_id,
        'limit'       => -1,
        'return'      => 'ids',
        'status'      => array_keys( wc_get_order_statuses() ),
    ));

        // Jeśli brak zamówień po customer_id (np. zamówienie poszło jako "Gość"),
    // robimy fallback: szukamy order_id w tabeli kodów i filtrujemy tylko te,
    // które faktycznie należą do tego usera (HPOS-safe).
    if ( empty($order_ids) ) {

        global $wpdb;
        $table = $wpdb->prefix . 'tsme_meal_codes';

        // pobierz unikalne order_id z tabeli kodów (ostatnie 200 dla bezpieczeństwa)
        $raw_order_ids = $wpdb->get_col( "SELECT DISTINCT order_id FROM {$table} ORDER BY id DESC LIMIT 200" );

        $order_ids = array();
        if ( $raw_order_ids ) {
            foreach ( $raw_order_ids as $oid ) {
                $order = wc_get_order( (int) $oid );
                if ( ! $order ) continue;

                // owner check (HPOS-safe)
                if ( (int) $order->get_customer_id() === (int) $user_id ) {
                    $order_ids[] = (int) $oid;
                }
            }
        }

        // dalej nic nie znaleźliśmy => brak rezerwacji
        if ( empty($order_ids) ) {
            echo '<h3>Twoje zarezerwowane posiłki</h3>';
            echo '<p>Brak aktywnych rezerwacji.</p>';
            return;
        }
    }


    global $wpdb;
    $table = $wpdb->prefix . 'tsme_meal_codes';

    // 2) Pobierz usługi po order_id IN (order_ids)
    $placeholders = implode(',', array_fill(0, count($order_ids), '%d'));
    $sql = "SELECT * FROM $table
            WHERE order_id IN ($placeholders)
              AND status != 'void'
            ORDER BY stay_from ASC";

    $services = $wpdb->get_results(
        $wpdb->prepare($sql, $order_ids),
        ARRAY_A
    );

    echo '<h3>Twoje zarezerwowane posiłki</h3>';
    if ( ! $services ) {
        echo '<p>Brak aktywnych rezerwacji.</p>';
        return;
    }

    echo '<table class="shop_table"><thead><tr><th>Usługa</th><th>Obiekt</th><th>Data</th><th>Akcja</th></tr></thead><tbody>';

    $nonce = wp_create_nonce('ts_b2b_cancel_meal');

    foreach ( $services as $row ) {
        $can_cancel = ( strtotime( $row['stay_from'] ) - time() > 7 * DAY_IN_SECONDS );

        echo '<tr>
            <td>'.esc_html($row['meal_type']).'</td>
            <td>'.esc_html($row['object_label']).'</td>
            <td>'.esc_html($row['stay_from']).'</td>
            <td>';

        if ( $can_cancel ) {
            echo '<button class="button ts-cancel"
                    data-id="'.(int)$row['id'].'"
                    data-nonce="'.esc_attr($nonce).'">Anuluj</button>';
        } else {
            echo 'Po terminie';
        }

        echo '</td></tr>';
    }

    echo '</tbody></table>';
    ?>
    <script>
    (function($){
        $('.ts-cancel').on('click', function(){
            var $b = $(this);
            if(!confirm('Anulować?')) return;

            $b.prop('disabled', true);

            $.post('<?php echo esc_url( admin_url('admin-ajax.php') ); ?>', {
                action: 'ts_b2b_cancel_meal',
                code_id: $b.data('id'),
                nonce: $b.data('nonce')
            }, function(resp){
                if(resp && resp.success){
                    location.reload();
                } else {
                    alert((resp && resp.data) ? resp.data : 'Nie udało się anulować.');
                    $b.prop('disabled', false);
                }
            });
        });
    })(jQuery);
    </script>
    <?php
}


    public static function ajax_handle_cancellation() {
        if ( ! is_user_logged_in() ) {
            wp_send_json_error( array( 'message' => 'Musisz być zalogowany.' ), 401 );
        }

        if ( ! self::is_strictly_b2b() ) {
            wp_send_json_error( array( 'message' => 'Brak uprawnień.' ), 403 );
        }

        // Nonce
        $nonce = isset($_POST['nonce']) ? sanitize_text_field( wp_unslash($_POST['nonce']) ) : '';
        if ( ! wp_verify_nonce( $nonce, self::NONCE_ACTION_CANCEL ) ) {
            wp_send_json_error( array( 'message' => 'Nieprawidłowa sesja (nonce). Odśwież stronę i spróbuj ponownie.' ), 403 );
        }

        $code_id = isset($_POST['code_id']) ? absint($_POST['code_id']) : 0;
        if ( ! $code_id ) {
            wp_send_json_error( array( 'message' => 'Brak ID usługi.' ), 400 );
        }

        global $wpdb;
        $table = $wpdb->prefix . 'tsme_meal_codes';
        $user_id = get_current_user_id();

        // 1) Pobierz rekord usługi
        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT id, order_id, stay_from, status
                 FROM {$table}
                 WHERE id = %d
                 LIMIT 1",
                $code_id
            ),
            ARRAY_A
        );

        if ( ! $row ) {
            wp_send_json_error( array( 'message' => 'Nie znaleziono usługi.' ), 404 );
        }

        // 2) Weryfikacja własności: order_id musi należeć do tego usera (HPOS-safe)
if ( ! function_exists('wc_get_order') ) {
    wp_send_json_error( array( 'message' => 'WooCommerce nie jest aktywne.' ), 500 );
}

$order = wc_get_order( (int) $row['order_id'] );
if ( ! $order ) {
    wp_send_json_error( array( 'message' => 'Nie znaleziono zamówienia powiązanego z usługą.' ), 404 );
}

if ( (int) $order->get_customer_id() !== (int) $user_id ) {
    wp_send_json_error( array( 'message' => 'Nie możesz anulować tej usługi (nie należy do Ciebie).' ), 403 );
}


        // 3) Reguła 7 dni – serwerowo
        $stay_ts = strtotime( (string) $row['stay_from'] );
        if ( ! $stay_ts ) {
            wp_send_json_error( array( 'message' => 'Błędna data usługi. Skontaktuj się z administratorem.' ), 400 );
        }

        if ( $stay_ts - time() <= 7 * DAY_IN_SECONDS ) {
            wp_send_json_error( array( 'message' => 'Nie można anulować: zostało mniej niż 7 dni do rozpoczęcia.' ), 400 );
        }

        // 4) Jeśli już void – nie rób nic
        if ( isset($row['status']) && $row['status'] === 'void' ) {
            wp_send_json_success( array( 'message' => 'Usługa była już anulowana.' ) );
        }

        // 5) Anuluj przez silnik ts-meals jeśli jest, w przeciwnym razie fallback w DB
        $did = false;

        if ( class_exists('TSME_Codes') && method_exists('TSME_Codes', 'void_code_by_id') ) {
            // silnik powinien sam ustawić status
            TSME_Codes::void_code_by_id( $code_id );
            $did = true;
        } else {
            // fallback: oznacz jako void bezpośrednio w tabeli (bez zależności)
            $updated = $wpdb->update(
                $table,
                array( 'status' => 'void' ),
                array( 'id' => $code_id ),
                array( '%s' ),
                array( '%d' )
            );
            $did = ( $updated !== false );
        }

        if ( ! $did ) {
            wp_send_json_error( array( 'message' => 'Nie udało się anulować usługi. Spróbuj ponownie lub skontaktuj się z administratorem.' ), 500 );
        }

        wp_send_json_success( array( 'message' => 'Usługa anulowana.' ) );
    }
}
