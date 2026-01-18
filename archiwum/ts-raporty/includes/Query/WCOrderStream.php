<?php
namespace TSR\Query;

use TSR\Filters\FilterDTO;

if (!defined('ABSPATH')) { exit; }

final class WCOrderStream {

    /**
     * Stream orders using WC_Order_Query based on shared filters.
     * Note: product/category filters are applied at item-level (not order query).
     *
     * @return \Generator<int, \WC_Order>
     */
    public static function orders(FilterDTO $f): \Generator {
        $args = [
            'orderby' => 'date',
            'order'   => 'DESC',
            'return'  => 'objects',
            'limit'   => 100,
            'page'    => 1,
        ];

        // Budowanie pancernego zapytania o daty
        if (!empty($f->date_from) || !empty($f->date_to)) {
            $from = !empty($f->date_from) ? date('Y-m-d', strtotime($f->date_from)) : '2000-01-01';
            $to   = !empty($f->date_to)   ? date('Y-m-d', strtotime($f->date_to))   : date('Y-m-d');
            
            // Format YYYY-MM-DD...YYYY-MM-DD jest najbezpieczniejszy dla WC_Order_Query
            $args['date_created'] = $from . '...' . $to;
        }

        // Statusy - rzutowanie na string dla bezpieczeństwa
        if (!empty($f->statuses)) {
            $args['status'] = array_map(function($s) {
                $s = (string)$s;
                return (strpos($s, 'wc-') === 0) ? substr($s, 3) : $s;
            }, (array)$f->statuses);
        }

        // Metody płatności - optymalizacja na poziomie SQL tylko dla pojedynczej
        if (!empty($f->payment_methods) && count((array)$f->payment_methods) === 1) {
            $methods = (array)$f->payment_methods;
            $args['payment_method'] = $methods[0];
        }

        // --- OPTYMALIZACJA SQL: Filtracja Produktów i Kategorii ---
        if (!empty($f->product_names) || !empty($f->categories)) {
            global $wpdb;
            $found_order_ids = [];

            // Szukanie po nazwach produktów
            if (!empty($f->product_names)) {
                $names_placeholder = implode("','", array_map('esc_sql', $f->product_names));
                $found_order_ids = $wpdb->get_col("
                    SELECT DISTINCT order_id FROM {$wpdb->prefix}woocommerce_order_items 
                    WHERE order_item_name IN ('$names_placeholder') AND order_item_type = 'line_item'
                ");
            }

            // Szukanie po kategoriach
            if (!empty($f->categories)) {
                $cat_ids = implode(",", array_map('absint', $f->categories));
                $cat_order_ids = $wpdb->get_col("
                    SELECT DISTINCT oi.order_id 
                    FROM {$wpdb->prefix}woocommerce_order_items oi
                    JOIN {$wpdb->prefix}woocommerce_order_itemmeta oim ON oi.order_item_id = oim.order_item_id
                    JOIN {$wpdb->prefix}term_relationships tr ON oim.meta_value = tr.object_id
                    WHERE oim.meta_key = '_product_id' AND tr.term_taxonomy_id IN ($cat_ids)
                ");
                
                // Jeśli filtrowaliśmy już po nazwach, wyciągamy część wspólną. Jeśli nie - bierzemy wyniki z kategorii.
                if (!empty($f->product_names)) {
                    $found_order_ids = array_intersect($found_order_ids, $cat_order_ids);
                } else {
                    $found_order_ids = $cat_order_ids;
                }
            }

            if (empty($found_order_ids)) { return; } // Nic nie znaleziono - kończymy generator
            $args['post__in'] = array_map('absint', $found_order_ids);
        }

        while (true) {
            try {
                $q = new \WC_Order_Query($args);
                $orders = $q->get_orders();
            } catch (\Exception $e) {
                // Jeśli WC_Order_Query wywali błąd, przerywamy generator zamiast sypać krytykiem
                break; 
            }

            if (empty($orders)) {
                break;
            }

            foreach ($orders as $order) {
                if (!$order instanceof \WC_Order) {
                    continue;
                }

                // --- NOWA LOGIKA: Filtracja po dacie zdarzenia (Karnety) ---
                // Jeśli szukamy po dacie użycia, ignorujemy standardowy filtr daty zakupu WC
                $is_event_search = !empty($f->event_date_from) || !empty($f->event_date_to);
                
                if (!$is_event_search) {
                    $created = $order->get_date_created();
                    if ($created) {
                        $ts = $created->getTimestamp();
                        if (!empty($f->date_from)) {
                            $from_ts = strtotime($f->date_from . ' 00:00:00');
                            if ($from_ts && $ts < $from_ts) { continue; }
                        }
                        if (!empty($f->date_to)) {
                            $to_ts = strtotime($f->date_to . ' 23:59:59');
                            if ($to_ts && $ts > $to_ts) { continue; }
                        }
                    }
                }

                // Filtracja wielu metod płatności w PHP (jeśli wybrano > 1)
                if (!empty($f->payment_methods) && count((array)$f->payment_methods) > 1) {
                    if (!in_array($order->get_payment_method(), (array)$f->payment_methods, true)) {
                        continue;
                    }
                }

                // --- POPRAWKA: Filtracja po Nazwach Produktów (zamiast item->get_name()) ---
                if (!empty($f->product_names)) {
                    $has_product = false;
                    foreach ($order->get_items() as $item) {
                        $product = $item->get_product();
                        if ($product && in_array($product->get_name(), (array)$f->product_names)) {
                            $has_product = true;
                            break;
                        }
                    }
                    if (!$has_product) { continue; }
                }

                // 2. Filtracja po Kategoriach (Dodano)
                if (!empty($f->categories)) {
                    $has_category = false;
                    foreach ($order->get_items() as $item) {
                        $product_id = $item->get_product_id();
                        $item_cats = wp_get_post_terms($product_id, 'product_cat', ['fields' => 'ids']);
                        if (!is_wp_error($item_cats) && array_intersect($item_cats, (array)$f->categories)) {
                            $has_category = true;
                            break;
                        }
                    }
                    if (!$has_category) { continue; }
                }

                // 3. Filtracja po Budynkach (TS-Meals)
                if (!empty($f->buildings)) {
                    $has_building = false;
                    foreach ($order->get_items() as $item) {
                        // Pobieramy meta budynku zapisaną przez TS-Meals
                        $building = $item->get_meta('_tsme_object', true);
                        if (in_array($building, (array)$f->buildings, true)) {
                            $has_building = true;
                            break;
                        }
                    }
                    if (!$has_building) { continue; }
                }

                // Filtracja NIP
                if ($f->invoice_mode !== 'all') {
                    $nip = trim((string)$order->get_meta('_billing_nip', true));
                    if ($f->invoice_mode === 'with' && $nip === '') {
                        continue;
                    }
                    if ($f->invoice_mode === 'without' && $nip !== '') {
                        continue;
                    }
                }

                // Filtracja po Pochodzeniu (Origin)
                if ($f->origin_mode !== 'all') {
                    $via = strtolower((string)$order->get_meta('_created_via', true));
                    // Za ADMINA uznajemy 'admin', 'manual' lub puste pole (częste przy ręcznych zamówieniach)
                    $is_admin = ($via === 'admin' || $via === 'manual' || $via === '');
                    
                    if ($f->origin_mode === 'admin' && !$is_admin) { continue; }
                    if ($f->origin_mode === 'web' && $is_admin) { continue; }
                }

                yield $order;
            }

            $args['page']++;
            
            // Zabezpieczenie przed nieskończoną pętlą przy błędach paginacji
            if ($args['page'] > 1000) { break; } 
        }
    }
}
