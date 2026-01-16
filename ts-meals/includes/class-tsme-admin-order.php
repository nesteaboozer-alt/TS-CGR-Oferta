<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Panel TS Posiłki (Hotel) w edycji zamówienia + AJAX do wysyłki maili.
 */
class TSME_Admin_Order {

    public static function init() {
        // Panel w danych zamówienia – taka sama sekcja jak "TS Karnety".
        add_action(
            'woocommerce_admin_order_data_after_order_details',
            array( __CLASS__, 'order_panel' ),
            25,
            1
        );

        // AJAX – wysłanie po raz pierwszy.
        add_action( 'wp_ajax_tsme_send_meal_email', array( __CLASS__, 'ajax_send_email' ) );

        // AJAX – ponowna wysyłka.
        add_action( 'wp_ajax_tsme_resend_meal_email', array( __CLASS__, 'ajax_resend_email' ) );
    }

    /**
     * Rysuje kolumnę "TS Posiłki (Hotel)" w panelu danych zamówienia.
     *
     * @param WC_Order $order
     */
    public static function order_panel( $order ) {
        if ( ! $order instanceof WC_Order ) {
            return;
        }

        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            return;
        }

        $order_id   = $order->get_id();
        $meal_items = array();

        foreach ( $order->get_items( 'line_item' ) as $item_id => $item ) {
            /** @var WC_Order_Item_Product $item */
            $product = $item->get_product();
            if ( ! $product ) {
                continue;
            }

            if ( ! TSME_Frontend::is_meal_product( $product->get_id() ) ) {
                continue;
            }

            $meal_items[] = array(
                'name'      => $item->get_name(),
                'object'    => $item->get_meta( '_tsme_object', true ),
                'room'      => $item->get_meta( '_tsme_room_number', true ),
                'stay_from' => $item->get_meta( '_tsme_stay_from', true ),
                'stay_to'   => $item->get_meta( '_tsme_stay_to', true ),
                'adults'    => $item->get_meta( '_tsme_adults', true ),
                'children'  => $item->get_meta( '_tsme_children', true ),
                'code'      => $item->get_meta( '_tsme_code', true ),
            );
        }

        // Jeżeli w zamówieniu nie ma posiłków – nie pokazujemy panelu.
        if ( empty( $meal_items ) ) {
            return;
        }

        $last_email = $order->get_meta( '_tsme_last_email', true );
        $nonce      = wp_create_nonce( 'tsme_email_' . $order_id );
        ?>
        <div class="order_data_column">
            <h3>TS Posiłki (Hotel)</h3>

            <p style="margin-top:0;color:#64748b;">
                Poniżej podsumowanie posiłków zapisanych w tym zamówieniu.
            </p>

            <table class="widefat striped" style="margin-top:8px;">
                <thead>
                    <tr>
                        <th>Produkt</th>
                        <th>Obiekt</th>
                        <th>Pokój</th>
                        <th>Pobyt</th>
                        <th>Osoby</th>
                        <th>Kod awaryjny</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ( $meal_items as $mi ) : ?>
                    <tr>
                        <td><?php echo esc_html( $mi['name'] ); ?></td>
                        <td><?php echo esc_html( $mi['object'] ); ?></td>
                        <td><?php echo esc_html( $mi['room'] ); ?></td>
                        <td>
                            <?php
                            $from = $mi['stay_from'] ?: '—';
                            $to   = $mi['stay_to'] ?: '—';
                            echo esc_html( $from . ' – ' . $to );
                            ?>
                        </td>
                        <td>
                            <?php
                            printf(
                                'Dorośli: %1$s, Dzieci: %2$s',
                                $mi['adults']   !== '' ? $mi['adults']   : '0',
                                $mi['children'] !== '' ? $mi['children'] : '0'
                            );
                            ?>
                        </td>
                        <td>
                            <?php
                            if ( $mi['code'] ) {
                                echo '<code>' . esc_html( $mi['code'] ) . '</code>';
                            } else {
                                echo '<em>brak</em>';
                            }
                            ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>

            <p style="margin-top:12px;">
                <?php if ( $last_email ) : ?>
                    <span style="color:#64748b;">
                        Ostatnia wysyłka: <?php echo esc_html( $last_email ); ?>
                    </span>
                <?php else : ?>
                    <span style="color:#9ca3af;">
                        Brak wysłanych maili TS Posiłki dla tego zamówienia.
                    </span>
                <?php endif; ?>
            </p>

            <p style="margin-top:8px;">
                <button
                    type="button"
                    class="button button-secondary tsme-ajax-btn"
                    data-action="send"
                    data-order="<?php echo esc_attr( $order_id ); ?>"
                    data-nonce="<?php echo esc_attr( $nonce ); ?>"
                    data-loading="Wysyłam informacje o posiłkach…"
                >
                    Wyślij informacje o posiłkach
                </button>

                <button
    type="button"
    class="button button-primary tsme-ajax-btn"
    data-action="resend"
    data-order="<?php echo esc_attr( $order_id ); ?>"
    data-nonce="<?php echo esc_attr( $nonce ); ?>"
    data-loading="Wysyłam informacje ponownie…"
>
    Wyślij ponownie
</button>

            </p>

            <script>
                jQuery(function($){
                    $(document).on('click', '.tsme-ajax-btn', function(e){
                        e.preventDefault();

                        var $btn = $(this);
                        if ($btn.data('busy')) {
                            return;
                        }

                        var actionType = $btn.data('action') === 'resend'
                            ? 'tsme_resend_meal_email'
                            : 'tsme_send_meal_email';

                        var orderId = $btn.data('order');
                        var nonce   = $btn.data('nonce');

                        $btn.data('busy', true);
                        $btn.data('orig-text', $btn.text());

                        var loadingText = $btn.data('loading') || 'Przetwarzam…';
                        $btn.prop('disabled', true).text(loadingText);

                        $.post(ajaxurl, {
                            action:  actionType,
                            order_id: orderId,
                            nonce:   nonce
                        }).done(function(response){
    if (!response || !response.success) {
        var msg = response && response.data && response.data.message
            ? response.data.message
            : 'Wystąpił błąd podczas wysyłki.';
        alert(msg);
        return;
    }

    var data = response.data || {};
    if (data.status === 'already') {
        var last = data.last || '';
        alert(
            'Ten e-mail został już wysłany'
            + (last ? (': ' + last) : '.')
            + '\nJeśli chcesz wysłać go ponownie, użyj przycisku "Wyślij ponownie".'
        );
        window.location.reload();
    } else {
        alert('Wysłano e-mail z informacjami o posiłkach.');
        window.location.reload();
    }
}).fail(function(){

                            alert('Wystąpił błąd podczas wysyłki.');
                        }).always(function(){
                            $('.tsme-ajax-btn').each(function(){
                                var $b = $(this);
                                if (!$b.data('busy')) {
                                    return;
                                }
                                var orig = $b.data('orig-text');
                                if (orig) {
                                    $b.prop('disabled', false)
                                      .text(orig)
                                      .data('busy', false);
                                }
                            });
                        });
                    });
                });
            </script>
        </div>
        <?php
    }

    /**
     * AJAX: pierwszy przycisk – "Wyślij informacje o posiłkach".
     * Jeśli już wysłano ręcznie, nie wysyła ponownie – zwraca status "already".
     */
    public static function ajax_send_email() {
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( array( 'message' => 'Brak uprawnień.' ) );
        }

        $order_id = isset( $_POST['order_id'] ) ? absint( $_POST['order_id'] ) : 0;
        $nonce    = isset( $_POST['nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['nonce'] ) ) : '';

        if ( ! $order_id || ! wp_verify_nonce( $nonce, 'tsme_email_' . $order_id ) ) {
            wp_send_json_error( array( 'message' => 'Nieprawidłowe żądanie.' ) );
        }

        if ( ! function_exists( 'wc_get_order' ) ) {
            wp_send_json_error( array( 'message' => 'WooCommerce jest niedostępny.' ) );
        }

        $order = wc_get_order( $order_id );
        if ( ! $order ) {
            wp_send_json_error( array( 'message' => 'Nie znaleziono zamówienia.' ) );
        }

        $manual_sent = (bool) $order->get_meta( '_tsme_manual_sent' );
        $last_email  = $order->get_meta( '_tsme_last_email', true );

        if ( $manual_sent ) {
            wp_send_json_success(
                array(
                    'status' => 'already',
                    'last'   => $last_email,
                )
            );
        }

        // Wysyłamy maila.
        TSME_Email::send_meals_email( $order_id, 'manual' );

        $now = current_time( 'mysql' );
        $order->update_meta_data( '_tsme_manual_sent', 1 );
        $order->update_meta_data( '_tsme_last_email', $now );
        $order->save();

        wp_send_json_success(
            array(
                'status' => 'sent',
                'last'   => $now,
            )
        );
    }

    /**
     * AJAX: drugi przycisk – "Wyślij ponownie" (zawsze wysyła).
     */
    public static function ajax_resend_email() {
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( array( 'message' => 'Brak uprawnień.' ) );
        }

        $order_id = isset( $_POST['order_id'] ) ? absint( $_POST['order_id'] ) : 0;
        $nonce    = isset( $_POST['nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['nonce'] ) ) : '';

        if ( ! $order_id || ! wp_verify_nonce( $nonce, 'tsme_email_' . $order_id ) ) {
            wp_send_json_error( array( 'message' => 'Nieprawidłowe żądanie.' ) );
        }

        if ( ! function_exists( 'wc_get_order' ) ) {
            wp_send_json_error( array( 'message' => 'WooCommerce jest niedostępny.' ) );
        }

        $order = wc_get_order( $order_id );
        if ( ! $order ) {
            wp_send_json_error( array( 'message' => 'Nie znaleziono zamówienia.' ) );
        }

        // Wysyłamy maila bez względu na to, czy już był wysłany.
        TSME_Email::send_meals_email( $order_id, 'manual' );

        $now = current_time( 'mysql' );
        $order->update_meta_data( '_tsme_manual_sent', 1 );
        $order->update_meta_data( '_tsme_last_email', $now );
        $order->save();

        wp_send_json_success(
            array(
                'status' => 'resent',
                'last'   => $now,
            )
        );
    }
}
