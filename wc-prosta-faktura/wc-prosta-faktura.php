<?php
/**
 * Plugin Name: WC Apple V12 (Data Cleaner)
 * Description: Design Apple + Żółty Przycisk + Logika czyszczenia danych (Backend & Frontend) gdy faktura odznaczona.
 * Version: 12.0
 * Author: Gemini
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * ============================================================
 * 1. PHP: KONFIGURACJA PÓL
 * ============================================================
 */
add_filter( 'woocommerce_billing_fields', 'wc_apple_v12_fields', 20, 1 );
function wc_apple_v12_fields( $fields ) {
    
    // Firma
    $fields['billing_company']['required'] = false;
    $fields['billing_company']['label'] = 'Nazwa firmy';
    $fields['billing_company']['priority'] = 30;

    // Checkbox Faktury
    $new_fields = array();
    foreach ( $fields as $key => $field ) {
        if ( $key === 'billing_company' ) {
            $new_fields['billing_want_invoice'] = array(
                'type'      => 'checkbox',
                'label'     => 'Chcę otrzymać fakturę VAT',
                'class'     => array('form-row-wide', 'apple-invoice-toggle'),
                'clear'     => true,
                'priority'  => 29,
                'default'   => 0, 
            );
        }
        $new_fields[$key] = $field;
    }
    
    // NIP
    $new_fields['billing_nip'] = array(
        'label'       => 'NIP',
        'placeholder' => '',
        'required'    => false,
        'class'       => array('form-row-wide'),
        'clear'       => true,
        'priority'    => 35,
    );

    return $new_fields;
}

/**
 * ============================================================
 * 2. PHP: SANITYZACJA DANYCH (KLUCZOWA ZMIANA)
 * Czyścimy dane zanim trafią do walidacji i zapisu
 * ============================================================
 */
add_filter( 'woocommerce_checkout_posted_data', 'wc_apple_v12_sanitize_posted_data' );
function wc_apple_v12_sanitize_posted_data( $data ) {
    // Sprawdzamy czy checkbox jest zaznaczony (1) czy nie (0/pusty)
    if ( empty( $data['billing_want_invoice'] ) ) {
        
        // Jeśli klient NIE chce faktury, bezwzględnie czyścimy te pola
        // To zapobiega zapisywaniu "zapamiętanych" danych w bazie
        $data['billing_company'] = '';
        $data['billing_nip'] = '';
        
        // Czyścimy też globalną tablicę POST dla pewności (dla wtyczek trzecich)
        $_POST['billing_company'] = '';
        $_POST['billing_nip'] = '';
    }
    return $data;
}

// Walidacja (Pozostaje jako druga linia obrony)
add_action( 'woocommerce_checkout_process', 'wc_apple_v12_validate' );
function wc_apple_v12_validate() {
    if ( ! empty( $_POST['billing_want_invoice'] ) ) {
        if ( empty( $_POST['billing_company'] ) ) {
            wc_add_notice( '<strong>Błąd:</strong> Pole <strong>Nazwa firmy</strong> jest wymagane do faktury.', 'error' );
        }
        if ( empty( $_POST['billing_nip'] ) ) {
            wc_add_notice( '<strong>Błąd:</strong> Pole <strong>NIP</strong> jest wymagane do faktury.', 'error' );
        }
    }
}

// Zapis NIP i znacznika (tylko jeśli checkbox zaznaczony)
add_action( 'woocommerce_checkout_update_order_meta', function( $order_id ) {
    // Dzięki filtrowi wyżej, jeśli checkbox był pusty, to billing_nip też tu dotrze pusty
    if ( ! empty( $_POST['billing_nip'] ) ) {
        update_post_meta( $order_id, '_billing_nip', sanitize_text_field( $_POST['billing_nip'] ) );
        update_post_meta( $order_id, 'billing_nip', sanitize_text_field( $_POST['billing_nip'] ) );
    }
    
    // Znacznik faktury
    if ( ! empty( $_POST['billing_want_invoice'] ) ) {
        update_post_meta( $order_id, '_want_invoice', 'yes' );
    } else {
        delete_post_meta( $order_id, '_want_invoice' ); // Usuń znacznik jeśli odznaczono
    }
});

// Wyświetlanie (Admin/Email)
add_action( 'woocommerce_admin_order_data_after_billing_address', function( $order ) {
    $nip = $order->get_meta( '_billing_nip' ) ?: $order->get_meta( 'billing_nip' );
    if ( ! empty( $nip ) ) echo '<p><strong>NIP:</strong> ' . esc_html( $nip ) . '</p>';
}, 10, 1 );

add_filter( 'woocommerce_email_order_meta_fields', function( $fields, $sent_to_admin, $order ) {
    $nip = $order->get_meta( '_billing_nip' ) ?: $order->get_meta( 'billing_nip' );
    if ( ! empty( $nip ) ) $fields['nip'] = array( 'label' => 'NIP', 'value' => $nip );
    return $fields;
}, 10, 3 );


/**
 * ============================================================
 * 3. JS: LOGIKA + CZYSZCZENIE FIZYCZNE
 * ============================================================
 */
add_action( 'wp_footer', 'wc_apple_v12_scripts' );
function wc_apple_v12_scripts() {
    if ( ! is_checkout() ) return;
    ?>
    <script type="text/javascript">
    jQuery(document).ready(function($){
        
        // Reset checkboxa na start (dla pewności)
        var checkbox = $('#billing_want_invoice');
        if(checkbox.length && !checkbox.hasClass('user-interacted')) {
            checkbox.prop('checked', false);
        }

        function runInvoiceLogic() {
            var cb = $('#billing_want_invoice');
            var companyRow = $('#billing_company_field');
            var nipRow = $('#billing_nip_field');
            var companyInput = $('#billing_company');
            var nipInput = $('#billing_nip');

            if ( cb.is(':checked') ) {
                // POKAŻ
                companyRow.addClass('show-faktura-now');
                nipRow.addClass('show-faktura-now');
                
                companyRow.addClass('validate-required');
                nipRow.addClass('validate-required');
                
                updateLabel(companyRow, 'Nazwa firmy', true);
                updateLabel(nipRow, 'NIP', true);
            } else {
                // UKRYJ
                companyRow.removeClass('show-faktura-now');
                nipRow.removeClass('show-faktura-now');
                
                companyRow.removeClass('validate-required');
                nipRow.removeClass('validate-required');
                
                updateLabel(companyRow, 'Nazwa firmy', false);
                updateLabel(nipRow, 'NIP', false);

                // CZYSZCZENIE DANYCH (Frontend)
                // Jeśli użytkownik odznaczył, czyścimy wartości, żeby ich nie widział i żeby przeglądarka nie trzymała
                if(cb.hasClass('user-clicked')) {
                    companyInput.val('');
                    nipInput.val('');
                }
            }
        }

        function updateLabel(row, baseText, required) {
            var label = row.find('label');
            if(required) {
                label.html(baseText + ' <span class="required-text" style="color:#ff3b30; font-weight:normal; font-size:12px;">(wymagane)</span> <abbr class="required" title="wymagane" style="color:#ff3b30; text-decoration:none; border:none;">*</abbr>');
            } else {
                label.html(baseText + ' <span class="optional-text" style="color:#86868b; font-weight:normal; font-size:12px;">(opcjonalne)</span>');
            }
        }

        // Listener zmiany
        $(document.body).on('change', '#billing_want_invoice', function() {
            $(this).addClass('user-clicked');
            runInvoiceLogic();
        });

        // Start
        setTimeout(runInvoiceLogic, 50);

        // AJAX update
        $(document.body).on('updated_checkout', function() {
            runInvoiceLogic();
        });
    });
    </script>
    <?php
}

/**
 * ============================================================
 * 4. CSS: DESIGN (Bez zmian - Apple + Nuclear Fix)
 * ============================================================
 */
add_action( 'wp_head', 'wc_apple_v12_styles' );
function wc_apple_v12_styles() {
    if ( ! is_checkout() ) return;
    ?>
    <style>
        /* ATOMOWE UKRYWANIE PÓL */
        #billing_company_field, 
        #billing_nip_field {
            display: none !important; 
        }
        #billing_company_field.show-faktura-now, 
        #billing_nip_field.show-faktura-now {
            display: block !important;
            animation: fadeInField 0.3s ease-in-out;
        }
        @keyframes fadeInField {
            from { opacity: 0; transform: translateY(-5px); }
            to { opacity: 1; transform: translateY(0); }
        }

        /* DESIGN APPLE */
        .woocommerce-checkout {
            background-color: #f5f5f7;
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
            color: #1d1d1f;
        }
        .woocommerce form .form-row {
            margin-bottom: 24px !important;
            padding: 0 !important;
            display: block !important;
        }
        #customer_details, 
        #order_review_heading, 
        #order_review,
        .woocommerce-checkout-payment {
            background: #ffffff;
            border-radius: 18px;
            padding: 40px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.04);
            border: none;
            margin-bottom: 30px;
        }
        .woocommerce-checkout h3 {
            font-size: 24px;
            font-weight: 600;
            margin-bottom: 35px;
            color: #1d1d1f;
            border-bottom: 1px solid #f0f0f0;
            padding-bottom: 15px;
        }
        .woocommerce form .form-row input.input-text,
        .woocommerce form .form-row textarea,
        .select2-container .select2-selection--single {
            background-color: #f5f5f7 !important;
            border: 1px solid transparent !important;
            border-radius: 12px !important;
            padding: 16px 18px !important;
            font-size: 16px !important;
            color: #1d1d1f !important;
            line-height: 1.4;
            min-height: 54px;
            box-shadow: none !important;
            width: 100%;
        }
        .woocommerce form .form-row input.input-text:focus,
        .woocommerce form .form-row textarea:focus {
            background-color: #ffffff !important;
            border-color: #0071e3 !important;
            box-shadow: 0 0 0 4px rgba(0,113,227, 0.1) !important;
        }
        .woocommerce form .form-row label {
            font-size: 13px;
            font-weight: 500;
            color: #86868b;
            margin-bottom: 8px;
            display: block;
            text-transform: uppercase;
        }
        .woocommerce .select2-container .select2-selection--single {
            height: 54px;
            display: flex;
            align-items: center;
        }
        .select2-selection__arrow { top: 50% !important; transform: translateY(-50%); }

        /* CHECKBOX */
        .apple-invoice-toggle {
            background: #fff;
            border: 2px solid #f5f5f7;
            border-radius: 12px;
            padding: 20px !important;
            margin-top: 10px !important;
            margin-bottom: 30px !important;
            cursor: pointer;
            transition: border-color 0.2s;
        }
        .apple-invoice-toggle:hover { border-color: #d2d2d7; }
        .apple-invoice-toggle label {
            margin: 0 !important;
            font-size: 16px !important;
            text-transform: none !important;
            color: #1d1d1f !important;
            font-weight: 500 !important;
            display: flex !important;
            align-items: center;
            cursor: pointer;
        }
        .apple-invoice-toggle input[type="checkbox"] {
            width: 22px !important;
            height: 22px !important;
            margin-right: 15px !important;
            accent-color: #1d1d1f;
            cursor: pointer;
        }

        /* TABELA */
        #order_review table.shop_table { border: none !important; border-spacing: 0; border-collapse: collapse; }
        #order_review table.shop_table th {
            background: transparent; font-size: 12px; text-transform: uppercase; color: #86868b; padding: 15px 0; border-bottom: 1px solid #e5e5e5;
        }
        #order_review table.shop_table td { padding: 20px 0; border-bottom: 1px solid #f5f5f7; font-size: 15px; color: #1d1d1f; }
        .product-name { font-weight: 500; }
        #order_review table.shop_table tr.order-total th,
        #order_review table.shop_table tr.order-total td {
            border-top: 2px solid #1d1d1f !important; border-bottom: none !important; font-size: 22px; font-weight: 700; color: #1d1d1f; padding-top: 30px;
        }

        /* PŁATNOŚCI */
        #payment { background: #f5f5f7 !important; border-radius: 12px; padding: 20px !important; }
        #payment ul.payment_methods { border-bottom: 1px solid #e5e5e5; padding-bottom: 20px; }
        #payment .payment_box { background-color: #fff !important; color: #555; font-size: 14px; padding: 15px !important; border-radius: 8px; margin-top: 10px; }

        /* GUZIK */
        #place_order {
            background-color: #fedb32 !important; color: #000000 !important; font-size: 17px !important; font-weight: 600 !important;
            padding: 18px 40px !important; border-radius: 50px !important; border: none !important; width: 100%; cursor: pointer; margin-top: 20px; transition: transform 0.1s;
        }
        #place_order:hover { transform: scale(1.01); background-color: #fdd410 !important; }

        @media (min-width: 900px) {
            .col2-set { display: flex; gap: 40px; justify-content: space-between; }
            .col2-set .col-1, .col2-set .col-2 { width: 48%; float: none; }
        }
    </style>
    <?php
}
/**
 * ============================================================
 * 5. TEKST POD PRZYCISKIEM – REGULAMIN + POLITYKA PRYWATNOŚCI
 * ============================================================
 */

// Nadpisujemy tekst prywatności na checkout (klasyczny szablon)
add_filter( 'woocommerce_checkout_privacy_policy_text', 'cg_custom_checkout_privacy_text_force', 9999 );

// Nadpisujemy też ogólny tekst polityki prywatności (na wszelki wypadek)
add_filter( 'woocommerce_get_privacy_policy_text', 'cg_custom_checkout_privacy_text_force', 9999 );

function cg_custom_checkout_privacy_text_force( $text ) {
    $regulamin = '<a href="https://oferta.czarnagora.pl/regulamin/" target="_blank">regulamin</a>';
    $polityka  = '<a href="https://oferta.czarnagora.pl/polityka-prywatnosci/" target="_blank">politykę prywatności</a>';

    return 'Kontynuując akceptujesz ' . $regulamin . ' i ' . $polityka . '.';
}