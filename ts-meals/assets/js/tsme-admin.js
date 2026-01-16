(function ($) {
    $(function () {
        // Proste zakładki – na razie mamy tylko "podgląd",
        // ale struktura jest gotowa pod kolejne.
        $(document).on("click", ".tsme-tabs__link", function (e) {
            e.preventDefault();

            var $btn = $(this);
            var tab = $btn.data("tsme-tab");
            if (!tab) {
                return;
            }

            // Przełącz aktywną zakładkę
            $(".tsme-tabs__link").removeClass("tsme-tabs__link--active");
            $btn.addClass("tsme-tabs__link--active");

            // Przełącz panel
            $(".tsme-tab-panel").removeClass("tsme-tab-panel--active");
            $('#tsme-tab-' + tab).addClass("tsme-tab-panel--active");
        });
    });
})(jQuery);
