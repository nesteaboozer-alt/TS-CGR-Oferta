<?php
class TSKF_Admin {
    static function init(){
        add_action('admin_menu',[__CLASS__,'menu']);
        add_action('admin_enqueue_scripts',[__CLASS__,'assets']);
        add_action('rest_api_init',[__CLASS__,'rest']);
        add_action('add_meta_boxes',[__CLASS__,'order_box']);
        add_action('woocommerce_admin_order_data_after_order_details',[__CLASS__,'order_panel'],20,1);
        add_action('wp_ajax_tskf_generate_for_order',[__CLASS__,'ajax_generate']);
        add_action('wp_ajax_tskf_resend_codes',[__CLASS__,'ajax_resend']);
    }

    static function menu(){
        if (defined('TSB_VER')) {
            return;
        }
        add_menu_page('TS Karnety','TS Karnety','manage_woocommerce','tskf',[__CLASS__,'page_verify'],'dashicons-tickets-alt',56);
        add_submenu_page('tskf','Weryfikacja','Weryfikacja','manage_woocommerce','tskf',[__CLASS__,'page_verify']);
        add_submenu_page('tskf','Kody','Kody','manage_woocommerce','tskf-codes',[__CLASS__,'page_codes']);
        add_submenu_page('tskf','Dodaj ręcznie','Dodaj ręcznie','manage_woocommerce','tskf-add-manual',[__CLASS__,'page_add_manual']);
    }

    static function assets($hook){
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        $id     = $screen ? $screen->id : '';
        $page   = isset($_GET['page']) ? sanitize_text_field($_GET['page']) : '';

        $allow_ids = [
            'toplevel_page_tskf',
            'tskf_page_tskf-codes',
            'tskf_page_tskf-add-manual',
            'shop_order',
            'edit-shop_order',
            'woocommerce_page_wc-orders',
        ];

        $allow = in_array($id, $allow_ids, true)
            || (is_string($hook) && strpos($hook, 'tskf') !== false)
            || (is_string($page) && strpos($page, 'tskf') === 0);

        if (!$allow) return;

        wp_enqueue_style('tskf-admin', TSKF_URL.'assets/tsk-admin.css', [], TSKF_VER);
        wp_enqueue_script('tskf-admin', TSKF_URL.'assets/tsk-admin.js', ['jquery'], TSKF_VER, true);
        wp_localize_script('tskf-admin','TSKF',[
            'rest'  => esc_url_raw(rest_url('tskf/v1')),
            'nonce' => wp_create_nonce('wp_rest'),
        ]);
    }

    static function category_badge($product_id){
        $terms = get_the_terms($product_id, 'product_cat');
        if (empty($terms) || is_wp_error($terms)) return '';
        $term = $terms[0];
        $slug = $term->slug;
        $name = $term->name;
        $h    = intval(hexdec(substr(md5($slug),0,2)) % 360);
        $style = sprintf(
            'background:hsl(%d,65%%,86%%);color:#083344;padding:3px 8px;border-radius:999px;font-size:11px;font-weight:700;display:inline-block;',
            $h
        );
        return '<span class="tsk-badge" style="'.$style.'">'.esc_html($name).'</span>';
    }

    static function page_verify(){
        echo '<div class="tsk-wrap"><h1>Weryfikacja</h1>
        <div class="tsk-term">
          <input type="text" id="tsk-code" placeholder="Wpisz / zeskanuj kod" autofocus />
          <button class="button button-primary" id="tsk-check">Sprawdź</button>
          <button class="button" id="tsk-consume">Użyj teraz</button>
        </div>
        <div id="tsk-result" class="tsk-result" aria-live="polite"></div>
        </div>';
    }

    static function page_codes(){
        $rows = TSKF_Tickets::list_recent();

        $filter_status = isset($_GET['tsk_status']) ? sanitize_text_field($_GET['tsk_status']) : '';
        $filter_cat    = isset($_GET['tsk_cat'])    ? sanitize_text_field($_GET['tsk_cat'])    : '';
        $search_code   = isset($_GET['tsk_code'])   ? sanitize_text_field($_GET['tsk_code'])   : '';

        $cats = [];
        foreach ($rows as $r) {
            $terms = get_the_terms($r->product_id, 'product_cat');
            if ($terms && !is_wp_error($terms) && !empty($terms)) {
                $term = $terms[0];
                $cats[$term->slug] = $term->name;
            }
        }

        $status_map = [
            'active'    => ['Aktywny',            'active'],
            'activated' => ['Aktywny (okres)',    'running'],
            'exhausted' => ['Wykorzystany',       'exhausted'],
            'expired'   => ['Wygasły',            'expired'],
            'void'      => ['Unieważniony',       'void'],
        ];

        $filtered = [];
        foreach ($rows as $t) {
            if ($search_code !== '' && stripos($t->code, $search_code) === false) continue;
            if ($filter_status !== '' && $t->status !== $filter_status) continue;
            if ($filter_cat !== '') {
                $terms = get_the_terms($t->product_id, 'product_cat');
                $slug  = ($terms && !is_wp_error($terms) && !empty($terms)) ? $terms[0]->slug : '';
                if ($slug !== $filter_cat) continue;
            }
            $filtered[] = $t;
        }

        echo '<div class="tsk-wrap"><h1>Kody</h1>';

        echo '<style>
.tsk-chip{display:inline-block;padding:4px 10px;border-radius:999px;font-size:12px;font-weight:700;line-height:1}
.tsk-chip--active{background:#ecfdf5;color:#065f46}
.tsk-chip--running{background:#eff6ff;color:#1e40af}
.tsk-chip--exhausted{background:#fef3c7;color:#92400e}
.tsk-chip--expired{background:#fee2e2;color:#991b1b}
.tsk-chip--void{background:#e5e7eb;color:#374151}
</style>';

        $base_url = admin_url('admin.php?page=tskf-codes');

        echo '<form method="get" style="margin:8px 0 14px;display:flex;flex-wrap:wrap;gap:8px;align-items:center">';
        echo '<input type="hidden" name="page" value="tskf-codes" />';
        echo '<input type="search" name="tsk_code" value="'.esc_attr($search_code).'" placeholder="Szukaj po kodzie..." class="regular-text" style="min-width:220px" />';

        echo '<label>Status: <select name="tsk_status"><option value="">Wszystkie</option>';
        foreach ($status_map as $key => $meta) {
            $sel = selected($filter_status, $key, false);
            echo '<option value="'.esc_attr($key).'" '.$sel.'>'.esc_html($meta[0]).'</option>';
        }
        echo '</select></label>';

        echo '<label>Kategoria: <select name="tsk_cat"><option value="">Wszystkie</option>';
        foreach ($cats as $slug => $name) {
            $sel = selected($filter_cat, $slug, false);
            echo '<option value="'.esc_attr($slug).'" '.$sel.'>'.esc_html($name).'</option>';
        }
        echo '</select></label>';

        echo '<button class="button">Filtruj</button> <a class="button" href="'.esc_url($base_url).'">Wyczyść</a>';
        echo '</form>';

        echo '<table class="widefat fixed striped tsk-table"><thead><tr>';
        echo '<th>Kod</th><th>Produkt</th><th>Kategoria</th><th>Typ</th><th>Status</th><th>Wejścia</th><th>Start</th><th>Koniec</th><th>Data zabiegu</th><th>Odbiorca</th><th>Akcje</th>';
        echo '</tr></thead><tbody>';

        foreach ($filtered as $t) {
            $prod_name = $t->product_id ? get_the_title($t->product_id) : '-';
            $m = isset($status_map[$t->status]) ? $status_map[$t->status] : [$t->status,'active'];

            echo '<tr>';
            echo '<td><code>'.esc_html($t->code).'</code></td>';
            echo '<td>'.esc_html($prod_name).'</td>';
            echo '<td>'.self::category_badge($t->product_id).'</td>';
            echo '<td>'.esc_html($t->ticket_type).'</td>';
            echo '<td><span class="tsk-chip tsk-chip--'.esc_attr($m[1]).'">'.esc_html($m[0]).'</span></td>';
            echo '<td>'.intval($t->entries_left).' / '.intval($t->entries_total).'</td>';
            echo '<td>'.esc_html($t->period_started_at ?: '-').'</td>';
            echo '<td>'.esc_html($t->period_expires_at ?: '-').'</td>';
            echo '<td>'.esc_html($t->treatment_date ?: '-').'</td>';
            echo '<td>'.esc_html($t->treatment_client ?: '-').'</td>';

            $order_link = $t->order_id ? esc_url(admin_url('post.php?post='.intval($t->order_id).'&action=edit')) : '#';

            echo '<td class="tsk-actions">';
            echo '<div class="tsk-actions-group">';
            echo '<a href="#" class="button button-small tsk-btn tsk-btn--danger tsk-act" data-action="block" data-code="'.esc_attr($t->code).'">Zablokuj</a> ';
            echo '<a href="#" class="button button-small tsk-btn tsk-btn--ok tsk-act" data-action="unblock" data-code="'.esc_attr($t->code).'">Odblokuj</a> ';
            echo '<a href="#" class="button button-small tsk-btn tsk-btn--muted tsk-act" data-action="extend_days" data-days="1" data-code="'.esc_attr($t->code).'">+1 dzień</a> ';
            echo '<a href="#" class="button button-small tsk-btn tsk-btn--muted tsk-act" data-action="set_expire" data-code="'.esc_attr($t->code).'">Ustaw koniec</a> ';
            echo '<a href="#" class="button button-small tsk-btn tsk-btn--muted tsk-act" data-action="set_entries" data-code="'.esc_attr($t->code).'">Ustaw wejścia</a> ';
            echo '<a href="#" class="button button-small tsk-btn tsk-btn--primary tsk-edit" data-code="'.esc_attr($t->code).'">Edytuj informacje</a> ';
            echo '<a href="'.$order_link.'" target="_blank" class="button button-small tsk-btn tsk-btn--ghost">Dane</a>';
            echo '</div>';
            echo '</td>';

            echo '</tr>';
        }

        if (empty($filtered)) {
            echo '<tr><td colspan="11"><em>Brak wyników.</em></td></tr>';
        }

        echo '</tbody></table></div>';
    }

    static function page_add_manual(){
        if (! current_user_can('manage_woocommerce')) {
            wp_die('Brak uprawnień');
        }

        $notice = '';

        if (isset($_POST['tskf_manual_nonce']) && wp_verify_nonce($_POST['tskf_manual_nonce'], 'tskf_manual')) {
            $email        = sanitize_email($_POST['email']            ?? '');
            $product_id   = intval($_POST['product_id_select']        ?? 0);
            if (!$product_id) {
                $product_id = intval($_POST['product_id'] ?? 0);
            }
            $qty          = max(1, intval($_POST['qty']              ?? 1));
            $send_email   = !empty($_POST['send_email']);
        
            // 🔴 NOWE POLA – NAZWA FIRMY + NIP
            $company_name = sanitize_text_field($_POST['company_name'] ?? '');
            $company_nip  = sanitize_text_field($_POST['company_nip']  ?? '');
        
            // WALIDACJA: produkt, firma, NIP, e-mail (jeśli ma wysyłać)
            if ( (!$send_email || $email) && $product_id && $company_name && $company_nip ) {
        
                $order   = wc_create_order();
                $product = wc_get_product($product_id);
        
                if ($product) {
                    $order->add_product($product, $qty);
                    $order->calculate_totals();
        
                    // Ustawiamy standardowe meta WooCommerce
                    if ($email) {
                        $order->set_billing_email($email);
                    }
        
                    // 🔵 ZAPIS NAZWY FIRMY
                    $order->set_billing_company($company_name);
        
                    // 🔵 ZAPIS NIP – kompatybilnie z tym, co masz już w innych miejscach
                    $order->update_meta_data('_billing_nip', $company_nip);
                    $order->update_meta_data('billing_nip',  $company_nip);
        
                    $order->save();
        
                    $order->update_status('completed', 'Ręczne zamówienie (TSKF).');
        
                    if ($send_email && $email) {
                        TSKF_Email::send($order->get_id(), true);
                    }
        
                    $notice = '<div class="updated notice"><p>Utworzono zamówienie <strong>#' . $order->get_order_number() . '</strong> i wygenerowano kody.</p></div>';
                } else {
                    $notice = '<div class="error notice"><p>Nieprawidłowy produkt.</p></div>';
                }
            } else {
                // Komunikat z doprecyzowaniem wymaganych pól
                $notice = '<div class="error notice"><p>Uzupełnij wszystkie wymagane pola: produkt, nazwę firmy, NIP, a jeżeli zaznaczono wysyłkę e-mail — podaj adres e-mail.</p></div>';
            }
        }

        $products = function_exists('wc_get_products') ? wc_get_products([
            'status'  => 'publish',
            'limit'   => -1,
            'return'  => 'objects',
            'orderby' => 'title',
            'order'   => 'ASC',
        ]) : [];

        echo '<div class="wrap"><h1>Dodaj ręcznie</h1>'.$notice.'<form method="post">';
        wp_nonce_field('tskf_manual','tskf_manual_nonce');

        echo '<table class="form-table">';

        echo '<tr><th><label for="email">E-mail (opcjonalnie)</label></th><td><input type="email" name="email" id="email" class="regular-text"></td></tr>';
// 🔴 NOWE: NAZWA FIRMY – WYMAGANA
echo '<tr valign="top">';
echo '<th scope="row"><label for="company_name">Nazwa firmy <span style="color:red">*</span></label></th>';
echo '<td><input name="company_name" type="text" id="company_name" value="' . esc_attr($_POST['company_name'] ?? '') . '" class="regular-text" required></td>';
echo '</tr>';

// 🔴 NOWE: NIP – WYMAGANY
echo '<tr valign="top">';
echo '<th scope="row"><label for="company_nip">NIP <span style="color:red">*</span></label></th>';
echo '<td><input name="company_nip" type="text" id="company_nip" value="' . esc_attr($_POST['company_nip'] ?? '') . '" class="regular-text" required></td>';
echo '</tr>';
        echo '<tr><th><label for="product_id_select">Produkt</label></th><td>';
        echo '<select name="product_id_select" id="product_id_select">';
        echo '<option value="">— wybierz produkt —</option>';
        foreach ($products as $p) {
            echo '<option value="'.esc_attr($p->get_id()).'">'.esc_html($p->get_name()).' (ID: '.$p->get_id().')</option>';
        }
        echo '</select>';
        echo '<p class="description">Możesz też wpisać ID produktu ręcznie poniżej.</p>';
        echo '</td></tr>';

        echo '<tr><th><label for="product_id">Produkt (ID ręcznie)</label></th><td><input type="number" name="product_id" id="product_id" ></td></tr>';

        echo '<tr><th><label for="qty">Ilość</label></th><td><input type="number" name="qty" id="qty" value="1" min="1"></td></tr>';

        echo '<tr><th>Wysyłka e-mail</th><td><label><input type="checkbox" name="send_email" value="1"> Wyślij kody do klienta</label></td></tr>';

        echo '</table>';
        echo '<p><button class="button button-primary">Utwórz zamówienie offline</button></p>';
        echo '</form></div>';
    }

    static function order_box(){
        add_meta_box('tskf_order_box','TS Karnety',[__CLASS__,'render_order_box'],'shop_order','side','high');
    }

    static function render_order_box($post){
        $order_id = (int)$post->ID;
        $order    = wc_get_order($order_id);
        $codes    = [];

        if ($order) {
            foreach ($order->get_items() as $item_id=>$item) {
                $arr = (array) wc_get_order_item_meta($item_id,'_ts_code',false);
                foreach ($arr as $c) {
                    if ($c) $codes[] = $c;
                }
            }
        }

        $nonce = wp_create_nonce('tskf_order_box');

        echo '<div class="tsk-box"><div id="tsk-order-notice"></div><p><strong>Kody w zamówieniu:</strong><br>';
        if ($codes) {
            foreach ($codes as $c) {
                echo '<code style="display:inline-block;margin:2px 0;">'.esc_html($c).'</code><br>';
            }
        } else {
            echo '<em>Brak wygenerowanych kodów.</em>';
        }
        echo '</p>';

        $log  = (array) get_post_meta($order_id,'_ts_codes_emails_log',true);
        $last = !empty($log) ? end($log) : null;
        $last_ts = $last ? esc_html($last['ts']) : '—';

                echo '<p>';
        echo '<button type="button" class="button tskf-ajax-btn" id="tsk-generate" data-order="'.$order_id.'" data-nonce="'.esc_attr($nonce).'" data-loading="Generuję kody…">Generuj kody teraz</button> ';
        echo '<button type="button" class="button button-primary tskf-ajax-btn" id="tsk-resend" data-order="'.$order_id.'" data-nonce="'.esc_attr($nonce).'" data-loading="Wysyłam kody ponownie…">Wyślij kody ponownie</button>';
        echo '<br><span class="description">Ostatnia wysyłka: '.$last_ts.'</span>';
        echo '</p>';
        echo '</div>';
    }

    static function order_panel($order){
        if (! current_user_can('manage_woocommerce')) return;

        $order_id = $order->get_id();
        $codes    = [];

        foreach ($order->get_items() as $item_id=>$item) {
            $arr = (array) wc_get_order_item_meta($item_id,'_ts_code',false);
            foreach ($arr as $c) {
                if ($c) $codes[] = $c;
            }
        }

        $nonce = wp_create_nonce('tskf_order_box');

        echo '<div class="order_data_column"><h3>TS Karnety</h3>';
        echo '<p><strong>Kody:</strong><br>';
        if ($codes) {
            foreach ($codes as $c) {
                echo '<code style="display:inline-block;margin:2px 0;">'.esc_html($c).'</code><br>';
            }
        } else {
            echo '<em>Brak</em>';
        }
        echo '</p>';

        $log  = (array) get_post_meta($order_id,'_ts_codes_emails_log',true);
        $last = !empty($log) ? end($log) : null;
        $last_ts = $last ? esc_html($last['ts']) : '—';

                echo '<p>';
        echo '<button type="button" class="button tskf-ajax-btn" id="tsk-generate" data-order="'.$order_id.'" data-nonce="'.esc_attr($nonce).'" data-loading="Generuję kody…">Generuj kody teraz</button> ';
        echo '<button type="button" class="button button-primary tskf-ajax-btn" id="tsk-resend" data-order="'.$order_id.'" data-nonce="'.esc_attr($nonce).'" data-loading="Wysyłam kody ponownie…">Wyślij kody ponownie</button>';
        echo '<br><span class="description">Ostatnia wysyłka: '.$last_ts.'</span></p>';

        // Prosty loader na przyciskach generowania / wysyłki kodów
        echo '<script>
jQuery(function($){
  // Po kliknięciu – blokujemy przycisk i zmieniamy tekst
  $(document).on("click", ".tskf-ajax-btn", function(){
    var $btn = $(this);
    if ($btn.data("busy")) return; // już leci jakiś request

    $btn.data("busy", true);
    $btn.data("orig-text", $btn.text());

    var loadingText = $btn.data("loading") || "Przetwarzam…";
    $btn.prop("disabled", true).text(loadingText);
  });

  // Gdy jakikolwiek AJAX w panelu się skończy – przywracamy przyciski
  $(document).ajaxComplete(function(){
    $(".tskf-ajax-btn").each(function(){
      var $btn = $(this);
      if (!$btn.data("busy")) return;

      var orig = $btn.data("orig-text");
      if (orig) {
        $btn.prop("disabled", false)
            .text(orig)
            .data("busy", false);
      }
    });
  });
});
</script>';

        echo '</div>';

    }

static function ajax_generate(){
    // Musi być zalogowany i mieć prawo do panelu / Woo
    if ( ! is_user_logged_in() || ! ( current_user_can('manage_woocommerce') || current_user_can('tsb_access_panel') ) ) {
        wp_send_json_error(['msg' => 'Brak uprawnień (ajax_generate)']);
    }

    $order_id = (int)($_POST['order_id'] ?? 0);

    // Chronimy się nonce'em z meta boxa przy zamówieniu
    if ( ! wp_verify_nonce($_POST['nonce'] ?? '', 'tskf_order_box') ) {
        wp_send_json_error(['msg' => 'Błędny nonce']);
    }

    if ( ! $order_id ) {
        wp_send_json_error(['msg' => 'Brak ID zamówienia']);
    }

    do_action('tskf_generate_for_order', $order_id);

    wp_send_json_success(['msg' => 'Wygenerowano kody (o ile brakowało).']);
}





static function ajax_resend(){
    // Musi być zalogowany i mieć prawo do panelu / Woo
    if ( ! is_user_logged_in() || ! ( current_user_can('manage_woocommerce') || current_user_can('tsb_access_panel') ) ) {
        wp_send_json_error(['msg' => 'Brak uprawnień (ajax_resend)']);
    }

    $order_id = (int)($_POST['order_id'] ?? 0);

    // Chronimy się nonce'em z meta boxa przy zamówieniu
    if ( ! wp_verify_nonce($_POST['nonce'] ?? '', 'tskf_order_box') ) {
        wp_send_json_error(['msg' => 'Błędny nonce']);
    }

    if ( ! $order_id ) {
        wp_send_json_error(['msg' => 'Brak ID zamówienia']);
    }

    // Tutaj dojdziemy TYLKO jeśli:
    // - user jest zalogowany
    // - ma manage_woocommerce LUB tsb_access_panel
    TSKF_Email::send($order_id, true);

    wp_send_json_success(['msg' => 'Wysłano ponownie e-mail z kodami.']);
}





static function rest(){
    // Szczegóły biletu (do modala "Edycja informacji" itp.)
    register_rest_route('tskf/v1','/admin/ticket/get',[
        'methods'             => 'POST',
        'permission_callback' => function(){
            return current_user_can('manage_woocommerce')
                || current_user_can('tsb_access_panel');
        },
        'callback'            => ['TSKF_Admin','rest_ticket_get'],
    ]);

    // Historia biletu – już miała dostęp dla tsb_access_panel, zostawiamy
    register_rest_route('tskf/v1','/admin/ticket/history',[
        'methods'             => 'POST',
        'permission_callback' => function(){
            return current_user_can('manage_woocommerce')
                || current_user_can('tsb_access_panel');
        },
        'callback'            => ['TSKF_Admin','rest_ticket_history'],
        'args'                => ['code'=>['required'=>true]],
    ]);

    // Sprawdzanie biletu (TS Panel: "Sprawdź")
    register_rest_route('tskf/v1','/tickets/check',[
        'methods'             => 'POST',
        'permission_callback' => function(){
            return current_user_can('manage_woocommerce')
                || current_user_can('tsb_access_panel');
        },
        'callback'            => ['TSKF_Admin','rest_check'],
        'args'                => ['code'=>['required'=>true]],
    ]);

    // Użycie biletu (TS Panel: "Użyj teraz")
    register_rest_route('tskf/v1','/tickets/consume',[
        'methods'             => 'POST',
        'permission_callback' => function(){
            return current_user_can('manage_woocommerce')
                || current_user_can('tsb_access_panel');
        },
        'callback'            => ['TSKF_Admin','rest_consume'],
        'args'                => ['code'=>['required'=>true]],
    ]);

    // Akcje admina z TS Panelu (block/unblock/+1 dzień/ustaw koniec/ustaw wejścia/edycja info)
    register_rest_route('tskf/v1','/admin/update',[
        'methods'             => 'POST',
        'permission_callback' => function(){
            return current_user_can('manage_woocommerce')
                || current_user_can('tsb_access_panel');
        },
        'callback'            => ['TSKF_Admin','rest_update'],
    ]);
}


static function rest_ticket_get(WP_REST_Request $r){
    $code = sanitize_text_field($r->get_param('code'));
    $t    = TSKF_Tickets::get_by_code($code);

    if (!$t) {
        return new WP_REST_Response(['ok'=>false],404);
    }

    $order = function_exists('wc_get_order') ? wc_get_order($t->order_id) : null;
    $phone = $order ? $order->get_billing_phone() : '';

    $local = '';
    if (!empty($t->treatment_date)) {
        $ts = strtotime($t->treatment_date);
        if ($ts) {
            $local = date('Y-m-d\TH:i', $ts);
        }
    }

    return new WP_REST_Response([
        'ok'   => true,
        'data' => [
            'treatment_date'       => $t->treatment_date,
            'treatment_date_local' => $local,
            'treatment_client'     => $t->treatment_client,
            'phone'                => $phone,
        ],
    ], 200);
}

    static function rest_ticket_history( WP_REST_Request $r ) {
        $code = sanitize_text_field( $r->get_param( 'code' ) );

        if ( ! $code ) {
            return new WP_REST_Response(
                [
                    'ok'      => false,
                    'message' => 'Brak kodu.',
                ],
                400
            );
        }

        $history = TSKF_Tickets::get_history_by_code( $code, 50 );

        return new WP_REST_Response(
            [
                'ok'      => true,
                'code'    => $code,
                'history' => $history,
            ],
            200
        );
    }

    static function fmt($t){
        return [
            'code'             => $t->code,
            'status'           => $t->status,
            'entries_left'     => (int)$t->entries_left,
            'entries_total'    => (int)$t->entries_total,
            'period_started_at'=> $t->period_started_at,
            'period_expires_at'=> $t->period_expires_at,
        ];
    }

      static function rest_check(WP_REST_Request $r){
        $code = sanitize_text_field($r->get_param('code'));
        $t    = TSKF_Tickets::get_by_code($code);

        if (!$t) {
            return new WP_REST_Response([
                'ok'      => false,
                'type'    => 'not_found',
                'message' => 'Nie znaleziono kodu',
            ], 404);
        }

        $now       = current_time('timestamp');
        $is_zabieg = has_term( 'zabieg', 'product_cat', $t->product_id );

                // NOWY BLOK: globalna ważność kodu – 90 dni od daty zakupu
        $purchase_ts    = 0;
        $valid_until_ts = 0;

        if ( function_exists( 'wc_get_order' ) && $t->order_id ) {
            $order = wc_get_order( $t->order_id );
            if ( $order && $order->get_date_created() ) {
                $purchase_ts    = $order->get_date_created()->getTimestamp();
                $valid_until_ts = $purchase_ts + ( 90 * DAY_IN_SECONDS );
            }
        }

        if ( $purchase_ts && $valid_until_ts ) {
            // Log do historii: "Zakupiony dnia ... ważny do ..."
            TSKF_Tickets::log_event(
                $t->id,
                $t->code,
                'purchase_window',
                [
                    'purchase_ts'    => $purchase_ts,
                    'valid_until_ts' => $valid_until_ts,
                    'note'           => sprintf(
                        'Zakupiony dnia %s, ważny do %s (90 dni).',
                        date_i18n( 'd.m.Y', $purchase_ts ),
                        date_i18n( 'd.m.Y', $valid_until_ts )
                    ),
                ]
            );

            // Jeśli kod nigdy nie był użyty (pełna liczba wejść + brak startu okresu),
            // to po 90 dniach od zakupu blokujemy możliwość użycia / aktywacji.
            $never_used = ( (int) $t->entries_left === (int) $t->entries_total )
                && empty( $t->period_started_at );

            if ( $never_used && $now > $valid_until_ts ) {
                $days_passed = (int) floor( ( $now - $purchase_ts ) / DAY_IN_SECONDS );

                $msg = sprintf(
                    'Kod stracił ważność. Data zakupu: %s. Ilość dni, która minęła od zakupu: %d.',
                    date_i18n( 'd.m.Y', $purchase_ts ),
                    $days_passed
                );

                // LOG: wygaśnięcie okna 90 dni
                TSKF_Tickets::log_event(
                    $t->id,
                    $t->code,
                    'initial_expired',
                    [
                        'purchase_ts'    => $purchase_ts,
                        'valid_until_ts' => $valid_until_ts,
                        'days_passed'    => $days_passed,
                    ]
                );

                return new WP_REST_Response(
                    [
                        'ok'      => false,
                        'type'    => 'initial_expired',
                        'message' => $msg,
                        'data'    => self::fmt( $t ),
                    ],
                    410
                );
            }
        }


        if ($t->status === 'void') {
            return new WP_REST_Response([
                'ok'      => false,
                'type'    => 'void',
                'message' => 'Kod został unieważniony.',
                'data'    => self::fmt($t),
            ], 403);
        }

        if ($t->status === 'exhausted') {
            $msg = $is_zabieg
                ? 'Voucher zabiegowy został już wykorzystany.'
                : 'Ilość wejść została wykorzystana.';
            return new WP_REST_Response([
                'ok'      => false,
                'type'    => 'exhausted',
                'message' => $msg,
                'data'    => self::fmt($t),
            ], 409);
        }

        if ($t->status === 'expired') {
            $msg = $is_zabieg
                ? sprintf(
                    'Voucher zabiegowy wygasł dnia %s.',
                    $t->period_expires_at ? date_i18n('d.m.Y', strtotime($t->period_expires_at)) : 'nieznana'
                  )
                : 'Kod wygasł.';
            return new WP_REST_Response([
                'ok'      => false,
                'type'    => 'expired',
                'message' => $msg,
                'data'    => self::fmt($t),
            ], 410);
        }

        if ($t->period_expires_at && $now > strtotime($t->period_expires_at)) {
            $msg = $is_zabieg
                ? sprintf(
                    'Voucher zabiegowy wygasł dnia %s.',
                    date_i18n('d.m.Y', strtotime($t->period_expires_at))
                  )
                : 'Kod wygasł: '.$t->period_expires_at;

            return new WP_REST_Response([
                'ok'      => false,
                'type'    => 'expired',
                'message' => $msg,
                'data'    => self::fmt($t),
            ], 410);
        }

        if ($t->ticket_type !== 'period' && (int)$t->entries_left <= 0) {
            $msg = $is_zabieg
                ? 'Voucher zabiegowy został już wykorzystany.'
                : 'Ilość wejść została wykorzystana.';
            return new WP_REST_Response([
                'ok'      => false,
                'type'    => 'exhausted',
                'message' => $msg,
                'data'    => self::fmt($t),
            ], 409);
        }

        // Zapis ostatniego sprawdzenia
        TSKF_Tickets::update($t->id,[
            'last_checked_at' => current_time('mysql'),
            'last_checked_by' => get_current_user_id(),
        ]);

        // LOG: sprawdzenie kodu
        TSKF_Tickets::log_event(
            $t->id,
            $t->code,
            'checked',
            [
                'status'        => $t->status,
                'entries_left'  => (int) $t->entries_left,
                'entries_total' => (int) $t->entries_total,
            ]
        );

        // Komunikaty "OK"
        if ( $is_zabieg ) {
            $msg = 'Voucher zabiegowy jest ważny.';

            if ( $t->period_expires_at ) {
                $msg = sprintf(
                    'Voucher zabiegowy jest ważny do %s.',
                    date_i18n('d.m.Y', strtotime($t->period_expires_at))
                );
            }
        } else {
            $msg = empty($t->period_started_at)
              ? 'Kod jest gotowy do użycia!'
              : ('Kod jest aktywny'.($t->period_expires_at ? ' i ważny do: '.$t->period_expires_at : ''));
        }

        // 👉 dopisujemy nazwę produktu (karnetu), jeśli jest
        $product_name = $t->product_id ? get_the_title( $t->product_id ) : '';
        if ( $product_name ) {
            $msg = $product_name . ': ' . $msg;
        }

        return new WP_REST_Response([
            'ok'      => true,
            'message' => $msg,
            'data'    => self::fmt($t),
        ], 200);
    }



    static function rest_consume(WP_REST_Request $r){
        $code = sanitize_text_field($r->get_param('code'));
        $t    = TSKF_Tickets::get_by_code($code);

        if (!$t) {
            return new WP_REST_Response([
                'ok'      => false,
                'type'    => 'not_found',
                'message' => 'Nie znaleziono kodu',
            ], 404);
        }

        $now = current_time('timestamp');

                // NOWY BLOK: globalna ważność kodu – 90 dni od daty zakupu
        $purchase_ts    = 0;
        $valid_until_ts = 0;

        if ( function_exists( 'wc_get_order' ) && $t->order_id ) {
            $order = wc_get_order( $t->order_id );
            if ( $order && $order->get_date_created() ) {
                $purchase_ts    = $order->get_date_created()->getTimestamp();
                $valid_until_ts = $purchase_ts + ( 90 * DAY_IN_SECONDS );
            }
        }

        if ( $purchase_ts && $valid_until_ts ) {
            // Log do historii: "Zakupiony dnia ... ważny do ..."
            TSKF_Tickets::log_event(
                $t->id,
                $t->code,
                'purchase_window',
                [
                    'purchase_ts'    => $purchase_ts,
                    'valid_until_ts' => $valid_until_ts,
                    'note'           => sprintf(
                        'Zakupiony dnia %s, ważny do %s (90 dni).',
                        date_i18n( 'd.m.Y', $purchase_ts ),
                        date_i18n( 'd.m.Y', $valid_until_ts )
                    ),
                ]
            );

            // Jeśli kod nigdy nie był użyty (pełna liczba wejść + brak startu okresu),
            // to po 90 dniach od zakupu blokujemy możliwość użycia / aktywacji.
            $never_used = ( (int) $t->entries_left === (int) $t->entries_total )
                && empty( $t->period_started_at );

            if ( $never_used && $now > $valid_until_ts ) {
                $days_passed = (int) floor( ( $now - $purchase_ts ) / DAY_IN_SECONDS );

                $msg = sprintf(
                    'Kod stracił ważność. Data zakupu: %s. Ilość dni, która minęła od zakupu: %d.',
                    date_i18n( 'd.m.Y', $purchase_ts ),
                    $days_passed
                );

                // LOG: wygaśnięcie okna 90 dni
                TSKF_Tickets::log_event(
                    $t->id,
                    $t->code,
                    'initial_expired',
                    [
                        'purchase_ts'    => $purchase_ts,
                        'valid_until_ts' => $valid_until_ts,
                        'days_passed'    => $days_passed,
                    ]
                );

                return new WP_REST_Response(
                    [
                        'ok'      => false,
                        'type'    => 'initial_expired',
                        'message' => $msg,
                        'data'    => self::fmt( $t ),
                    ],
                    410
                );
            }
        }


        if ($t->status === 'void') {
            return new WP_REST_Response([
                'ok'      => false,
                'type'    => 'void',
                'message' => 'Kod jest zablokowany (unieważniony).',
                'data'    => self::fmt($t),
            ], 403);
        }

        if ($t->period_expires_at && $now > strtotime($t->period_expires_at)) {
            return new WP_REST_Response([
                'ok'      => false,
                'type'    => 'expired',
                'message' => 'Kod wygasł: '.$t->period_expires_at,
                'data'    => self::fmt($t),
            ], 410);
        }

        if ($t->ticket_type !== 'period' && (int)$t->entries_left <= 0) {
            return new WP_REST_Response([
                'ok'      => false,
                'type'    => 'exhausted',
                'message' => 'Ilość wejść została wykorzystana.',
                'data'    => self::fmt($t),
            ], 409);
        }

        $was_inactive = (empty($t->period_started_at) && $t->duration_days > 0 && $t->ticket_type !== 'single');

        $res = TSKF_Engine::consume($t, get_current_user_id());
        if (is_wp_error($res)) {
            return new WP_REST_Response([
                'ok'      => false,
                'type'    => 'cannot_use',
                'message' => 'Nie można użyć kodu',
            ], 400);
        }

        $msg  = 'Kod wykorzystany poprawnie.';
        $type = 'consumed';

        if ($was_inactive && $t->period_expires_at) {
            $msg  .= ' Aktywowano okres, ważny do: '.$t->period_expires_at;
            $type  = 'activated_now';
        }

        // LOG: użycie kodu
        TSKF_Tickets::log_event(
            $t->id,
            $t->code,
            $type, // "consumed" lub "activated_now"
            [
                'entries_left'  => (int) $t->entries_left,
                'entries_total' => (int) $t->entries_total,
            ]
        );

        return new WP_REST_Response([
            'ok'      => true,
            'type'    => $type,
            'message' => $msg,
            'data'    => self::fmt($t),
        ], 200);
    }

    static function rest_update(WP_REST_Request $r){
        $code   = sanitize_text_field($r->get_param('code'));
        $t      = TSKF_Tickets::get_by_code($code);
        if (!$t) {
            return new WP_REST_Response(['ok'=>false,'message'=>'Nie znaleziono kodu'],404);
        }

        $action = sanitize_text_field($r->get_param('action'));

        switch ($action) {
            case 'block':
                TSKF_Tickets::update($t->id,[
                    'status'     => 'void',
                    'updated_at' => current_time('mysql'),
                ]);
                break;

            case 'unblock':
                TSKF_Tickets::update($t->id,[
                    'status'     => 'active',
                    'updated_at' => current_time('mysql'),
                ]);
                break;

            case 'extend_days':
                $days = intval($r->get_param('days'));
                if ($days <= 0) $days = 1;
                $end  = $t->period_expires_at ? strtotime($t->period_expires_at) : current_time('timestamp');
                $base = $end;
                if ($days > 0) {
                    $base = strtotime('+'.$days.' days', $base);
                }
                $new = date('Y-m-d H:i:s', $base);
                TSKF_Tickets::update($t->id,[
                    'period_expires_at' => $new,
                    'updated_at'        => current_time('mysql'),
                ]);
                break;

            case 'set_expire':
                $date = sanitize_text_field($r->get_param('date'));
                TSKF_Tickets::update($t->id,[
                    'period_expires_at' => $date,
                    'updated_at'        => current_time('mysql'),
                ]);
                break;

case 'set_entries':
    $entries = max(0, intval($r->get_param('entries')));

    $update_data = [
        'entries_left' => $entries,
        'updated_at'   => current_time('mysql'),
    ];

    // 🔥 jeśli przywracamy wejścia > 0 → zmień status na active
    if ($entries > 0 && $t->status === 'exhausted') {
        $update_data['status'] = 'active';
    }

    TSKF_Tickets::update($t->id, $update_data);
    break;


            case 'edit_info':
                $date   = sanitize_text_field($r->get_param('treatment_date'));
                $client = sanitize_text_field($r->get_param('treatment_client'));
                $mysql  = ($date ? date('Y-m-d H:i:s', strtotime($date)) : null);
                TSKF_Tickets::update($t->id,[
                    'treatment_date'   => $mysql,
                    'treatment_client' => $client,
                    'updated_at'       => current_time('mysql'),
                ]);
                break;

            default:
                return new WP_REST_Response(['ok'=>false,'message'=>'Nieznana akcja'],400);
        }

        $t = TSKF_Tickets::get_by_code($code);

        // LOG: akcja admina na kodzie
        if ( $t ) {
            $meta = [];

            switch ( $action ) {
                case 'block':
                    $meta['note'] = 'Kod zablokowany (void)';
                    break;

                case 'unblock':
                    $meta['note'] = 'Kod odblokowany (active)';
                    break;

                case 'extend_days':
                    $meta['days'] = (int) $r->get_param('days');
                    break;

                case 'set_expire':
                    $meta['expires_at'] = $r->get_param('date');
                    break;

                case 'set_entries':
                    $meta['entries_left'] = (int) $r->get_param('entries');
                    break;

                case 'edit_info':
                    $meta['treatment_date']   = $r->get_param('treatment_date');
                    $meta['treatment_client'] = $r->get_param('treatment_client');
                    break;
            }

            TSKF_Tickets::log_event(
                $t->id,
                $t->code,
                'admin_' . $action,
                $meta
            );
        }

        return new WP_REST_Response(['ok'=>true,'data'=>self::fmt($t)],200);
    }
}

