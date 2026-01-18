<?php
class TSKF_Email {

    static function from_name($name = '') {
        return 'Czarna Góra Resort';
    }

    static function from_addr($addr = '') {
        return 'uslugi@czarnagora.pl';
    }

    static function collect($order){
        $out = [];
        foreach ($order->get_items() as $item_id=>$item) {
            $arr  = (array) wc_get_order_item_meta($item_id,'_ts_code', false);
            $arr2 = (array) get_metadata('order_item', $item_id, '_ts_codes', true);

            $merged = array_unique(array_filter(array_merge($arr,$arr2)));
            if (!empty($merged)) {
                $prod = $item->get_product();
                
                // POPRAWKA: Pobieramy ID produktu głównego (nadrzędnego), a nie wariantu
                // W WC_Order_Item_Product metoda get_product_id() zawsze zwraca ID produktu głównego
                $main_product_id = $item->get_product_id(); 

                $is_zabieg = $main_product_id ? has_term( 'zabieg', 'product_cat', $main_product_id ) : false;

                $out[] = [
                    'product'   => $prod ? $prod->get_name() : 'Produkt',
                    'codes'     => $merged,
                    'is_zabieg' => $is_zabieg,
                ];
            }
        }
        return $out;
    }


    static function send($order_id, $force=false){
        if (!function_exists('wc_get_order')) return;

        $order = wc_get_order($order_id);
        if (!$order) return;

        if (!$force && 'yes' === get_post_meta($order_id,'_ts_skip_email',true)) return;

        $to = $order->get_billing_email();
        if (!$to) return;

        $groups = self::collect($order);
        if (empty($groups)) return;

        ob_start();
        $groups_for_template = $groups;
        include TSKF_DIR.'templates/emails/tickets.php';
        $body = ob_get_clean();

        $subject = sprintf('Twoje karnety – zamówienie #%s', $order->get_order_number());

        add_filter('woocommerce_email_from_name',    [__CLASS__, 'from_name'],  999, 1);
        add_filter('woocommerce_email_from_address', [__CLASS__, 'from_addr'],  999, 1);
        add_filter('wp_mail_from_name',              [__CLASS__, 'from_name'],  999, 1);
        add_filter('wp_mail_from',                   [__CLASS__, 'from_addr'],  999, 1);

        wc_mail($to, $subject, $body, ['Content-Type: text/html; charset=UTF-8']);

        remove_filter('woocommerce_email_from_name',    [__CLASS__, 'from_name'],  999);
        remove_filter('woocommerce_email_from_address', [__CLASS__, 'from_addr'],  999);
        remove_filter('wp_mail_from_name',              [__CLASS__, 'from_name'],  999);
        remove_filter('wp_mail_from',                   [__CLASS__, 'from_addr'],  999);

        $flat = [];
        foreach ($groups as $g) {
            foreach ($g['codes'] as $c) {
                $flat[] = $c;
            }
        }

        $log   = (array) get_post_meta($order_id,'_ts_codes_emails_log',true);
        $first = empty($log);

        $entry = [
            'ts'    => current_time('mysql'),
            'to'    => sanitize_text_field($to),
            'codes' => array_values(array_map('sanitize_text_field',$flat)),
        ];
        $log[] = $entry;
        update_post_meta($order_id,'_ts_codes_emails_log', $log);

        $order->add_order_note(
            ($first ? 'Kody wysłane po raz pierwszy: ' : 'Kody wysłane ponownie: ')
            . implode(', ', $flat) . ' → ' . $to
        );
    }
}
