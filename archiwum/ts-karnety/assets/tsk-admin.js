(function($){
  // Verify screen
  const $code = $('#tsk-code'), $res = $('#tsk-result');

function show(msg, ok) {
  if (!$res.length) return;
  $res.removeClass('tsk-ok tsk-err')
      .addClass(ok ? 'tsk-ok' : 'tsk-err')
      .html(msg);
}

// Normalizacja kodu: usuwamy wszystko poza A-Z0-9, duże litery
// i jeśli ma 14 znaków – składamy w TS-XXXX-XXXX-XXXX
function normalizeCode(raw) {
    if (!raw) return '';
    let s = String(raw).replace(/[^A-Za-z0-9]/g, '').toUpperCase();

    // Format TSB-XX-XX → 3 + 2 + 2 = 7 znaków "czystych"
    if (s.length === 7 && s.startsWith('TSB')) {
        // T S B X X X X
        // 0 1 2 3 4 5 6
        const part1 = s.slice(3, 5); // po TSB
        const part2 = s.slice(5, 7);
        return `TSB-${part1}-${part2}`;
    }

    return s;
}


  function call(path, payload){
    return fetch(TSKF.rest+path, {method:'POST', headers:{'Content-Type':'application/json','X-WP-Nonce':TSKF.nonce}, body:JSON.stringify(payload)})
      .then(r=>r.json());
  }
  $('#tsk-check').on('click', function(){
  const raw  = $code.val() ? $code.val().trim() : '';
  const code = normalizeCode(raw);
  if (!code) return;

  $res.text('Sprawdzam...');

  call('/tickets/check', { code }).then(d => {

      if(!d || d.ok===false){ show('Błąd: '+(d && d.message || 'nieznany'), false); return; }
      const data = d.data||{}; let html = d.message || 'OK';
      if(typeof data.entries_left!=='undefined' && data.entries_total>0){ html += '<br><span class="tsk-mini">Pozostało: '+data.entries_left+' / '+data.entries_total+'</span>'; }
      show(html, true);
    }).catch(()=>show('Błąd połączenia',false));
  });
  $('#tsk-consume').on('click', function(){
  const raw  = $code.val() ? $code.val().trim() : '';
  const code = normalizeCode(raw);
  if (!code) return;

  $res.text('Zatwierdzam...');

  call('/tickets/consume', { code }).then(d => {

      if(!d || d.ok===false){ show('Błąd: '+(d && d.message || 'nieznany'), false); return; }
      const data = d.data||{}; let html = d.message || 'OK';
      if(typeof data.entries_left!=='undefined' && data.entries_total>0){ html += '<br><span class="tsk-mini">Pozostało: '+data.entries_left+' / '+data.entries_total+'</span>'; }
      show(html, true);
    }).catch(()=>show('Błąd połączenia',false));
  });

  // Codes list: inline actions
  $(document).on('click','.tsk-act', function(e){
    e.preventDefault();
    const $a=$(this), code=$a.data('code'), action=$a.data('action'), days=$a.data('days')||0;
    if(!code||!action) return;
    if(action==='set_expire'){
      const val=prompt('Podaj datę końca (YYYY-MM-DD HH:MM):',''); if(!val) return;
      call('/admin/update',{code,action,date:val}).then(()=>location.reload()); return;
    }
    if(action==='set_entries'){
      const val=prompt('Ustaw liczbę pozostałych wejść:',''); if(val===null) return;
      call('/admin/update',{code,action,entries:parseInt(val||0)}).then(()=>location.reload()); return;
    }
    call('/admin/update',{code,action,days:days}).then(()=>location.reload());
  });

  // Order box buttons
  $(document).on('click','#tsk-generate', function(){
    const order = $(this).data('order'), nonce=$(this).data('nonce');
    $.post(ajaxurl, {action:'tskf_generate_for_order', order_id:order, nonce:nonce}, function(resp){
      alert(resp && resp.data ? resp.data.msg : 'Gotowe'); location.reload();
    });
  });
  $(document).on('click','#tsk-resend', function(){
    const order = $(this).data('order'), nonce=$(this).data('nonce');
    $.post(ajaxurl, {action:'tskf_resend_codes', order_id:order, nonce:nonce}, function(resp){
      alert(resp && resp.data ? resp.data.msg : 'Wysłano'); location.reload();
    });
  });
})(jQuery);

// 0.4.4 Modal: Edytuj informacje
(function($){
  function ensureModal(){
    let $b = $('.tsk-modal-backdrop');
    if(!$b.length){
      $('body').append('<div class="tsk-modal-backdrop"><div class="tsk-modal"><div class="content"></div><div class="actions" style="text-align:right;margin-top:12px"><button class="button tsk-close">Anuluj</button> <button class="button button-primary tsk-save">Zapisz</button></div></div></div>');
      $b = $('.tsk-modal-backdrop');
    }
    return $b;
  }
  $(document).on('click','.tsk-close', function(){ $('.tsk-modal-backdrop').hide(); });

  $(document).on('click','.tsk-edit', function(e){
    e.preventDefault();
    const code = $(this).data('code'); const $b = ensureModal();
    fetch(TSKF.rest+'/admin/ticket/get', {
      method:'POST', headers:{'Content-Type':'application/json','X-WP-Nonce':TSKF.nonce}, body:JSON.stringify({code})
    }).then(r=>r.json()).then(d=>{
      if(!d || !d.ok){ alert('Błąd pobierania danych'); return; }
      const t=d.data||{};
      const html = '<h2>Edycja informacji</h2>'
        + '<div class="row"><label>Data zabiegu</label><input type="datetime-local" id="tsk-treat" value="'+(t.treatment_date_local||'')+'"></div>'
        + '<div class="row"><label>Odbiorca</label><input type="text" id="tsk-client" value="'+(t.treatment_client||'')+'"></div>';
      $b.find('.content').html(html);
      $b.css('display','flex');
      $('.tsk-save').off('click').on('click', function(){
        const treatment_date = $('#tsk-treat').val();
        const treatment_client = $('#tsk-client').val();
        fetch(TSKF.rest+'/admin/update', {
          method:'POST', headers:{'Content-Type':'application/json','X-WP-Nonce':TSKF.nonce},
          body:JSON.stringify({code, action:'edit_info', treatment_date, treatment_client})
        }).then(r=>r.json()).then(()=>location.reload());
      });
    });
  });
})(jQuery);
