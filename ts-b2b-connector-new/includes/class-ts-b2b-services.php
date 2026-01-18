<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class TS_B2B_Services {

    const ENDPOINT = 'b2b-services';
    const AJAX_ACTION_CANCEL = 'ts_b2b_cancel_meal';
    const NONCE_ACTION_CANCEL = 'ts_b2b_cancel_meal';

    public static function init() {
        add_filter( 'woocommerce_account_menu_items', array( __CLASS__, 'add_b2b_menu_item' ) );
        add_action( 'init', array( __CLASS__, 'add_b2b_endpoint' ) );
        // Kluczowe: Rejestracja widoku dla endpointu My Account
        add_action( 'woocommerce_account_' . self::ENDPOINT . '_endpoint', array( __CLASS__, 'render_b2b_services_page' ) );
        add_action( 'wp_ajax_' . self::AJAX_ACTION_CANCEL, array( __CLASS__, 'ajax_handle_cancellation' ) );
    }

    /**
     * Bezpiecznik roli – pozwala Administratorowi na testy widoku B2B.
     */
    private static function is_strictly_b2b() {
        if ( ! is_user_logged_in() ) return false;
        
        // Administrator musi widzieć tę zakładkę do celów testowych
        if ( current_user_can( 'manage_options' ) ) return true;

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
        // Uproszczenie maski do EP_PAGES dla lepszej kompatybilności z My Account
        add_rewrite_endpoint( self::ENDPOINT, EP_PAGES );
    }

    public static function render_b2b_services_page() {
        if ( ! function_exists('wc_get_orders') ) {
            echo '<p>Błąd: WooCommerce nie jest aktywny.</p>';
            return;
        }

        $user_id = get_current_user_id();
        
        $user = get_userdata( $user_id );
$email = $user ? strtolower( trim( $user->user_email ) ) : '';

$user = get_userdata( $user_id );
$email = $user ? strtolower( trim( $user->user_email ) ) : '';

$order_ids = wc_get_orders(array(
    'customer_id' => $user_id,
    'limit'       => -1,
    'return'      => 'ids',
    'status'      => array_keys( wc_get_order_statuses() ),
));

// fallback: zamówienia po emailu (gdy order był "jak gość")
if ( $email ) {
    $by_email = wc_get_orders(array(
        'billing_email' => $email,
        'limit'         => -1,
        'return'        => 'ids',
        'status'        => array_keys( wc_get_order_statuses() ),
    ));
    $order_ids = array_merge( $order_ids, $by_email );
}

$order_ids = array_values( array_unique( array_map( 'absint', $order_ids ) ) );


// fallback: zamówienia po emailu (gdy order był "jak gość")
if ( $email ) {
    $by_email = wc_get_orders(array(
        'billing_email' => $email,
        'limit'         => -1,
        'return'        => 'ids',
        'status'        => array_keys( wc_get_order_statuses() ),
    ));
    $order_ids = array_merge( $order_ids, $by_email );
}

$order_ids = array_values( array_unique( array_map( 'absint', $order_ids ) ) );


        if ( empty($order_ids) ) {
            echo '<h3>Twoje zarezerwowane posiłki</h3>';
            echo '<p>Nie znaleziono żadnych zamówień przypisanych do Twojego konta.</p>';
            return;
        }

        global $wpdb;
        $table = $wpdb->prefix . 'tsme_meal_codes';

        // 2) Pobierz usługi po order_id
        $placeholders = implode(',', array_fill(0, count($order_ids), '%d'));
        $sql = "SELECT * FROM $table WHERE order_id IN ($placeholders) AND status != 'void' ORDER BY stay_from ASC";

        // Użycie operatora rozpakowania (...$order_ids) dla prepare
        $services = $wpdb->get_results(
            $wpdb->prepare($sql, ...$order_ids),
            ARRAY_A
        );

        echo '<h3>Twoje zarezerwowane posiłki</h3>';
        if ( ! $services ) {
            echo '<p>Masz zamówienia, ale nie wygenerowano jeszcze dla nich kodów usług (lub są po terminie).</p>';
            return;
        }

        echo '<table class="shop_table shop_table_responsive my_account_orders"><thead><tr><th>Usługa</th><th>Obiekt</th><th>Data</th><th>Akcja</th></tr></thead><tbody>';
        $nonce = wp_create_nonce(self::NONCE_ACTION_CANCEL);

        foreach ( $services as $row ) {
            $can_cancel = ( strtotime( $row['stay_from'] ) - time() > 7 * DAY_IN_SECONDS );
            echo '<tr>
                <td data-title="Usługa">'.esc_html($row['meal_type']).'</td>
                <td data-title="Obiekt">'.esc_html($row['object_label']).'</td>
                <td data-title="Data">'.esc_html($row['stay_from']).'</td>
                <td data-title="Akcja">';

            if ( $can_cancel ) {
                echo '<button class="button ts-cancel" data-id="'.(int)$row['id'].'" data-nonce="'.esc_attr($nonce).'">Anuluj</button>';
            } else {
                echo '<small style="color:#999;">Brak możliwości anulacji (min. 7 dni przed)</small>';
            }
            echo '</td></tr>';
        }
        echo '</tbody></table>';
        ?>
        <script>
        (function($){
            $('.ts-cancel').on('click', function(){
                var $b = $(this);
                if(!confirm('Czy na pewno chcesz anulować tę usługę?')) return;
                
                $b.prop('disabled', true).text('...');
                
                $.post('<?php echo esc_url( admin_url('admin-ajax.php') ); ?>', {
                    action: '<?php echo esc_js(self::AJAX_ACTION_CANCEL); ?>',
                    code_id: $b.data('id'),
                    nonce: $b.data('nonce')
                }, function(resp){
                    if(resp && resp.success) {
                        location.reload();
                    } else {
                        alert((resp && resp.data && resp.data.message) ? resp.data.message : 'Błąd podczas anulowania.');
                        $b.prop('disabled', false).text('Anuluj');
                    }
                });
            });
        })(jQuery);
        </script>
        <?php
    }

    public static function ajax_handle_cancellation() {
        if ( ! is_user_logged_in() || ! self::is_strictly_b2b() ) {
            wp_send_json_error( array( 'message' => 'Brak uprawnień.' ), 403 );
        }

        $nonce = $_POST['nonce'] ?? '';
        if ( ! wp_verify_nonce( $nonce, self::NONCE_ACTION_CANCEL ) ) {
            wp_send_json_error( array( 'message' => 'Błąd bezpieczeństwa (nonce).' ), 403 );
        }

        $code_id = absint($_POST['code_id'] ?? 0);
        if ( ! $code_id ) {
            wp_send_json_error( array( 'message' => 'Błędne ID usługi.' ), 400 );
        }

        global $wpdb;
        $table = $wpdb->prefix . 'tsme_meal_codes';
        $user_id = get_current_user_id();

        $row = $wpdb->get_row( $wpdb->prepare( "SELECT order_id, stay_from FROM {$table} WHERE id = %d LIMIT 1", $code_id ), ARRAY_A );
        if ( ! $row ) {
            wp_send_json_error( array( 'message' => 'Nie znaleziono usługi.' ), 404 );
        }

        // Weryfikacja własności zamówienia w sposób bezpieczny dla HPOS
        $order = wc_get_order( (int) $row['order_id'] );
        if ( ! $order || (int) $order->get_customer_id() !== (int) $user_id ) {
            wp_send_json_error( array( 'message' => 'To nie jest Twoja usługa.' ), 403 );
        }

        if ( ( strtotime( $row['stay_from'] ) - time() ) <= 7 * DAY_IN_SECONDS ) {
            wp_send_json_error( array( 'message' => 'Anulacja możliwa najpóźniej na 7 dni przed usługą.' ), 400 );
        }

        if ( class_exists('TSME_Codes') && method_exists('TSME_Codes', 'void_code_by_id') ) {
            TSME_Codes::void_code_by_id( $code_id );
        } else {
            $wpdb->update( $table, array( 'status' => 'void' ), array( 'id' => $code_id ) );
        }

        wp_send_json_success( array( 'message' => 'Usługa anulowana.' ) );
    }
}