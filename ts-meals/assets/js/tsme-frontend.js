(function($){
    $(function(){
        
        // --- KONFIGURACJA CACHE ---
        var STORAGE_KEY = 'tsme_rooms_cache_' + tsme_vars.product_id;
        var STORAGE_TTL = 5 * 60 * 1000; // 5 minut w milisekundach

        // --- INFORMACJE O TYPIE POSIŁKU ---
        var mealType = (window.tsme_vars && tsme_vars.meal_type) ? tsme_vars.meal_type : '';

        // --- UCHWYTY DOM ---
        var $summary    = $('#tsme-ajax-summary');

        var $priceBox   = $('#tsme-ajax-price');
        var $msgBox     = $('#tsme-ajax-messages');
        var $errorBox   = $('#tsme-validation-msg');
        
        var $roomsList  = $('#tsme-added-rooms-list');
        var $listHeader = $('#tsme-rooms-list-header');
        var $promptBox  = $('#tsme-next-room-prompt');
        var $modalAbandon = $('#tsme-abandon-modal');

        var $btnAdd     = $('#tsme-btn-add-another');
        var $btnFinish  = $('#tsme-btn-finish');
        var $btnClear   = $('#tsme-clear-all-btn');
        var $counter    = $('#tsme-cart-counter');
        
        var $modal      = $('#tsme-success-modal');
        var $modalNext  = $('#tsme-modal-add-next');
        var $modalCart  = $('#tsme-modal-go-cart');
        var $loader     = $('#tsme-loading-overlay');
        
        var roomsQueue = []; 
        var updateTimer;
        var isPriceCalculated = false; 

        // --- 1. OBSŁUGA CACHE (LOCAL STORAGE) ---
        
        function saveQueueToStorage() {
            var data = {
                queue: roomsQueue,
                timestamp: new Date().getTime()
            };
            localStorage.setItem(STORAGE_KEY, JSON.stringify(data));
        }

        function loadQueueFromStorage() {
            var raw = localStorage.getItem(STORAGE_KEY);
            if (!raw) return;

            try {
                var data = JSON.parse(raw);
                var now = new Date().getTime();
                // Sprawdź czy cache nie wygasł (5 min)
                if (now - data.timestamp < STORAGE_TTL) {
                    roomsQueue = data.queue || [];
                    if (roomsQueue.length > 0) {
                        renderQueue();
                        // Jeśli załadowaliśmy dane, pokaż listę
                        $listHeader.show();
                        // Wyczyść formularz startowy, żeby nie mylił
                        resetForm();
                    }
                } else {
                    localStorage.removeItem(STORAGE_KEY); // Wygasło
                }
            } catch(e) {
                console.error('Błąd odczytu cache TSME', e);
            }
        }

        function clearQueueStorage() {
            roomsQueue = [];
            localStorage.removeItem(STORAGE_KEY);
            renderQueue();
            $listHeader.hide();
            updateCounter();
            // Reset przycisków
            checkFormState();
        }


        // --- 2. WALIDACJA I BŁĘDY ---

        function showValidationError( message ) {
            $errorBox.html('⚠️ ' + message).slideDown();
            // Scroll do błędu
            $('html, body').animate({ 
                scrollTop: $errorBox.offset().top - 120 
            }, 300);
            // USUNIĘTO setTimeout - błąd nie znika sam
        }

 

        // Czy formularz jest "napoczęty"? (Ignoruje domyślne 2 dorosłych)
        function isFormDirty() {
            var obj  = $('#tsme_object').val();
            var room = $('#tsme_room_number').val();
            var from = $('#tsme_stay_from').val();
            
            // Uznajemy formularz za brudny TYLKO jeśli ruszono Obiekt, Pokój lub Datę.
            // Ilość osób (2/0) jest domyślna, więc nie traktujemy jej jako zmiany użytkownika.
            return (obj || room || from);
        }

        function hideError() {
            $errorBox.slideUp();
        }

        function explainError() {
            var obj     = $('#tsme_object').val();
            var from    = $('#tsme_stay_from').val();
            var to      = $('#tsme_stay_to').val();
            var roomNum = $('#tsme_room_number').val();
            var adults  = parseInt($('#tsme_adults').val()) || 0;

            if( !obj ) return 'Wybierz najpierw <strong>Obiekt</strong> z listy.';
            if( !from || !to ) return 'Uzupełnij <strong>daty pobytu</strong>.';
            
            // NAJPIERW sprawdzamy pola ręczne
            if( !roomNum ) return 'Wpisz <strong>imię i nazwisko</strong>.';
            if( adults < 1 ) return 'Liczba dorosłych musi wynosić <strong>minimum 1</strong>.';

            // DOPIERO POTEM cenę (jeśli dane są OK, a ceny brak - to wina dat/backendu)
            if( !isPriceCalculated ) return 'Cena nie została obliczona (sprawdź poprawność dat).';
            
            return 'Uzupełnij poprawnie formularz.';
        }

        // Sterowanie stanem przycisków
        function checkFormState() {
            var hasAddedRooms = roomsQueue.length > 0;
            
            var roomNum = $('#tsme_room_number').val();
            var adults  = parseInt($('#tsme_adults').val()) || 0;

            var isPeopleValid = (adults >= 1);
            var isCurrentFormValid = isPriceCalculated && (roomNum.trim() !== '') && isPeopleValid;

            // Dodaj kolejny
            if ( isCurrentFormValid ) {
                $btnAdd.removeClass('tsme-disabled');
            } else {
                $btnAdd.addClass('tsme-disabled');
            }

            // Koszyk (aktywny jeśli form OK lub coś w kolejce)
            if ( isCurrentFormValid || hasAddedRooms ) {
                $btnFinish.removeClass('tsme-disabled');
            } else {
                $btnFinish.addClass('tsme-disabled');
            }

            updateCounter(isCurrentFormValid);
        }

        function updateCounter(isCurrentValid) {
            var total = roomsQueue.length;
            // Jeśli obecny formularz jest gotowy, dolicz go wirtualnie do licznika
            if (isCurrentValid) total++;

            if (total > 0) {
                $counter.text(' (' + total + ')');
            } else {
                $counter.text('');
            }
        }

        // --- 3. LISTA POKOI ---

        function renderQueue() {
            $roomsList.empty();
            roomsQueue.forEach(function(room, idx) {
                var number = idx + 1;
                var html = `
                    <div class="tsme-room-success-item">
                        <div style="display:flex;align-items:center;">
                            <span class="tsme-room-number-badge">#${number}</span>
                            <div>
                                <strong>${room.displayObj} &ndash; Pokój ${room.room_number}</strong>
                                <span>${room.stay_from} do ${room.stay_to} (${room.guests})</span>
                            </div>
                        </div>
                        <div class="tsme-room-remove" title="Usuń" data-index="${idx}">✕</div>
                    </div>
                `;
                $roomsList.append(html);
            });

            if(roomsQueue.length > 0) $listHeader.show();
            else $listHeader.hide();
        }

        // Usuwanie pojedyncze
        $(document).on('click', '.tsme-room-remove', function() {
            var idx = $(this).data('index');
            roomsQueue.splice(idx, 1);
            saveQueueToStorage(); // Update cache
            renderQueue();
            checkFormState();
        });

        // Wyczyść wszystko
        $btnClear.on('click', function(){
            if(confirm('Czy na pewno chcesz usunąć wszystkie dodane pokoje?')) {
                clearQueueStorage();
            }
        });


        
        // --- 4. AJAX CENA (Live) ---
        function updateSummary() {
            var objectName = $('#tsme_object').val();
            var stayFrom   = $('#tsme_stay_from').val();
            var stayTo     = $('#tsme_stay_to').val();
            var adults     = $('#tsme_adults').val();
            var children   = $('#tsme_children').val();

            isPriceCalculated = false;
            checkFormState();
            
            // USUNIĘTO hideError(); stąd - błąd ma znikać tylko przy sukcesie lub edycji

                        if (!objectName || !stayFrom || !stayTo) { $summary.hide(); return; }

            // Dla Eventu dopuszczamy 1 dzień (stayFrom == stayTo)
            if ( mealType !== 'event' && stayFrom >= stayTo ) {
                showValidationError('Data wyjazdu musi być późniejsza niż przyjazdu.');
                $summary.hide(); return;
            }


            if ( parseInt(adults) < 1 ) {
                // Nie pokazujemy błędu od razu, ale blokujemy liczenie
                $summary.hide(); return; 
            }

            if ( $summary.is(':hidden') ) {
                $priceBox.html('<span style="color:#999;font-size:0.6em">Liczenie...</span>');
                $summary.slideDown();
            } else { $priceBox.css('opacity', 0.4); }

            $.post( tsme_vars.ajaxurl, {
                action: 'tsme_calculate_summary', product_id: tsme_vars.product_id,
                object: objectName, stay_from: stayFrom, stay_to: stayTo,
                adults: adults, children: children
            }, function(res) {
                $priceBox.css('opacity', 1);
                if(res.success) {
                    $priceBox.html(res.data.price_html);
                    $msgBox.empty();
                    if(res.data.messages) {
                        $.each(res.data.messages, function(i, item){
                            // Sprawdzamy czy to obiekt (nowa wersja) czy string (stara wersja)
                            var icon = item.icon ? item.icon : 'ℹ️';
                            var text = item.text ? item.text : item;
                            
                            $msgBox.append('<div class="tsme-info-msg">' + icon + ' ' + text + '</div>');
                        });
                    }
                    isPriceCalculated = true;
                    checkFormState();
                } else {
                    // Błąd z backendu (np. brak oferty)
                    var err = res.data || 'Błąd.';
                    showValidationError(err);
                    $summary.slideUp();
                    isPriceCalculated = false;
                    checkFormState();
                }
            }).fail(function() { $priceBox.css('opacity', 1).html('---'); });
        }

        $('#tsme_object, #tsme_stay_from, #tsme_stay_to, #tsme_adults, #tsme_children, #tsme_room_number').on('change input blur keyup', function(){
            // Jak user zaczyna pisać/zmieniać -> schowaj stary błąd
            hideError();
            
            clearTimeout(updateTimer); 
            updateTimer = setTimeout(updateSummary, 150);
            setTimeout(checkFormState, 50);
        });


        // --- 5. AKCJE PRZYCISKÓW ---

         function resetForm() {
            // 1. Reset wartości w HTML
            var $objSelect = $('#tsme_object');
            $objSelect.val('');
            
            // 2. FIX WIZUALNY: Wymuszenie odświeżenia na motywie
            // To mówi nakładkom typu nice-select/select2: "zmieniłem się, przerysuj się!"
            $objSelect.trigger('change'); 
            
            if ( $.fn.niceSelect ) {
                $objSelect.niceSelect('update'); // Specyficzne dla Twojego motywu
            }

                        // 3. Reset reszty pól
            $('#tsme_room_number').val('');

            // Dla Eventu NIE czyścimy dat (są przypisane na sztywno do produktu)
            if ( mealType !== 'event' ) {
                $('#tsme_stay_from').val('');
                $('#tsme_stay_to').val('');
            }
            
            // Domyślne osoby
            $('#tsme_adults').val('2');
            $('#tsme_children').val('0');

            
            // Ukrycie ceny i blokada przycisków
            $summary.hide();
            isPriceCalculated = false;
            checkFormState();
        }

        // KLIK: Dodaj kolejny
        $btnAdd.on('click', function(e){
            e.preventDefault();
            
            if ( $(this).hasClass('tsme-disabled') ) {
                showValidationError( explainError() );
                return;
            }

            // Dodajemy lokalnie do kolejki (bez wysyłki do Woo jeszcze!)
            // Zmiana logiki na korzyść "Batch Submit" dla lepszego UX i mniejszej szansy na błędy sieci
            // Ale skoro mamy już logikę "Wyślij -> Wyczyść", trzymajmy się jej, ALE zapiszmy w storage dla bezpieczeństwa.
            
            // 1. Pobieramy dane
            var roomData = {
                object: $('#tsme_object').val(),
                room_number: $('#tsme_room_number').val(),
                stay_from: $('#tsme_stay_from').val(),
                stay_to: $('#tsme_stay_to').val(),
                adults: $('#tsme_adults').val(),
                children: $('#tsme_children').val(),
                displayObj: $('#tsme_object option:selected').text(),
                guests: $('#tsme_adults').val() + ' dor., ' + $('#tsme_children').val() + ' dz.'
            };

            // 2. Dodajemy do tablicy i Storage
            roomsQueue.push(roomData);
            saveQueueToStorage();
            renderQueue();

            // 3. Modal i Reset
            $modal.css('display', 'flex').hide().fadeIn(200);
            
            // Tutaj nie wysyłamy jeszcze do Woo! Wysyłamy wszystko na koniec.
            // Dzięki temu mamy cache i undo.
        });

        // --- 5. KLIK: KOSZYK ---
        $btnFinish.on('click', function(e){
            e.preventDefault();
            
            var hasAddedRooms = $roomsList.children().length > 0;
            var formDirty = isFormDirty();

            // Sprawdź gotowość obecnego formularza
            var roomNum = $('#tsme_room_number').val();
            var adults  = parseInt($('#tsme_adults').val()) || 0;
            var isCurrentReady = (isPriceCalculated && roomNum && adults >= 1);

            // 1. Jeśli przycisk jest SZARY (zablokowany) -> Pokaż dlaczego
            if ( $(this).hasClass('tsme-disabled') ) {
                if (!hasAddedRooms) {
                    showValidationError( explainError() );
                } else {
                    // Mamy pokoje w kolejce, ale obecny formularz jest błędny/niepełny
                    // Pytamy usera co robić (Modal Porzucony)
                    $modalAbandon.css('display', 'flex').hide().fadeIn(200);
                }
                return;
            }

            // 2. SCENARIUSZ IDEALNY: Formularz jest wypełniony i poprawny
            // -> Dodaj go automatycznie do kolejki i wyślij wszystko
            if ( isCurrentReady ) {
                var currentRoom = {
                    object: $('#tsme_object').val(),
                    room_number: $('#tsme_room_number').val(),
                    stay_from: $('#tsme_stay_from').val(),
                    stay_to: $('#tsme_stay_to').val(),
                    adults: $('#tsme_adults').val(),
                    children: $('#tsme_children').val(),
                    displayObj: $('#tsme_object option:selected').text(),
                    guests: $('#tsme_adults').val() + ' dor.' + ($('#tsme_children').val() > 0 ? ', ' + $('#tsme_children').val() + ' dz.' : '')
                };
                roomsQueue.push(currentRoom);
                saveQueueToStorage();
                
                // Wyślij wszystko (Batch)
                processBatch();
                return;
            }

            // 3. SCENARIUSZ: Formularz jest "rozgrzebany" ale niepoprawny (np. brak ceny)
            // (To w sumie łapie się w punkcie 1 jeśli button jest disabled, 
            // ale dla pewności zostawiamy obsługę dirty)
            if ( formDirty && !isCurrentReady ) {
                $modalAbandon.css('display', 'flex').hide().fadeIn(200);
                return;
            }

            // 4. SCENARIUSZ: Formularz czysty, ale są pokoje w kolejce -> Wyślij
            if ( hasAddedRooms ) {
                processBatch();
            }
        });

        function processBatch() {
            $loader.css('display', 'flex').hide().fadeIn(200);
            sendRoomRecursive(0);
        }

        function sendRoomRecursive(index) {
            if(index >= roomsQueue.length) {
                // SUKCES WSZYSTKIEGO
                clearQueueStorage(); // Czyść cache po udanym zakupie
                window.location.href = tsme_vars.cart_url;
                return;
            }

            var room = roomsQueue[index];
            // Aktualizacja tekstu loadera
            // $('#tsme-loading-text').text('Dodaję pokój ' + (index+1)); 

            var data = {
                'tsme_object': room.object,
                'tsme_room_number': room.room_number,
                'tsme_stay_from': room.stay_from,
                'tsme_stay_to': room.stay_to,
                'tsme_adults': room.adults,
                'tsme_children': room.children,
                'add-to-cart': tsme_vars.product_id
            };

            $.post( window.location.href, data, function() {
                sendRoomRecursive(index + 1);
            }).fail(function() {
                $loader.fadeOut(200);
                alert('Wystąpił błąd połączenia. Spróbuj ponownie.');
            });
        }

        // Modals
        $modalNext.on('click', function(){
            $modal.fadeOut(200);
            resetForm(); // Czyść formularz dopiero tutaj
            $('html, body').animate({ scrollTop: $("#tsme-app-root").offset().top }, 500);
            // Pokaż prompt
            $promptBox.slideDown();
        });

        $modalCart.on('click', function(){
            $modal.fadeOut(200);
            processBatch();
        });

        // Focus validation
        $('#tsme_stay_from, #tsme_stay_to, #tsme_adults, #tsme_children').on('focus', function() {
            if ( ! $('#tsme_object').val() ) {
                $(this).blur(); showValidationError('Najpierw wybierz <strong>Obiekt</strong> z listy.');
            }
        });

        // --- MODAL PORZUCONY: AKCJE ---
        
        // "Dodaj ten pokój i idź do kasy"
        $('#tsme-modal-add-current-go').on('click', function(){
            // Walidacja obecnego
            if ( !isPriceCalculated || !$('#tsme_room_number').val() ) {
                $modalAbandon.fadeOut(200);
                showValidationError('Nie można dodać tego pokoju - dane są niekompletne. Popraw je lub wybierz "Pomiń".');
                return;
            }
            
            // Dodaj do kolejki
            var roomData = {
                object: $('#tsme_object').val(),
                room_number: $('#tsme_room_number').val(),
                stay_from: $('#tsme_stay_from').val(),
                stay_to: $('#tsme_stay_to').val(),
                adults: $('#tsme_adults').val(),
                children: $('#tsme_children').val(),
                displayObj: $('#tsme_object option:selected').text(),
                guests: $('#tsme_adults').val() + ' dor.'
            };
            roomsQueue.push(roomData);
            saveQueueToStorage();
            
            // Wyślij wszystko
            $modalAbandon.fadeOut(200);
            processBatch();
        });

        // "Pomiń go i idź do kasy"
        $('#tsme-modal-ignore-go').on('click', function(){
            $modalAbandon.fadeOut(200);
            processBatch(); // Wyślij tylko to co było w kolejce
        });


        // --- AUTO-FILL Z URL (Dla Booking Baru) ---
        function getUrlParameter(name) {
            var results = new RegExp('[\\?&]' + name + '=([^&#]*)').exec(window.location.href);
            return results ? decodeURIComponent(results[1].replace(/\+/g, ' ')) : null;
        }

        if ( getUrlParameter('tsme_prefill') ) {
            var preObj = getUrlParameter('tsme_object');
            var preDate = getUrlParameter('tsme_stay_from');

            if (preObj) {
                $('#tsme_object').val(preObj).trigger('change');
                // Fix dla nice-select
                if($.fn.niceSelect) $('#tsme_object').niceSelect('update');
            }
            
            if (preDate) {
                $('#tsme_stay_from').val(preDate);
                // Domyślnie ustawiamy wyjazd na następny dzień dla wygody
                // (Opcjonalne, ale pomocne UX)
                // var d = new Date(preDate); d.setDate(d.getDate() + 1); 
                // ...tu musiałaby być konwersja na YYYY-MM-DD, pomińmy dla prostoty
            }
            
            // Przeskroluj do formularza
            $('html, body').animate({ scrollTop: $("#tsme-app-root").offset().top - 100 }, 500);
            
            // Odpal walidację (żeby przyciski odżyły)
            setTimeout(function(){
                $('#tsme_object').trigger('change'); // Wymuś update logiki
            }, 500);
        }
        // START: Ładuj z cache
        loadQueueFromStorage();

    });
})(jQuery);