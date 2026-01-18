(function($, window, document, undefined){
  'use strict';

  function rest(path, payload){
    return fetch(TSB.rest+path, {
      method:'POST',
      headers:{
        'Content-Type':'application/json',
        'X-WP-Nonce':TSB.nonce
      },
      body:JSON.stringify(payload||{})
    }).then(function(r){ return r.json(); });
  }

  function showResult($box, msg, ok){
    $box.removeClass('ok err').addClass(ok ? 'ok' : 'err').html(msg);
  }

  // ===============================
  //   MODAL: USTAW KONIEC WAŻNOŚCI
  // ===============================
  function ensureExpireModal(){
    var $b = $('.tsb-expire-backdrop');
    if (!$b.length){
      var html =
        '<div class="tsb-expire-backdrop" style="position:fixed;inset:0;background:rgba(0,0,0,.35);display:none;align-items:center;justify-content:center;z-index:100001">'+
        '  <div class="tsb-modal" style="background:#fff;padding:16px 18px;width:min(420px,90vw);border-radius:12px;box-shadow:0 10px 30px rgba(0,0,0,.15)">'+
        '    <h2 style="margin-top:0;margin-bottom:10px">Ustaw koniec ważności</h2>'+
        '    <p style="margin:0 0 8px;font-size:13px;color:#4b5563">Wybierz datę i godzinę zakończenia ważności karnetu.</p>'+
        '    <input type="datetime-local" id="tsb-expire-input" style="width:100%;margin-bottom:12px" />'+
        '    <div style="text-align:right">'+
        '      <button type="button" class="button tsb-expire-cancel">Anuluj</button> '+
        '      <button type="button" class="button button-primary tsb-expire-save">Zapisz</button>'+
        '    </div>'+
        '  </div>'+
        '</div>';
      $('body').append(html);
      $b = $('.tsb-expire-backdrop');
    }
    return $b;
  }

  function openExpireModal(code){
    var $b = ensureExpireModal();
    $b.data('code', code || '');
    $('#tsb-expire-input').val('');
    $b.css('display','flex');
  }

  // Obsługa przycisków w modalu "Ustaw koniec"
  $(document).on('click', '.tsb-expire-cancel', function(e){
    e.preventDefault();
    $('.tsb-expire-backdrop').hide();
  });

  $(document).on('click', '.tsb-expire-save', function(e){
    e.preventDefault();
    var $b   = $('.tsb-expire-backdrop');
    var code = $b.data('code') || '';
    var val  = $('#tsb-expire-input').val();

    if (!code){
      alert('Brak kodu.');
      return;
    }
    if (!val){
      alert('Wybierz datę i godzinę.');
      return;
    }

    // datetime-local zwraca "YYYY-MM-DDTHH:MM"
    var formatted = val.replace('T',' ') + ':00';

    rest('/admin/update', {
      code:   code,
      action: 'set_expire',
      date:   formatted
    }).then(function(){
      $('.tsb-expire-backdrop').hide();
      if (typeof loadCodes === 'function'){
        loadCodes();
      } else {
        $('#tsb-reload-codes').trigger('click');
      }
    }).catch(function(){
      alert('Błąd zapisu daty końca.');
    });
  });


  // ===============================
  //          TABS
  // ===============================
  $(document).on('click', '.tsb-tab', function(){
    var pane = $(this).data('pane');
    $('.tsb-tab').removeClass('is-active');
    $(this).addClass('is-active');
    $('.tsb-pane').removeClass('is-active');
    $('#tsb-pane-'+pane).addClass('is-active');

    // Po zmianie zakładki automatycznie odświeżamy odpowiedni widok
    if (pane === 'overview'){
      if (typeof loadStatsSafe === 'function') {
        loadStatsSafe();
      }
    } else if (pane === 'codes'){
      if (typeof loadCodes === 'function') {
        loadCodes();
      }
    }
  });

  // ===============================
  //   WERYFIKACJA KODU + OKNA CZASOWE
  // ===============================

  var $code = $('#tsb-code'),
      $res  = $('#tsb-result');

  function normalizeCode(raw) {
  if (!raw) return '';

  // wyrzucamy spacje, myślniki itd.
  var cleaned = String(raw).replace(/[^A-Za-z0-9]/g, '').toUpperCase();

  // NOWY FORMAT: TSB-XX-XX
  // po wyczyszczeniu ma 7 znaków: "TSB" + 2 + 2
  if (cleaned.length === 7 && cleaned.indexOf('TSB') === 0) {
    // TSBABCD -> TSB-AB-CD
    return cleaned.replace(/^TSB(.{2})(.{2})$/, 'TSB-$1-$2');
  }

  // STARY FORMAT: TS-XXXX-XXXX-XXXX
  // po wyczyszczeniu ma 14 znaków: 2 + 4+4+4
  if (cleaned.length === 14 && cleaned.indexOf('TS') === 0) {
    return cleaned.replace(/^(.{2})(.{4})(.{4})(.{4})$/, '$1-$2-$3-$4');
  }

  // fallback – po prostu górne litery, bez czyszczenia
  return String(raw).trim().toUpperCase();
}


  $('#tsb-check').on('click', function(){
    var raw  = ($code.val() || '').trim();
    var code = normalizeCode(raw);
    if (!code) return;

    showResult($res, 'Sprawdzam…', true);

    rest('/tickets/check', { code: code }).then(function(d){

      if (!d || d.ok === false){
        showResult($res, 'Błąd: ' + (d && d.message || 'nieznany'), false);
        return;
      }

      var data     = d.data || {};
      var baseHtml = d.message || 'OK';

      if (typeof data.entries_left !== 'undefined' && data.entries_total > 0){
        baseHtml += '<br><small>Pozostało: ' + data.entries_left + ' / ' + data.entries_total + '</small>';
      }

      // Dodatkowo: sprawdzenie okna godzinowego
      $.post(TSB.ajax, {
        action: 'tsb_time_window',
        code: code
      }).done(function(resp){
        if (resp && resp.success && resp.data && resp.data.window){
          var win = resp.data.window;
          var now = new Date();
          var h   = now.getHours();

          if (h < win.from || h >= win.to){
            var msg = 'Karnet jednorazowy aktywny, ale ważny w godzinach ' + win.label + '.';
            showResult($res, msg, false);
            return;
          }
        }
        // brak okna lub godzina OK
        showResult($res, baseHtml, true);
      }).fail(function(){
        // jak nie uda się pobrać okna – nie blokujemy sprawdzania
        showResult($res, baseHtml, true);
      });

    }).catch(function(){
      showResult($res, 'Błąd połączenia', false);
    });
  });

  $('#tsb-use').on('click', function(){
    var raw  = ($code.val() || '').trim();
    var code = normalizeCode(raw);
    if (!code) return;

    showResult($res, 'Zużywam…', true);

    // Najpierw sprawdzamy okno godzinowe – żeby nie zużyć biletu poza oknem
    $.post(TSB.ajax, {
      action: 'tsb_time_window',
      code: code
    }).done(function(resp){
      if (resp && resp.success && resp.data && resp.data.window){
        var win = resp.data.window;
        var now = new Date();
        var h   = now.getHours();

        if (h < win.from || h >= win.to){
          var msg = 'Tego biletu można użyć tylko w godzinach ' + win.label + '.';
          showResult($res, msg, false);
          return; // NIE wywołujemy consume
        }
      }

      // jeśli nie ma okna albo godzina jest OK – dopiero wtedy consume
      rest('/tickets/consume', {code: code}).then(function(d){
        if (!d || d.ok === false){
          showResult($res, 'Błąd: ' + (d && d.message || 'nieznany'), false);
          return;
        }

        var data = d.data || {};
        var html = d.message || 'OK';

        if (typeof data.entries_left !== 'undefined' && data.entries_total > 0){
          html += '<br><small>Pozostało: ' + data.entries_left + ' / ' + data.entries_total + '</small>';
        }

        showResult($res, html, true);

        // po poprawnym zużyciu odświeżamy przegląd i listę kodów
        if (typeof loadStatsSafe === 'function') {
          loadStatsSafe();
        }
        if (typeof loadCodes === 'function') {
          loadCodes();
        }

      }).catch(function(){
        showResult($res, 'Błąd połączenia', false);
      });

    }).fail(function(){
      // Jeśli coś nie styknie po stronie okna godzinowego, dla bezpieczeństwa NIE zużywamy biletu
      showResult($res, 'Nie udało się zweryfikować godzin ważności karnetu.', false);
    });
  });

  // ===============================
  //          STATYSTYKI
  // ===============================
  function renderStats(data){
    var u = data.used_today || {count:0,last:[]};
    var html = '<div class="n" style="font-weight:700;font-size:22px">'+(u.count||0)+'</div>';
    if (u.last && u.last.length){
      html += '<div class="tsb-mini-list">';
      u.last.forEach(function(i){
        html += '<div class="item"><span><code>'+i.code+'</code> — '+(i.product||'')+'</span><span>'+i.ts+'</span></div>';
      });
      html += '</div>';
    }
    $('#tsb-used-today').html(html);

    var p = data.period || {active:0,ending_soon:0};
    $('#tsb-period').html(
      '<div class="item"><span>Aktywne</span><strong>'+p.active+'</strong></div>'+
      '<div class="item"><span>Kończą się ≤3 dni</span><strong>'+p.ending_soon+'</strong></div>'
    );

    $('#tsb-pending').html(
      '<div class="n" style="font-weight:700;font-size:22px">'+(data.pending_send||0)+'</div>'+
      '<div class="hint">zamówień do wysyłki kodów</div>'
    );

    var s = data.sales || {total:0,by_cat:{}};
    var sh = '<div class="n" style="font-weight:700;font-size:22px">'+Number(s.total||0).toFixed(2)+' zł</div>';
    var entries = Object.entries(s.by_cat||{}).sort(function(a,b){return b[1]-a[1]}).slice(0,5);
    if (entries.length){
      sh += '<div class="tsb-mini-list">';
      entries.forEach(function(e){
        sh += '<div class="item"><span>'+e[0]+'</span><strong>'+Number(e[1]).toFixed(2)+' zł</strong></div>';
      });
      sh += '</div>';
    }
    $('#tsb-sales').html(sh);

    var tc = data.top_customers || [];
    var t  = '<table class="tsb-table"><thead><tr><th>E-mail</th><th>Ilość</th><th>Wartość</th></tr></thead><tbody>';
    if (tc.length){
      tc.forEach(function(c){
        t += '<tr><td>'+c.email+'</td><td>'+c.qty+'</td><td>'+Number(c.total).toFixed(2)+' zł</td></tr>';
      });
    } else {
      t += '<tr><td colspan="3"><em>Brak danych</em></td></tr>';
    }
    t += '</tbody></table>';
    $('#tsb-top').html(t);

    var ex = data.expiring || [];
    var exhtml = '';
    if (ex.length){
      ex.forEach(function(e){
        exhtml += '<div class="item"><span><code>'+e.code+'</code> — '+(e.product||'')+'</span><span>'+e.hours_left+'h</span></div>';
      });
    } else {
      exhtml = '<em>Brak pozycji</em>';
    }
    $('#tsb-expiring').html(exhtml);
  }

  function loadStatsSafe(){
    // 1) Wyraźny indicator – wszystkie boxy pokazują "Odświeżanie…"
    $('.tsb-mini').each(function(){
      $(this).html('<div class="tsb-loading">Odświeżanie…</div>');
    });

    var done = false;
    var to = setTimeout(function(){
      if (!done){
        $('.tsb-mini').each(function(){
          if ($(this).find('.tsb-loading').length){
            $(this).html('<em>Błąd ładowania</em>');
          }
        });
      }
    }, 10000);

    $.post(TSB.ajax, {action:'tsb_stats'}).done(function(resp){
      done = true; clearTimeout(to);
      if (!resp || !resp.success){
        $('.tsb-mini').html('<em>Błąd</em>');
        return;
      }
      renderStats(resp.data || {});
    }).fail(function(){
      done = true;
      clearTimeout(to);
      $('.tsb-mini').html('<em>Błąd połączenia</em>');
    });
  }


  $(loadStatsSafe);

  // Ręczne odświeżenie sekcji "Przegląd"
  $(document).on('click', '#tsb-refresh-overview', function(e){
    e.preventDefault();
    loadStatsSafe();
  });

  // Ręczne odświeżenie formularza ręcznego generowania
  $(document).on('click', '#tsb-refresh-manual', function(e){
    e.preventDefault();
    $('#tsb-m-company').val(''); // [MODIFIED]
    $('#tsb-m-nip').val('');     // [MODIFIED]
    $('#tsb-m-email').val('');
    $('#tsb-m-product').val('');
    $('#tsb-m-qty').val('1');
  });

  // ===============================
  //   RĘCZNE GENEROWANIE – "UTWÓRZ"
  // ===============================
  $(document).on('click', '#tsb-m-create', function(e){
    e.preventDefault();

    // [MODIFIED] Pobranie danych firmy
    var company    = ($('#tsb-m-company').val() || '').trim();
    var nip        = ($('#tsb-m-nip').val() || '').trim();
    var email      = ($('#tsb-m-email').val() || '').trim();
    var product_id = $('#tsb-m-product').val() || '';
    var qty        = parseInt($('#tsb-m-qty').val(), 10) || 1;
    var send       = $('#tsb-m-send').is(':checked');

    var $box = $('#tsb-m-result');
    $box.removeClass('ok err').empty();

    // Prosta walidacja
    if (!product_id){
      $box.addClass('err').text('Wybierz produkt.');
      return;
    }
    
    // [MODIFIED] Walidacja wymaganych pól
    if (!company || !nip){
      $box.addClass('err').text('Podaj nazwę firmy i NIP.');
      return;
    }

    if (send && !email){
      $box.addClass('err').text('Podaj e-mail, jeśli chcesz wysłać kody.');
      return;
    }

    // Info dla kasjera, że coś się dzieje
    $box.removeClass('err').addClass('ok').text('Tworzę zamówienie…');

    $.post(TSB.ajax, {
      action:     'tsb_manual_create',
      email:      email,
      company:    company, // [MODIFIED] wysyłka
      nip:        nip,     // [MODIFIED] wysyłka
      product_id: product_id,
      qty:        qty,
      send:       send ? 1 : 0
    }).done(function(resp){
      if (!resp || !resp.success){
        var msg = (resp && resp.data && resp.data.msg)
          ? resp.data.msg
          : 'Błąd podczas tworzenia zamówienia.';
        $box.removeClass('ok').addClass('err').text(msg);
        return;
      }

      var msgOk = (resp.data && resp.data.msg)
        ? resp.data.msg
        : 'Zamówienie utworzone.';
      $box.removeClass('err').addClass('ok').text(msgOk);
    }).fail(function(){
      $box.removeClass('ok').addClass('err').text('Błąd połączenia z serwerem.');
    });
  });





    // ===============================
  //          CODES TAB
  // ===============================
  function loadCodes(page){
    var p = page || 1;
    var q = $('#tsb-find').val() || '';

    // Wyraźny indicator na liście kodów
    $('#tsb-codes').html('<div class="tsb-loading">Odświeżanie…</div>');

    $.post(TSB.ajax, {
      action: 'tsb_recent_codes',
      q:      q,
      page:   p
    }, function(resp){
      if (resp && resp.success && resp.data && resp.data.html){
        $('#tsb-codes').html(resp.data.html);
      } else {
        $('#tsb-codes').html('<em>Brak danych</em>');
      }
    });
  }

  // Pierwsze załadowanie listy kodów (strona 1)
  $(function(){
    loadCodes(1);
  });

  // Ręczne odświeżenie – wracamy na stronę 1
  $('#tsb-reload-codes').on('click', function(e){
    e.preventDefault();
    loadCodes(1);
  });

  // Enter w polu szukania – też strona 1
  $('#tsb-find').on('keydown', function(e){
    if (e.key === 'Enter'){
      e.preventDefault();
      loadCodes(1);
    }
  });

  // Paginacja – kliknięcie przycisku strony
  $(document).on('click', '.tsb-page-link', function(e){
    e.preventDefault();
    var p = parseInt($(this).data('page'), 10) || 1;
    loadCodes(p);
  });



  // ===============================
  //   OSTATNIE 10 KODÓW NA PRZEGLĄDZIE
  // ===============================
  (function(){
    function loadRecent10(){
      $.post(TSB.ajax, {action:'tsb_recent_codes', q:''}, function(resp){
        if (resp && resp.success && resp.data && resp.data.html){
          var $wrap = $('<div>'+resp.data.html+'</div>');
          var $rows = $wrap.find('tbody tr');
          if ($rows.length > 10){
            $rows.slice(10).remove();
          }
          $('#tsb-recent10').html($wrap.html());
        } else {
          $('#tsb-recent10').html('<em>Brak danych</em>');
        }
      });
    }
    $(loadRecent10);
  })();

  // ===============================
  //   DROPDOWN "AKCJE" W TABELI
  // ===============================
  $(document).on('click', '.tsb-dropdown > .button', function(e){
    e.preventDefault();
    $(this).closest('.tsb-dropdown').toggleClass('open');
  });

  $(document).on('click', function(e){
    if (!$(e.target).closest('.tsb-dropdown').length){
      $('.tsb-dropdown').removeClass('open');
    }
  });

  // ===============================
  //   AKCJE ADMINA (block/unblock/extend/set)
  // ===============================
  $(document).on('click', '.tsb-act', function(e){
    e.preventDefault();
    var $a    = $(this),
        code  = $a.data('code'),
        action= $a.data('action'),
        days  = $a.data('days') || 0;

    if (action === 'extend_time'){
      var hours = parseInt($a.data('hours') || 1, 10);
      rest('/admin/update', {code:code, action:action, hours:hours}).then(loadCodes);
      return;
    }

    if (action === 'set_expire'){
      openExpireModal(code);
      return;
    }


    if (action === 'set_entries'){
      var val2 = prompt('Ustaw liczbę pozostałych wejść:','');
      if (val2 === null) return;
      rest('/admin/update', {code:code, action:action, entries:parseInt(val2||0,10)}).then(loadCodes);
      return;
    }

    rest('/admin/update', {code:code, action:action, days:days}).then(loadCodes);
  });

})(jQuery, window, document);


/* =====================================================
   PRZEJŚCIE DO ZAMÓWIEŃ
===================================================== */
(function($){
  $(document).on('click', '.tsb-tab[data-external="orders"],#tsb-go-orders', function(e){
    e.preventDefault();
    var url = (TSB && TSB.orders) ? TSB.orders : 'edit.php?post_type=shop_order';
    window.location.href = url;
  });
})(jQuery);


/* ====================================================
   MODAL EDYCJI INFORMACJI – .tsb-edit / .tsk-edit
===================================================== */
(function($){
  'use strict';

  function ensureModal(){
    var $b = $('.tsb-modal-backdrop');
    if (!$b.length){
      var html =
        '<div class="tsb-modal-backdrop" style="position:fixed;inset:0;background:rgba(0,0,0,.35);display:none;align-items:center;justify-content:center;z-index:100000">'+
        '  <div class="tsb-modal" style="background:#fff;padding:16px 18px;width:min(520px,90vw);border-radius:12px;box-shadow:0 10px 30px rgba(0,0,0,.15)">'+
        '    <div class="content"></div>'+
        '    <div class="actions" style="text-align:right;margin-top:12px">'+
        '      <button class="button tsb-close">Anuluj</button> '+
        '      <button class="button button-primary tsb-save">Zapisz</button>'+
        '    </div>'+
        '  </div>'+
        '</div>';
      $('body').append(html);
      $b = $('.tsb-modal-backdrop');

      $(document).on('click', '.tsb-close', function(){
        $('.tsb-modal-backdrop').hide();
      });
    }
    return $b;
  }

  function openEditModal(code){
    if (!code){
      alert('Brak kodu');
      return;
    }

    var $b = ensureModal();
    $b.find('.content').html('<h2 style="margin:0 0 10px">Edycja informacji</h2><div>Ładowanie…</div>');
    $b.css('display','flex');

    fetch(TSB.rest + '/admin/ticket/get', {
      method: 'POST',
      headers: {
        'Content-Type':'application/json',
        'X-WP-Nonce': TSB.nonce
      },
      body: JSON.stringify({code:code})
    })
    .then(function(r){ return r.json(); })
    .then(function(d){
      if (!d || !d.ok){
        throw new Error('Błąd pobierania danych');
      }

      var t = d.data || {};
      var html =
        '<div class="row" style="margin:8px 0">'+
        '  <label style="display:block;margin:0 0 6px">Data zabiegu</label>'+
        '  <input type="datetime-local" id="tsb-treat" value="'+(t.treatment_date_local||'')+'" style="width:100%">'+
        '</div>'+
        '<div class="row" style="margin:8px 0">'+
        '  <label style="display:block;margin:0 0 6px">Odbiorca</label>'+
        '  <input type="text" id="tsb-client" value="'+(t.treatment_client||'')+'" style="width:100%">'+
        '</div>';

      if (t.phone){
        html += '<div class="row" style="margin:8px 0"><small>Telefon z zamówienia: <strong>'+t.phone+'</strong></small></div>';
      }

      $b.find('.content').html('<h2 style="margin:0 0 10px">Edycja informacji</h2>'+html);

      $('.tsb-save').off('click').on('click', function(){
        var treatment_date   = $('#tsb-treat').val();
        var treatment_client = $('#tsb-client').val();

        fetch(TSB.rest + '/admin/update', {
          method: 'POST',
          headers: {
            'Content-Type':'application/json',
            'X-WP-Nonce': TSB.nonce
          },
          body: JSON.stringify({
            code:code,
            action:'edit_info',
            treatment_date:treatment_date,
            treatment_client:treatment_client
          })
        })
        .then(function(r){ return r.json(); })
        .then(function(){
          $('.tsb-modal-backdrop').hide();
          $('#tsb-reload-codes').trigger('click');
        })
        .catch(function(){
          alert('Błąd zapisu');
        });
      });

    })
    .catch(function(){
      $b.find('.content').html('<h2>Edycja informacji</h2><div style="color:#b91c1c">Nie udało się pobrać danych.</div>');
    });
  }

  $(document).on('click', '.tsb-edit, .tsk-edit', function(e){
    e.preventDefault();

    var $el  = $(this),
        code = $el.data('code');

    if (!code){
      var $tr = $el.closest('tr');
      var txt = $tr.find('code').first().text();
      code = (txt || '').trim();
    }

    openEditModal(code);
  });

})(jQuery);
/* ====================================================
   MODAL HISTORII KODU – .tsb-history
===================================================== */
(function($){
  'use strict';

  function ensureHistoryModal(){
    var $b = $('.tsb-history-backdrop');
    if (!$b.length){
      var html =
        '<div class="tsb-history-backdrop" style="position:fixed;inset:0;background:rgba(0,0,0,.35);display:none;align-items:center;justify-content:center;z-index:100002">'+
        '  <div class="tsb-modal" style="background:#fff;padding:16px 18px;width:min(620px,95vw);max-height:80vh;overflow:auto;border-radius:12px;box-shadow:0 10px 30px rgba(0,0,0,.15)">'+
        '    <div class="content"></div>'+
        '    <div class="actions" style="text-align:right;margin-top:12px">'+
        '      <button class="button tsb-history-close">Zamknij</button>'+
        '    </div>'+
        '  </div>'+
        '</div>';
      $('body').append(html);
      $b = $('.tsb-history-backdrop');

      $(document).on('click', '.tsb-history-close', function(){
        $('.tsb-history-backdrop').hide();
      });
    }
    return $b;
  }

  function openHistoryModal(code){
    if (!code){
      alert('Brak kodu');
      return;
    }

    var $b = ensureHistoryModal();
    $b.find('.content').html('<h2 style="margin:0 0 10px">Historia kodu</h2><div>Ładowanie…</div>');
    $b.css('display','flex');

    fetch(TSB.rest + '/admin/ticket/history', {
      method: 'POST',
      headers: {
        'Content-Type':'application/json',
        'X-WP-Nonce': TSB.nonce
      },
      body: JSON.stringify({code:code})
    })
    .then(function(r){ return r.json(); })
    .then(function(d){
      if (!d || !d.ok){
        throw new Error(d && d.message ? d.message : 'Błąd pobierania historii');
      }

      var rows = d.history || [];
      var html = '<h2 style="margin:0 0 10px">Historia kodu: <code>'+code+'</code></h2>';

      if (!rows.length){
        html += '<p><em>Brak zarejestrowanych zdarzeń.</em></p>';
      } else {
        html += '<table class="tsb-table"><thead><tr>'+
                '<th>Data</th><th>Zdarzenie</th><th>Użytkownik</th><th>Szczegóły</th>'+
                '</tr></thead><tbody>';

        rows.forEach(function(row){
          var meta = row.meta || {};
          if (typeof meta === 'string'){
            try { meta = JSON.parse(meta); } catch(e){ meta = {}; }
          }

          var details = [];
          if (meta.note){ details.push(meta.note); }
          if (typeof meta.entries_left !== 'undefined'){
            details.push('Wejścia: '+meta.entries_left);
          }
          if (meta.expires_at){ details.push('Koniec: '+meta.expires_at); }
          if (meta.days){ details.push('Przedłużono o: '+meta.days+' dni'); }
          if (meta.treatment_date){ details.push('Data zabiegu: '+meta.treatment_date); }
          if (meta.treatment_client){ details.push('Odbiorca: '+meta.treatment_client); }

          var detailsStr = details.length ? details.join(' | ') : '';

          var userText = row.user_id ? ('#'+row.user_id) : '—';

          html += '<tr>'+
                  '<td>'+ (row.created_at || '') +'</td>'+
                  '<td>'+ (row.event || '') +'</td>'+
                  '<td>'+ userText +'</td>'+
                  '<td>'+ (detailsStr || '') +'</td>'+
                  '</tr>';
        });

        html += '</tbody></table>';
      }

      $b.find('.content').html(html);
    })
    .catch(function(err){
      $b.find('.content').html(
        '<h2 style="margin:0 0 10px">Historia kodu</h2>'+
        '<div style="color:#b91c1c">Nie udało się pobrać historii: '+(err && err.message ? err.message : '')+'</div>'
      );
    });
  }

  $(document).on('click', '.tsb-history', function(e){
    e.preventDefault();
    var $el  = $(this);
    var code = $el.data('code');

    if (!code){
      var $tr = $el.closest('tr');
      var txt = $tr.find('code').first().text();
      code = (txt || '').trim();
    }

    openHistoryModal(code);
  });

})(jQuery);
