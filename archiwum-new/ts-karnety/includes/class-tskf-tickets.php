<?php
class TSKF_Tickets {
        /**
     * Tabela logów zdarzeń karnetów.
     */
    public static function log_table() {
        global $wpdb;
        return $wpdb->prefix . 'tskf_ticket_logs';
    }

    /**
     * Tworzy tabelę logów, jeśli jej nie ma.
     */
    public static function maybe_create_log_table() {
        global $wpdb;
        $table = self::log_table();
        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE {$table} (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            ticket_id BIGINT(20) UNSIGNED NOT NULL,
            code VARCHAR(190) NOT NULL,
            event VARCHAR(50) NOT NULL,
            meta LONGTEXT NULL,
            user_id BIGINT(20) UNSIGNED NULL,
            created_at DATETIME NOT NULL,
            PRIMARY KEY  (id),
            KEY ticket_id (ticket_id),
            KEY code (code),
            KEY event (event),
            KEY created_at (created_at)
        ) {$charset_collate};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);
    }

    /**
     * Loguje pojedyncze zdarzenie karnetu.
     *
     * @param int    $ticket_id
     * @param string $code
     * @param string $event  np. "checked", "consumed", "blocked"
     * @param array  $meta   dodatkowe dane (np. zmiany, IP)
     */
    public static function log_event( $ticket_id, $code, $event, $meta = [] ) {
        global $wpdb;

        if ( ! $ticket_id && ! $code ) {
            return;
        }

        self::maybe_create_log_table();

        $table = self::log_table();

        $wpdb->insert(
            $table,
            [
                'ticket_id'  => (int) $ticket_id,
                'code'       => sanitize_text_field( (string) $code ),
                'event'      => sanitize_key( $event ),
                'meta'       => ! empty( $meta ) ? wp_json_encode( $meta ) : null,
                'user_id'    => get_current_user_id() ?: null,
                'created_at' => current_time( 'mysql' ),
            ],
            [
                '%d',
                '%s',
                '%s',
                '%s',
                '%d',
                '%s',
            ]
        );
    }

    /**
     * Zwraca historię zdarzeń dla danego kodu.
     *
     * @param string $code
     * @param int    $limit
     *
     * @return array
     */
    public static function get_history_by_code( $code, $limit = 50 ) {
        global $wpdb;

        if ( ! $code ) {
            return [];
        }

        self::maybe_create_log_table();

        $table = self::log_table();

        $sql = $wpdb->prepare(
            "SELECT * FROM {$table} WHERE code = %s ORDER BY created_at DESC, id DESC LIMIT %d",
            $code,
            $limit
        );

        $rows = $wpdb->get_results( $sql, ARRAY_A );

        if ( ! $rows ) {
            return [];
        }

        foreach ( $rows as &$row ) {
            if ( ! empty( $row['meta'] ) && is_string( $row['meta'] ) ) {
                $decoded = json_decode( $row['meta'], true );
                if ( is_array( $decoded ) ) {
                    $row['meta'] = $decoded;
                }
            }
        }

        return $rows;
    }

    static function table(){ global $wpdb; return $wpdb->prefix.'ts_tickets'; }

    static function activate(){
        global $wpdb; require_once ABSPATH.'wp-admin/includes/upgrade.php';
        $t  = self::table();
        $cc = $wpdb->get_charset_collate();
        $sql = "CREATE TABLE $t (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            order_id BIGINT UNSIGNED NOT NULL,
            order_item_id BIGINT UNSIGNED NOT NULL,
            product_id BIGINT UNSIGNED NOT NULL,
            code VARCHAR(64) NOT NULL,
            ticket_type VARCHAR(16) NOT NULL DEFAULT 'single',
            duration_days INT UNSIGNED DEFAULT 0,
            entries_total INT UNSIGNED NOT NULL DEFAULT 1,
            entries_left INT UNSIGNED NOT NULL DEFAULT 1,
            valid_from DATETIME NULL,
            period_started_at DATETIME NULL,
            period_expires_at DATETIME NULL,
            status VARCHAR(16) NOT NULL DEFAULT 'active',
            used_log LONGTEXT NULL,
            last_checked_at DATETIME NULL,
            last_checked_by BIGINT UNSIGNED NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NULL,
            treatment_date DATETIME NULL,
            treatment_client VARCHAR(255) NULL,
            UNIQUE KEY code (code),
            PRIMARY KEY (id)
        ) $cc;";
        dbDelta($sql);
    }

    static function create( $args ) {
        global $wpdb;

        $table = self::table();
        $wpdb->insert( $table, $args );
        $id = (int) $wpdb->insert_id;

        // 🔹 Pierwszy log: data utworzenia + ważność kodu (90 dni od zakupu)
        if ( $id && ! empty( $args['code'] ) ) {
            try {
                $code     = $args['code'];
                $order_id = isset( $args['order_id'] ) ? (int) $args['order_id'] : 0;

                $purchase_ts = null;

                // 1) Próba użycia daty zamówienia
                if ( $order_id && function_exists( 'wc_get_order' ) ) {
                    $order = wc_get_order( $order_id );
                    if ( $order && $order->get_date_created() ) {
                        $purchase_ts = $order->get_date_created()->getTimestamp();
                    }
                }

                // 2) Fallback: created_at z rekordu albo "teraz"
                if ( ! $purchase_ts ) {
                    if ( ! empty( $args['created_at'] ) ) {
                        $purchase_ts = strtotime( $args['created_at'] );
                    } else {
                        $purchase_ts = current_time( 'timestamp' );
                    }
                }

                // 90 dni od daty zakupu
                $valid_until_ts = $purchase_ts ? strtotime( '+90 days', $purchase_ts ) : 0;

                $meta = [
                    // surowe daty, gdyby kiedyś były potrzebne w UI
                    'purchase_date'        => $purchase_ts ? date( 'Y-m-d H:i:s', $purchase_ts ) : null,
                    'purchase_valid_until' => $valid_until_ts ? date( 'Y-m-d H:i:s', $valid_until_ts ) : null,
                    // używane w modalu historii
                    'note'                 => '',
                ];

                // żeby w szczegółach w historii pokazało "Koniec: ..."
                if ( $valid_until_ts ) {
                    $meta['expires_at'] = $meta['purchase_valid_until'];
                }

                if ( $purchase_ts && $valid_until_ts ) {
                    $meta['note'] = sprintf(
                        'Zakupiony dnia %s, ważny do %s (90 dni od zakupu).',
                        date_i18n( 'd.m.Y', $purchase_ts ),
                        date_i18n( 'd.m.Y', $valid_until_ts )
                    );
                } elseif ( $purchase_ts ) {
                    $meta['note'] = sprintf(
                        'Zakupiony dnia %s.',
                        date_i18n( 'd.m.Y', $purchase_ts )
                    );
                }

                self::log_event(
                    $id,
                    $code,
                    'created',
                    $meta
                );
            } catch ( Throwable $e ) {
                // Nie blokujemy tworzenia kodu, jeżeli logowanie się wywali
            }
        }

        return $id;
    }

    static function get_by_code($code){ global $wpdb; return $wpdb->get_row($wpdb->prepare("SELECT * FROM ".self::table()." WHERE code=%s", $code)); }
    static function update($id,$data){ global $wpdb; return $wpdb->update(self::table(), $data, ['id'=>$id]); }
    static function list_recent($limit=500){ global $wpdb; return $wpdb->get_results($wpdb->prepare("SELECT * FROM ".self::table()." ORDER BY id DESC LIMIT %d", $limit)); }
}
