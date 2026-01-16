<?php
/**
 * Plugin Name: TS Woo – Kolumna "Faktura" w zamówieniach
 * Description: Dodaje kolumnę Faktura (Tak/Nie) w panelu WooCommerce -> Zamówienia.
 * Version: 1.0.2
 * Author: TechSolver
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Ustalanie czy zamówienie ma fakturę.
 *
 * @param WC_Order $order
 * @return bool
 */
function ts_wic_order_has_invoice( $order ) {
    if ( ! $order || ! is_a( $order, 'WC_Order' ) ) {
        return false;
    }

    // Najczęstsze meta pola spotykane w PL (różne wtyczki).
    $vat = trim( (string) $order->get_meta( '_billing_vat' ) );
    $nip = trim( (string) $order->get_meta( '_billing_nip' ) );
    $tax = trim( (string) $order->get_meta( '_billing_tax_id' ) );

    // Standard WooCommerce.
    $company = trim( (string) $order->get_billing_company() );

    if ( $vat !== '' || $nip !== '' || $tax !== '' || $company !== '' ) {
        return true;
    }

    return false;
}

/**
 * Dodanie kolumny w klasycznym widoku zamówień (CPT shop_order).
 */
add_filter( 'manage_edit-shop_order_columns', function( $columns ) {
    $new = array();

    foreach ( $columns as $key => $label ) {
        $new[ $key ] = $label;

        // Wstaw po kolumnie "order_total" (możesz zmienić miejsce).
        if ( 'order_total' === $key ) {
            $new['ts_invoice'] = __( 'Faktura', 'ts-woo-invoice-column' );
        }
    }

    // Jakby nie było order_total, dołóż na końcu.
    if ( ! isset( $new['ts_invoice'] ) ) {
        $new['ts_invoice'] = __( 'Faktura', 'ts-woo-invoice-column' );
    }

    return $new;
}, 20 );

/**
 * Wypełnienie kolumny (CPT shop_order).
 */
add_action( 'manage_shop_order_posts_custom_column', function( $column, $post_id ) {
    if ( 'ts_invoice' !== $column ) {
        return;
    }

    $order = wc_get_order( $post_id );
    if ( ! $order ) {
        echo '—';
        return;
    }

    if ( ts_wic_order_has_invoice( $order ) ) {
        echo '<span class="dashicons dashicons-media-spreadsheet ts-wic-icon" data-tswic="1" style="color:#16a34a;font-size:20px;" title="Faktura"></span>';
    } else {
        echo '<span class="dashicons dashicons-media-spreadsheet ts-wic-icon" data-tswic="0" style="color:#cbd5e1;font-size:20px;opacity:0.4;" title="Brak faktury"></span>';
    }

}, 20, 2 );

/**
 * HPOS: kolumny na nowej liście zamówień (WooCommerce -> Orders / wc-orders).
 * Zadziała gdy włączone jest HPOS.
 */
add_filter( 'manage_woocommerce_page_wc-orders_columns', function( $columns ) {
    $new = array();

    foreach ( $columns as $key => $label ) {
        $new[ $key ] = $label;

        if ( 'order_total' === $key ) {
            $new['ts_invoice'] = __( 'Faktura', 'ts-woo-invoice-column' );
        }
    }

    if ( ! isset( $new['ts_invoice'] ) ) {
        $new['ts_invoice'] = __( 'Faktura', 'ts-woo-invoice-column' );
    }

    return $new;
}, 20 );

/**
 * HPOS: dane w kolumnie.
 */
add_action( 'manage_woocommerce_page_wc-orders_custom_column', function( $column, $order ) {
    if ( 'ts_invoice' !== $column ) {
        return;
    }

    if ( is_numeric( $order ) ) {
        $order = wc_get_order( (int) $order );
    }

    if ( ! $order || ! is_a( $order, 'WC_Order' ) ) {
        echo '—';
        return;
    }

    if ( ts_wic_order_has_invoice( $order ) ) {
        echo '<span class="dashicons dashicons-media-spreadsheet ts-wic-icon" data-tswic="1" style="color:#16a34a;font-size:20px;" title="Faktura"></span>';
    } else {
        echo '<span class="dashicons dashicons-media-spreadsheet ts-wic-icon" data-tswic="0" style="color:#cbd5e1;font-size:20px;opacity:0.4;" title="Brak faktury"></span>';
    }

}, 20, 2 );

/**
 * Admin helper: tylko na listach zamówień.
 */
function ts_wic_is_orders_list_screen() : bool {
    // klasycznie: edit.php?post_type=shop_order
    if ( is_admin() && isset($_GET['post_type']) && $_GET['post_type'] === 'shop_order' ) {
        return true;
    }
    // HPOS: admin.php?page=wc-orders
    if ( is_admin() && isset($_GET['page']) && $_GET['page'] === 'wc-orders' ) {
        return true;
    }
    return false;
}

/**
 * CSS: kolor pomarańczowy dla ikon faktury przy "Panel administratora".
 * Wstrzykujemy bezpośrednio, bez zależności od handle Woo.
 */
add_action( 'admin_head', function() {
    if ( ! ts_wic_is_orders_list_screen() ) {
        return;
    }
    echo '<style>
        .ts-wic-admin-origin{
            color:#f59e0b !important;
            opacity:0.95 !important;
        }
    </style>';
}, 50 );

/**
 * JS: wykryj w wierszu tekst "Panel administratora" w kolumnie Pochodzenie
 * i wtedy ustaw ikonę faktury na pomarańczową.
 *
 * Używa MutationObserver, żeby łapać odświeżenia tabeli.
 */
add_action( 'admin_footer', function() {
    if ( ! ts_wic_is_orders_list_screen() ) {
        return;
    }
    ?>
    <script>
    (function(){
        function norm(s){ return (s||"").replace(/\s+/g," ").trim().toLowerCase(); }

        function isAdminOriginText(txt){
            // Twardo po PL labelu z UI:
            return norm(txt).includes('panel administratora');
        }

        function findOriginCell(row){
            // Najpewniejsze selektory pod to co wkleiłeś:
            return row.querySelector('td.origin, td.column-origin, td[data-colname="Pochodzenie"]');
        }

        function findInvoiceIcon(row){
            // Ikona jest w naszej kolumnie - ale łapiemy szeroko, żeby nie zależeć od klas tabeli.
            return row.querySelector('.ts-wic-icon[data-tswic="1"]');
        }

        function processRow(row){
            try{
                var originCell = findOriginCell(row);
                if(!originCell) return;

                var originText = originCell.textContent || '';
                if(!isAdminOriginText(originText)) return;

                var icon = findInvoiceIcon(row);
                if(!icon) return;

                icon.classList.add('ts-wic-admin-origin');
                icon.setAttribute('title', 'Faktura (Panel administratora – ręcznie)');
            } catch(e){}
        }

        function processTable(){
            // klasyczny WP list table ma tbody#the-list; łapiemy też na zapas inne tbodies.
            var bodies = document.querySelectorAll('tbody#the-list, table.wp-list-table tbody');
            bodies.forEach(function(tbody){
                tbody.querySelectorAll('tr').forEach(processRow);
            });
        }

        // Start
        document.addEventListener('DOMContentLoaded', processTable);

        // Obserwuj zmiany w DOM (odświeżenia listy, filtrowanie, paginacja, itp.)
        var target = document.querySelector('tbody#the-list') || document.body;
        var obs = new MutationObserver(function(){
            processTable();
        });
        obs.observe(target, { childList:true, subtree:true });

        // Dodatkowy strzał po chwili (na wypadek późnego renderu)
        setTimeout(processTable, 600);
        setTimeout(processTable, 1400);
    })();
    </script>
    <?php
}, 50 );
