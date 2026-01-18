<?php
class TSKF_ProductMeta {
    static function init(){
        // Pola dodatkowe w edycji produktu
        add_action(
            'woocommerce_product_options_general_product_data',
            [ __CLASS__, 'fields' ]
        );

        // Zapis meta (zostawiamy jak było)
        add_action(
            'woocommerce_admin_process_product_object',
            [ __CLASS__, 'save' ]
        );
    }

    /**
     * Pola karnetowe w edycji produktu.
     * Pokazujemy je dla normalnych produktów,
     * ukrywamy dla typu "TS Posiłki (Hotel)" (hotel_meal).
     */
    static function fields() {
        global $post;

        // Domyślnie NIE jest posiłkiem
        $is_hotel_meal = false;

        if ( $post && function_exists( 'wc_get_product' ) ) {
            $product = wc_get_product( $post->ID );
            if ( $product && $product->get_type() === 'hotel_meal' ) {
                $is_hotel_meal = true;
            }
        }

        // Jeśli to hotel_meal – na wszelki wypadek wychodzimy,
        // ale i tak dorzucimy JS, który schowa grupę po zmianie typu.
        if ( $is_hotel_meal ) {
            // Grupa i tak będzie ukryta przez JS (patrz <script> poniżej),
            // więc żeby nie robić rewolucji, po prostu nie renderujemy tu nic.
            return;
        }

        // Dla zwykłych produktów renderujemy jak wcześniej
        echo '<div class="options_group ts-karnety-group">';

        woocommerce_wp_select( [
            'id'      => '_ts_ticket_type',
            'label'   => 'TS Karnet – Typ',
            'options' => [
                'single' => 'Jednorazowy',
                'multi'  => 'Wielorazowy (liczba wejść)',
                'period' => 'Wielodniowy (nielimit w okresie)',
            ],
            'desc_tip'    => true,
            'description' => 'Wybierz model karnetu.',
        ] );

        woocommerce_wp_text_input( [
            'id'                => '_ts_entries_total',
            'label'             => 'TS Karnet – Liczba wejść',
            'type'              => 'number',
            'custom_attributes' => [ 'min' => '1', 'step' => '1' ],
            'desc_tip'          => true,
            'description'       => 'Dotyczy tylko typu Wielorazowy.',
        ] );

        woocommerce_wp_text_input( [
            'id'                => '_ts_duration_days',
            'label'             => 'TS Karnet – Długość (dni)',
            'type'              => 'number',
            'custom_attributes' => [ 'min' => '1', 'step' => '1' ],
            'desc_tip'          => true,
            'description'       => 'Dotyczy Wielodniowy.',
        ] );

        echo '</div>';

        // JS:
        // 1) przełącza widoczność pól wejścia / dni jak wcześniej,
        // 2) chowa całą grupę .ts-karnety-group, gdy typ = hotel_meal.
        echo '<script>
jQuery(function($){
  function tsToggleTicketFields(){
    var tType = $("#_ts_ticket_type").val();
    var showE = (tType === "multi");
    var showD = (tType === "period");
    $("._ts_entries_total_field").toggle(showE);
    $("#_ts_entries_total").prop("disabled", !showE);
    $("._ts_duration_days_field").toggle(showD);
    $("#_ts_duration_days").prop("disabled", !showD);
  }

  function tsToggleKarnetGroup(){
    var pType = $("#product-type").val();
    if (pType === "hotel_meal") {
      $(".ts-karnety-group").hide();
    } else {
      $(".ts-karnety-group").show();
    }
  }

  // start
  tsToggleTicketFields();
  tsToggleKarnetGroup();

  $(document).on("change", "#_ts_ticket_type", tsToggleTicketFields);
  $(document).on("change", "#product-type", tsToggleKarnetGroup);
});
</script>';
    }

    static function save( $product ) {
        // NIC nie zmieniamy w logice zapisu – jak było, tak jest
        $type    = sanitize_text_field( $_POST['_ts_ticket_type']   ?? 'single' );
        $days    = intval( $_POST['_ts_duration_days']              ?? 0 );
        $entries = max( 1, intval( $_POST['_ts_entries_total']      ?? 1 ) );

        $product->update_meta_data( '_ts_ticket_type',   $type );
        $product->update_meta_data( '_ts_duration_days', $days );
        $product->update_meta_data( '_ts_entries_total', $entries );
    }
}
