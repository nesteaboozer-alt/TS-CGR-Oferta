<?php
/**
 * Template Name: TS Hotel Meal - Final v3.8
 */
if ( ! defined( 'ABSPATH' ) ) exit;
get_header( 'shop' );
global $product;
?>

<div id="tsme-app-root">
    <div class="tsme-layout-container">
        
        <div class="tsme-hero-header">
            <h1><?php the_title(); ?></h1>
        </div>

        <div class="tsme-booking-card">
            <div class="tsme-intro-content"><?php the_excerpt(); ?></div>
            <div class="tsme-woo-messages"><?php do_action( 'woocommerce_before_single_product' ); ?></div>

            <div id="tsme-rooms-list-header" style="display:none;">
                <h4>Twoje skonfigurowane pokoje:</h4>
                <button type="button" id="tsme-clear-all-btn">Wyczyść listę 🗑️</button>
            </div>
            <div id="tsme-added-rooms-list"></div>

            <form class="cart" id="tsme-main-form" action="<?php echo esc_url( apply_filters( 'woocommerce_add_to_cart_form_action', $product->get_permalink() ) ); ?>" method="post" enctype='multipart/form-data'>
                
                <div class="tsme-form-header">
                    <h3>Konfiguracja pokoju</h3>
                    <p>Uzupełnij dane dla bieżącego pokoju.</p>
                </div>

                <div id="tsme-next-room-prompt" style="display:none;">
                    👇 <strong>Poprzedni pokój dodany!</strong><br>
                    Formularz został wyczyszczony. Możesz teraz skonfigurować kolejny pokój od zera i dodać go do listy lub przejść do koszyka.
                </div>

                <div class="tsme-form-body"><?php TSME_Frontend::render_form(); ?></div>

                <div class="tsme-form-footer">
                    <button type="button" id="tsme-btn-add-another" class="tsme-btn tsme-btn-outline tsme-disabled">
                        <span>+</span> Dodaj kolejny pokój
                    </button>
                    <button type="button" id="tsme-btn-finish" class="tsme-btn tsme-btn-primary tsme-disabled">
                        Przejdź do koszyka <span id="tsme-cart-counter"></span>
                    </button>
                </div>
            </form>
        </div>
        
        <div class="tsme-tabs-wrapper"><?php wc_get_template( 'single-product/tabs/tabs.php' ); ?></div>
    </div>
</div>

<div id="tsme-success-modal" style="display:none;">
    <div class="tsme-modal-overlay"></div>
    <div class="tsme-modal-content">
        <div class="tsme-modal-icon">✅</div>
        <h3>Pokój dodany!</h3>
        <p>Co chcesz zrobić?</p>
        <div class="tsme-modal-actions">
            <button type="button" id="tsme-modal-add-next" class="tsme-btn tsme-btn-outline">Chce dodać kolejny pokój</button>
            <button type="button" id="tsme-modal-go-cart" class="tsme-btn tsme-btn-primary">Idź do kasy, mam wszystko</button>
        </div>
    </div>
</div>
<div id="tsme-abandon-modal" style="display:none;">
    <div class="tsme-modal-overlay"></div>
    <div class="tsme-modal-content">
        <div class="tsme-modal-icon">⚠️</div>
        <h3>Masz niezatwierdzone dane!</h3>
        <p>Zaczęto wypełniać kolejny pokój, ale jest niekompletny. Co chcesz zrobić?</p>
        <div class="tsme-modal-actions">
            <button type="button" id="tsme-modal-add-current-go" class="tsme-btn tsme-btn-primary">
                Wróc do konfiguracji
            </button>
            <button type="button" id="tsme-modal-ignore-go" class="tsme-btn tsme-btn-outline">
                Idź do kasy, mam wszystko
            </button>
        </div>
    </div>
</div>
<div id="tsme-loading-overlay"><div class="tsme-spinner"></div></div>

<?php get_footer( 'shop' ); ?>