<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class TS_B2B_Services {

    public static function init() {
        add_filter( 'woocommerce_account_menu_items', array( __CLASS__, 'add_b2b_menu_item' ) );
        add_action( 'init', array( __CLASS__, 'add_b2b_endpoint' ) );
        add_action( 'woocommerce_account_b2b-services_endpoint', array( __CLASS__, 'render_b2b_services_page' ) );
        add_action( 'wp_ajax_ts_b2b_cancel_meal', array( __CLASS__, 'ajax_handle_cancellation' ) );
    }

    public static function add_b2b_menu_item( $items ) {
        if ( TS_B2B_Core::is_strictly_b2b() ) {
            $logout = $items['customer-logout'] ?? null;
            if ( $logout !== null ) unset( $items['customer-logout'] );

            $items['b2b-services'] = 'Moje Usługi (B2B)';

            if ( $logout !== null ) $items['customer-logout'] = $logout;
        }
        return $items;
    }

    public static function add_b2b_endpoint() {
        add_rewrite_endpoint( 'b2b-services', EP_PAGES );
    }

    public static function render_b2b_services_page() {
        global $wpdb;
        $table = $wpdb->prefix . 'tsme_meal_codes';

        $services = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM $table
                 WHERE order_id IN (
                   SELECT post_id FROM {$wpdb->postmeta}
                   WHERE meta_key = '_customer_user' AND meta_value = %d
                 )
                 AND status != 'void'
                 ORDER BY stay_from ASC",
                get_current_user_id()
            ),
            ARRAY_A
        );

        echo '<h3>Twoje zarezerwowane posiłki</h3>';
        if ( ! $services ) { echo '<p>Brak aktywnych rezerwacji.</p>'; return; }

        echo '<table class="shop_table"><thead><tr><th>Usługa</th><th>Obiekt</th><th>Data</th><th>Akcja</th></tr></thead><tbody>';
        foreach ( $services as $row ) {
            $can_cancel = ( strtotime( $row['stay_from'] ) - time() > 7 * DAY_IN_SECONDS );
            echo '<tr><td>'.esc_html($row['meal_type']).'</td><td>'.esc_html($row['object_label']).'</td><td>'.esc_html($row['stay_from']).'</td><td>';
            if ( $can_cancel ) echo '<button class="button ts-cancel" data-id="'.(int)$row['id'].'">Anuluj</button>';
            else echo 'Po terminie';
            echo '</td></tr>';
        }
        echo '</tbody></table>';
        ?>
        <script>
        jQuery('.ts-cancel').on('click', function(){
            var $b = jQuery(this); if(!confirm('Anulować?')) return;
            $b.prop('disabled', true);
            jQuery.post('<?php echo esc_url( admin_url('admin-ajax.php') ); ?>', { action: 'ts_b2b_cancel_meal', code_id: $b.data('id') }, function(){ location.reload(); });
        });
        </script>
        <?php
    }

    public static function ajax_handle_cancellation() {
        if ( class_exists('TSME_Codes') ) {
            TSME_Codes::void_code_by_id( absint($_POST['code_id'] ?? 0) );
        }
        wp_send_json_success();
    }
}
