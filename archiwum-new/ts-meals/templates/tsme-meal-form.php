<?php
/**
 * TS Hotel Meals – Pola Formularza (v3.7)
 */
defined( 'ABSPATH' ) || exit;
/** @var WC_Product $product */
/** @var array $pricing_matrix */

$meal_type      = '';
$event_from     = '';
$event_to       = '';
$event_comment  = '';

if ( $product instanceof WC_Product ) {
    $product_id = $product->get_id();
    $meal_type  = get_post_meta( $product_id, TSME_Admin_Product::META_MEAL_TYPE, true );

    if ( 'event' === $meal_type ) {
        $event_from    = get_post_meta( $product_id, TSME_Admin_Product::META_EVENT_DATE_FROM, true );
        $event_to      = get_post_meta( $product_id, TSME_Admin_Product::META_EVENT_DATE_TO, true );
        $event_comment = get_post_meta( $product_id, TSME_Admin_Product::META_EVENT_COMMENT, true );

        if ( $event_from && ! $event_to ) {
            // jeśli admin poda tylko jeden dzień – traktujemy jako jednodniowy event
            $event_to = $event_from;
        }
    }
}
?>

<div class="tsme-form-content">


    <div id="tsme-validation-msg" style="display:none;"></div>

    <div class="tsme-grid">
        
        <div class="tsme-col-full">
            <label class="tsme-label">Obiekt</label>
            <div class="tsme-select-wrap">
                <?php if ( ! empty( $pricing_matrix ) && is_array( $pricing_matrix ) ) : ?>
                    <select id="tsme_object" name="tsme_object" class="tsme-input" required>
                        <option value="">Wybierz budynek...</option>
                        <?php foreach ( $pricing_matrix as $row ) : ?>
                            <option value="<?php echo esc_attr( $row['name'] ); ?>"><?php echo esc_html( $row['name'] ); ?></option>
                        <?php endforeach; ?>
                    </select>
                <?php else : ?>
                    <input type="text" id="tsme_object" name="tsme_object" class="tsme-input" placeholder="Wpisz nazwę..." required />
                <?php endif; ?>
            </div>
        </div>

        <div class="tsme-col-full">
            <label class="tsme-label">Imię i nazwisko - wystarczy jednej z osób</label>
            <input type="text" id="tsme_room_number" name="tsme_room_number" class="tsme-input" placeholder="Np. Jan Kowalski" required />
        </div>

                <?php if ( 'event' === $meal_type ) : ?>
            <div class="tsme-col-full tsme-event-date">
                <label class="tsme-label">Data realizacji usługi</label>

                <div class="tsme-event-date-text">
                    <?php
                    $display_from = $event_from ? date_i18n( 'd.m.Y', strtotime( $event_from ) ) : '';
                    $display_to   = $event_to   ? date_i18n( 'd.m.Y', strtotime( $event_to ) )   : '';

                    if ( $display_from && $display_to && $display_from !== $display_to ) :
                        printf(
                            '<strong>%s – %s</strong>',
                            esc_html( $display_from ),
                            esc_html( $display_to )
                        );
                    elseif ( $display_from ) :
                        printf(
                            '<strong>%s</strong>',
                            esc_html( $display_from )
                        );
                    else :
                        ?>
                        <em><?php esc_html_e( 'Data eventu nie została skonfigurowana. Skontaktuj się z obsługą.', 'ts-hotel-meals' ); ?></em>
                        <?php
                    endif;
                    ?>
                </div>

                <?php if ( ! empty( $event_comment ) ) : ?>
                    <p class="tsme-event-comment">
                        <?php echo nl2br( esc_html( $event_comment ) ); ?>
                    </p>
                <?php endif; ?>

                <!-- Ukryte pola, żeby logika JS / backend dostała zakres pobytu -->
                <input type="hidden" id="tsme_stay_from" name="tsme_stay_from" value="<?php echo esc_attr( $event_from ); ?>" />
                <input type="hidden" id="tsme_stay_to"   name="tsme_stay_to"   value="<?php echo esc_attr( $event_to ); ?>" />
            </div>
        <?php else : ?>
            <div class="tsme-col-half">
                <label class="tsme-label">Data przyjazdu</label>
                <input type="date" id="tsme_stay_from" name="tsme_stay_from" class="tsme-input" required />
            </div>
            <div class="tsme-col-half">
                <label class="tsme-label">Data wyjazdu</label>
                <input type="date" id="tsme_stay_to" name="tsme_stay_to" class="tsme-input" required />
            </div>
        <?php endif; ?>


        <div class="tsme-col-half">
            <label class="tsme-label">Dorośli</label>
            <input type="number" min="0" id="tsme_adults" name="tsme_adults" class="tsme-input" value="2" required />
        </div>
        <div class="tsme-col-half">
            <label class="tsme-label">Dzieci (3-12 lat)</label>
            <input type="number" min="0" id="tsme_children" name="tsme_children" class="tsme-input" value="0" required />
        </div>

    </div>

    <div id="tsme-ajax-summary">
        <div id="tsme-ajax-messages"></div>
        <div id="tsme-ajax-total">
            <span class="tsme-total-label">Szacowany koszt tego pokoju:</span>
            <span id="tsme-ajax-price">---</span>
        </div>
    </div>

</div>