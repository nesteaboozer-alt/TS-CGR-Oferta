<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Wysyłka maili TS Hotel Meals.
 */
class TSME_Email {

    public static function init() {
        // Na razie nie musimy rejestrować dodatkowych hooków.
    }

    /**
     * Wysyła mail z posiłkami dla danego zamówienia.
     *
     * @param int    $order_id
     * @param string $context 'auto' lub 'manual'
     * @param bool   $force   jeśli true, ignoruje blokadę ponownego auto-maila
     *
     * @return bool true jeśli wysłano, false jeśli nie
     */
    public static function send_meals_email( $order_id, $context = 'manual', $force = false ) {
        if ( ! function_exists( 'wc_get_order' ) ) {
            return false;
        }

        $order = wc_get_order( $order_id );
        if ( ! $order ) {
            return false;
        }

        // Jeśli to auto-mail i już kiedyś auto poszło – nie wysyłamy drugi raz.
        if ( $context === 'auto' && ! $force ) {
            $already_auto = $order->get_meta( '_tsme_autosent', true );
            if ( $already_auto === 'yes' ) {
                return false;
            }
        }

        // Zbierz pozycje z posiłkami TS.
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

            // Pobranie komunikatów o wyjątkach (daty)
            $msgs = $item->get_meta( '_tsme_messages', true );
            if ( ! is_array( $msgs ) ) {
                $msgs = array();
            }

            // Pobranie opisu budynku
            $b_desc = $item->get_meta( '_tsme_building_desc', true );
            
            // Łączenie w jedną listę dla szablonu maila
            $final_messages = array();
            
            // 1. Najpierw opis budynku z ikonką domku
            if ( $b_desc ) {
                $final_messages[] = '🏠 ' . $b_desc;
            }
            
            // 2. Potem komunikaty wyjątków z ikonką info
            foreach ( $msgs as $m ) {
                $final_messages[] = 'ℹ️ ' . $m;
            }

            $meal_items[] = array(
                'item_id'    => $item_id,
                'name'       => $item->get_name(),
                'object'     => $item->get_meta( '_tsme_object', true ),
                'room'       => $item->get_meta( '_tsme_room_number', true ),
                'stay_from'  => $item->get_meta( '_tsme_stay_from', true ),
                'stay_to'    => $item->get_meta( '_tsme_stay_to', true ),
                'adults'     => $item->get_meta( '_tsme_adults', true ),
                'children'   => $item->get_meta( '_tsme_children', true ),
                'code'       => $item->get_meta( '_tsme_code', true ),
                'messages'   => $final_messages, // Przekazujemy połączoną listę
            );
        }

        // Jeśli w zamówieniu nie ma posiłków – nic nie wysyłamy.
        if ( empty( $meal_items ) ) {
            return false;
        }

        $to = $order->get_billing_email();
        if ( ! $to ) {
            return false;
        }

        // ZMIANA: Tytuł maila wpisany na sztywno (omija ustawienia strony)
        $subject = '[Usługi Czarna Góra Resort] Potwierdzenie posiłków hotelowych';

        // ZMIANA: Nagłówek w środku maila (H2)
        $heading = 'Potwierdzenie rezerwacji posiłków';

        // Treść HTML – prosty, schludny szablon.
        $body = wc_get_template_html(
            'emails/tsme-meals.php',
            array(
                'order'         => $order,
                'meal_items'    => $meal_items,
                'email_heading' => $heading,
            ),
            '',
            TSME_DIR . 'templates/'
        );

        $headers = array( 'Content-Type: text/html; charset=UTF-8' );

        $sent = wc_mail( $to, $subject, $body, $headers );

        if ( $sent ) {
            $now = current_time( 'mysql' );

            $order->update_meta_data( '_tsme_last_email', $now );
            $order->update_meta_data( '_tsme_last_email_context', $context );

            if ( $context === 'auto' ) {
                $order->update_meta_data( '_tsme_autosent', 'yes' );
            }

            $order->save();

            $order->add_order_note(
                sprintf(
                    /* translators: 1: auto/manual, 2: email */
                    __( 'TS Hotel Meals: wysłano e-mail z informacjami o posiłkach (%1$s) na adres %2$s.', 'ts-hotel-meals' ),
                    $context === 'auto' ? __( 'automatycznie', 'ts-hotel-meals' ) : __( 'ręcznie', 'ts-hotel-meals' ),
                    $to
                )
            );
        } else {
            $order->add_order_note(
                sprintf(
                    __( 'TS Hotel Meals: NIE UDAŁO SIĘ wysłać e-maila z informacjami o posiłkach (%1$s) na adres %2$s.', 'ts-hotel-meals' ),
                    $context,
                    $to
                )
            );
        }

        return $sent;
    }
}
