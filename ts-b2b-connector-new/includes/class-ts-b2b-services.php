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
    }

    /**
     * Bezpiecznik roli – pozwala Partnerom B2B i Adminom na dostęp do zakładki.
     */
    private static function is_strictly_b2b() {
        if ( ! is_user_logged_in() ) return false;
        
        // Zawsze pozwalamy administratorowi na testy widoku
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
        add_rewrite_endpoint( self::ENDPOINT, EP_PAGES );
    }

    public static function render_b2b_services_page() {
        $user_id = get_current_user_id();
        
        // 1) Pobieramy zamówienia dokładnie tak jak WC w zakładce /orders/
        $orders = wc_get_orders(array(
            'customer' => $user_id,
            'limit'    => -1,
            'status'   => array('completed', 'processing', 'on-hold'),
        ));

        echo '<h3>Twoje usługi i rezerwacje (B2B)</h3>';

        if ( empty($orders) ) {
            echo '<p>Brak aktywnych zamówień przypisanych do Twojego konta.</p>';
            return;
        }

        global $wpdb;
        $table = $wpdb->prefix . 'tsme_meal_codes';
        $nonce = wp_create_nonce(self::NONCE_ACTION_CANCEL);
        $found_any_service = false;

        // 2) Grupowanie: Iterujemy po zamówieniach i szukamy dla nich kodów usług
        foreach ( $orders as $order ) {
            $order_id = $order->get_id();
            
            $services = $wpdb->get_results( $wpdb->prepare(
                "SELECT * FROM $table WHERE order_id = %d AND status != 'void' ORDER BY stay_from ASC",
                $order_id
            ), ARRAY_A );

            if ( ! empty($services) ) {
                $found_any_service = true;
                
                // Box zamówienia
                echo '<div class="b2b-order-section" style="margin-bottom: 30px; border: 1px solid #ddd; border-radius: 8px; overflow: hidden;">';
                echo '<div style="background: #f8f8f8; padding: 12px 15px; border-bottom: 1px solid #ddd; display: flex; justify-content: space-between;">';
                echo '<strong>Zamówienie #' . $order_id . '</strong>';
                echo '<span style="color: #666; font-size: 0.9em;">Data: ' . $order->get_date_created()->date('d.m.Y') . '</span>';
                echo '</div>';
                
                echo '<table class="shop_table shop_table_responsive" style="margin: 0; border: none;">';
                echo '<thead><tr><th>Produkt / Usługa</th><th>Obiekt</th><th>Data</th><th>Akcja</th></tr></thead>';
                echo '<tbody>';

                foreach ( $services as $row ) {
                    $can_cancel = ( (strtotime( $row['stay_from'] ) - time()) > 7 * DAY_IN_SECONDS );
                    
                    echo '<tr>';
                    echo '<td data-title="Usługa">' . esc_html($row['meal_type']) . '</td>';
                    echo '<td data-title="Obiekt">' . esc_html($row['object_label']) . '</td>';
                    echo '<td data-title="Data">' . esc_html($row['stay_from']) . '</td>';
                    echo '<td data-title="Akcja">';
                    
                    if ( $can_cancel ) {
                        echo '<button class="button ts-cancel" data-id="'.(int)$row['id'].'" data-nonce="'.esc_attr($nonce).'">Anuluj usługę</button>';
                    } else {
                        echo '<span style="color:#999; font-size: 0.85em;">Brak możliwości anulacji<br>(min. 7 dni przed)</span>';
                    }
                    
                    echo '</td></tr>';
                }

                echo '</tbody></table></div>';
            }
        }

        if ( ! $found_any_service ) {
            echo '<p>Masz zamówienia (#' . implode(', #', array_map(function($o){return $o->get_id();}, $orders)) . '), ale nie znaleziono dla nich wygenerowanych kodów usług w systemie.</p>';
        }

        ?>
        <script>
        (function($){
            $('.ts-cancel').on('click', function(e){
                e.preventDefault();
                var $b = $(this);
                if(!confirm('Czy na pewno chcesz anulować tę rezerwację? Tej operacji nie można cofnąć.')) return;
                
                $b.prop('disabled', true).text('Przetwarzanie...');
                
                $.post('<?php echo esc_url( admin_url('admin-ajax.php') ); ?>', {
                    action: '<?php echo esc_js(self::AJAX_ACTION_CANCEL); ?>',
                    code_id: $b.data('id'),
                    nonce: $b.data('nonce')
                }, function(resp){
                    if(resp && resp.success) {
                        location.reload();
                    } else {
                        alert((resp && resp.data && resp.data.message) ? resp.data.message : 'Wystąpił błąd.');
                        $b.prop('disabled', false).text('Anuluj usługę');
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
        global $wpdb;
        $table = $wpdb->prefix . 'tsme_meal_codes';
        
        $row = $wpdb->get_row( $wpdb->prepare( "SELECT order_id, stay_from FROM {$table} WHERE id = %d LIMIT 1", $code_id ), ARRAY_A );
        
        if ( ! $row ) {
            wp_send_json_error( array( 'message' => 'Nie znaleziono usługi.' ), 404 );
        }

        $order = wc_get_order( (int) $row['order_id'] );
        if ( ! $order || (int) $order->get_customer_id() !== (int) get_current_user_id() ) {
            wp_send_json_error( array( 'message' => 'Brak uprawnień do tego zamówienia.' ), 403 );
        }

        if ( ( strtotime( $row['stay_from'] ) - time() ) <= 7 * DAY_IN_SECONDS ) {
            wp_send_json_error( array( 'message' => 'Anulacja możliwa tylko do 7 dni przed usługą.' ), 400 );
        }

        if ( class_exists('TSME_Codes') && method_exists('TSME_Codes', 'void_code_by_id') ) {
            TSME_Codes::void_code_by_id( $code_id );
        } else {
            $wpdb->update( $table, array( 'status' => 'void' ), array( 'id' => $code_id ) );
        }

        wp_send_json_success( array( 'message' => 'Usługa anulowana.' ) );
    }
}