jQuery(document).ready(function($){
    
    // Proste filtrowanie po kliknięciu w zakładkę
    $('.tsfl-tab').on('click', function(){
        // 1. Klasy
        $('.tsfl-tab').removeClass('active');
        $(this).addClass('active');

        // 2. Slug
        var targetSlug = $(this).data('cat');

        // 3. Pokaż/Ukryj kafelki
        $('.tsfl-card').each(function(){
            var productCats = $(this).data('categories'); // string np. "posilki,basen"
            
            // Jeśli zakładka nie ma sluga (np. Wszystkie) -> Pokaż
            if ( targetSlug === '' ) {
                $(this).fadeIn(200);
            } else {
                // Szukamy sluga w stringu kategorii (z przecinkami dla pewności)
                if ( (',' + productCats + ',').indexOf(',' + targetSlug + ',') !== -1 ) {
                    $(this).fadeIn(200);
                } else {
                    $(this).hide();
                }
            }
        });
    });

    // Auto-start (kliknij pierwszą)
    var $activeTab = $('.tsfl-tab.active');
    if($activeTab.length) {
        $activeTab.trigger('click');
    } else {
        $('.tsfl-tab').first().trigger('click');
    }

});