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
  $(document).on('click','#woo2nostr-verify',function(){
    var $b=$(this), $r=$('#woo2nostr-verify-result');
    $b.prop('disabled',true); $r.text('Querying relays…');
    $.post(woo2nostr.ajax,{action:'woo2nostr_verify',nonce:woo2nostr.nonce}).done(function(res){
      if(res.success){
        $r.html('Found <strong>'+res.data.count+'</strong> listing events (kind 30402/30403) on '+res.data.relay+'<br><code style="font-size:11px">'+res.data.d_tags.slice(0,10).map(escapeHtml).join('<br>')+'</code>');
      } else {
        $r.css('color','#d63638').text('Error: '+res.data);
      }
      $b.prop('disabled',false);
    }).fail(function(){ $r.text('Request failed'); $b.prop('disabled',false); });
  });
  function escapeHtml(s){ return String(s).replace(/[&<>"']/g, function(c){return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c];}); }

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

  var RELAYS = (woo2nostr.relays && woo2nostr.relays.length)? woo2nostr.relays : ['wss://relay.primal.net','wss://nos.lol','wss://relay.nostr.net','wss://auth.nostr1.com','wss://relay.damus.io'];

  function wsOkPending(relay, ev){
    return new Promise(function(resolve){
      var ws, timer, done=false;
      var finish=function(res){ if(done) return; done=true; clearTimeout(timer); try{ ws&&ws.close(); }catch(e){} resolve(res); };
      try{ ws = new WebSocket(relay); }
      catch(e){ return finish({relay:relay,ok:false,error:'websocket construct failed'}); }
      timer = setTimeout(function(){ finish({relay:relay,ok:false,error:'timeout 9s'}); },9000);
      ws.onopen = function(){ try{ ws.send(JSON.stringify(['EVENT',ev])); }catch(e){ finish({relay:relay,ok:false,error:'send failed'}); } };
      ws.onmessage = function(m){
        var j; try{ j=JSON.parse(m.data); }catch(e){ return; }
        if(j && j[0]==='OK' && j[1]===ev.id){ finish({relay:relay,ok:!!j[2],msg:j[3]||''}); }
      };
      ws.onerror = function(){ finish({relay:relay,ok:false,error:'connect failed'}); };
      ws.onclose = function(){ finish({relay:relay,ok:false,error:'closed before OK'}); };
    });
  }

  async function publishDirect(ev){
    var results = await Promise.all(RELAYS.map(function(r){ return wsOkPending(r,ev); }));
    return results;
  }

  function countOk(results){ return results.filter(function(r){ return r.ok; }).length; }

  async function recordNip07Publish(pid, signed){
    var res2 = await $.post(woo2nostr.ajax,{action:'woo2nostr_nip07_publish',nonce:woo2nostr.nonce,product_id:pid,signed:JSON.stringify(signed),record:1});
    if(!res2.success) throw new Error(res2.data && res2.data.error ? res2.data.error : JSON.stringify(res2.data));
    return res2;
  }

  async function publishProductNip07(pid){
    if(!hasNostr()) throw new Error(woo2nostr.i18n.noExtension);
    var r = await $.post(woo2nostr.ajax,{action:'woo2nostr_publish_single',nonce:woo2nostr.nonce,product_id:pid});
    if(!(r.success && r.data && r.data.need_sign)) throw new Error(typeof r.data==='string'? r.data : JSON.stringify(r.data));
    var total={ok:0,fail:0}, detail=[];
    for(var i=0;i<r.data.events.length;i++){
      var ev=r.data.events[i];
      var signed = await nip07Sign(ev);
      var results = await publishDirect(signed);
      var ok = countOk(results);
      detail.push(ok+'/'+results.length+' relays OK');
      if(ok>0){
        total.ok++;
        try{ await recordNip07Publish(pid, signed); }catch(e){ detail.push('meta:'+e.message); }
      } else {
        total.fail++;
        var bad = results.filter(function(x){return !x.ok;}).map(function(x){ return x.relay+'('+(x.msg||x.error||'?')+')'; }).join(', ');
        throw new Error('No relay accepted event '+i+' ['+bad+']');
      }
    }
    return {pid:pid, total:total, detail:detail};
  }

  $(document).on('click','#woo2nostr-publish',async function(){
    var $btn=$(this), pid=$btn.data('id'), orig=$btn.text();
    $btn.prop('disabled',true).text(woo2nostr.i18n.signing);
    setPublishStatus('Publishing…','#666');
    try{
      var mode=woo2nostr.mode||'server';
      if(mode!=='server'){
        var out = await publishProductNip07(pid);
        setPublishStatus('Published via NIP-07 ✓ ('+out.detail.join('; ')+')','green');
        setTimeout(function(){ location.reload(); },800);
        return;
      }
      var r = await $.post(woo2nostr.ajax,{action:'woo2nostr_publish_single',nonce:woo2nostr.nonce,product_id:pid});
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
      var ok=0, fail=0, fails=[];
      for(var idx=0; idx<ids.length; idx++){
        var pid=ids[idx];
        $prog.text('('+ (idx+1) +'/'+ids.length+') Publishing #'+pid+'…');
        try{
          var out=await publishProductNip07(pid);
          ok++; $log.prepend('✓ #'+pid+' ('+out.detail.join('; ')+')\n');
        }catch(e){
          fail++; fails.push(pid);
          var msg=(e.message||e);
          if(e.responseJSON && e.responseJSON.data) msg=JSON.stringify(e.responseJSON.data);
          $log.prepend('✗ #'+pid+' '+msg+'\n');
        }
        await new Promise(function(res){ setTimeout(res, 300); });
      }
      $prog.text('Done: '+ok+' ok, '+fail+' failed'+(fail? ' — '+fails.join(', '):'')).css('color', fail?'#d63638':'green');
      $btn.prop('disabled',false);
    }catch(e){
      $prog.text('Error: '+(e.message||e)).css('color','#d63638');
      $btn.prop('disabled',false);
    }
  });
});
