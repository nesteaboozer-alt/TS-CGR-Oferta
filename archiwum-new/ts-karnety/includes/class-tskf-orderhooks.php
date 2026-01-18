<?php
class TSKF_OrderHooks {
    static function init(){
        add_action('woocommerce_order_status_completed',[__CLASS__,'on_completed']);
        add_action('tskf_generate_for_order',[__CLASS__,'on_completed'],10,1);
    }

    static function on_completed($order_id){
    if (!function_exists('wc_get_order')) return;

    $order = wc_get_order($order_id);
    if (!$order) return;

    // Czy w tym zamówieniu faktycznie wygenerowaliśmy jakiekolwiek karnety?
    $has_tickets = false;

    foreach ($order->get_items() as $item_id=>$item) {
        $product = $item->get_product();
        if (!$product) continue;

        // ✅ Kompatybilność z TS Hotel Meals:
        // jeśli produkt jest oznaczony jako "TS Hotel Meals", pomijamy go,
        // żeby nie generować dla niego kodów basenowych (_ts_code) ani maili.
        $tsme_enabled = get_post_meta( $product->get_id(), '_tsme_enabled', true );
        if ( $tsme_enabled === 'yes' ) {
            continue;
        }


            $existing = wc_get_order_item_meta($item_id,'_ts_code', false);
            $existing = is_array($existing) ? $existing : array_filter([$existing]);

            $qty  = max(1, (int)$item->get_quantity());
            $need = max(0, $qty - count($existing));
            if ($need <= 0) continue;

            $type    = get_post_meta($product->get_id(),'_ts_ticket_type',true) ?: 'single';
            $days    = (int) get_post_meta($product->get_id(),'_ts_duration_days',true) ?: 0;
            $entries = (int) get_post_meta($product->get_id(),'_ts_entries_total',true) ?: 1;
            if ($type === 'period') $entries = 0;

            // Czy produkt ma kategorię "Zabieg" (slug: zabieg)
            $is_zabieg = has_term( 'zabieg', 'product_cat', $product->get_id() );

            // Wylicz datę wygaśnięcia dla Zabiegu: 30 dni od daty zamówienia
            $expires_at = null;
            if ( $is_zabieg ) {
                $created = $order->get_date_created(); // WC_DateTime
                if ( $created ) {
                    $expires_dt = clone $created;
                    $expires_dt->modify( '+30 days' );
                    $expires_at = $expires_dt->date_i18n( 'Y-m-d H:i:s' );
                } else {
                    // fallback – 30 dni od teraz
                    $expires_at = date_i18n(
                        'Y-m-d H:i:s',
                        strtotime( '+30 days', current_time( 'timestamp' ) )
                    );
                }
            }

            for ($i=0; $i<$need; $i++) {
                $code = TSKF_Helpers::gen_code();
                $now  = TSKF_Helpers::now_mysql();

                TSKF_Tickets::create([
                    'order_id'         => $order_id,
                    'order_item_id'    => $item_id,
                    'product_id'       => $product->get_id(),
                    'code'             => $code,
                    'ticket_type'      => $type,
                    'duration_days'    => $days,
                    'entries_total'    => $entries,
                    'entries_left'     => $entries,
                    'valid_from'       => $now,
                    'period_expires_at'=> $expires_at, // <- tu klucz dla Zabiegu
                    'status'           => 'active',
                    'used_log'         => wp_json_encode([]),
                    'created_at'       => $now,
                    'updated_at'       => $now,
                ]);

                    wc_add_order_item_meta($item_id,'_ts_code',$code);

    // zaznaczamy, że w tym zamówieniu faktycznie powstał co najmniej jeden karnet
    $has_tickets = true;
}

            }

    // Wyślij mail z karnetami tylko jeśli faktycznie coś wygenerowano
    if ( $has_tickets ) {
        TSKF_Email::send($order_id);
    }
}

}
