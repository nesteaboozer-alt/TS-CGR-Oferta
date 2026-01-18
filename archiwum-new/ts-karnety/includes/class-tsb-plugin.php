<?php
class TSB_Plugin {
static function init(){
    add_action('admin_menu',          [__CLASS__, 'menu']);
    add_action('admin_enqueue_scripts',[__CLASS__, 'assets']);
    // add_action('admin_init',       [__CLASS__, 'maybe_add_role']); // przeniesione do mu-plugin
    add_action('admin_notices',       [__CLASS__, 'deps_notice']);

    add_action('wp_ajax_tsb_resend_order',  [__CLASS__, 'ajax_resend_order']);
    add_action('wp_ajax_tsb_recent_codes',  [__CLASS__, 'ajax_recent_codes']);
    add_action('wp_ajax_tsb_stats',         [__CLASS__, 'ajax_stats']);
    add_action('wp_ajax_tsb_manual_create', [__CLASS__, 'ajax_manual_create']);
    add_action('wp_ajax_tsb_time_window',   [__CLASS__, 'ajax_time_window']); // okna godzinowe
}





    static function deps_notice(){
        if (!current_user_can('manage_woocommerce')) return;
        $miss = [];
        if (!class_exists('WooCommerce')) $miss[] = 'WooCommerce';
        if (!class_exists('TSKF_Tickets')) $miss[] = 'TS Karnety';
        if ($miss){
            echo '<div class="notice notice-error"><p><strong>TS Backoffice Dashboard</strong>: brak zależności: '.esc_html(implode(', ', $miss)).'.</p></div>';
        }
    }




static function menu(){
    add_menu_page(
        'TS Panel',
        'TS Panel',
        'tsb_access_panel',              // ⬅ KLUCZ: używamy naszego capa
        'tsb',
        [__CLASS__, 'page_dashboard'],
        'dashicons-screenoptions',
        55
    );
}


    static function assets($hook){
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        $id = $screen ? $screen->id : '';
        if ($id !== 'toplevel_page_tsb') return;

        wp_enqueue_style('tsb-admin', TSB_URL.'assets/tsb-admin.css', [], TSB_VER);
        wp_enqueue_script('tsb-admin', TSB_URL.'assets/tsb-admin.js', ['jquery'], TSB_VER, true);

        wp_localize_script('tsb-admin','TSB',[
            'rest'  => esc_url_raw( rest_url('tskf/v1') ),
            'nonce' => wp_create_nonce('wp_rest'),
            'ajax'  => admin_url('admin-ajax.php'),
            'i18n'  => [
                'checking'=>'Sprawdzam...',
                'using'   =>'Zatwierdzam...',
                'ok'      =>'OK',
                'err'     =>'Błąd',
                'saved'   =>'Zapisano'
            ],
            'orders'=> admin_url('edit.php?post_type=shop_order')
        ]);
    }

static function page_dashboard(){
    // Uprawnienia do wejścia do TS Panel:
    // - tsb_access_panel (kasjer)
    // - manage_woocommerce (administrator lub manager)
    if (
        ! current_user_can('tsb_access_panel')
        && ! current_user_can('manage_woocommerce')
    ) {
        wp_die('Brak uprawnień');
    }
    ?>
    <div class="tsb-wrap">

          <h1>TS Backoffice TEST</h1>

          <div class="tsb-tabs">
            <button class="tsb-tab is-active" data-pane="overview">Przegląd</button>
            <button class="tsb-tab" data-pane="codes">Kody</button>
            <button class="tsb-tab" data-pane="manual">Ręczne generowanie</button>
          </div>

          <!-- PANE: PRZEGLĄD -->
          <!-- PANE: PRZEGLĄD -->
          <div class="tsb-pane is-active" id="tsb-pane-overview">
            <div class="tsb-toolbar">
              <h2 style="margin:0;">Przegląd</h2>
              <div style="flex:1"></div>
              <button class="button" id="tsb-refresh-overview">Odśwież</button>
            </div>
            <section class="tsb-grid">

              <div class="tsb-card tsb-span2">
                <h2>Weryfikacja kodu</h2>
                <div class="tsb-row">
                  <input type="text" id="tsb-code" placeholder="Wpisz / zeskanuj kod" autofocus />
                  <button class="button button-primary" id="tsb-check">Sprawdź</button>
                  <button class="button" id="tsb-use">Użyj teraz</button>
                </div>
                <div id="tsb-result" class="tsb-result" aria-live="polite"></div>
              </div>

              <div class="tsb-card">
                <h2>Okresowe aktywne</h2>
                <div id="tsb-period" class="tsb-mini">
                  <div class="tsb-loading">Ładowanie…</div>
                </div>
              </div>

              <div class="tsb-card">
                <h2>Oczekujące wysyłki</h2>
                <div id="tsb-pending" class="tsb-mini">
                  <div class="tsb-loading">Ładowanie…</div>
                </div>
                <div class="tsb-row" style="margin-top:8px">
                  <a href="#" id="tsb-go-orders" class="button">Przejdź do zamówień</a>
                </div>
              </div>

              <div class="tsb-card">
                <h2>Użyte dzisiaj</h2>
                <div id="tsb-used-today" class="tsb-mini">
                  <div class="tsb-loading">Ładowanie…</div>
                </div>
              </div>

              <div class="tsb-card">
                <h2>Sprzedaż (7 dni)</h2>
                <div id="tsb-sales" class="tsb-mini">
                  <div class="tsb-loading">Ładowanie…</div>
                </div>
              </div>

              <div class="tsb-card">
                <h2>Niedługo wygasają</h2>
                <div id="tsb-expiring" class="tsb-mini" style="max-height:280px;overflow:auto">
                  <div class="tsb-loading">Ładowanie…</div>
                </div>
              </div>

              <div class="tsb-card">
                <h2>Ostatnie kody (10)</h2>
                <div id="tsb-recent10" class="tsb-mini" style="max-height:280px;overflow:auto">
                  <div class="tsb-loading">Ładowanie…</div>
                </div>
              </div>

              <div class="tsb-card">
                <h2>Top klienci (30 dni)</h2>
                <div id="tsb-top" class="tsb-mini">
                  <div class="tsb-loading">Ładowanie…</div>
                </div>
              </div>
            </section>
          </div>

          <!-- PANE: KODY -->
          <div class="tsb-pane" id="tsb-pane-codes">
            <div class="tsb-card">
              <div class="tsb-toolbar">
                <h2 style="margin:0;">Lista kodów</h2>
                <div style="flex:1"></div>
                <input type="search" id="tsb-find" placeholder="Szukaj po kodzie…" />
                <button class="button" id="tsb-reload-codes">Odśwież</button>
              </div>
              <div id="tsb-codes" class="tsb-table-wrap">
                <div class="tsb-loading">Ładowanie…</div>
              </div>
            </div>
          </div>

          <!-- PANE: RĘCZNE GENEROWANIE -->
          <div class="tsb-pane" id="tsb-pane-manual">
            <div class="tsb-toolbar">
              <h2 style="margin:0;">Ręczne generowanie</h2>
              <div style="flex:1"></div>
              <button class="button" id="tsb-refresh-manual">Odśwież</button>
            </div>
            <div class="tsb-card">
                <h2>Utwórz zamówienie offline i wygeneruj kody</h2>

              <div class="tsb-row">
                  <input type="text" id="tsb-m-company" placeholder="Nazwa firmy (WYMAGANE)" style="flex:2; margin-right:8px;" />
                  <input type="text" id="tsb-m-nip" placeholder="NIP (WYMAGANE)" style="flex:1;" />
              </div>

              <div class="tsb-row">
                <input type="email" id="tsb-m-email" placeholder="E-mail klienta (opcjonalnie)" />
              </div>

              <div class="tsb-row">
                <?php
                $products = function_exists('wc_get_products') ? wc_get_products([
                    'status'  => 'publish',
                    'limit'   => -1,
                    'orderby' => 'title',
                    'order'   => 'ASC',
                ]) : [];
                ?>
                <select id="tsb-m-product">
                  <option value="">— wybierz produkt —</option>
                  <?php foreach ( $products as $product ) : ?>
                    <option value="<?php echo esc_attr( $product->get_id() ); ?>">
                      <?php echo esc_html( $product->get_name() ); ?>
                    </option>
                  <?php endforeach; ?>
                </select>

                <input
                  type="number"
                  id="tsb-m-qty"
                  placeholder="Ilość"
                  value="1"
                  min="1"
                  style="max-width:90px;margin-left:8px;"
                />

                <label style="margin-left:12px;">
                  <input type="checkbox" id="tsb-m-send" checked />
                  Wyślij e-mail z kodami
                </label>

                <button class="button button-primary" id="tsb-m-create" style="margin-left:auto;">
                  Utwórz
                </button>
              </div>

              <div id="tsb-m-result" class="tsb-result" aria-live="polite"></div>
              <div class="tsb-hint">Wymaga produktów skonfigurowanych jako karnety w WooCommerce.</div>
            </div>
          </div>

        <?php
    }

    // ====== AJAX & helpers ======

static function ajax_resend_order(){
    // Admin (manage_woocommerce) LUB Kasjer (tsb_access_panel)
    if ( ! ( current_user_can('manage_woocommerce') || current_user_can('tsb_access_panel') ) ) {
        wp_send_json_error(['msg'=>'Brak uprawnień (tsb_resend_order)']);
    }

    $order_id = (int)($_POST['order_id'] ?? 0);
    if ( ! $order_id ) {
        wp_send_json_error(['msg'=>'Brak ID']);
    }

    // Przekazujemy dalej do core'owego handlera z TSKF_Admin
    $_POST['action'] = 'tskf_resend_codes';
    do_action('wp_ajax_tskf_resend_codes');

    wp_send_json_success(['msg'=>'Zlecono wysyłkę (sprawdź notatki zamówienia).']);
}


    static function ajax_recent_codes(){
        if ( ! current_user_can('manage_woocommerce') ) {
            wp_send_json_error();
        }
        if ( ! class_exists('TSKF_Tickets') ) {
            wp_send_json_error(['msg' => 'Brak TS Karnety']);
        }

        global $wpdb;
        $t = TSKF_Tickets::table();

// Szukajka – pozwalamy na wpisywanie kodu bez myślników, w dowolnym case
$q_raw = isset($_POST['q']) ? sanitize_text_field($_POST['q']) : '';
$q     = $q_raw;

// Znormalizowana wersja – tylko litery/cyfry, wielkie litery
$q_norm = preg_replace('/[^A-Za-z0-9]/', '', $q_raw);
$q_norm = strtoupper($q_norm);


        // Paginacja
        $page     = isset($_POST['page']) ? max(1, (int)$_POST['page']) : 1;
        $per_page = 30;
        $offset   = ($page - 1) * $per_page;

// Warunek WHERE + parametry
$where  = '1=1';
$params = [];

// Jeżeli coś wpisano w szukajkę
if ( $q_norm !== '' ) {
    // Porównujemy po "oczyszczonym" kodzie:
    // REPLACE(UPPER(code), '-', '') LIKE UPPER(wpis_bez_śmieci)
    $where     .= ' AND REPLACE(UPPER(code), "-", "") LIKE %s';
    $params[]   = '%' . $wpdb->esc_like( $q_norm ) . '%';
}


        // Liczba wszystkich rekordów (do paginacji)
        if ( ! empty($params) ) {
            $sql_total = $wpdb->prepare(
                "SELECT COUNT(*) FROM $t WHERE $where",
                $params
            );
        } else {
            $sql_total = "SELECT COUNT(*) FROM $t WHERE $where";
        }
        $total       = (int) $wpdb->get_var( $sql_total );
        $total_pages = max(1, (int) ceil($total / $per_page));

        // Pobranie rekordów dla danej strony
        if ( ! empty($params) ) {
            $params_rows = $params;
            $params_rows[] = $per_page;
            $params_rows[] = $offset;
            $sql_rows = $wpdb->prepare(
                "SELECT * FROM $t WHERE $where ORDER BY id DESC LIMIT %d OFFSET %d",
                $params_rows
            );
        } else {
            $sql_rows = $wpdb->prepare(
                "SELECT * FROM $t WHERE $where ORDER BY id DESC LIMIT %d OFFSET %d",
                $per_page,
                $offset
            );
        }

        $rows = $wpdb->get_results($sql_rows);

        ob_start();
        ?>
        <style>
          .tsk-chip{display:inline-block;padding:4px 10px;border-radius:999px;font-size:12px;font-weight:700;line-height:1}
          .tsk-chip--active{background:#ecfdf5;color:#065f46}
          .tsk-chip--running{background:#eff6ff;color:#1e40af}
          .tsk-chip--exhausted{background:#fef3c7;color:#92400e}
          .tsk-chip--expired{background:#fee2e2;color:#991b1b}
          .tsk-chip--void{background:#e5e7eb;color:#374151}
          .tsb-dropdown{position:relative;display:inline-block}
          .tsb-dropdown-menu{display:none;position:absolute;right:0;top:100%;background:#fff;border:1px solid #e5e7eb;border-radius:8px;min-width:180px;z-index:9}
          .tsb-dropdown-menu a{display:block;padding:8px 10px;text-decoration:none;color:#111}
          .tsb-dropdown-menu a:hover{background:#f3f4f6}
          .tsb-pagination{margin-top:10px;display:flex;flex-wrap:wrap;gap:4px;justify-content:flex-end}
          .tsb-page-link{border:1px solid #d1d5db;background:#fff;padding:2px 8px;border-radius:4px;cursor:pointer;font-size:12px}
          .tsb-page-link.is-active{background:#2563eb;color:#fff;border-color:#2563eb}
        </style>
        <table class="tsb-table">
          <thead>
            <tr>
              <th>Kod</th><th>Produkt</th><th>Kategoria</th><th>Typ</th><th>Status</th>
              <th>Wejścia</th><th>Start</th><th>Koniec</th><th>Data zabiegu</th><th>Odbiorca</th><th>Akcje</th>
            </tr>
          </thead>
          <tbody>
          <?php if ( $rows ) : ?>
            <?php foreach ( $rows as $t_row ) :
              $prod  = $t_row->product_id ? get_the_title($t_row->product_id) : '-';
              $terms = get_the_terms($t_row->product_id, 'product_cat');
              $badge = '';
              if ($terms && !is_wp_error($terms) && !empty($terms)) {
                $term = $terms[0];
                $h    = intval(hexdec(substr(md5($term->slug),0,2)) % 360);
                $badge = '<span style="background:hsl('.$h.',65%,86%);color:#083344;padding:3px 8px;border-radius:999px;font-size:11px;font-weight:700;">'.esc_html($term->name).'</span>';
              }
              $status_map = [
                'active'    => ['Aktywny','active'],
                'activated' => ['Aktywny (okres)','running'],
                'exhausted' => ['Wykorzystany','exhausted'],
                'expired'   => ['Wygasły','expired'],
                'void'      => ['Unieważniony','void'],
              ];
              $m = isset($status_map[$t_row->status]) ? $status_map[$t_row->status] : [$t_row->status,'active'];
            ?>
            <tr>
              <td><code><?php echo esc_html($t_row->code); ?></code></td>
              <td><?php echo esc_html($prod); ?></td>
              <td><?php echo $badge; ?></td>
              <td><?php echo esc_html($t_row->ticket_type); ?></td>
              <td><span class="tsk-chip tsk-chip--<?php echo esc_attr($m[1]); ?>"><?php echo esc_html($m[0]); ?></span></td>
              <td><?php echo intval($t_row->entries_left).' / '.intval($t_row->entries_total); ?></td>
              <td><?php echo esc_html($t_row->period_started_at ?: '—'); ?></td>
              <td><?php echo esc_html($t_row->period_expires_at ?: '—'); ?></td>
              <td><?php echo esc_html($t_row->treatment_date ?: '—'); ?></td>
              <td><?php echo esc_html($t_row->treatment_client ?: '—'); ?></td>
              <td>
                <div class="tsb-dropdown">
                  <button class="button">Akcje ▾</button>
                  <div class="tsb-dropdown-menu">
                    <a href="#" class="tsb-act" data-action="block" data-code="<?php echo esc_attr($t_row->code); ?>">Zablokuj</a>
                    <a href="#" class="tsb-act" data-action="unblock" data-code="<?php echo esc_attr($t_row->code); ?>">Odblokuj</a>
                    <a href="#" class="tsb-act" data-action="extend_days" data-days="1" data-code="<?php echo esc_attr($t_row->code); ?>">+1 dzień</a>
                    <a href="#" class="tsb-act" data-action="set_expire" data-code="<?php echo esc_attr($t_row->code); ?>">Ustaw koniec</a>
                    <a href="#" class="tsb-act" data-action="set_entries" data-code="<?php echo esc_attr($t_row->code); ?>">Ustaw wejścia</a>
                    <a href="#" class="tsb-edit"    data-code="<?php echo esc_attr($t_row->code); ?>">Edytuj informacje</a>
                    <a href="#" class="tsb-history" data-code="<?php echo esc_attr($t_row->code); ?>">Historia</a>
                    <a href="<?php echo esc_url( admin_url('post.php?post='.intval($t_row->order_id).'&action=edit') ); ?>" target="_blank">Dane (zamówienie)</a>
                  </div>
                </div>
              </td>
            </tr>
            <?php endforeach; ?>
          <?php else : ?>
            <tr><td colspan="11"><em>Brak wyników.</em></td></tr>
          <?php endif; ?>
          </tbody>
        </table>

        <?php if ( $total_pages > 1 ) : ?>
          <div class="tsb-pagination">
            <?php for ( $p = 1; $p <= $total_pages; $p++ ) : ?>
              <?php $cls = 'tsb-page-link'.( $p === $page ? ' is-active' : '' ); ?>
              <button type="button" class="<?php echo esc_attr($cls); ?>" data-page="<?php echo esc_attr($p); ?>">
                <?php echo esc_html($p); ?>
              </button>
            <?php endfor; ?>
          </div>
        <?php endif; ?>

        <?php
        $html = ob_get_clean();

        wp_send_json_success([
            'html'        => $html,
            'page'        => $page,
            'total_pages' => $total_pages,
            'total'       => $total,
        ]);
    }


    static function ajax_stats(){
        if (!current_user_can('manage_woocommerce')) wp_send_json_error();
        $out = [
            'used_today'   =>['count'=>0,'last'=>[]],
            'period'       =>['active'=>0,'ending_soon'=>0],
            'pending_send' =>0,
            'sales'        =>['total'=>0,'by_cat'=>[]],
            'top_customers'=>[],
            'expiring'     =>[]
        ];
        try {
            $tz = wp_timezone();
            $today_start = new DateTime('today', $tz);
            $today_end   = new DateTime('tomorrow', $tz);
            $today_start_sql = $today_start->format('Y-m-d H:i:s');
            $today_end_sql   = $today_end->format('Y-m-d H:i:s');

            if (class_exists('TSKF_Tickets')){
                global $wpdb;
                $t = TSKF_Tickets::table();
                // used today
                $out['used_today']['count'] = (int)$wpdb->get_var($wpdb->prepare(
                    "SELECT COUNT(*) FROM $t WHERE last_checked_at >= %s AND last_checked_at < %s",
                    $today_start_sql, $today_end_sql
                ));
                $rows = $wpdb->get_results($wpdb->prepare(
                    "SELECT code, product_id, last_checked_at FROM $t WHERE last_checked_at >= %s AND last_checked_at < %s ORDER BY last_checked_at DESC LIMIT 5",
                    $today_start_sql, $today_end_sql
                ));
                if ($rows) {
                    foreach($rows as $r){
                        $out['used_today']['last'][] = [
                            'code'   => $r->code,
                            'product'=> $r->product_id ? get_the_title($r->product_id) : '',
                            'ts'     => $r->last_checked_at
                        ];
                    }
                }
                // period
                $now  = current_time('mysql');
                $soon = gmdate('Y-m-d H:i:s', strtotime('+3 days', current_time('timestamp')));
                $out['period']['active'] = (int)$wpdb->get_var($wpdb->prepare(
                    "SELECT COUNT(*) FROM $t WHERE ticket_type=%s AND (status=%s OR status=%s)",
                    'period','activated','active'
                ));
                $out['period']['ending_soon'] = (int)$wpdb->get_var($wpdb->prepare(
                    "SELECT COUNT(*) FROM $t WHERE ticket_type=%s AND period_expires_at IS NOT NULL AND period_expires_at >= %s AND period_expires_at <= %s",
                    'period', $now, $soon
                ));

                // expiring soon list
                $expiring = $wpdb->get_results($wpdb->prepare(
                    "SELECT code, product_id, period_expires_at FROM $t WHERE ticket_type=%s AND period_expires_at IS NOT NULL AND period_expires_at >= %s AND period_expires_at <= %s ORDER BY period_expires_at ASC LIMIT 10",
                    'period', $now, $soon
                ));
                if ($expiring){
                    foreach($expiring as $e){
                        $remaining = (strtotime($e->period_expires_at) - current_time('timestamp')) / 3600;
                        $out['expiring'][] = [
                            'code'       => $e->code,
                            'product'    => $e->product_id ? get_the_title($e->product_id) : '',
                            'expires_at' => $e->period_expires_at,
                            'hours_left' => max(0, round($remaining))
                        ];
                    }
                }
            }

            if (class_exists('WooCommerce')){
                // pending/on-hold without codes
                $orders = wc_get_orders([
                    'limit'        => 100,
                    'status'       => ['pending','on-hold'],
                    'orderby'      => 'date',
                    'order'        => 'DESC',
                    'return'       => 'ids'
                ]);
                $pending_count = 0;
                foreach($orders as $oid){
                    $o = wc_get_order($oid);
                    $has_codes = false;
                    foreach($o->get_items() as $item_id=>$it){
                        $codes = (array)wc_get_order_item_meta($item_id,'_ts_code', false);
                        if (!empty(array_filter($codes))) { $has_codes = true; break; }
                    }
                    if (!$has_codes) $pending_count++;
                }
                $out['pending_send'] = $pending_count;

                // Sales 7 days
                $date   = (new DateTime('-7 days', wp_timezone()))->format('Y-m-d H:i:s');
                $orders = wc_get_orders([
                    'limit'        => -1,
                    'status'       => ['completed','processing'],
                    'date_created' => '>='.$date,
                    'return'       => 'ids'
                ]);
                $cat_map = [];
                $total = 0.0;
                foreach($orders as $oid){
                    $o = wc_get_order($oid);
                    foreach($o->get_items() as $it){
                        $total += (float)$it->get_total();
                        $pid   = $it->get_product_id();
                        $terms = get_the_terms($pid,'product_cat');
                        $cat   = ($terms && !is_wp_error($terms) && !empty($terms)) ? $terms[0]->name : 'Inne';
                        $cat_map[$cat] = ($cat_map[$cat] ?? 0) + (float)$it->get_total();
                    }
                }
                $out['sales']['total']  = $total;
                $out['sales']['by_cat'] = $cat_map;

                // Top customers 30 days
                $date30 = (new DateTime('-30 days', wp_timezone()))->format('Y-m-d H:i:s');
                $orders = wc_get_orders([
                    'limit'        => -1,
                    'status'       => ['completed','processing'],
                    'date_created' => '>='.$date30,
                    'return'       => 'ids'
                ]);
                $cust = [];
                foreach($orders as $oid){
                    $o     = wc_get_order($oid);
                    $email = strtolower($o->get_billing_email() ?: '—');
                    $sum   = (float)$o->get_total();
                    $qty   = 0;
                    foreach($o->get_items() as $it){ $qty += (int)$it->get_quantity(); }
                    if (!isset($cust[$email])) {
                        $cust[$email] = [
                            'email'=>$email,
                            'qty'  =>0,
                            'total'=>0.0
                        ];
                    }
                    $cust[$email]['qty']   += $qty;
                    $cust[$email]['total'] += $sum;
                }
                usort($cust, function($a,$b){ return $b['total'] <=> $a['total']; });
                $out['top_customers'] = array_slice($cust, 0, 5);
            }
        } catch (Throwable $e){
            // możesz ewentualnie logować błędy
        }
        wp_send_json_success($out);
    }

    static function ajax_manual_create(){
      if (!current_user_can('manage_woocommerce')) {
          wp_send_json_error(['msg'=>'Brak uprawnień']);
      }

      $email      = sanitize_email($_POST['email'] ?? '');
      // [MODIFIED] Odbiór danych firmy
      $company    = sanitize_text_field($_POST['company'] ?? '');
      $nip        = sanitize_text_field($_POST['nip'] ?? '');

      $product_id = intval($_POST['product_id'] ?? 0);
      $qty        = max(1, intval($_POST['qty'] ?? 1));
      $send       = !empty($_POST['send']);

      if (!$product_id) {
          wp_send_json_error(['msg'=>'Brak produktu']);
      }
      
      // [MODIFIED] Walidacja wymaganych pól B2B
      if (empty($company) || empty($nip)) {
          wp_send_json_error(['msg'=>'Nazwa firmy i NIP są wymagane!']);
      }

        if (!function_exists('wc_create_order') || !function_exists('wc_get_product')) {
            wp_send_json_error(['msg'=>'WooCommerce nie jest aktywny']);
        }

        $product = wc_get_product($product_id);
        if (!$product) {
            wp_send_json_error(['msg'=>'Nieprawidłowy produkt']);
        }

        $order = wc_create_order();
        $order->add_product($product, $qty);
        $order->calculate_totals();

        if ($email) {
          $order->set_billing_email($email);
      }

      // [MODIFIED] Ustawienie danych firmowych (blokuje eparagony)
      $order->set_billing_company($company);
      // Zapis NIP w standardowym polu meta (używanym przez wtyczki księgowe/PL)
      $order->update_meta_data('_billing_nip', $nip);

      $order->save();
      $order->update_status('completed','Backoffice: ręczne generowanie kodów (Firma: '.$company.', NIP: '.$nip.').');

        if ($send && $email && class_exists('TSKF_Email')){
            TSKF_Email::send($order->get_id(), true);
        }

        wp_send_json_success([
            'msg'=>'Zamówienie #'.$order->get_order_number().' utworzone.'
        ]);
    }

    /**
     * AJAX: sprawdza okno godzinowe produktu na podstawie kodu
     */
    public static function ajax_time_window() {
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(['msg'=>'Brak uprawnień']);
        }

        $code = sanitize_text_field($_POST['code'] ?? '');
        if (!$code) {
            wp_send_json_error(['msg'=>'Brak kodu']);
        }

        if (!class_exists('TSKF_Tickets')) {
            wp_send_json_error(['msg'=>'Brak bazy karnetów']);
        }

        global $wpdb;
        $table = TSKF_Tickets::table();
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT product_id FROM $table WHERE code = %s",
            $code
        ));

        if (!$row) {
            wp_send_json_error(['msg'=>'Nie znaleziono kodu']);
        }

        $win = self::get_product_time_window($row->product_id);

        wp_send_json_success(['window' => $win]);
    }

    /**
     * Sprawdza, czy produkt ma okno godzinowe (jednorazowy 9–16, 16–21)
     */
public static function get_product_time_window( $product_id ) {
    if ( ! $product_id ) {
        return null;
    }

    $ids = [ (int) $product_id ];

    // Jeśli to wariant, dorzucamy rodzica
    if ( function_exists( 'wc_get_product' ) ) {
        $product = wc_get_product( $product_id );
        if ( $product && $product->get_parent_id() ) {
            $ids[] = (int) $product->get_parent_id();
        }
    }

    $ids = array_values( array_unique( array_filter( $ids ) ) );

    // Helper do mapowania sluga na okno
    $map_window = function( $slug ) {
        $slug = sanitize_title( $slug );

        if ( $slug === 'jednorazowy-9-16' ) {
            return [
                'type'  => 'single_9_16',
                'from'  => 9,
                'to'    => 16,
                'label' => '9:00 – 16:00',
            ];
        }

        if ( $slug === 'jednorazowy-16-21' ) {
            return [
                'type'  => 'single_16_21',
                'from'  => 16,
                'to'    => 21,
                'label' => '16:00 – 21:00',
            ];
        }

        return null;
    };

    foreach ( $ids as $id ) {
        // 1) Najpierw kategorie product_cat
        $terms = get_the_terms( $id, 'product_cat' );
        if ( $terms && ! is_wp_error( $terms ) ) {
            foreach ( $terms as $t ) {
                $w = $map_window( $t->slug );
                if ( $w ) {
                    return $w;
                }
            }
        }

        // 2) Jeśli brak – patrzymy na slug samego produktu / rodzica
        if ( function_exists( 'wc_get_product' ) ) {
            $p = wc_get_product( $id );
            if ( $p ) {
                $base_slug = method_exists( $p, 'get_slug' ) ? $p->get_slug() : $p->get_name();
                $w = $map_window( $base_slug );
                if ( $w ) {
                    return $w;
                }
            }
        }
    }

    // Brak dopasowania – produkt bez ograniczeń godzinowych
    return null;
}


}
