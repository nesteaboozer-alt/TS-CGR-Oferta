jQuery(document).ready(function($){
    
    // Funkcja filtrująca
    function filterProducts(targetSlug) {
        // Filtrujemy tylko produkty w głównej sekcji
        $('.tsfl-main-grid .tsfl-card').each(function(){
            var productCats = $(this).data('categories') || "";
            
            if ( targetSlug === '' ) {
                $(this).fadeIn(200);
            } else {
                if ( (',' + productCats + ',').indexOf(',' + targetSlug + ',') !== -1 ) {
                    $(this).fadeIn(200);
                } else {
                    $(this).hide();
                }
            }
        });
    }

    // Obsługa kliknięcia w zakładkę
    $('.tsfl-tab').on('click', function(){
        $('.tsfl-tab').removeClass('active');
        $(this).addClass('active');

        var targetSlug = $(this).data('cat');
        filterProducts(targetSlug);
    });

    // STARTOWA INICJALIZACJA PRZEZ JS JEST USUNIĘTA 
    // PHP zajęło się renderowaniem domyślnej zakładki, więc JS czeka na interakcję.
});