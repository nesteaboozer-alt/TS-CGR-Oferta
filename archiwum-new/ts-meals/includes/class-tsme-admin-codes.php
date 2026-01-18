<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Panel admina dla TS Hotel Meals – Dashboard kasjera.
 *
 * Zakładki:
 * - Podgląd: sprawdzanie kodu + statystyki + lista posiłków na dzień
 * - Zamówienia: pełna lista kodów z paginacją i wyszukiwarką
 * - Ręczne generowanie: placeholder
 * - Ustawienia: placeholder
 */
class TSME_Admin_Codes {

        public static function init() {
        add_action( 'admin_menu', array( __CLASS__, 'register_menu' ) );

        // Eksport CSV z listy dziennej (admin-post.php?action=tsme_daily_export_csv).
        add_action(
            'admin_post_tsme_daily_export_csv',
            array( __CLASS__, 'handle_daily_export_csv' )
        );

        // Usuwanie pojedynczego kodu z listy zamówień (admin-post.php?action=tsme_delete_code).
        add_action(
            'admin_post_tsme_delete_code',
            array( __CLASS__, 'handle_delete_code' )
        );

        add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
        add_action( 'wp_ajax_tsme_void_code', array( __CLASS__, 'ajax_void_code' ) );
    }


    /**
     * Rejestracja menu "TS Posiłki" w kokpicie.
     */
    public static function register_menu() {
        add_menu_page(
            __( 'TS Posiłki', 'ts-hotel-meals' ),
            __( 'TS Posiłki', 'ts-hotel-meals' ),
            'manage_woocommerce',
            'tsme-dashboard',
            array( __CLASS__, 'render_dashboard' ),
            'dashicons-food',
            56
        );
    }

    /**
     * Ładowanie CSS/JS tylko na dashboardzie TS Posiłki.
     *
     * @param string $hook
     */
    public static function enqueue_assets( $hook ) {
        if ( ! function_exists( 'get_current_screen' ) ) {
            return;
        }

        $screen = get_current_screen();
        $page   = isset( $_GET['page'] ) ? sanitize_text_field( wp_unslash( $_GET['page'] ) ) : '';

        // Ładujemy tylko na głównej stronie TS Posiłki.
        if (
            ! $screen
            || ( 'toplevel_page_tsme-dashboard' !== $screen->id && 'tsme_page_tsme-dashboard' !== $screen->id )
            || 'tsme-dashboard' !== $page
        ) {
            return;
        }

        wp_enqueue_style(
            'tsme-admin',
            TSME_URL . 'assets/css/tsme-admin.css',
            array(),
            TSME_VER
        );

        wp_enqueue_script(
            'tsme-admin',
            TSME_URL . 'assets/js/tsme-admin.js',
            array( 'jquery' ),
            TSME_VER,
            true
        );

        wp_localize_script( 'tsme-admin', 'tsme_admin', array(
            'ajaxurl' => admin_url( 'admin-ajax.php' ),
            'nonce'   => wp_create_nonce( 'tsme_admin_void' )
        ) );
    }

    /**
     * Dashboard TS Posiłki – zakładki +:
     * - sprawdzanie kodu
     * - statystyki
     * - lista posiłków na dzień
     * - lista kodów (Zamówienia)
     */
    public static function render_dashboard() {
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            return;
        }

        // Która zakładka ma być aktywna po przeładowaniu?
        $default_tab = isset( $_GET['tsme_tab'] )
            ? sanitize_key( wp_unslash( $_GET['tsme_tab'] ) )
            : 'overview';

        if ( ! in_array( $default_tab, array( 'overview', 'orders', 'manual', 'settings' ), true ) ) {
            $default_tab = 'overview';
        }

                $overview_active = ( 'overview' === $default_tab );
        $orders_active   = ( 'orders' === $default_tab );
        $manual_active   = ( 'manual' === $default_tab );
        $settings_active = ( 'settings' === $default_tab );

        // Ustawienia – auto-mail
        $auto_email_enabled = get_option( 'tsme_auto_email_enabled', 'yes' ) === 'yes';
        $settings_saved     = false;

        // Obsługa formularza ustawień (zakładka "Ustawienia")
        if ( isset( $_POST['tsme_settings_submit'] ) ) {
            check_admin_referer( 'tsme_settings_action', 'tsme_settings_nonce' );

            $new_val = isset( $_POST['tsme_auto_email_enabled'] ) ? 'yes' : 'no';
            update_option( 'tsme_auto_email_enabled', $new_val );

            $auto_email_enabled = ( 'yes' === $new_val );
            $settings_saved     = true;

            // Po zapisaniu ustawień wracamy na zakładkę "Ustawienia"
            $default_tab     = 'settings';
            $overview_active = false;
            $orders_active   = false;
            $manual_active   = false;
            $settings_active = true;
        }


        // ----------------------------------
        // 1. Logika "Sprawdź kod" (Podgląd)
        // ----------------------------------
        $result         = null;
        $error_message  = '';

        if ( isset( $_POST['tsme_check_code_submit'] ) ) {
            check_admin_referer( 'tsme_check_code_action', 'tsme_check_code_nonce' );

            $raw_code = isset( $_POST['tsme_check_code'] )
                ? sanitize_text_field( wp_unslash( $_POST['tsme_check_code'] ) )
                : '';

            if ( empty( $raw_code ) ) {
                $error_message = __( 'Wpisz kod, aby go sprawdzić.', 'ts-hotel-meals' );
            } else {
                $row = TSME_Codes::get_code_row_by_raw( $raw_code );

                if ( $row ) {
                    $result = $row;
                } else {
                    $error_message = __( 'Nie znaleziono kodu. Sprawdź, czy wpisałeś go poprawnie.', 'ts-hotel-meals' );
                }
            }
        }

        // Na razie statystyki są "dummy" – tylko struktura pod dalsze wdrożenia.
        $today_breakfasts  = 0;
        $today_dinners     = 0;
        $open_orders_count = 0;

        // ----------------------------------
        // 2. Dane wspólne z bazy tsme_meal_codes
        //    - lista kodów (Zamówienia)
        //    - distinct obiekty / typy posiłków (Podgląd -> Lista na dzień)
        // ----------------------------------
        global $wpdb;

        $codes_table      = TSME_Codes::get_table_name();
        $orders_rows      = array();
        $total_orders     = 0;
        $per_page         = 20;
        $current_page     = isset( $_GET['tsme_orders_page'] ) ? max( 1, absint( $_GET['tsme_orders_page'] ) ) : 1;
        $search_raw       = isset( $_GET['tsme_orders_search'] ) ? sanitize_text_field( wp_unslash( $_GET['tsme_orders_search'] ) ) : '';
        $search_norm      = self::normalize_code_for_search( $search_raw );
        $offset           = ( $current_page - 1 ) * $per_page;
        $objects_list     = array();
        $meal_types_list  = array();

        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $codes_table ) ) === $codes_table ) {

            // Wyszukiwanie / paginacja dla zakładki "Zamówienia".
            $where_sql = '1=1';

            if ( $search_norm ) {
                $like      = '%' . $wpdb->esc_like( $search_norm ) . '%';
                $where_sql .= $wpdb->prepare( ' AND code LIKE %s', $like );
            }

            $total_orders = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$codes_table} WHERE {$where_sql}" );

            $orders_rows = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT * FROM {$codes_table} WHERE {$where_sql} ORDER BY id DESC LIMIT %d OFFSET %d",
                    $per_page,
                    $offset
                ),
                ARRAY_A
            );

            // Lista dostępnych obiektów / budynków.
            $objects_list = $wpdb->get_col(
                "SELECT DISTINCT object_label FROM {$codes_table} WHERE object_label IS NOT NULL AND object_label <> '' ORDER BY object_label ASC"
            );

            // Lista typów posiłków (Śniadanie / Obiadokolacja itd.).
            $meal_types_list = $wpdb->get_col(
                "SELECT DISTINCT meal_type FROM {$codes_table} WHERE meal_type IS NOT NULL AND meal_type <> '' ORDER BY meal_type ASC"
            );
        }

        $max_pages = ( $per_page > 0 ) ? (int) ceil( $total_orders / $per_page ) : 1;

        $orders_base_url = add_query_arg(
            array(
                'page'     => 'tsme-dashboard',
                'tsme_tab' => 'orders',
            ),
            admin_url( 'admin.php' )
        );

               // 3. Logika "Lista posiłków na dzień"
        // ----------------------------------
        $daily_error       = '';
        $daily_results     = array();
        $daily_date        = '';
        $daily_object      = '';
        $daily_meal_types  = array(); // lista wybranych typów posiłków
        $daily_submitted   = false;

        /**
         * 3b. Generowanie listy na ekran (HTML w zakładce PODGLĄD).
         * Obsługa wielu typów posiłków naraz (checkboxy).
         */
        if ( isset( $_POST['tsme_daily_list_submit'] ) ) {
            check_admin_referer( 'tsme_daily_list_action', 'tsme_daily_list_nonce' );

            // Data i obiekt
            $daily_date = isset( $_POST['tsme_daily_date'] )
                ? sanitize_text_field( wp_unslash( $_POST['tsme_daily_date'] ) )
                : '';

            $daily_object = isset( $_POST['tsme_daily_object'] )
                ? sanitize_text_field( wp_unslash( $_POST['tsme_daily_object'] ) )
                : '';

            // NOWE: lista wybranych typów posiłków (checkboxy)
            $daily_meal_types = array();
            if ( ! empty( $_POST['tsme_daily_meal_types'] ) && is_array( $_POST['tsme_daily_meal_types'] ) ) {
                foreach ( $_POST['tsme_daily_meal_types'] as $mt_raw ) {
                    $mt = sanitize_text_field( wp_unslash( $mt_raw ) );
                    if ( '' !== $mt ) {
                        $daily_meal_types[] = $mt;
                    }
                }
                $daily_meal_types = array_values( array_unique( $daily_meal_types ) );
            }

            $daily_submitted = true;

            // Walidacja wstępna
            if ( empty( $daily_date ) || empty( $daily_object ) || empty( $daily_meal_types ) ) {
                $daily_error = __( 'Wybierz obiekt, co najmniej jeden rodzaj posiłku i dzień, aby wygenerować listę.', 'ts-hotel-meals' );
            } else {
                $dt = date_create_from_format( 'Y-m-d', $daily_date );
                if ( ! $dt || $dt->format( 'Y-m-d' ) !== $daily_date ) {
                    $daily_error = __( 'Nieprawidłowa data.', 'ts-hotel-meals' );
                } else {
                    global $wpdb;

                    $codes_table = TSME_Codes::get_table_name();
                    if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $codes_table ) ) !== $codes_table ) {
                        $daily_error = __( 'Tabela z kodami posiłków nie istnieje.', 'ts-hotel-meals' );
                    } else {
                        // SELECT dla kilku typów posiłków naraz (IN (...))
                        $placeholders = implode( ',', array_fill( 0, count( $daily_meal_types ), '%s' ) );

                        // Dodano warunek status != 'void'
                        $sql = "SELECT * FROM {$codes_table} WHERE 1=1 AND status != 'void' AND object_label = %s AND meal_type IN ({$placeholders})";

                        // przygotowanie zapytania z dynamiczną liczbą parametrów
                        $prepare_args = array_merge( array( $sql, $daily_object ), $daily_meal_types );
                        $prepared     = call_user_func_array( array( $wpdb, 'prepare' ), $prepare_args );

                        $rows_for_daily = $wpdb->get_results( $prepared, ARRAY_A );

                        // Filtrowanie rekordów po faktycznie serwowanym dniu (breakfast/dinner/event logic)
                        foreach ( $rows_for_daily as $row ) {
                            $row_meal_type = isset( $row['meal_type'] ) ? $row['meal_type'] : '';
                            $meal_kind     = self::detect_meal_kind( $row_meal_type );

                            if ( self::is_meal_served_on_date( $row, $daily_date, $meal_kind ) ) {
                                $daily_results[] = $row;
                            }
                        }

                        // Sortujemy: najpierw po typie posiłku, potem po numerze pokoju
                        usort(
                            $daily_results,
                            function( $a, $b ) {
                                $ta = isset( $a['meal_type'] ) ? (string) $a['meal_type'] : '';
                                $tb = isset( $b['meal_type'] ) ? (string) $b['meal_type'] : '';

                                if ( $ta === $tb ) {
                                    $ra = isset( $a['room_number'] ) ? (string) $a['room_number'] : '';
                                    $rb = isset( $b['room_number'] ) ? (string) $b['room_number'] : '';
                                    return strcmp( $ra, $rb );
                                }

                                return strcmp( $ta, $tb );
                            }
                        );

                        if ( empty( $daily_results ) ) {
                            $daily_error = __( 'Brak posiłków dla wybranych parametrów.', 'ts-hotel-meals' );
                        }
                    }
                }
            }
        }




        ?>
        <div class="tsme-wrap">
            <h1><?php esc_html_e( 'TS Posiłki (Hotel)', 'ts-hotel-meals' ); ?></h1>
            <p><?php esc_html_e( 'Panel kasjera do obsługi śniadań i obiadokolacji.', 'ts-hotel-meals' ); ?></p>

            <div class="tsme-tabs">
                <button
                    type="button"
                    class="tsme-tabs__link <?php echo $overview_active ? 'tsme-tabs__link--active' : ''; ?>"
                    data-tsme-tab="overview"
                >
                    <?php esc_html_e( 'Podgląd', 'ts-hotel-meals' ); ?>
                </button>

                <button
                    type="button"
                    class="tsme-tabs__link <?php echo $orders_active ? 'tsme-tabs__link--active' : ''; ?>"
                    data-tsme-tab="orders"
                >
                    <?php esc_html_e( 'Zamówienia', 'ts-hotel-meals' ); ?>
                </button>

                <button
                    type="button"
                    class="tsme-tabs__link <?php echo $manual_active ? 'tsme-tabs__link--active' : ''; ?>"
                    data-tsme-tab="manual"
                >
                    <?php esc_html_e( 'Ręczne generowanie', 'ts-hotel-meals' ); ?>
                </button>

                <button
                    type="button"
                    class="tsme-tabs__link <?php echo $settings_active ? 'tsme-tabs__link--active' : ''; ?>"
                    data-tsme-tab="settings"
                >
                    <?php esc_html_e( 'Ustawienia', 'ts-hotel-meals' ); ?>
                </button>
            </div>

                                   <!-- Zakładka PODGLĄD -->
            <div id="tsme-tab-overview" class="tsme-tab-panel tsme-tab-panel--active">
                <div class="tsme-grid">
                    <div class="tsme-col-main">
                        <div class="tsme-card">
                            <h2><?php esc_html_e( 'Sprawdź kod posiłku', 'ts-hotel-meals' ); ?></h2>
                            <p class="tsme-card__meta">
                                <?php esc_html_e( 'Wpisz kod w dowolnym formacie (z myślnikami lub bez, wielkość liter nie ma znaczenia).', 'ts-hotel-meals' ); ?>
                            </p>

                            <form method="post">
                                <?php wp_nonce_field( 'tsme_check_code_action', 'tsme_check_code_nonce' ); ?>

                                <div class="tsme-field">
                                    <label for="tsme_check_code">
                                        <?php esc_html_e( 'Kod awaryjny posiłku', 'ts-hotel-meals' ); ?>
                                    </label>
                                    <input
                                        type="text"
                                        name="tsme_check_code"
                                        id="tsme_check_code"
                                        placeholder="<?php esc_attr_e( 'Np. TSHM-ABCD-1234-EFGH lub tshmabcd1234efgh', 'ts-hotel-meals' ); ?>"
                                        value="<?php echo isset( $_POST['tsme_check_code'] ) ? esc_attr( wp_unslash( $_POST['tsme_check_code'] ) ) : ''; ?>"
                                        autocomplete="off"
                                    />
                                </div>

                                <p>
                                    <button
                                        type="submit"
                                        name="tsme_check_code_submit"
                                        class="button button-primary"
                                    >
                                        <?php esc_html_e( 'Sprawdź kod', 'ts-hotel-meals' ); ?>
                                    </button>
                                </p>
                            </form>

                            <?php if ( $error_message ) : ?>
                                <div class="tsme-alert tsme-alert--error">
                                    <?php echo esc_html( $error_message ); ?>
                                </div>
                            <?php endif; ?>

                            <?php if ( $result ) : ?>
                                <?php
                                $display_code = TSME_Codes::format_display_code_from_db( $result['code'] );
                                $order_link   = $result['order_id']
                                    ? sprintf(
                                        '<a href="%s" target="_blank">%s</a>',
                                        esc_url( get_edit_post_link( $result['order_id'] ) ),
                                        sprintf( '#%d', (int) $result['order_id'] )
                                    )
                                    : '-';
                                ?>
                                <?php 
                                $is_void = ( $result['status'] === 'void' );
                                $alert_class = $is_void ? 'tsme-alert--error' : 'tsme-alert--success';
                                ?>
                                <div class="tsme-alert <?php echo $alert_class; ?>">
                                    <strong>
                                        <?php if ( $is_void ) : ?>
                                            ⚠️ <?php esc_html_e( 'KOD UNIEWAŻNIONY PRZEZ OPERATORA', 'ts-hotel-meals' ); ?>
                                        <?php else : ?>
                                            <?php esc_html_e( 'Kod znaleziony', 'ts-hotel-meals' ); ?>
                                        <?php endif; ?>
                                        : <?php echo esc_html( $display_code ); ?>
                                    </strong>

                                    <div class="tsme-alert__grid">
                                        <span><?php esc_html_e( 'Status', 'ts-hotel-meals' ); ?>:</span>
                                        <span><?php echo esc_html( $result['status'] ); ?></span>

                                        <span><?php esc_html_e( 'Obiekt / budynek', 'ts-hotel-meals' ); ?>:</span>
                                        <span><?php echo esc_html( $result['object_label'] ); ?></span>

                                        <span><?php esc_html_e( 'Pokój', 'ts-hotel-meals' ); ?>:</span>
                                        <span><?php echo esc_html( $result['room_number'] ); ?></span>

                                        <span><?php esc_html_e( 'Pobyt', 'ts-hotel-meals' ); ?>:</span>
                                        <span>
                                            <?php
                                            $from = $result['stay_from'] ?: '-';
                                            $to   = $result['stay_to'] ?: '-';
                                            echo esc_html( $from . ' – ' . $to );
                                            ?>
                                        </span>

                                        <span><?php esc_html_e( 'Osoby', 'ts-hotel-meals' ); ?>:</span>
                                        <span>
                                            <?php
                                            printf(
                                                esc_html__( 'Dorośli: %1$d, Dzieci 4–17: %2$d', 'ts-hotel-meals' ),
                                                (int) $result['adults'],
                                                (int) $result['children']
                                            );
                                            ?>
                                        </span>

                                        <span><?php esc_html_e( 'Typ posiłku', 'ts-hotel-meals' ); ?>:</span>
                                        <span><?php echo esc_html( $result['meal_type'] ); ?></span>

                                        <span><?php esc_html_e( 'Powiązane zamówienie', 'ts-hotel-meals' ); ?>:</span>
                                        <span>
                                            <?php echo $order_link; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                                        </span>
                                    </div>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="tsme-col-side">
                        <div class="tsme-card">
                            <h3><?php esc_html_e( 'Dzisiejsze posiłki', 'ts-hotel-meals' ); ?></h3>
                            <p class="tsme-card__meta">
                                <?php esc_html_e( 'Tu pojawią się statystyki liczby śniadań i obiadokolacji w danym dniu.', 'ts-hotel-meals' ); ?>
                            </p>

                            <div class="tsme-stats-list">
                                <div class="tsme-stat">
                                    <span><?php esc_html_e( 'Śniadania (dziś)', 'ts-hotel-meals' ); ?></span>
                                    <span><?php echo (int) $today_breakfasts; ?></span>
                                </div>
                                <div class="tsme-stat">
                                    <span><?php esc_html_e( 'Obiadokolacje (dziś)', 'ts-hotel-meals' ); ?></span>
                                    <span><?php echo (int) $today_dinners; ?></span>
                                </div>
                                <div class="tsme-stat">
                                    <span><?php esc_html_e( 'Zamówienia nie-zrealizowane', 'ts-hotel-meals' ); ?></span>
                                    <span><?php echo (int) $open_orders_count; ?></span>
                                </div>
                            </div>

                            <p style="margin-top:12px;">
                                <a
                                    href="<?php echo esc_url( admin_url( 'edit.php?post_type=shop_order' ) ); ?>"
                                    class="button button-secondary"
                                    target="_blank"
                                >
                                    <?php esc_html_e( 'Przejdź do zamówień WooCommerce', 'ts-hotel-meals' ); ?>
                                </a>
                            </p>
                        </div>
                    </div>
                                </div><!-- .tsme-grid -->

                                <!-- Lista posiłków na dzień – pełna karta pod gridem, na całą szerokość -->
                <div class="tsme-card tsme-card--full" style="margin-top:16px;">
                    <h2><?php esc_html_e( 'Lista posiłków na dzień', 'ts-hotel-meals' ); ?></h2>
                    <p class="tsme-card__meta">
                        <?php esc_html_e( 'Wybierz obiekt, rodzaj posiłku i dzień, aby wygenerować listę pokoi i osób dla gastronomii.', 'ts-hotel-meals' ); ?>
                    </p>

                    <!-- Formularz generowania listy -->
                    <form method="post" style="margin-bottom:16px;">
                        <?php wp_nonce_field( 'tsme_daily_list_action', 'tsme_daily_list_nonce' ); ?>

                        <div style="display:flex;flex-wrap:wrap;gap:16px;">
                            <p>
                                <label for="tsme_daily_object">
                                    <?php esc_html_e( 'Obiekt / budynek', 'ts-hotel-meals' ); ?>
                                </label><br />
                                <select name="tsme_daily_object" id="tsme_daily_object">
                                    <option value="">
                                        <?php esc_html_e( 'Wybierz obiekt…', 'ts-hotel-meals' ); ?>
                                    </option>
                                    <?php if ( ! empty( $objects_list ) ) : ?>
                                        <?php foreach ( $objects_list as $obj ) : ?>
                                            <option
                                                value="<?php echo esc_attr( $obj ); ?>"
                                                <?php selected( $daily_object, $obj ); ?>
                                            >
                                                <?php echo esc_html( $obj ); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </select>
                            </p>

                                                        <p>
                                <span>
                                    <?php esc_html_e( 'Rodzaje posiłków', 'ts-hotel-meals' ); ?>
                                </span><br />
                                <?php if ( ! empty( $meal_types_list ) ) : ?>
                                    <?php
                                    // $daily_meal_types mamy z logiki PHP (A2)
                                    $selected_types = is_array( $daily_meal_types ) ? $daily_meal_types : array();
                                    ?>
                                    <?php foreach ( $meal_types_list as $mt ) : ?>
                                        <?php
                                        $is_checked = in_array( $mt, $selected_types, true );
                                        ?>
                                        <label style="display:inline-flex;align-items:center;margin-right:8px;margin-top:4px;">
                                            <input
                                                type="checkbox"
                                                name="tsme_daily_meal_types[]"
                                                value="<?php echo esc_attr( $mt ); ?>"
                                                <?php checked( $is_checked ); ?>
                                            />
                                            <span style="margin-left:4px;"><?php echo esc_html( $mt ); ?></span>
                                        </label>
                                    <?php endforeach; ?>
                                    <br />
                                    <small style="color:#6b7280;">
                                        <?php esc_html_e( 'Zaznacz jeden lub więcej rodzajów. Brak zaznaczenia = brak listy.', 'ts-hotel-meals' ); ?>
                                    </small>
                                <?php else : ?>
                                    <em><?php esc_html_e( 'Brak skonfigurowanych typów posiłków.', 'ts-hotel-meals' ); ?></em>
                                <?php endif; ?>
                            </p>


                            <p>
                                <label for="tsme_daily_date">
                                    <?php esc_html_e( 'Dzień wydania posiłku', 'ts-hotel-meals' ); ?>
                                </label><br />
                                <input
                                    type="date"
                                    id="tsme_daily_date"
                                    name="tsme_daily_date"
                                    value="<?php echo esc_attr( $daily_date ); ?>"
                                />
                            </p>
                        </div>

                            <p class="submit">
        <button type="submit" name="tsme_daily_list_submit" class="button button-primary">
            <?php esc_html_e( 'Generuj listę', 'ts-hotel-meals' ); ?>
        </button>

        <button
            type="submit"
            name="tsme_daily_export_csv"
            class="button button-secondary"
            formaction="<?php echo esc_url( admin_url( 'admin-post.php?action=tsme_daily_export_csv' ) ); ?>"
        >
            <?php esc_html_e( 'Eksport CSV', 'ts-hotel-meals' ); ?>
        </button>
    </p>

                    </form>

                                        <?php if ( $daily_error ) : ?>
                        <div class="tsme-alert tsme-alert--error">
                            <?php echo esc_html( $daily_error ); ?>
                        </div>
                    <?php elseif ( $daily_submitted && ! empty( $daily_results ) ) : ?>
                        <?php
                        // Podsumowanie łącznej liczby dorosłych i dzieci – z podziałem na typ posiłku.
                        $per_type       = array();
                        $grand_adults   = 0;
                        $grand_children = 0;

                        foreach ( $daily_results as $row ) {
                            $mt_label = isset( $row['meal_type'] ) ? (string) $row['meal_type'] : '';

                            if ( ! isset( $per_type[ $mt_label ] ) ) {
                                $per_type[ $mt_label ] = array(
                                    'adults'   => 0,
                                    'children' => 0,
                                );
                            }

                            $a = isset( $row['adults'] )   ? (int) $row['adults']   : 0;
                            $c = isset( $row['children'] ) ? (int) $row['children'] : 0;

                            $per_type[ $mt_label ]['adults']   += $a;
                            $per_type[ $mt_label ]['children'] += $c;

                            $grand_adults   += $a;
                            $grand_children += $c;
                        }

                        // Grupowanie rekordów po typie posiłku.
                        $grouped_by_type = array();
                        foreach ( $daily_results as $row ) {
                            $mt_label = isset( $row['meal_type'] ) ? (string) $row['meal_type'] : '';
                            if ( ! isset( $grouped_by_type[ $mt_label ] ) ) {
                                $grouped_by_type[ $mt_label ] = array();
                            }
                            $grouped_by_type[ $mt_label ][] = $row;
                        }

                        // Kolejność typów – najpierw wg wybranych w formularzu, potem pozostałe.
                        $keys_order = array();
                        if ( ! empty( $daily_meal_types ) && is_array( $daily_meal_types ) ) {
                            $keys_order = $daily_meal_types;
                        }
                        foreach ( array_keys( $grouped_by_type ) as $key ) {
                            if ( ! in_array( $key, $keys_order, true ) ) {
                                $keys_order[] = $key;
                            }
                        }

                        // Typ używany do eksportu CSV – na razie pierwszy z listy (opcjonalnie).
                        $export_meal_type = '';
                        if ( ! empty( $daily_meal_types ) && is_array( $daily_meal_types ) ) {
                            $export_meal_type = (string) reset( $daily_meal_types );
                        }
                        ?>
                        <div id="tsme-daily-print-area" style="margin-top:16px;">
                            <h3 style="margin-top:0;">
                                <?php
                                printf(
                                    /* translators: 1: object, 2: date */
                                    esc_html__( 'Lista pokoi – %1$s, dzień %2$s', 'ts-hotel-meals' ),
                                    esc_html( $daily_object ),
                                    esc_html( $daily_date )
                                );
                                ?>
                            </h3>

                            <p style="font-size:12px;margin:4px 0 12px;color:#4b5563;">
                                <strong><?php esc_html_e( 'Podsumowanie:', 'ts-hotel-meals' ); ?></strong><br />
                                <?php foreach ( $per_type as $label => $counts ) : ?>
                                    <?php
                                    $ad = isset( $counts['adults'] ) ? (int) $counts['adults'] : 0;
                                    $ch = isset( $counts['children'] ) ? (int) $counts['children'] : 0;
                                    ?>
                                    <?php
                                    printf(
                                        '%1$s – %2$s %3$d, %4$s %5$d',
                                        esc_html( $label ? $label : __( 'Bez typu', 'ts-hotel-meals' ) ),
                                        esc_html__( 'dorośli:', 'ts-hotel-meals' ),
                                        $ad,
                                        esc_html__( 'dzieci:', 'ts-hotel-meals' ),
                                        $ch
                                    );
                                    ?>
                                    <br />
                                <?php endforeach; ?>

                                <?php if ( $grand_adults || $grand_children ) : ?>
                                    <br />
                                    <?php
                                    printf(
                                        esc_html__( 'Razem (wszystkie typy): dorośli: %1$d, dzieci: %2$d', 'ts-hotel-meals' ),
                                        (int) $grand_adults,
                                        (int) $grand_children
                                    );
                                    ?>
                                <?php endif; ?>
                            </p>

                            <?php foreach ( $keys_order as $mt_label ) : ?>
                                <?php if ( empty( $grouped_by_type[ $mt_label ] ) ) { continue; } ?>

                                <h4 style="margin:18px 0 6px;">
                                    <?php echo esc_html( $mt_label ? $mt_label : __( 'Bez typu', 'ts-hotel-meals' ) ); ?>
                                </h4>

                                <table class="widefat fixed striped" style="margin-bottom:12px;">
                                    <thead>
                                        <tr>
                                            <th><?php esc_html_e( 'Pokój', 'ts-hotel-meals' ); ?></th>
                                            <th><?php esc_html_e( 'Posiłek', 'ts-hotel-meals' ); ?></th>
                                            <th><?php esc_html_e( 'Pobyt', 'ts-hotel-meals' ); ?></th>
                                            <th><?php esc_html_e( 'Osoby', 'ts-hotel-meals' ); ?></th>
                                            <th><?php esc_html_e( 'Kod awaryjny', 'ts-hotel-meals' ); ?></th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                    <?php foreach ( $grouped_by_type[ $mt_label ] as $row ) : ?>
                                        <?php
                                        $room_number   = ! empty( $row['room_number'] ) ? $row['room_number'] : '—';
                                        $stay_from     = ! empty( $row['stay_from'] ) ? substr( $row['stay_from'], 0, 10 ) : '—';
                                        $stay_to       = ! empty( $row['stay_to'] ) ? substr( $row['stay_to'], 0, 10 ) : '—';
                                        $adults        = isset( $row['adults'] )   ? (int) $row['adults']   : 0;
                                        $children      = isset( $row['children'] ) ? (int) $row['children'] : 0;
                                        $meal_type     = ! empty( $row['meal_type'] ) ? $row['meal_type'] : '';
                                        $display_code  = ! empty( $row['code'] )
                                            ? TSME_Codes::format_display_code_from_db( $row['code'] )
                                            : '';
                                        $order_id      = isset( $row['order_id'] ) ? (int) $row['order_id'] : 0;
                                        $order_item_id = isset( $row['order_item_id'] ) ? (int) $row['order_item_id'] : 0;

                                        // Nazwa produktu z zamówienia
                                        $product_name = '';
                                        if ( $order_id && $order_item_id ) {
                                            $order = wc_get_order( $order_id );
                                            if ( $order ) {
                                                $item = $order->get_item( $order_item_id );
                                                if ( $item ) {
                                                    $product_name = $item->get_name();
                                                }
                                            }
                                        }

                                        // Kolor badge wg rodzaju posiłku
                                        $badge_style = 'background:#e5e7eb;color:#111827;';
                                        $kind        = self::detect_meal_kind( $meal_type );
                                        if ( 'breakfast' === $kind ) {
                                            $badge_style = 'background:#facc15;color:#111827;'; // żółty
                                        } elseif ( 'dinner' === $kind ) {
                                            $badge_style = 'background:#fb923c;color:#111827;'; // pomarańczowy
                                        } elseif ( 'generic' === $kind ) {
                                            $badge_style = 'background:#a855f7;color:#f9fafb;'; // fiolet
                                        }
                                        ?>
                                        <tr>
                                            <td><?php echo esc_html( $room_number ); ?></td>
                                            <td>
                                                <span class="tsme-badge" style="display:inline-block;padding:2px 6px;border-radius:999px;font-size:11px;<?php echo esc_attr( $badge_style ); ?>">
                                                    <?php echo $meal_type ? esc_html( $meal_type ) : esc_html__( 'Brak typu', 'ts-hotel-meals' ); ?>
                                                </span>
                                                <?php if ( $product_name ) : ?>
                                                    <div style="margin-top:2px;font-size:11px;opacity:0.85;">
                                                        <?php echo esc_html( $product_name ); ?>
                                                    </div>
                                                <?php endif; ?>
                                            </td>
                                            <td><?php echo esc_html( $stay_from . ' – ' . $stay_to ); ?></td>
                                            <td>
                                                <?php
                                                printf(
                                                    esc_html__( 'Dorośli: %1$d, Dzieci: %2$d', 'ts-hotel-meals' ),
                                                    $adults,
                                                    $children
                                                );
                                                ?>
                                            </td>
                                            <td>
                                                <?php if ( $display_code ) : ?>
                                                    <code><?php echo esc_html( $display_code ); ?></code>
                                                <?php else : ?>
                                                    —
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                    </tbody>
                                </table>
                            <?php endforeach; ?>
                        </div>

                        <!-- Formularz eksportu CSV + przycisk Drukuj/PDF -->
                        <form method="post" style="margin-top:12px;">
                            <?php wp_nonce_field( 'tsme_daily_list_action', 'tsme_daily_list_nonce' ); ?>
                            <input type="hidden" name="tsme_daily_date" value="<?php echo esc_attr( $daily_date ); ?>" />
                            <input type="hidden" name="tsme_daily_object" value="<?php echo esc_attr( $daily_object ); ?>" />
                            <input type="hidden" name="tsme_daily_meal_type" value="<?php echo esc_attr( $export_meal_type ); ?>" />

                            <button
                                type="submit"
                                name="tsme_daily_export_csv"
                                class="button"
                                formaction="<?php echo esc_url( admin_url( 'admin-post.php?action=tsme_daily_export_csv' ) ); ?>"
                            >
                                <?php esc_html_e( 'Eksport CSV', 'ts-hotel-meals' ); ?>
                            </button>

                            <button
                                type="button"
                                class="button"
                                id="tsme_daily_print_btn"
                            >
                                <?php esc_html_e( 'Drukuj / PDF', 'ts-hotel-meals' ); ?>
                            </button>
                        </form>
                    <?php endif; ?>



                </div>
            </div> <!-- /#tsme-tab-overview -->





            <!-- Zakładka ZAMÓWIENIA – pełna lista kodów -->
            <div
                id="tsme-tab-orders"
                class="tsme-tab-panel <?php echo $orders_active ? 'tsme-tab-panel--active' : ''; ?>"
            >
                <div class="tsme-grid">
                    <div class="tsme-col-main">
                        <div class="tsme-card">
                            <div class="tsme-card__header" style="display:flex;justify-content:space-between;align-items:center;">
                                <h2 style="margin:0;">
                                    <?php esc_html_e( 'Lista kodów posiłków', 'ts-hotel-meals' ); ?>
                                </h2>
                                <a href="<?php echo esc_url( $orders_base_url ); ?>" class="button">
                                    <?php esc_html_e( 'Odśwież', 'ts-hotel-meals' ); ?>
                                </a>
                            </div>

                            <form method="get" class="tsme-orders-search" style="margin-top:10px;margin-bottom:10px;display:flex;gap:6px;align-items:center;">
                                <input type="hidden" name="page" value="tsme-dashboard" />
                                <input type="hidden" name="tsme_tab" value="orders" />

                                <input
                                    type="search"
                                    name="tsme_orders_search"
                                    style="max-width:260px;width:100%;"
                                    placeholder="<?php esc_attr_e( 'Szukaj po kodzie…', 'ts-hotel-meals' ); ?>"
                                    value="<?php echo esc_attr( $search_raw ); ?>"
                                />
                                <button type="submit" class="button">
                                    <?php esc_html_e( 'Szukaj', 'ts-hotel-meals' ); ?>
                                </button>
                            </form>

                            <?php if ( empty( $orders_rows ) ) : ?>
                                <p class="tsme-card__meta">
                                    <?php esc_html_e( 'Brak zapisanych kodów posiłków dla wybranych filtrów.', 'ts-hotel-meals' ); ?>
                                </p>
                            <?php else : ?>
                                <table class="widefat fixed striped tsme-table-codes">
                                    <thead>
                                        <tr>
                                            <th><?php esc_html_e( 'Kod', 'ts-hotel-meals' ); ?></th>
                                            <th><?php esc_html_e( 'Typ posiłku', 'ts-hotel-meals' ); ?></th>
                                            <th><?php esc_html_e( 'Obiekt / budynek', 'ts-hotel-meals' ); ?></th>
                                            <th><?php esc_html_e( 'Pokój', 'ts-hotel-meals' ); ?></th>
                                            <th><?php esc_html_e( 'Pobyt', 'ts-hotel-meals' ); ?></th>
                                            <th><?php esc_html_e( 'Osoby', 'ts-hotel-meals' ); ?></th>
                                            <th><?php esc_html_e( 'Status', 'ts-hotel-meals' ); ?></th>
                                            <th><?php esc_html_e( 'Zamówienie', 'ts-hotel-meals' ); ?></th>
                                            <th><?php esc_html_e( 'Akcje', 'ts-hotel-meals' ); ?></th>
                                        </tr>
                                    </thead>
                                                                        <tbody>
                                    <?php foreach ( $orders_rows as $row ) : ?>
                                        <?php
                                        $display_code = isset( $row['code'] )
                                            ? TSME_Codes::format_display_code_from_db( $row['code'] )
                                            : '';

                                        $meal_type    = isset( $row['meal_type'] ) ? $row['meal_type'] : '';
                                        $status       = isset( $row['status'] ) ? $row['status'] : '';

                                        $order_id     = isset( $row['order_id'] ) ? (int) $row['order_id'] : 0;
                                        $order_link   = $order_id
                                            ? sprintf(
                                                '<a href="%s" target="_blank">%s</a>',
                                                esc_url( get_edit_post_link( $order_id ) ),
                                                sprintf( '#%d', $order_id )
                                            )
                                            : '—';

                                        $stay_from    = ! empty( $row['stay_from'] ) ? $row['stay_from'] : '—';
                                        $stay_to      = ! empty( $row['stay_to'] ) ? $row['stay_to'] : '—';

                                        $adults       = isset( $row['adults'] )   ? (int) $row['adults']   : 0;
                                        $children     = isset( $row['children'] ) ? (int) $row['children'] : 0;

                                        $object_label = isset( $row['object_label'] ) ? $row['object_label'] : '';
                                        $room_number  = isset( $row['room_number'] ) ? $row['room_number'] : '';

                                        // Przygotowanie URL do usunięcia kodu
                                        $delete_url = wp_nonce_url(
                                            add_query_arg(
                                                array(
                                                    'action'  => 'tsme_delete_code',
                                                    'code_id' => isset( $row['id'] ) ? (int) $row['id'] : 0,
                                                ),
                                                admin_url( 'admin-post.php' )
                                            ),
                                            'tsme_delete_code_action',
                                            'tsme_delete_code_nonce'
                                        );
                                        ?>
                                        <tr>
                                            <td>
                                                <?php if ( $display_code ) : ?>
                                                    <code><?php echo esc_html( $display_code ); ?></code>
                                                <?php else : ?>
                                                    —
                                                <?php endif; ?>
                                            </td>
                                            <td><?php echo $meal_type ? esc_html( $meal_type ) : '—'; ?></td>
                                            <td><?php echo $object_label ? esc_html( $object_label ) : '—'; ?></td>
                                            <td><?php echo $room_number ? esc_html( $room_number ) : '—'; ?></td>
                                            <td><?php echo esc_html( $stay_from . ' – ' . $stay_to ); ?></td>
                                            <td>
                                                <?php
                                                printf(
                                                    esc_html__( 'Dorośli: %1$d, Dzieci: %2$d', 'ts-hotel-meals' ),
                                                    $adults,
                                                    $children
                                                );
                                                ?>
                                            </td>
                                            <td><?php echo $status ? esc_html( $status ) : '—'; ?></td>
                                            <td>
                                                <?php echo $order_link; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                                            </td>
                                            <td>
                                                <?php if ( $order_id ) : ?>
                                                    <a
                                                        href="<?php echo esc_url( get_edit_post_link( $order_id ) ); ?>"
                                                        class="button button-small"
                                                        target="_blank"
                                                    >
                                                        <?php esc_html_e( 'Zamówienie', 'ts-hotel-meals' ); ?>
                                                    </a>
                                                <?php endif; ?>

                                                <?php if ( $display_code ) : ?>
                                                    <button
                                                        type="button"
                                                        class="button button-small"
                                                        data-tsme-copy-code="<?php echo esc_attr( $display_code ); ?>"
                                                    >
                                                        <?php esc_html_e( 'Kopiuj kod', 'ts-hotel-meals' ); ?>
                                                    </button>
                                                <?php endif; ?>

                                                <button
                                                    type="button"
                                                    class="button button-small tsme-void-btn"
                                                    data-id="<?php echo (int) $row['id']; ?>"
                                                    <?php disabled( $status, 'void' ); ?>
                                                >
                                                    <?php esc_html_e( 'Unieważnij', 'ts-hotel-meals' ); ?>
                                                </button>

                                                <a
                                                    href="<?php echo esc_url( $delete_url ); ?>"
                                                    class="button button-small"
                                                    onclick="return confirm('<?php echo esc_js( __( 'Czy na pewno chcesz trwale usunąć ten kod z bazy? Tej operacji nie można cofnąć.', 'ts-hotel-meals' ) ); ?>');"
                                                >
                                                    <?php esc_html_e( 'Usuń kod', 'ts-hotel-meals' ); ?>
                                                </a>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                    </tbody>

                                </table>

                                <?php if ( $max_pages > 1 ) : ?>
                                    <div class="tablenav tsme-pagination" style="margin-top:10px;">
                                        <div class="tablenav-pages">
                                            <?php
                                            $page_links = array();

                                            if ( $current_page > 1 ) {
                                                $page_links[] = sprintf(
                                                    '<a class="prev-page" href="%s">&laquo;</a>',
                                                    esc_url(
                                                        add_query_arg(
                                                            array(
                                                                'tsme_orders_page'   => $current_page - 1,
                                                                'tsme_orders_search' => $search_raw,
                                                            ),
                                                            $orders_base_url
                                                        )
                                                    )
                                                );
                                            }

                                            $page_links[] = sprintf(
                                                '<span class="paging-input">%1$d / <span class="total-pages">%2$d</span></span>',
                                                (int) $current_page,
                                                (int) $max_pages
                                            );

                                            if ( $current_page < $max_pages ) {
                                                $page_links[] = sprintf(
                                                    '<a class="next-page" href="%s">&raquo;</a>',
                                                    esc_url(
                                                        add_query_arg(
                                                            array(
                                                                'tsme_orders_page'   => $current_page + 1,
                                                                'tsme_orders_search' => $search_raw,
                                                            ),
                                                            $orders_base_url
                                                        )
                                                    )
                                                );
                                            }

                                            echo implode( ' ', $page_links ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
                                            ?>
                                        </div>
                                    </div>
                                <?php endif; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Zakładka RĘCZNE GENEROWANIE – placeholder -->
            <div
                id="tsme-tab-manual"
                class="tsme-tab-panel <?php echo $manual_active ? 'tsme-tab-panel--active' : ''; ?>"
            >
                <div class="tsme-grid">
                    <div class="tsme-col-main">
                        <div class="tsme-card">
                            <h2><?php esc_html_e( 'Ręczne generowanie posiłków', 'ts-hotel-meals' ); ?></h2>
                            <p class="tsme-card__meta">
                                <?php esc_html_e( 'Tutaj dodamy formularz jak na froncie (budynek, pokój, daty, osoby, e-mail), który utworzy zamówienie ręczne.', 'ts-hotel-meals' ); ?>
                            </p>
                            <p><?php esc_html_e( 'W tej wersji to tylko miejsce na przyszłe wdrożenie.', 'ts-hotel-meals' ); ?></p>
                        </div>
                    </div>
                </div>
            </div>

                       <!-- Zakładka USTAWIENIA -->
            <div
                id="tsme-tab-settings"
                class="tsme-tab-panel <?php echo $settings_active ? 'tsme-tab-panel--active' : ''; ?>"
            >
                <div class="tsme-grid">
                    <div class="tsme-col-main">
                        <div class="tsme-card">
                            <h2><?php esc_html_e( 'Ustawienia wysyłki maili', 'ts-hotel-meals' ); ?></h2>
                            <p class="tsme-card__meta">
                                <?php esc_html_e( 'Tutaj możesz przełączyć między ręcznym a automatycznym wysyłaniem maili z informacjami o posiłkach.', 'ts-hotel-meals' ); ?>
                            </p>

                            <?php if ( $settings_saved ) : ?>
                                <div class="tsme-alert tsme-alert--success" style="margin-bottom:12px;">
                                    <?php esc_html_e( 'Ustawienia zostały zapisane.', 'ts-hotel-meals' ); ?>
                                </div>
                            <?php endif; ?>

                            <form method="post" id="tsme-settings-form">
                                <?php wp_nonce_field( 'tsme_settings_action', 'tsme_settings_nonce' ); ?>

                                <div class="tsme-field">
                                    <label for="tsme_auto_email_enabled" style="font-weight:600;">
                                        <?php esc_html_e( 'Automatyczne wysyłanie maili po oznaczeniu zamówienia jako „Zrealizowane”', 'ts-hotel-meals' ); ?>
                                    </label>

                                    <p style="margin:6px 0 10px 0;color:#64748b;">
                                        <?php esc_html_e( 'Gdy ta opcja jest włączona, po wygenerowaniu kodów przy zmianie statusu zamówienia na „Zrealizowane” system automatycznie wyśle e-mail z informacjami o posiłkach.', 'ts-hotel-meals' ); ?>
                                    </p>

                                    <label style="display:inline-flex;align-items:center;gap:8px;cursor:pointer;">
                                        <input
                                            type="checkbox"
                                            name="tsme_auto_email_enabled"
                                            id="tsme_auto_email_enabled"
                                            value="1"
                                            <?php checked( $auto_email_enabled ); ?>
                                            data-tsme-was-enabled="<?php echo $auto_email_enabled ? '1' : '0'; ?>"
                                        />
                                        <span>
                                            <?php esc_html_e( 'Włącz automatyczną wysyłkę maili', 'ts-hotel-meals' ); ?>
                                        </span>
                                    </label>
                                </div>

                                <p style="margin-top:16px;">
                                    <button type="submit" name="tsme_settings_submit" class="button button-primary">
                                        <?php esc_html_e( 'Zapisz ustawienia', 'ts-hotel-meals' ); ?>
                                    </button>
                                </p>

                                <p class="tsme-card__meta" style="margin-top:8px;font-size:11px;color:#94a3b8;">
                                    <?php esc_html_e( 'Ręczne przyciski „Wyślij informacje o posiłkach” i „Wyślij ponownie” wciąż pozostają dostępne na poziomie zamówienia WooCommerce.', 'ts-hotel-meals' ); ?>
                                </p>
                            </form>
                        </div>
                    </div>
                </div>
            </div>


        <script>
        jQuery(function($){
            // Domyślna zakładka po przeładowaniu (np. przy paginacji listy kodów).
            var defaultTab = '<?php echo esc_js( $default_tab ); ?>';
            if (defaultTab && defaultTab !== 'overview') {
                var $btn = $('.tsme-tabs__link[data-tsme-tab="' + defaultTab + '"]');
                if ($btn.length) {
                    $btn.trigger('click');
                }
            }

            // Proste kopiowanie kodu do schowka.
            $(document).on('click', '[data-tsme-copy-code]', function(e){
                e.preventDefault();
                var code = $(this).data('tsme-copy-code');
                if (!code) {
                    return;
                }
                if (navigator.clipboard && navigator.clipboard.writeText) {
                    navigator.clipboard.writeText(code).then(function(){
                        alert('<?php echo esc_js( __( 'Kod skopiowany do schowka.', 'ts-hotel-meals' ) ); ?>');
                    });
                } else {
                    // Fallback.
                    window.prompt('<?php echo esc_js( __( 'Skopiuj kod ręcznie:', 'ts-hotel-meals' ) ); ?>', code);
                }
            });
                        // Potwierdzenie przy włączaniu automatycznej wysyłki maili.
            $('#tsme-settings-form').on('submit', function(e){
                var $checkbox = $('#tsme_auto_email_enabled');
                if (!$checkbox.length) {
                    return;
                }

                var wasEnabled = String($checkbox.data('tsme-was-enabled') || '0');
                var nowEnabled = $checkbox.is(':checked') ? '1' : '0';

                // Interesuje nas przejście z wyłączonego na włączone (0 -> 1).
                if (wasEnabled === '0' && nowEnabled === '1') {
                    var msg = '<?php echo esc_js( __( 'Włączasz automatyczne wysyłanie maili z informacjami o posiłkach po oznaczeniu zamówienia jako „Zrealizowane”. Czy na pewno chcesz to zrobić?', 'ts-hotel-meals' ) ); ?>';
                    if ( ! window.confirm(msg) ) {
                        e.preventDefault();
                        return false;
                    }
                }
            });
                        // Przycisk "Drukuj / PDF" – drukuje tylko sekcję z listą dzienną.
            $(document).on('click', '#tsme_daily_print_btn', function(e){
                e.preventDefault();

                var $area = $('#tsme-daily-print-area');
                if (!$area.length) {
                    // awaryjnie – jakby coś poszło nie tak, drukujemy całą stronę
                    window.print();
                    return;
                }

                var contents = $area.html();
                var printWindow = window.open('', '', 'width=900,height=700');

                printWindow.document.write('<html><head><title>TS Posiłki – lista na dzień</title>');
                printWindow.document.write('<style>');
                printWindow.document.write('body{font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Helvetica,Arial,sans-serif;font-size:12px;padding:16px;color:#0f172a;}');
                printWindow.document.write('h3{margin-top:0;margin-bottom:10px;font-size:16px;}');
                printWindow.document.write('table{border-collapse:collapse;width:100%;margin-top:8px;}');
                printWindow.document.write('th,td{border:1px solid #cbd5e1;padding:4px 6px;text-align:left;font-size:12px;}');
                printWindow.document.write('th{background:#f1f5f9;font-weight:600;}');
                printWindow.document.write('code{font-family:monospace;font-size:11px;}');
                printWindow.document.write('</style>');
                printWindow.document.write('</head><body>');
                printWindow.document.write(contents);
                printWindow.document.write('</body></html>');

                printWindow.document.close();
                printWindow.focus();
                printWindow.print();
                printWindow.close();
            });


        });
        </script>
        <?php
    }

    /**
     * Normalizacja wpisanego kodu do formatu zbliżonego do tego w bazie (bez myślników, wielkie litery).
     *
     * @param string $raw
     * @return string
     */
    protected static function normalize_code_for_search( $raw ) {
        $raw = strtoupper( (string) $raw );
        // Usuwamy wszystko poza A–Z i 0–9 (czyli pozbywamy się myślników, spacji itd.).
        $raw = preg_replace( '/[^A-Z0-9]/', '', $raw );
        return $raw;
    }

    /**
     * Wykrywa typ posiłku na potrzeby logiki dni (śniadanie / obiadokolacja / inne).
     *
     * @param string $label
     * @return string 'breakfast' | 'dinner' | 'generic'
     */
    protected static function detect_meal_kind( $label ) {
        if ( function_exists( 'remove_accents' ) ) {
            $label = remove_accents( $label );
        }
        $label = strtolower( (string) $label );

        if ( false !== strpos( $label, 'sniada' ) || false !== strpos( $label, 'breakfast' ) ) {
            return 'breakfast';
        }

        if (
            false !== strpos( $label, 'obiad' )
            || false !== strpos( $label, 'kolac' )
            || false !== strpos( $label, 'dinner' )
        ) {
            return 'dinner';
        }

        return 'generic';
    }

    /**
     * Sprawdza, czy dla danego rekordu i dnia posiłek powinien być wydany.
     *
     * @param array  $row
     * @param string $date       Data w formacie Y-m-d (dzień wydania posiłku).
     * @param string $meal_kind  'breakfast' | 'dinner' | 'generic'
     * @return bool
     */
        protected static function is_meal_served_on_date( $row, $date, $meal_kind ) {
        $date = substr( (string) $date, 0, 10 );
        if ( ! $date ) {
            return false;
        }

        $stay_from = ! empty( $row['stay_from'] ) ? substr( $row['stay_from'], 0, 10 ) : '';
        $stay_to   = ! empty( $row['stay_to'] ) ? substr( $row['stay_to'], 0, 10 ) : '';

        if ( ! $stay_from || ! $stay_to ) {
            return false;
        }

        $d_ts    = strtotime( $date );
        $from_ts = strtotime( $stay_from );
        $to_ts   = strtotime( $stay_to );

        if ( false === $d_ts || false === $from_ts || false === $to_ts ) {
            return false;
        }

        // Ogólne zabezpieczenie – dzień musi być wewnątrz pobytu.
        if ( $d_ts < $from_ts || $d_ts > $to_ts ) {
            return false;
        }

        if ( 'breakfast' === $meal_kind ) {
            // Śniadania: od dnia po przyjeździe do dnia wyjazdu (włącznie).
            $breakfast_start = strtotime( '+1 day', $from_ts );
            return ( $d_ts >= $breakfast_start && $d_ts <= $to_ts );
        }

        if ( 'dinner' === $meal_kind ) {
            // Obiadokolacje: od dnia przyjazdu do dnia przed wyjazdem.
            $dinner_end = strtotime( '-1 day', $to_ts );
            return ( $d_ts >= $from_ts && $d_ts <= $dinner_end );
        }

        // Inne typy – po prostu każdy dzień w zakresie pobytu.
        return true;
    }
    /**
     * Handler akcji admin-post.php?action=tsme_delete_code
     * Usuwa pojedynczy kod z tabeli tsme_meal_codes.
     */
    public static function handle_delete_code() {
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_die( esc_html__( 'Brak uprawnień do usuwania kodów posiłków.', 'ts-hotel-meals' ) );
        }

        if ( ! isset( $_GET['tsme_delete_code_nonce'] ) ) {
            wp_die( esc_html__( 'Nieprawidłowe żądanie (brak nonce).', 'ts-hotel-meals' ) );
        }

        check_admin_referer( 'tsme_delete_code_action', 'tsme_delete_code_nonce' );

        $code_id = isset( $_GET['code_id'] ) ? absint( $_GET['code_id'] ) : 0;
        if ( ! $code_id ) {
            wp_die( esc_html__( 'Nieprawidłowy identyfikator kodu.', 'ts-hotel-meals' ) );
        }

        global $wpdb;

        $codes_table = TSME_Codes::get_table_name();
        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $codes_table ) ) !== $codes_table ) {
            wp_die( esc_html__( 'Tabela z kodami posiłków nie istnieje.', 'ts-hotel-meals' ) );
        }

        $deleted = $wpdb->delete(
            $codes_table,
            array( 'id' => $code_id ),
            array( '%d' )
        );

        // Po usunięciu wracamy do zakładki "Zamówienia".
        $redirect_url = add_query_arg(
            array(
                'page'         => 'tsme-dashboard',
                'tsme_tab'     => 'orders',
                'tsme_message' => $deleted ? 'code_deleted' : 'code_delete_failed',
            ),
            admin_url( 'admin.php' )
        );

        wp_safe_redirect( $redirect_url );
        exit;
    }

    /**
     * Handler akcji admin-post.php?action=tsme_daily_export_csv
     * Generuje CSV na podstawie parametrów z formularza dziennej listy.
     */
    public static function handle_daily_export_csv() {
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_die( esc_html__( 'Brak uprawnień do eksportu listy posiłków.', 'ts-hotel-meals' ) );
        }

        // Sprawdzenie nonce – używamy tego samego, co w formularzu listy dziennej.
        if ( ! isset( $_POST['tsme_daily_list_nonce'] ) ) {
            wp_die( esc_html__( 'Nieprawidłowe żądanie (brak nonce).', 'ts-hotel-meals' ) );
        }

        check_admin_referer( 'tsme_daily_list_action', 'tsme_daily_list_nonce' );

        // Pobranie i sanityzacja danych z formularza.
        $daily_date      = isset( $_POST['tsme_daily_date'] ) ? sanitize_text_field( wp_unslash( $_POST['tsme_daily_date'] ) ) : '';
        $daily_object    = isset( $_POST['tsme_daily_object'] ) ? sanitize_text_field( wp_unslash( $_POST['tsme_daily_object'] ) ) : '';
        $daily_meal_type = isset( $_POST['tsme_daily_meal_type'] ) ? sanitize_text_field( wp_unslash( $_POST['tsme_daily_meal_type'] ) ) : '';

        if ( empty( $daily_date ) || empty( $daily_object ) || empty( $daily_meal_type ) ) {
            wp_die( esc_html__( 'Wybierz obiekt, rodzaj posiłku i dzień, aby wygenerować listę.', 'ts-hotel-meals' ) );
        }

        $dt = date_create_from_format( 'Y-m-d', $daily_date );
        if ( ! $dt || $dt->format( 'Y-m-d' ) !== $daily_date ) {
            wp_die( esc_html__( 'Nieprawidłowy format daty.', 'ts-hotel-meals' ) );
        }

        global $wpdb;

        $codes_table = TSME_Codes::get_table_name();
        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $codes_table ) ) !== $codes_table ) {
            wp_die( esc_html__( 'Tabela z kodami posiłków nie istnieje.', 'ts-hotel-meals' ) );
        }

        // Pobieramy rekordy jak przy generowaniu listy na ekran.
        $sql    = "SELECT * FROM {$codes_table} WHERE 1=1 AND object_label = %s AND meal_type = %s";
        $params = array( $daily_object, $daily_meal_type );

        $prepared       = $wpdb->prepare( $sql, $params );
        $rows_for_daily = $wpdb->get_results( $prepared, ARRAY_A );

        $meal_kind     = self::detect_meal_kind( $daily_meal_type );
        $daily_results = array();

        foreach ( $rows_for_daily as $row ) {
            if ( self::is_meal_served_on_date( $row, $daily_date, $meal_kind ) ) {
                $daily_results[] = $row;
            }
        }

        if ( empty( $daily_results ) ) {
            wp_die( esc_html__( 'Brak posiłków dla wybranych parametrów.', 'ts-hotel-meals' ) );
        }

        // Sortowanie po numerze pokoju – tak jak na ekranie.
        usort(
            $daily_results,
            function( $a, $b ) {
                $ra = isset( $a['room_number'] ) ? (string) $a['room_number'] : '';
                $rb = isset( $b['room_number'] ) ? (string) $b['room_number'] : '';
                return strcmp( $ra, $rb );
            }
        );

        // Wygenerowanie i wysłanie CSV.
        self::output_daily_csv( $daily_date, $daily_object, $daily_meal_type, $daily_results );
        exit;
    }



    /**
     * Eksport dziennej listy posiłków do CSV.
     *
     * @param string $date
     * @param string $object
     * @param string $meal_type
     * @param array  $rows
     */
    protected static function output_daily_csv( $date, $object, $meal_type, $rows ) {
        if ( headers_sent() ) {
            return;
        }

        $filename = sprintf(
            'ts-posilki-%s-%s-%s.csv',
            preg_replace( '/[^0-9\-]/', '', (string) $date ),
            sanitize_title( $meal_type ),
            sanitize_title( $object )
        );

        header( 'Content-Type: text/csv; charset=utf-8' );
        header( 'Content-Disposition: attachment; filename="' . $filename . '"' );

        $output = fopen( 'php://output', 'w' );

        // Ustawiamy separator na średnik (lepsza współpraca z polskim Excelem).
        $header = array(
            'Obiekt',
            'Rodzaj posiłku',
            'Dzień wydania',
            'Pokój',
            'Pobyt od',
            'Pobyt do',
            'Dorośli',
            'Dzieci',
            'Kod awaryjny',
            'Status',
            'ID zamówienia',
        );
        fputcsv( $output, $header, ';' );

        foreach ( $rows as $row ) {
            $stay_from    = ! empty( $row['stay_from'] ) ? substr( $row['stay_from'], 0, 10 ) : '';
            $stay_to      = ! empty( $row['stay_to'] ) ? substr( $row['stay_to'], 0, 10 ) : '';
            $adults       = isset( $row['adults'] )   ? (int) $row['adults']   : 0;
            $children     = isset( $row['children'] ) ? (int) $row['children'] : 0;
            $display_code = ! empty( $row['code'] )
                ? TSME_Codes::format_display_code_from_db( $row['code'] )
                : '';
            $status       = isset( $row['status'] ) ? $row['status'] : '';
            $order_id     = isset( $row['order_id'] ) ? (int) $row['order_id'] : 0;
            $room_number  = isset( $row['room_number'] ) ? $row['room_number'] : '';

            $line = array(
                $object,
                $meal_type,
                $date,
                $room_number,
                $stay_from,
                $stay_to,
                $adults,
                $children,
                $display_code,
                $status,
                $order_id,
            );

            fputcsv( $output, $line, ';' );
        }

        fclose( $output );
    }

    /**
     * AJAX: Unieważnienie kodu.
     */
    public static function ajax_void_code() {
        check_ajax_referer( 'tsme_admin_void', 'nonce' );
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error();
        }

        $code_id = isset( $_POST['code_id'] ) ? absint( $_POST['code_id'] ) : 0;
        if ( $code_id && TSME_Codes::void_code_by_id( $code_id ) ) {
            wp_send_json_success();
        }

        wp_send_json_error();
    }
}

