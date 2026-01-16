jQuery(document).ready(function($){

    var poolData = (typeof tsbbVars !== 'undefined') ? tsbbVars.poolData : {};

    $('.tsbb-tab').on('click', function(){
        $('.tsbb-tab').removeClass('active');
        $(this).addClass('active');
        var target = $(this).data('target');
        $('.tsbb-panel').hide();
        $('#tsbb-panel-' + target).fadeIn(200);
    });
      // --- sterowanie UI dla zakładki Wyżywienie (EVENT: data + budynki) ---

    var $mealObjectSelect = $('#tsbb-meal-object'); // select "OBIEKT"
    var tsbbAllBuildingOptions = null;

    // zapamiętaj pełną listę opcji OBIEKT (żeby móc przywrócić dla nie-EVENT)
    if ($mealObjectSelect.length) {
        tsbbAllBuildingOptions = $mealObjectSelect.find('option').clone();
    }

    function tsbbUpdateMealUI() {
        var $select = $('#tsbb-meal-id'); // RODZAJ
        if (!$select.length) return;

        var $selected = $select.find('option:selected');
        var isEvent = $selected.data('event') === 1 || $selected.data('event') === '1';

        // 1) Data przyjazdu – jak wcześniej
        var $dateField = $('.tsbb-meal-date-field');
        if ($dateField.length) {
            if (isEvent) {
                $dateField.hide();
                $('#tsbb-meal-date').val(''); // czyścimy datę
            } else {
                $dateField.show();
            }
        }

        // 2) Lista OBIEKT – zawężamy tylko dla EVENT
        if ($mealObjectSelect.length && tsbbAllBuildingOptions) {

            if (isEvent) {
                var buildingsStr = $selected.data('buildings') || '';
                var buildingsList = buildingsStr
                    .split('|')
                    .map(function (s) { return s.trim(); })
                    .filter(Boolean);

                // jeśli są zdefiniowane budynki dla tego EVENTU → filtruj
                if (buildingsList.length) {
                    var $newOptions = [];

                    // placeholder (pierwsza opcja – np. "Wybierz obiekt")
                    var $placeholder = tsbbAllBuildingOptions.first().clone();
                    $newOptions.push($placeholder);

                    tsbbAllBuildingOptions.each(function (index) {
                        if (index === 0) return; // placeholder już mamy

                        var $opt = $(this);
                        var text = $.trim($opt.text());

                        // jeżeli nazwa opcji jest na liście budynków z macierzy
                        if (buildingsList.indexOf(text) !== -1) {
                            $newOptions.push($opt.clone());
                        }
                    });

                    $mealObjectSelect.empty();
                    $newOptions.forEach(function ($opt) {
                        $mealObjectSelect.append($opt);
                    });

                } else {
                    // brak zdefiniowanych budynków w macierzy – zostaw pełną listę
                    $mealObjectSelect.empty().append(tsbbAllBuildingOptions.clone());
                }

            } else {
                // NIE-EVENT → pełna lista budynków jak dotychczas
                $mealObjectSelect.empty().append(tsbbAllBuildingOptions.clone());
            }
        }
    }

    // reaguj na zmianę RODZAJ
    $('#tsbb-meal-id').on('change', tsbbUpdateMealUI);

    // stan początkowy po załadowaniu strony
    tsbbUpdateMealUI();
    // --- KONIEC BLOKU ---



    $('#tsbb-pool-cat').on('change', function(){
        var cat = $(this).val();
        var $obj = $('#tsbb-pool-object');
        var $srv = $('#tsbb-pool-service');

        $obj.html('<option value="">Wybierz...</option>').prop('disabled', true);
        $srv.html('<option value="">Najpierw obiekt...</option>').prop('disabled', true);

        if( cat && poolData && poolData[cat] ) {
            var places = Object.keys(poolData[cat]);
            
            if (places.length > 0) {
                $.each(places, function(index, placeName){
                    $obj.append('<option value="'+placeName+'">'+placeName+'</option>');
                });
                $obj.prop('disabled', false);
            }
        }

        if ($.fn.niceSelect) {
            $obj.niceSelect('update');
            $srv.niceSelect('update');
        }
    });

    $('#tsbb-pool-object').on('change', function(){
        var cat = $('#tsbb-pool-cat').val();
        var obj = $(this).val();
        var $srvSelect = $('#tsbb-pool-service');

        $srvSelect.html('<option value="">Wybierz usługę...</option>').prop('disabled', true);

        if( cat && obj && poolData[cat] && poolData[cat][obj] ) {
            var products = poolData[cat][obj];
            
            if (products.length > 0) {
                $.each(products, function(i, prod){
                    $srvSelect.append('<option value="'+prod.url+'">'+prod.name+'</option>');
                });
                $srvSelect.prop('disabled', false);
            }
        }

        if ($.fn.niceSelect) {
            $srvSelect.niceSelect('update');
        }
    });

    $('#tsbb-go-pool').on('click', function(){
        var url = $('#tsbb-pool-service').val();
        if(url) window.location.href = url;
        else alert('Wybierz usługę.');
    });
        // --- Wyżywienie: obsługa EVENT (data + budynki) ---

    var $mealObjectSelect = $('#tsbb-meal-object');
    var allMealObjectOptions = null;

    if ($mealObjectSelect.length) {
        allMealObjectOptions = $mealObjectSelect.find('option').clone();
    }

    function tsbbUpdateMealUI() {
        var $mealSelect = $('#tsbb-meal-id');
        if (!$mealSelect.length) return;

        var $selected = $mealSelect.find('option:selected');
        if (!$selected.length) return;

        var isEvent = $selected.data('event') === 1 || $selected.data('event') === '1';

        // 1) Data przyjazdu – dla EVENT chowamy
        var $dateField = $('.tsbb-meal-date-field');
        if ($dateField.length) {
            if (isEvent) {
                $dateField.hide();
                $('#tsbb-meal-date').val('');
            } else {
                $dateField.show();
            }
        }

        // 2) Obiekt – jeśli produkt ma przypisane budynki, filtrujemy listę
        if ($mealObjectSelect.length && allMealObjectOptions) {
            var buildingsStr = $selected.data('buildings') || '';
            var buildingsList = $.map(buildingsStr.split('|'), function (item) {
                item = $.trim(item);
                return item.length ? item : null;
            });

            if (buildingsList.length) {
                var $newOptions = [];
                // placeholder (pierwsza oryginalna opcja, np. "Wybierz...")
                var $placeholder = allMealObjectOptions.first().clone();
                $newOptions.push($placeholder);

                allMealObjectOptions.each(function (index) {
                    if (index === 0) return; // już mamy placeholder
                    var $opt = $(this);
                    var text = $.trim($opt.text());
                    if ($.inArray(text, buildingsList) !== -1) {
                        $newOptions.push($opt.clone());
                    }
                });

                $mealObjectSelect.empty();
                $.each($newOptions, function (_, $opt) {
                    $mealObjectSelect.append($opt);
                });

                if ($.fn.niceSelect) {
                    $mealObjectSelect.niceSelect('update');
                }

            } else {
                // brak ograniczeń – pełna lista budynków
                $mealObjectSelect.empty().append(allMealObjectOptions.clone());
                if ($.fn.niceSelect) {
                    $mealObjectSelect.niceSelect('update');
                }
            }
        }
    }

    $('#tsbb-meal-id').on('change', tsbbUpdateMealUI);
    tsbbUpdateMealUI();


           $('#tsbb-go-meals').on('click', function(){
        var $mealSelect = $('#tsbb-meal-id');
        var url = $mealSelect.val();
        var obj = $('#tsbb-meal-object').val();

        var isEvent = false;
        if ($mealSelect.length) {
            var $selected = $mealSelect.find('option:selected');
            if ($selected.length) {
                isEvent = $selected.data('event') === 1 || $selected.data('event') === '1';
            }
        }

        // dla EVENT nie bierzemy daty w ogóle
        var date = isEvent ? '' : $('#tsbb-meal-date').val();

        if (!url) {
            alert('Wybierz rodzaj posiłku.');
            return;
        }

        var finalUrl = url;
        if (obj || date) {
            finalUrl += '?tsme_prefill=1';
            if (obj) finalUrl += '&tsme_object=' + encodeURIComponent(obj);
            if (date) finalUrl += '&tsme_stay_from=' + encodeURIComponent(date);
        }
        window.location.href = finalUrl;
    });



});