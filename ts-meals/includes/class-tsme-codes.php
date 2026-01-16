<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Logika kodów TS Hotel Meals:
 * - tworzenie tabeli w DB
 * - generowanie unikalnych kodów
 * - zapis do tabeli
 * - pomocnicze funkcje (normalizacja, lookup).
 */
class TSME_Codes {

    const TABLE_NAME = 'tsme_meal_codes';

    /**
     * Rejestracja hooków.
     */
    public static function init() {
        // Tworzenie tabeli (bez paniki – IF NOT EXISTS).
        add_action( 'init', array( __CLASS__, 'maybe_create_table' ) );

        // Generowanie kodów przy zmianie statusu zamówienia na "completed" (Zrealizowane).
        add_action( 'woocommerce_order_status_completed', array( __CLASS__, 'handle_order_completed' ), 10, 1 );
    }

    /**
     * Zwraca pełną nazwę tabeli z prefixem.
     */
    public static function get_table_name() {
        global $wpdb;
        return $wpdb->prefix . self::TABLE_NAME;
    }

    /**
     * Tworzy tabelę w bazie, jeśli nie istnieje.
     */
    public static function maybe_create_table() {
        global $wpdb;

        $table_name = self::get_table_name();
        $charset_collate = $wpdb->get_charset_collate();

        // Prosty check czy tabela istnieje.
        $exists = $wpdb->get_var( $wpdb->prepare(
            "SHOW TABLES LIKE %s",
            $table_name
        ) );

        if ( $exists === $table_name ) {
            return;
        }

        $sql = "
            CREATE TABLE {$table_name} (
                id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
                order_id BIGINT(20) UNSIGNED NOT NULL,
                order_item_id BIGINT(20) UNSIGNED NOT NULL,
                code VARCHAR(32) NOT NULL,
                meal_type VARCHAR(20) NOT NULL DEFAULT '',
                building VARCHAR(100) NOT NULL DEFAULT '',
                object_label VARCHAR(191) NOT NULL DEFAULT '',
                room_number VARCHAR(100) NOT NULL DEFAULT '',
                stay_from DATE NULL DEFAULT NULL,
                stay_to DATE NULL DEFAULT NULL,
                adults INT(11) NOT NULL DEFAULT 0,
                children INT(11) NOT NULL DEFAULT 0,
                status VARCHAR(20) NOT NULL DEFAULT 'new',
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                used_at DATETIME NULL DEFAULT NULL,
                PRIMARY KEY  (id),
                KEY code (code),
                KEY order_id (order_id),
                KEY order_item_id (order_item_id),
                KEY status (status),
                KEY stay_from (stay_from),
                KEY stay_to (stay_to)
            ) $charset_collate;
        ";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta( $sql );
    }

    /**
     * Obsługuje zmianę statusu zamówienia na "completed".
     * Generuje kody dla pozycji TS Hotel Meals (jeśli jeszcze nie mają kodu).
     *
     * @param int $order_id
     */
    public static function handle_order_completed( $order_id ) {
        if ( ! $order_id ) {
            return;
        }

        $order = wc_get_order( $order_id );
        if ( ! $order ) {
            return;
        }

        $generated_for_items = array();

        foreach ( $order->get_items( 'line_item' ) as $item_id => $item ) {
            /** @var WC_Order_Item_Product $item */
            $product = $item->get_product();
            if ( ! $product ) {
                continue;
            }

            $product_id = $product->get_id();

            // Czy to w ogóle produkt TS Meals?
            if ( ! TSME_Frontend::is_meal_product( $product_id ) ) {
                continue;
            }

            // Jeśli pozycja ma już kod, nie generujemy ponownie.
            $existing_code_pretty = $item->get_meta( '_tsme_code', true );
            if ( ! empty( $existing_code_pretty ) ) {
                continue;
            }

            // Dane z metadanych pozycji.
            $object_label = $item->get_meta( '_tsme_object', true );
            $room_number  = $item->get_meta( '_tsme_room_number', true );
            $stay_from    = $item->get_meta( '_tsme_stay_from', true );
            $stay_to      = $item->get_meta( '_tsme_stay_to', true );
            $adults       = (int) $item->get_meta( '_tsme_adults', true );
            $children     = (int) $item->get_meta( '_tsme_children', true );

            $meal_type = get_post_meta( $product_id, TSME_Admin_Product::META_MEAL_TYPE, true );
            if ( empty( $meal_type ) ) {
                $meal_type = 'other';
            }

            // Na razie building = object_label, później podepniemy dedykowane meta budynku.
            $building = $object_label;

            // Wygeneruj unikalny kod.
            $code_pair = self::generate_unique_code_pair();
            if ( ! $code_pair ) {
                continue;
            }

            $db_code      = $code_pair['db'];      // np. TSHMABCD1234EFGH
            $display_code = $code_pair['display']; // np. TSHM-ABCD-1234-EFGH

            // Zapis kodu jako meta pozycji zamówienia (ładna wersja).
            $item->add_meta_data( '_tsme_code', $display_code, true );
            $item->save();

            // Zapis do tabeli.
            self::insert_code_row( array(
                'order_id'     => $order_id,
                'order_item_id'=> $item_id,
                'code'         => $db_code,
                'meal_type'    => $meal_type,
                'building'     => $building,
                'object_label' => $object_label,
                'room_number'  => $room_number,
                'stay_from'    => $stay_from ? $stay_from : null,
                'stay_to'      => $stay_to ? $stay_to : null,
                'adults'       => $adults,
                'children'     => $children,
            ) );

            $generated_for_items[ $item_id ] = $display_code;
        }

                if ( ! empty( $generated_for_items ) ) {
            $lines = array();
            foreach ( $generated_for_items as $item_id => $code_display ) {
                /** @var WC_Order_Item_Product $item */
                $item = $order->get_item( $item_id );
                $room = $item ? $item->get_meta( '_tsme_room_number', true ) : '';
                $lines[] = sprintf(
                    '%1$s (pokój %2$s)',
                    $code_display,
                    $room ? $room : '-'
                );
            }

            $note = "TS Hotel Meals: wygenerowano kody posiłków:\n" . implode( "\n", $lines );
                        $order->add_order_note( $note );

            // Po wygenerowaniu kodów – w zależności od ustawień – wyślij automatycznie mail z potwierdzeniem posiłków.
            $auto_email_enabled = get_option( 'tsme_auto_email_enabled', 'yes' ) === 'yes';

            if ( $auto_email_enabled && class_exists( 'TSME_Email' ) ) {
                TSME_Email::send_meals_email( $order_id, 'auto' );
            }
        }

    }


    /**
     * Tworzy unikalny kod (wersja do DB + wersja ładna do wyświetlania).
     *
     * DB:    TSHMABCD1234EFGH
     * Wygląd: TSHM-ABCD-1234-EFGH
     *
     * @return array|null ['db' => ..., 'display' => ...]
     */
    public static function generate_unique_code_pair() {
        global $wpdb;

        $table = self::get_table_name();
        $max_attempts = 10;

        for ( $i = 0; $i < $max_attempts; $i++ ) {
            $random_part = self::random_block( 12 ); // 12 znaków A-Z0-9
            $db_code     = 'TSHM' . $random_part;     // 4 + 12 = 16
            $display_code = sprintf(
                'TSHM-%s-%s-%s',
                substr( $random_part, 0, 4 ),
                substr( $random_part, 4, 4 ),
                substr( $random_part, 8, 4 )
            );

            // Czy taki kod już jest w tabeli?
            $exists = $wpdb->get_var( $wpdb->prepare(
                "SELECT id FROM {$table} WHERE code = %s LIMIT 1",
                $db_code
            ) );

            if ( ! $exists ) {
                return array(
                    'db'      => $db_code,
                    'display' => $display_code,
                );
            }
        }

        return null;
    }

    /**
     * Pomocniczo: losuje ciąg znaków A-Z0-9 o podanej długości.
     */
    protected static function random_block( $length ) {
        $chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
        $result = '';

        for ( $i = 0; $i < $length; $i++ ) {
            $result .= $chars[ wp_rand( 0, strlen( $chars ) - 1 ) ];
        }

        return $result;
    }

    /**
     * Wstawia rekord do tabeli kodów.
     */
    public static function insert_code_row( $data ) {
        global $wpdb;

        $table = self::get_table_name();

        $defaults = array(
            'order_id'      => 0,
            'order_item_id' => 0,
            'code'          => '',
            'meal_type'     => '',
            'building'      => '',
            'object_label'  => '',
            'room_number'   => '',
            'stay_from'     => null,
            'stay_to'       => null,
            'adults'        => 0,
            'children'      => 0,
            'status'        => 'new',
            'created_at'    => current_time( 'mysql', 1 ),
            'used_at'       => null,
        );

        $row = wp_parse_args( $data, $defaults );

        $wpdb->insert(
            $table,
            $row,
            array(
                '%d',
                '%d',
                '%s',
                '%s',
                '%s',
                '%s',
                '%s',
                '%s',
                '%s',
                '%d',
                '%d',
                '%s',
                '%s',
                '%s',
            )
        );
    }

    /**
     * Normalizuje wpisany kod:
     * - usuwa wszystko poza A-Za-z0-9
     * - zamienia na wielkie litery
     * Zwraca format pasujący do tego, co trzymamy w DB (bez myślników).
     */
    public static function normalize_code( $input ) {
        $clean = preg_replace( '/[^A-Za-z0-9]/', '', (string) $input );
        $clean = strtoupper( $clean );

        // Jeśli użytkownik wpisał już z prefixem "TSHM" + reszta, zostawiamy.
        // W DB trzymamy właśnie TSHM + 12 znaków.
        return $clean;
    }

    /**
     * Pobiera rekord z tabeli na podstawie wpisanego kodu (w dowolnym formacie).
     *
     * @param string $raw_code
     * @return array|null
     */
    public static function get_code_row_by_raw( $raw_code ) {
        global $wpdb;

        $normalized = self::normalize_code( $raw_code );
        if ( empty( $normalized ) ) {
            return null;
        }

        $table = self::get_table_name();

        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$table} WHERE code = %s LIMIT 1",
                $normalized
            ),
            ARRAY_A
        );

        return $row ? $row : null;
    }

    /**
     * Helper: zamienia kod z DB (bez myślników) na ładny `TSHM-XXXX-XXXX-XXXX`.
     *
     * @param string $db_code
     * @return string
     */
    public static function format_display_code_from_db( $db_code ) {
        $normalized = self::normalize_code( $db_code );

        // Oczekujemy 16 znaków (TSHM + 12 znaków)
        if ( strlen( $normalized ) !== 16 || strpos( $normalized, 'TSHM' ) !== 0 ) {
            return $normalized;
        }

        $random_part = substr( $normalized, 4 ); // 12 znaków
        return sprintf(
            'TSHM-%s-%s-%s',
            substr( $random_part, 0, 4 ),
            substr( $random_part, 4, 4 ),
            substr( $random_part, 8, 4 )
        );
    }
}
