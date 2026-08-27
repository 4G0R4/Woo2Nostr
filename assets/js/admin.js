jQuery(function($){
  function showPreview(pid, $out){
    $.post(woo2nostr.ajax,{action:'woo2nostr_preview',nonce:woo2nostr.nonce,product_id:pid},function(r){
      if(r.success){ $out.show().text(JSON.stringify(r.data,null,2)); } else { $out.show().text('Error: '+(r.data||'unknown')); }
    });
  }
  $('#woo2nostr-preview').on('click',function(){
    var pid=$(this).data('id'), $out=$('#woo2nostr-preview-out');
    showPreview(pid,$out);
  });
  function toggleRows(){
    var m=$('input[name="woo2nostr_key_mode"]:checked').val();
    $('.woo2nostr-row-server').toggle(m==='server');
    $('.woo2nostr-row-bunker').toggle(m==='bunker');
  }
  $(document).on('change','input[name="woo2nostr_key_mode"]',toggleRows); toggleRows();

  $('#woo2nostr-pull-profile').on('click',function(){
    var $r=$('#woo2nostr-pull-result').text('Pulling…');
    $.post(woo2nostr.ajax,{action:'woo2nostr_pull_profile',nonce:woo2nostr.nonce},function(r){
      if(r.success){ $r.text('OK — lud16: '+(r.data.lud16||'—')+' pref: '+r.data.pref); if(r.data.lud16) $('#woo2nostr_lud16').val(r.data.lud16); } else $r.text('Error: '+r.data);
    });
  });
  $('#woo2nostr-test-relay').on('click',function(){
    var $r=$('#woo2nostr-test-result').text('Checking…');
    $.post(woo2nostr.ajax,{action:'woo2nostr_test_relay',nonce:woo2nostr.nonce},function(r){ $r.text(r.success? r.data.count+' relays configured' : 'Error'); });
  });

  async function nip07Sign(event){
    if(!window.nostr||!window.nostr.signEvent) throw new Error(woo2nostr.i18n.noExtension);
    return await window.nostr.signEvent(event);
  }

  $('#woo2nostr-publish').on('click',async function(){
    var $btn=$(this), pid=$btn.data('id'), orig=$btn.text();
    $btn.prop('disabled',true).text(woo2nostr.i18n.signing);
    $.post(woo2nostr.ajax,{action:'woo2nostr_publish_single',nonce:woo2nostr.nonce,product_id:pid},async function(r){
      if(r.success && r.data && r.data.need_sign){
        try{
          for(const ev of r.data.events){
            const signed = await nip07Sign(ev);
            await $.post(woo2nostr.ajax,{action:'woo2nostr_nip07_publish',nonce:woo2nostr.nonce,product_id:pid,event:JSON.stringify(ev),signed:JSON.stringify(signed)});
          }
          alert('Published via NIP-07');
          location.reload();
        }catch(e){ alert('NIP-07 error: '+e.message); $btn.prop('disabled',false).text(orig); }
        return;
      }
      if(r.success){ alert('Published to relays'); location.reload(); }
      else { alert('Error: '+JSON.stringify(r.data)); $btn.prop('disabled',false).text(orig); }
    }).fail(function(){ $btn.prop('disabled',false).text(orig); });
  });
});
