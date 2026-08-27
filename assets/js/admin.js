jQuery(function($){
  if(typeof woo2nostr==='undefined'){ console.warn('woo2nostr not localized'); window.woo2nostr={ajax:'',nonce:'',mode:'server',i18n:{signing:'Signing…',noExtension:'Extension not found',connecting:'Connecting…',connected:'Connected',failed:'Failed',noPubkey:'No pubkey'}}; }
  function showPreview(pid,$out){
    $out.text('Loading…').show();
    $.post(woo2nostr.ajax,{action:'woo2nostr_preview',nonce:woo2nostr.nonce,product_id:pid}).done(function(r){
      $out.text(r.success? JSON.stringify(r.data,null,2): 'Error: '+(r.data||'unknown'));
    }).fail(function(xhr){ $out.text('Request failed: '+xhr.statusText); });
  }
  $(document).on('click','#woo2nostr-preview',function(){
    showPreview($(this).data('id'),$('#woo2nostr-preview-out'));
  });

  function toggleRows(){
    var m=$('input[name="woo2nostr_key_mode"]:checked').val();
    $('.woo2nostr-row-server').toggle(m==='server');
    $('.woo2nostr-row-bunker').toggle(m==='bunker');
    $('.woo2nostr-row-nip07').toggle(m==='nip07');
  }
  $(document).on('change','input[name="woo2nostr_key_mode"]',toggleRows); toggleRows();

  $('#woo2nostr-pull-profile').on('click',function(){
    var $r=$('#woo2nostr-pull-result').text('Pulling…');
    $.post(woo2nostr.ajax,{action:'woo2nostr_pull_profile',nonce:woo2nostr.nonce}).done(function(r){
      $r.text(r.success? 'OK — lud16: '+(r.data.lud16||'—')+' pref: '+r.data.pref : 'Error: '+r.data);
      if(r.success && r.data.lud16) $('#woo2nostr_lud16').val(r.data.lud16);
    }).fail(function(){ $r.text('Request failed'); });
  });
  $('#woo2nostr-test-relay').on('click',function(){
    var $r=$('#woo2nostr-test-result').text('Checking…');
    $.post(woo2nostr.ajax,{action:'woo2nostr_test_relay',nonce:woo2nostr.nonce}).done(function(r){
      $r.text(r.success? r.data.count+' relays configured' : 'Error');
    }).fail(function(){ $r.text('Failed'); });
  });

  function hasNostr(){
    return typeof window.nostr!=='undefined' && window.nostr;
  }

  async function connectNip07($btn,$resultSel,$pubkeySel){
    var $r=$($resultSel);
    if(!hasNostr() || typeof window.nostr.getPublicKey!=='function'){
      $r.css('color','#d63638').text(woo2nostr.i18n.noExtension);
      return;
    }
    $btn.prop('disabled',true); $r.css('color','').text(woo2nostr.i18n.connecting);
    try{
      var pubkey = await window.nostr.getPublicKey();
      if(!pubkey || !/^[0-9a-f]{64}$/i.test(pubkey)) throw new Error(woo2nostr.i18n.noPubkey);
      var res = await $.post(woo2nostr.ajax,{action:'woo2nostr_nip07_connect',nonce:woo2nostr.nonce,pubkey:pubkey});
      if(res.success){
        $r.css('color','green').text(woo2nostr.i18n.connected+': '+res.data.npub);
        if($pubkeySel) $($pubkeySel).show().find('code').text(res.data.npub+' ('+pubkey.slice(0,8)+'… )');
        $btn.text(woo2nostr.i18n.connected);
      } else {
        $r.css('color','#d63638').text('Error: '+res.data);
        $btn.prop('disabled',false);
      }
    }catch(e){
      $r.css('color','#d63638').text(woo2nostr.i18n.failed+': '+(e.message||e));
      $btn.prop('disabled',false);
    }
  }

  $(document).on('click','#woo2nostr-connect-nip07',function(){ connectNip07($(this),'#woo2nostr-connect-result','#woo2nostr-nip07-pubkey'); });
  $(document).on('click','#woo2nostr-connect-nip07-mb',function(){ connectNip07($(this),'#woo2nostr-connect-mb-result',null); });

  async function nip07Sign(event){
    if(!hasNostr() || typeof window.nostr.signEvent!=='function') throw new Error(woo2nostr.i18n.noExtension);
    return await window.nostr.signEvent(event);
  }

  function setPublishStatus(msg,color){
    $('#woo2nostr-publish-status').show().css('color',color||'').text(msg);
  }

  $(document).on('click','#woo2nostr-publish',async function(){
    var $btn=$(this), pid=$btn.data('id'), orig=$btn.text();
    $btn.prop('disabled',true).text(woo2nostr.i18n.signing);
    setPublishStatus('Publishing…','#666');
    try{
      var r = await $.post(woo2nostr.ajax,{action:'woo2nostr_publish_single',nonce:woo2nostr.nonce,product_id:pid});
      if(r.success && r.data && r.data.need_sign){
        if(!hasNostr()){
          throw new Error(woo2nostr.i18n.noExtension+' Click "Connect with Extension" first.');
        }
        for(var i=0;i<r.data.events.length;i++){
          var ev=r.data.events[i];
          var signed = await nip07Sign(ev);
          var res2 = await $.post(woo2nostr.ajax,{action:'woo2nostr_nip07_publish',nonce:woo2nostr.nonce,product_id:pid,signed:JSON.stringify(signed)});
          if(!res2.success) throw new Error(res2.data && res2.data.error ? res2.data.error : JSON.stringify(res2.data));
        }
        setPublishStatus('Published via NIP-07 ✓','green');
        setTimeout(function(){ location.reload(); },800);
        return;
      }
      if(r.success){
        setPublishStatus('Published to relays ✓','green');
        setTimeout(function(){ location.reload(); },800);
      } else {
        throw new Error(typeof r.data==='string'? r.data : JSON.stringify(r.data));
      }
    }catch(e){
      var msg = e.message||String(e);
      if(e.responseJSON && e.responseJSON.data) msg = typeof e.responseJSON.data==='string'? e.responseJSON.data : JSON.stringify(e.responseJSON.data);
      setPublishStatus('Error: '+msg,'#d63638');
      alert('Publish error: '+msg);
      $btn.prop('disabled',false).text(orig);
    }
  });

  $(document).on('click','#woo2nostr-bulk-nip07',async function(){
    var $btn=$(this), $prog=$('#woo2nostr-bulk-progress'), $log=$('#woo2nostr-bulk-log');
    if(!hasNostr()){ alert(woo2nostr.i18n.noExtension); return; }
    $btn.prop('disabled',true); $log.show().text(''); $prog.text('Fetching products…');
    try{
      var idsText=$('#woo2nostr-bulk-ids').val();
      var scope=$('input[name="scope"]:checked').val();
      var ids=[];
      if(scope==='selected' && idsText.trim()){
        ids=idsText.split(',').map(function(s){return parseInt(s.trim(),10);}).filter(Boolean);
      } else {
        var r=$.post(woo2nostr.ajax,{action:'woo2nostr_preview_bulk',nonce:woo2nostr.nonce});
        var res=await r;
        if(!res.success) throw new Error(res.data||'fetch failed');
        ids=res.data.ids;
      }
      if(!ids.length) throw new Error('No products found');
      $prog.text('Publishing '+ids.length+' products via extension… keep tab open');
      var ok=0, fail=0;
      for(var idx=0; idx<ids.length; idx++){
        var pid=ids[idx];
        $prog.text('('+ (idx+1) +'/'+ids.length+') Publishing #'+pid+'…');
        try{
          var rp=await $.post(woo2nostr.ajax,{action:'woo2nostr_publish_single',nonce:woo2nostr.nonce,product_id:pid});
          if(rp.success && rp.data && rp.data.need_sign){
            for(var j=0;j<rp.data.events.length;j++){
              var signed=await nip07Sign(rp.data.events[j]);
              var res2=await $.post(woo2nostr.ajax,{action:'woo2nostr_nip07_publish',nonce:woo2nostr.nonce,product_id:pid,signed:JSON.stringify(signed)});
              if(!res2.success) throw new Error(JSON.stringify(res2.data));
            }
            ok++; $log.prepend('✓ #'+pid+'\n');
          } else if(rp.success){ ok++; $log.prepend('✓ #'+pid+' (server)\n'); }
          else { fail++; $log.prepend('✗ #'+pid+' '+JSON.stringify(rp.data)+'\n'); }
        }catch(e){ fail++; $log.prepend('✗ #'+pid+' '+(e.message||e)+'\n'); }
        await new Promise(function(res){ setTimeout(res, 300); });
      }
      $prog.text('Done: '+ok+' ok, '+fail+' failed').css('color', fail?'#d63638':'green');
      $btn.prop('disabled',false);
    }catch(e){
      $prog.text('Error: '+(e.message||e)).css('color','#d63638');
      $btn.prop('disabled',false);
    }
  });
});
