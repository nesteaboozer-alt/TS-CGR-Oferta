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

            // Przełącz panel 
        
            $(".tsme-tab-panel").removeClass("tsme-tab-panel--active");
 $('#tsme-tab-' + tab).addClass("tsme-tab-panel--active");
});

        // Obsługa unieważniania kodu (Void)
        $(document).on("click", ".tsme-void-btn", function (e) {
            e.preventDefault();
            var $btn = $(this);
            var codeId = $btn.data("id");

            if (!confirm("Czy na pewno unieważnić ten kod? Klient nie będzie mógł go użyć.")) {
                return;
            }

            $btn.prop("disabled", true).text("...");

            $.post(tsme_admin.ajaxurl, {
                action: "tsme_void_code",
                code_id: codeId,
                nonce: tsme_admin.nonce
            }, function (response) {
                if (response.success) {
                    var $row = $btn.closest("tr");
                    // Status to 7-ma kolumna w tabeli Zamówienia
                    $row.find("td:nth-child(7)").text("void").css({
                        "color": "#ef4444",
                        "font-weight": "bold"
                    });
                    $btn.remove();
                } else {
                    alert("Błąd podczas unieważniania kodu.");
                    $btn.prop("disabled", false).text("Unieważnij");
                }
            });
        });
    });
})(jQuery);
