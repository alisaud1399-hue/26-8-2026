<?php if (!defined('ROOMLOCK_UI')): define('ROOMLOCK_UI', 1);
$rl_manual    = get_setting('inv_manual_picker','0') === '1';
$rl_qr_req    = get_setting('inv_qr_required_for_lock','1') === '1';
$rl_audio     = get_setting('inv_audio_cue','1') === '1';
$rl_vibrate   = get_setting('inv_vibration','1') === '1';
$rl_max_susp  = (int)get_setting('inv_max_suspend_count','3');
?>
<style>
.rl-exit-opt{width:100%;text-align:start;padding:14px;border-radius:14px;border:1.5px solid var(--line);background:#fff;cursor:pointer;margin-bottom:10px;font-family:'Tajawal';transition:.2s}
.rl-exit-opt:hover{transform:translateY(-1px);box-shadow:0 4px 10px rgba(0,0,0,.06)}
.rl-exit-opt .t{font-weight:900;font-size:14px}
.rl-exit-opt .s{font-size:11.5px;color:var(--muted);margin-top:3px}
.rl-lockbar{background:linear-gradient(135deg,#065f46,#10b981);color:#fff;border-radius:12px;padding:8px 12px;font-size:12px;font-weight:800;margin-bottom:10px;display:flex;gap:8px;align-items:center}
.rl-lockbar i{font-size:14px}
.rl-lockbar{flex-wrap:wrap;justify-content:space-between;align-items:center}
.rlb-main{flex:1;min-width:120px}
.rlb-main b{font-size:13px}
.rlb-sub{font-size:10.5px;opacity:.92;font-weight:600;margin-top:2px}
.rlb-o{background:rgba(255,255,255,.18);padding:1px 8px;border-radius:99px}
.rlb-time{display:flex;flex-direction:column;align-items:center;font-family:'Inter',monospace;font-size:11px;line-height:1.5}
#rlElapsed{background:rgba(0,0,0,.25);padding:1px 8px;border-radius:6px;font-weight:800}
.rlb-btn{background:rgba(255,255,255,.2);border:none;color:#fff;border-radius:8px;padding:6px 10px;font-size:14px;cursor:pointer}
</style>

<!-- نافذة التأكيد قبل دخول الغرفة -->
<div class="modal" id="rlEnterModal" onclick="if(event.target===this)this.classList.remove('show')" style="align-items:center;">
<div class="sheet" style="max-width:420px;text-align:center;margin:auto;">
<div id="rlEnterIcon" style="width:64px;height:64px;border-radius:50%;margin:0 auto 14px;display:flex;align-items:center;justify-content:center;font-size:28px;"></div>
<h4 id="rlEnterTitle" style="margin:0 0 6px;color:var(--navy);font-weight:900;font-size:17px;"></h4>
<p id="rlEnterRoom" style="margin:0 0 4px;font-size:13px;color:#475569;font-weight:700;"></p>
<p id="rlEnterLoc" style="margin:0 0 10px;font-size:11.5px;color:#94a3b8;font-weight:600;"></p>
<div id="rlEnterMeta" style="background:#f8fafc;border-radius:10px;padding:10px 14px;margin-bottom:14px;font-size:11.5px;color:#475569;font-weight:700;text-align:right;"></div>
<div id="rlEnterPrev" style="background:#fef3c7;border:1px solid #fde68a;border-radius:10px;padding:10px 14px;margin-bottom:14px;font-size:12px;color:#92400e;font-weight:700;display:none;text-align:right;"></div>
<div style="display:flex;gap:10px;">
<button id="rlEnterBtn" class="btn btn-g" style="flex:2;font-weight:900;" onclick="rlConfirmEnter()"><i class="fa-solid fa-right-to-bracket"></i> <span id="rlEnterBtnTxt"></span></button>
<button class="btn btn-o" style="flex:1;" onclick="rlCancelEnter()"><?= $is_ar?'تراجع':'Cancel' ?></button>
</div>
<div id="rlExtraBtns" style="display:none;margin-top:10px;"></div>
</div>
</div>

<!-- ورقة الخروج من الغرفة -->
<div class="modal" id="rlExitModal" onclick="if(event.target===this)this.classList.remove('show')">
<div class="sheet">
<h4 style="margin:0 0 14px;color:var(--navy);font-weight:900"><i class="fa-solid fa-door-open"></i> <?= $is_ar?'مغادرة الغرفة':'Leaving Room' ?></h4>
<button class="rl-exit-opt" style="border-color:#bbf7d0" onclick="rlComplete()">
  <div class="t" style="color:#15803d"><i class="fa-solid fa-lock"></i> <?= $is_ar?'إقفال نهائي وإنهاء الغرفة':'Finalize & Lock Room' ?></div>
  <div class="s"><?= $is_ar?'أُقرّ بأنني جردت كافة الموجودات فعلياً — تُقفل الغرفة على الجميع':'I confirm I audited everything present — room locks for all' ?></div>
</button>
<button class="rl-exit-opt" onclick="rlSuspend()">
  <div class="t" style="color:#b45309"><i class="fa-solid fa-pause"></i> <?= $is_ar?'استكمال فيما بعد':'Resume Later' ?></div>
  <div class="s"><?= $is_ar?'تبقى الغرفة مفتوحة باسمك، ويمكنك أو يمكن لغيرك (بموافقتك) المتابعة':'Room stays open under your name' ?></div>
</button>
<button class="rl-exit-opt" onclick="document.getElementById('rlExitModal').classList.remove('show')">
  <div class="t" style="color:#64748b"><i class="fa-solid fa-xmark"></i> <?= $is_ar?'إلغاء (البقاء)':'Cancel (stay)' ?></div>
</button>
</div>
</div>

<!-- نافذة التسلّم -->
<div class="modal" id="rlTakeoverModal">
<div class="sheet">
<h4 style="margin:0 0 10px;color:#b45309;font-weight:900"><i class="fa-solid fa-user-lock"></i> <?= $is_ar?'الغرفة قيد الجرد حالياً':'Room In Progress' ?></h4>
<div id="rlTakeoverInfo" style="font-size:13px;color:#334155;font-weight:700;margin-bottom:14px"></div>
<div style="display:flex;gap:10px">
<button class="btn btn-g" style="flex:2" onclick="rlDoTakeover()"><i class="fa-solid fa-right-to-bracket"></i> <?= $is_ar?'نعم، استكمال الجرد':'Yes, Take Over' ?></button>
<button class="btn btn-o" style="flex:1" onclick="rlCancelTakeover()"><?= $is_ar?'تراجع':'Cancel' ?></button>
</div>
</div>
</div>

<script>
const RL = {
  manual: <?=$rl_manual?'true':'false'?>,
  meName: '<?= e(current_user()['full_name'] ?? '') ?>',  // ✅ أضف هذا السطر هنا
  qrRequired: <?=$rl_qr_req?'true':'false'?>,
  audioCue: <?=$rl_audio?'true':'false'?>,
  vibrate: <?=$rl_vibrate?'true':'false'?>,
  maxSuspend: <?=$rl_max_susp?>,
  suspendCount: 0,
  room: null,
  pendingRoom: null
};
window.__origOpenRoom = openRoom;
window.__origGoBack = goBack;
window.__origLoadLocations = loadLocations;

/* ═══ تطبيق صارم: inv_manual_picker يتحكم في المنتقي + الإدخال اليدوي + الفاصل ═══ */
(function(){
  const picker = document.querySelector('.picker-section');
  const form = $('rlManualForm');
  const divider = document.querySelector('.qr-divider');
  if(picker) picker.style.display = RL.manual ? '' : 'none';
  if(form) form.style.display = RL.manual ? '' : 'none';
  if(divider) divider.style.display = RL.manual ? '' : 'none';
})();

/* إخفاء المنتقي اليدوي إن لم يكن مفعّلاً */
loadLocations = async function(){ await __origLoadLocations(); /* picker لا يحتاج to toggle القديم — فقط الإعدادات */ };

/* زر مسح QR الغرفة: إن لم يكن موجوداً (scan.php الجديد يحتويه) */
(function(){
  if($('rlScanBtn')) return;  // الزر موجود في scan.php
  const wrap = document.querySelector('#scrLoc .wrap');
  if(!wrap) return;
  const b = document.createElement('button');
  b.id = 'rlScanBtn';
  b.className='btn btn-g'; b.style.width='100%'; b.style.marginBottom='12px';
  b.innerHTML='<i class="fa-solid fa-qrcode"></i> '+(window.IS_AR?'مسح QR الغرفة':'Scan Room QR');
  b.onclick = rlScanRoom;
  wrap.prepend(b);
})();

let rlScanner=null;
function rlStopCam(){ if(rlScanner){ const s=rlScanner; rlScanner=null; s.stop().then(()=>s.clear()).catch(()=>{}); } const b=$('rlCamBox'); if(b) b.style.display='none'; }
function rlScanRoom(){
  let box=$('rlCamBox');
  if(!box){
    // ابحث عن مكان الإدراج: داخل qr-scan-card (الجديد) أو قبل .picker-section
    const cam = document.createElement('div');
    cam.id = 'rlCamBox';
    cam.className = 'qr-cam';
    cam.style.cssText='display:none;margin-bottom:18px';
    cam.innerHTML='<div id="rlQr"></div>';
    const anchor = $('rlScanBtn') ? $('rlScanBtn').closest('.qr-scan-card') : document.querySelector('#scrLoc .wrap');
    if(anchor) anchor.appendChild(cam);
    box = cam;
  }
  if(box.style.display==='block'){
    rlStopCam();
    const btn = $('rlScanBtn');
    if(btn) btn.classList.remove('scanning');
    return;
  }
  box.style.display='block';
  const btn = $('rlScanBtn');
  if(btn) btn.classList.add('scanning');
  rlScanner=new Html5Qrcode('rlQr');
  rlScanner.start({facingMode:'environment'},{fps:10,qrbox:{width:230,height:140}}, async txt=>{
    rlStopCam();
    if(btn) btn.classList.remove('scanning');
    await rlOpenByCode(txt);
  }, ()=>{}).catch(()=>{
    if(btn) btn.classList.remove('scanning');
    toast(window.IS_AR?'⚠️ تعذّر فتح الكاميرا':'⚠️ Cannot open camera');
  });
}
async function rlOpenByCode(txt){
  try{
    const r=await fetch(BASE+'/inventory/api/room_lock.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({action:'resolve',session_id:SID,room_id:1,code:txt})});
    const j=await r.json();
    if(!j.ok){
      beep(false);
      if(j.error==='room_out_of_scope') toast(window.IS_AR?'🚫 هذه الغرفة خارج نطاق فريقك':'🚫 Room is outside your team scope');
      else toast(window.IS_AR?'⚠️ رمز غرفة غير معروف':'⚠️ Unknown room code');
      return;
    }
    beep(true); openRoom(j.room_id);
  }catch(e){ toast('⚠️ '+(window.IS_AR?'فشل الاتصال':'Connection failed')); }
}

/* اعتراض فتح الغرفة: شاشة تأكيد ثم قفل/تسلّم */
openRoom = async function(roomId){
  try{
    const rp=await fetch(BASE+'/inventory/api/room_lock.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({action:'preview',session_id:SID,room_id:roomId})});
    const jp=await rp.json();
    if(!jp.ok){
      if(jp.error==='room_out_of_scope') toast(window.IS_AR?'🚫 هذه الغرفة خارج نطاق فريقك':'🚫 Room is outside your team scope');
      else toast('⚠️ '+(jp.msg||jp.error||'Error'));
      return;
    }
    const rm=jp.room||{};
    const arN=rm.name_en||rm.name||'', enN=rm.name||rm.name_en||'';
    const roomDisp=window.IS_AR?(arN||enN):(enN||arN);
    const arB=rm.b_name_en||rm.b_name||'', enB=rm.b_name||rm.b_name_en||'';
    const arF=rm.f_name_en||rm.f_name||'', enF=rm.f_name||rm.f_name_en||'';
    const locDisp=[window.IS_AR?(arB||enB):(enB||arB),window.IS_AR?(arF||enF):(enF||arF)].filter(Boolean).join(' / ');

    /* تعبئة مودال التأكيد */
    const icon=document.getElementById('rlEnterIcon');
    const title=document.getElementById('rlEnterTitle');
    const roomEl=document.getElementById('rlEnterRoom');
    const locEl=document.getElementById('rlEnterLoc');
    const meta=document.getElementById('rlEnterMeta');
    const prev=document.getElementById('rlEnterPrev');
    const btnTxt=document.getElementById('rlEnterBtnTxt');

    /* تنسيق الأنواع */
    const typeLabels={medical:window.IS_AR?'طبي':'Medical',it:window.IS_AR?'تقنية معلومات':'IT',infrastructure:window.IS_AR?'بنية تحتية':'Infra',hvac:window.IS_AR?'تكييف':'HVAC',transport:window.IS_AR?'مركبات':'Transport',furniture:window.IS_AR?'أثاث':'Furniture',other:window.IS_AR?'أخرى':'Other'};
    const typeIcons={medical:'🏥',it:'💻',infrastructure:'⚡',hvac:'❄️',transport:'🚗',furniture:'🪑',other:'📦'};
    function buildTypeHtml(obj,max){
      if(!obj||!Object.keys(obj).length)return '';
      return Object.entries(obj).sort((a,b)=>b[1]-a[1]).slice(0,max||5).map(([k,v])=>`<span style="background:#f1f5f9;padding:2px 8px;border-radius:99px;font-size:10.5px;font-weight:700;display:inline-flex;align-items:center;gap:3px;">${typeIcons[k]||'📦'} ${typeLabels[k]||k}: <strong class="eng">${v}</strong></span>`).join('');
    }

    if(jp.completed){
      icon.style.background='#fee2e2'; icon.innerHTML='<i class="fa-solid fa-lock" style="color:#dc2626"></i>';
      title.textContent=window.IS_AR?'🔒 الغرفة مُقفلة':'🔒 Room Locked';
      roomEl.textContent=roomDisp;
      locEl.textContent=locDisp;
      meta.innerHTML='<i class="fa-solid fa-user-check" style="margin-left:4px;"></i>'+(window.IS_AR?'أُغلقت بواسطة: ':'Locked by: ')+esc(jp.completed_by||'');
      prev.style.display='none';
      btnTxt.textContent=window.IS_AR?'العودة':'Go Back';
      document.getElementById('rlEnterBtn').onclick=function(){rlCancelEnter()};
      RL.pendingRoom = roomId;
      // إذا يوجد طلب معلق مسبقاً — نمنع المستخدم
      if(jp.pending_request){
        const extra=document.getElementById('rlExtraBtns');
        extra.style.display='block';
        extra.innerHTML='<div style="background:#fef3c7;border:1px solid #fde68a;border-radius:10px;padding:12px;text-align:center;"><div style="font-size:13px;font-weight:800;color:#92400e;"><i class="fa-solid fa-clock-rotate-left"></i> '+(window.IS_AR?'يوجد طلب إعادة فتح معلّق من: ':'A pending request from: ')+'<strong>'+esc(jp.pending_request_by||'')+'</strong></div><div style="font-size:11.5px;color:#94a3b8;margin-top:4px;">'+(window.IS_AR?'بانتظار قرار المدير — لا يمكنك تقديم طلب آخر':'Awaiting admin decision — no new requests allowed')+'</div></div>';
      }else{
        const extra=document.getElementById('rlExtraBtns');
        extra.style.display='block';
        extra.innerHTML='<button class="btn btn-g" style="width:100%;background:linear-gradient(135deg,#ea580c,#c2410c);border:none;color:#fff;padding:10px;border-radius:10px;font-weight:800;font-size:13px;cursor:pointer;font-family:inherit" onclick="document.getElementById(\'rlEnterModal\').classList.remove(\'show\');document.getElementById(\'rlExtraBtns\').style.display=\'none\';openRoomReauditModal()"><i class="fa-solid fa-flag"></i> '+(window.IS_AR?'اطلب إعادة فتح الغرفة':'Request Room Re-open')+'</button>';
      }
      document.getElementById('rlEnterModal').classList.add('show');
      return;
    }

    if(jp.returning){
      icon.style.background='#fef3c7'; icon.innerHTML='<i class="fa-solid fa-clock-rotate-left" style="color:#d97706"></i>';
      title.textContent=window.IS_AR?'▶️ استئناف جرد الغرفة':'▶️ Resume Room Audit';
      roomEl.textContent=roomDisp;
      locEl.textContent=locDisp;
      document.getElementById('rlExtraBtns').style.display='none';
      /* عرض إحصائيات الأدوات */
      const verified= jp.verified_count||0;
      const systemTotal= jp.asset_count||0;
      const audited= jp.audited_count||0;
      meta.innerHTML=`<div style="display:flex;flex-wrap:wrap;gap:6px;margin-bottom:6px;">
        <span style="background:#dcfce7;color:#166534;padding:2px 8px;border-radius:99px;font-size:11px;font-weight:800;"><i class="fa-solid fa-shield-check" style="margin-left:3px;"></i>${window.IS_AR?'موثّق: ':'Verified: '}<strong class="eng">${verified}</strong></span>
        <span style="background:#fef3c7;color:#92400e;padding:2px 8px;border-radius:99px;font-size:11px;font-weight:800;"><i class="fa-solid fa-cubes" style="margin-left:3px;"></i>${window.IS_AR?'حسب الموقع: ':'Location: '}<strong class="eng">${systemTotal}</strong></span>
        <span style="background:#e0e7ff;color:#4338ca;padding:2px 8px;border-radius:99px;font-size:11px;font-weight:800;"><i class="fa-solid fa-check-double" style="margin-left:3px;"></i>${window.IS_AR?'جرد في هذه الجلسة: ':'Audited this session: '}<strong class="eng">${audited}</strong></span>
      </div>`;
      const vTypes=buildTypeHtml(jp.verified_by_type);
      if(vTypes) meta.innerHTML+=`<div style="margin-top:4px;"><span style="font-size:10px;color:#166534;font-weight:700;">✅ ${window.IS_AR?'الموثّق حسب النوع:':'Verified by type:'}</span><div style="display:flex;flex-wrap:wrap;gap:4px;margin-top:3px;">${vTypes}</div></div>`;
      prev.style.display='block';
      const lastTime=jp.last_suspended?(new Date(jp.last_suspended)).toLocaleTimeString('en-GB',{hour:'2-digit',minute:'2-digit'}):'';
      prev.innerHTML='<i class="fa-solid fa-circle-info" style="margin-left:4px;"></i>'+(window.IS_AR?'تم التعليق سابقاً هنا — آخر إيقاف الساعة ':'Previously paused here — last pause at ')+lastTime;
      prev.style.background='#fef3c7'; prev.style.borderColor='#fde68a'; prev.style.color='#92400e';
      btnTxt.textContent=window.IS_AR?'استئناف الجرد':'Resume Audit';
      document.getElementById('rlEnterBtn').onclick=function(){rlConfirmEnter()};
      RL.pendingRoom=roomId;
      document.getElementById('rlEnterModal').classList.add('show');
      return;
    }

    /* حالة عادية: أول مرة يدخل */
    document.getElementById('rlExtraBtns').style.display='none';
    icon.style.background='#dcfce7'; icon.innerHTML='<i class="fa-solid fa-door-open" style="color:#16a34a"></i>';
    title.textContent=window.IS_AR?'🚪 الدخول للجرد':'🚪 Enter Room for Audit';
    roomEl.textContent=roomDisp;
    locEl.textContent=locDisp;
    /* عرض إحصائيات الأدوات: الموقع vs الموثّق */
    const systemTotal=jp.asset_count||0;
    const verifiedTotal=jp.verified_count||0;
    const unverified=systemTotal-verifiedTotal;
    meta.innerHTML=`<div style="display:flex;flex-wrap:wrap;gap:6px;margin-bottom:6px;">
      <span style="background:#fef3c7;color:#92400e;padding:3px 10px;border-radius:99px;font-size:11px;font-weight:800;"><i class="fa-solid fa-cubes" style="margin-left:3px;"></i>${window.IS_AR?'حسب الموقع: ':'By location: '}<strong class="eng">${systemTotal}</strong> <span style="font-size:9px;opacity:.7;">(${window.IS_AR?'قد لا يكون صحيحاً':'may be wrong'})</span></span>
      <span style="background:#dcfce7;color:#166534;padding:3px 10px;border-radius:99px;font-size:11px;font-weight:800;"><i class="fa-solid fa-shield-check" style="margin-left:3px;"></i>${window.IS_AR?'موثّق فعلياً: ':'Verified: '}<strong class="eng">${verifiedTotal}</strong></span>
    </div>`;
    /* تفصيل Types للموثّق أولاً */
    const vTypes=buildTypeHtml(jp.verified_by_type);
    const uTypes=buildTypeHtml(jp.asset_by_type);
    if(vTypes) meta.innerHTML+=`<div style="margin-top:4px;"><span style="font-size:10px;color:#166534;font-weight:700;">✅ ${window.IS_AR?'الموثّق حسب النوع:':'Verified by type:'}</span><div style="display:flex;flex-wrap:wrap;gap:4px;margin-top:3px;">${vTypes}</div></div>`;
    if(unverified>0 && uTypes) meta.innerHTML+=`<div style="margin-top:6px;padding-top:6px;border-top:1px dashed #e2e8f0;"><span style="font-size:10px;color:#92400e;font-weight:700;">⚠️ ${window.IS_AR?'غير موثّق بال الموقع ('+unverified+'):':'Unverified by location ('+unverified+'):'}</span><div style="display:flex;flex-wrap:wrap;gap:4px;margin-top:3px;">${uTypes}</div></div>`;
    /* عرض المستخدمين الحاليين في الغرفة */
    if(jp.current_users&&jp.current_users.length){
      prev.style.display='block';
      prev.style.background='#dbeafe'; prev.style.borderColor='#93c5fd'; prev.style.color='#1e40af';
      prev.innerHTML='<i class="fa-solid fa-users" style="margin-left:4px;"></i>'+(window.IS_AR?'يوجد بالغرفة الآن: ':'Currently in room: ')+jp.current_users.map(u=>'<strong>'+esc(u)+'</strong>').join('، ');
    }else{
      prev.style.display='none';
    }
    btnTxt.textContent=window.IS_AR?'دخول الغرفة':'Enter Room';
    document.getElementById('rlEnterBtn').onclick=function(){rlConfirmEnter()};
    document.getElementById('rlEnterModal').classList.add('show');

    /* حفظ roomId مؤقتاً */
    RL.pendingRoom=roomId;
  }catch(e){ alert('⚠️ '+(window.IS_AR?'فشل الاتصال':'Connection failed')); }
};
async function rlConfirmEnter(){
  const roomId=RL.pendingRoom;
  if(!roomId) return;
  document.getElementById('rlEnterModal').classList.remove('show');
  try{
    const r=await fetch(BASE+'/inventory/api/room_lock.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({action:'checkin',session_id:SID,room_id:roomId})});
    const j=await r.json();
    if(!j.ok){
      beep(false);
      if(j.error==='room_completed'){ 
        // عرض بطاقة طلب إعادة فتح الغرفة بدلاً من alert فقط
        const info = $('devInfo') || document.querySelector('#scrLoc .wrap');
        if(info){
          const rmName = (j.room||'');
          const doneBy = j.by||'';
          const div = document.createElement('div');
          div.id='roomReauditCard';
          div.style.cssText='background:#fff7ed;border:1.5px solid #fdba74;border-radius:14px;padding:16px;margin-top:12px;text-align:center';
          div.innerHTML='<div style="font-weight:800;font-size:15px;color:#9a3412;margin-bottom:6px">🔒 '+(window.IS_AR?'الغرفة مُقفلة':'Room Completed')+'</div>'
            +'<div style="font-size:12.5px;color:#78350f;margin-bottom:4px">'+esc(doneBy)+(window.IS_AR?' أغلقها':' locked it')+'</div>'
            +'<button onclick="openRoomReauditModal()" style="background:#ea580c;color:#fff;border:none;padding:10px 22px;border-radius:10px;font-weight:800;font-size:13px;cursor:pointer;font-family:inherit;margin-top:8px">'
            +'<i class="fa-solid fa-flag"></i> '+(window.IS_AR?'اطلب إعادة فتح الغرفة':'Request Re-open')+'</button>';
          info.prepend(div);
        }
        return; 
      }
      if(j.error==='has_other_lock'){ alert((window.IS_AR?'⚠️ لديك غرفة أخرى مفتوحة باسمك: ':'⚠️ You have another open room: ')+(j.room||'')+(window.IS_AR?' — أنهِها أو علّقها أولاً.':' — finish or suspend it first.')); return; }
      if(j.error==='needs_takeover'){ RL.pendingRoom=roomId; $('rlTakeoverInfo').innerHTML=(window.IS_AR?'فُتحت هذه الغرفة للجرد من <b>':'Opened by <b>')+(j.by||'')+'</b> '+(window.IS_AR?'— هل تريد استكمال الجرد مكانه؟':'— take over?'); $('rlTakeoverModal').classList.add('show'); return; }
      alert('⚠️ '+(j.error||'')); return;
    }
    RL.room=roomId; RL.pendingRoom=null;
    __origOpenRoom(roomId);
    rlShowLockBar();
  }catch(e){ alert('⚠️ '+(window.IS_AR?'فشل الاتصال':'Connection failed')); }
}
function rlCancelEnter(){ document.getElementById('rlEnterModal').classList.remove('show'); document.getElementById('rlExtraBtns').style.display='none'; document.getElementById('rlExtraBtns').innerHTML=''; RL.pendingRoom=null; }
async function rlDoTakeover(){
  $('rlTakeoverModal').classList.remove('show');
  try{
    const r=await fetch(BASE+'/inventory/api/room_lock.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({action:'takeover',session_id:SID,room_id:RL.pendingRoom})});
    const j=await r.json();
    if(!j.ok){ alert('⚠️ '+(j.error||'')); return; }
    RL.room=RL.pendingRoom; beep(true); __origOpenRoom(RL.room); rlShowLockBar();
  }catch(e){ alert('⚠️'); }
}
function rlCancelTakeover(){ $('rlTakeoverModal').classList.remove('show'); RL.pendingRoom=null; }

/* ── شريط القفل المطوّر ── */
let rlTimerInt=null, rlStartTs=null;
const rlIsAr=s=>/[\u0600-\u06FF]/.test(s||'');
function rlArEn(a,b){a=a||'';b=b||'';return rlIsAr(a)?[a,b]:[b,a];}
function rlFmt(s){s=Math.max(0,Math.floor(s));const h=Math.floor(s/3600),m=Math.floor(s%3600/60),x=s%60;return (h?h+':':'')+String(m).padStart(2,'0')+':'+String(x).padStart(2,'0');}
function rlTick(){
 const c=document.getElementById('rlClock'); if(c)c.textContent=new Date().toLocaleTimeString('en-GB',{hour:'2-digit',minute:'2-digit',second:'2-digit'});
 const e=document.getElementById('rlElapsed'); if(e&&rlStartTs){
   const currentSec=Math.max(0,Math.floor((Date.now()-rlStartTs)/1000));
   const totalSec=(RL.cumulativeSec||0)+currentSec;
   e.textContent=rlFmt(totalSec);
 }
}

window.rlShowLockBar = async function(){
 const head=document.querySelector('#scrRoom .wrap'); if(!head)return;
 let bar=document.getElementById('modernLockBar');
 if(!bar){
   bar=document.createElement('div');
   bar.id='modernLockBar';
   bar.className='rl-lockbar';
   bar.style.cssText = 'background:linear-gradient(135deg,#0f172a,#1e293b);color:#fff;border-radius:16px;padding:12px 14px;margin:12px 0;display:flex;flex-direction:column;gap:12px;box-shadow:0 4px 14px rgba(15,23,42,0.15);';
   const assetList = document.getElementById('assetList');
   if(assetList) head.insertBefore(bar, assetList);
   else head.appendChild(bar);
 }
 try{
  const r=await fetch(BASE+'/inventory/api/room_lock.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({action:'status',session_id:SID,room_id:RL.room})});
  const j=await r.json();
if(j.ok){
        const rm=j.room||{};
        const arN=rm.name_en||rm.name||'', enN=rm.name||rm.name_en||'';
        const roomDisp=window.IS_AR?(arN||enN):(enN||arN);
        const arB=rm.b_name_en||rm.b_name||'', enB=rm.b_name||rm.b_name_en||'';
        const bldDisp=window.IS_AR?(arB||enB):(enB||arB);
        const arF=rm.f_name_en||rm.f_name||'', enF=rm.f_name||rm.f_name_en||'';
        const flrDisp=window.IS_AR?(arF||enF):(enF||arF);
        
        const users=j.users||[];
        const meU=users.find(u=>u.me); const others=users.filter(u=>!u.me);
        window.roomShared = users.length > 1;
        rlStartTs=(meU&&(meU.resumed_at||meU.at))?new Date(String(meU.resumed_at||meU.at).replace(' ','T')).getTime():Date.now();
        if(j.suspend_count !== undefined) RL.suspendCount = parseInt(j.suspend_count)||0;
        /* الوقت التراكمي: المدة الإجمالية من كل الفترات السابقة + العداد الحالي */
        RL.cumulativeSec = meU ? (meU.cumulative_sec || 0) : 0;
    
    let othersHtml = others.map(u=>`<span style="background:rgba(255,255,255,0.12);padding:2px 8px;border-radius:99px;font-size:10px;display:inline-flex;align-items:center;gap:3px;"><i class="fa-solid fa-user" style="font-size:9px;"></i> ${esc(u.name)}</span>`).join('');
    if(othersHtml) othersHtml = `<div style="display:flex;gap:4px;flex-wrap:wrap;">${othersHtml}</div>`;
    
    const locParts = [bldDisp,flrDisp].filter(Boolean);
    const locHtml = locParts.length ? `<div style="font-size:10.5px;color:rgba(255,255,255,.6);margin-top:2px;font-weight:600;"><i class="fa-solid fa-location-dot" style="margin-left:3px;"></i>${esc(locParts.join(' / '))}</div>` : '';
    
    bar.innerHTML=`
      <div style="display:flex;align-items:flex-start;justify-content:space-between;gap:10px;">
        <div style="flex:1;min-width:0;">
          <b style="font-size:13px;font-weight:900;color:#38bdf8;"><i class="fa-solid fa-door-open"></i> ${esc(roomDisp)}</b>
          ${locHtml}
          <div style="display:flex;align-items:center;gap:6px;margin-top:6px;flex-wrap:wrap;">
            <span style="background:rgba(56,189,248,0.2);color:#38bdf8;padding:2px 8px;border-radius:99px;font-size:10px;display:inline-flex;align-items:center;gap:3px;"><i class="fa-solid fa-user-check" style="font-size:9px;"></i> ${esc(RL.meName||'')}</span>
            ${othersHtml}
          </div>
        </div>
        <div style="display:flex;flex-direction:column;align-items:center;font-family:'Inter',monospace;font-size:11px;background:rgba(0,0,0,0.3);padding:5px 10px;border-radius:10px;flex-shrink:0;">
          <span id="rlClock" style="font-size:10px;opacity:.7;">--:--</span>
          <span id="rlElapsed" style="font-size:13px;font-weight:900;color:#4ade80;">00:00</span>
          <span style="font-size:8px;opacity:.6;margin-top:1px;">${window.IS_AR?'الوقت الكلي':'Total'}</span>
        </div>
      </div>
      <div style="display:flex;gap:8px;width:100%;">
        <button onclick="rlSuspend()" style="flex:1;background:linear-gradient(135deg,#b45309,#92400e);border:none;color:#fff;border-radius:10px;padding:9px;font-size:12px;font-weight:800;cursor:pointer;display:flex;justify-content:center;align-items:center;gap:5px;"><i class="fa-solid fa-pause"></i> ${window.IS_AR?'إيقاف مؤقت':'Pause'}</button>
        <button onclick="rlComplete()" style="flex:1;background:linear-gradient(135deg,#16a34a,#15803d);border:none;color:#fff;border-radius:10px;padding:9px;font-size:12px;font-weight:800;cursor:pointer;display:flex;justify-content:center;align-items:center;gap:5px;"><i class="fa-solid fa-lock"></i> ${window.IS_AR?'إقفال نهائي':'Finish & Lock'}</button>
      </div>`;
  }
 }catch(e){}
 if(!rlTimerInt) rlTimerInt=setInterval(rlTick,1000);
 rlTick();
};

/* إصلاح اعتراض زر الرجوع — يعتمد على DOM الفعلي */
goBack = function(){
  const isRoomView = document.getElementById('scrRoom') && document.getElementById('scrRoom').classList.contains('on');
  const isDeviceView = document.getElementById('scrDevice') && document.getElementById('scrDevice').classList.contains('on');
  
  if (isRoomView && RL.room) { 
    document.getElementById('rlExitModal').classList.add('show'); 
    return; 
  }
  
  if (isDeviceView && window.curRoom) {
     show('room'); openRoom(curRoom.id);
     return;
  }
  
  location.href = BASE+'/inventory/session.php?id='+SID;
};
async function rlComplete(){
  if(!confirm(window.IS_AR?'تأكيد الإقفال النهائي؟ لا يمكن إعادة فتح الغرفة إلا باستثناء من مدير الأصول.':'Confirm final lock? Room cannot be reopened without admin exception.')) return;
  $('rlExitModal').classList.remove('show');
  try{
    const r=await fetch(BASE+'/inventory/api/room_lock.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({action:'complete',session_id:SID,room_id:RL.room,oath:1})});
    const j=await r.json();
    if(!j.ok){ alert('⚠️ '+(j.error||'')); return; }
    beep(true); toast(window.IS_AR?'🔒 أُقفلت الغرفة — تم الإتمام':'🔒 Room locked — done');
    RL.room=null; curRoom=null;
    location.href=BASE+'/inventory/session.php?id='+SID;
  }catch(e){ alert('⚠️'); }
}
async function rlSuspend(){
  $('rlExitModal').classList.remove('show');
  if(RL.maxSuspend > 0 && RL.suspendCount >= RL.maxSuspend){
    alert(window.IS_AR?'⚠️ تجاوزت الحد الأقصى لعمليات التعليق ('+RL.maxSuspend+') — تواصل مع مدير الأصول لإعادة تعيين العداد':'⚠️ Suspend limit reached ('+RL.maxSuspend+') — contact admin to reset');
    return;
  }
  try{
    const r=await fetch(BASE+'/inventory/api/room_lock.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({action:'suspend',session_id:SID,room_id:RL.room})});
    const j=await r.json();
    if(!j.ok){
      if(j.error==='suspend_limit_reached'){ alert(window.IS_AR?'⚠️ '+j.msg:'⚠️ '+j.msg); return; }
      alert('⚠️ '+(j.error||'')); return;
    }
    RL.suspendCount++;
    toast(window.IS_AR?'⏸️ علّقت الغرفة — تستأنف لاحقاً':'⏸️ Suspended — resume later');
    RL.room=null; curRoom=null;
    location.href=BASE+'/inventory/session.php?id='+SID;
  }catch(e){ alert('⚠️'); }
}
/* ═══ نافذة طلب إعادة فتح الغرفة ═══ */
function openRoomReauditModal(){ $('roomReauditModal').classList.add('show'); }
function closeRoomReauditModal(){ $('roomReauditModal').classList.remove('show'); }
async function submitRoomReauditRequest(){
    const reason = $('roomReauditReason').value.trim();
    if(!reason){ toast(window.IS_AR?'⚠️ اكتب السبب':'⚠️ Enter reason'); return; }
    try{
        const r = await fetch(`${BASE}/inventory/api/reaudit_request.php`,{
            method:'POST', headers:{'Content-Type':'application/json'},
            body: JSON.stringify({session_id:SID, request_type:'re_audit_room', room_id:RL.pendingRoom||0, reason:reason})
        });
        const j = await r.json();
        closeRoomReauditModal();
        if(j.ok)
            toast(window.IS_AR?'✅ تم إرسال الطلب — بانتظار اعتماد مدير الأصول':'✅ Request sent — awaiting admin approval');
        else
            toast('⚠️ '+(j.error||'Failed'));
        // إزالة البطاقة بعد الإرسال
        const card = document.getElementById('roomReauditCard');
        if(card) card.remove();
    }catch(e){ toast(window.IS_AR?'⚠️ فشل الاتصال':'⚠️ Connection failed'); }
}

</script>
<!-- نافذة طلب إعادة فتح الغرفة -->
<?php
// تحليل أسباب إعادة فتح الغرفة من الإعدادات
$_room_reasons_raw = get_setting('inv_room_reopen_reasons', '');
$_room_reasons = json_decode($_room_reasons_raw, true) ?: [];
if (empty($_room_reasons) && $_room_reasons_raw !== '') {
    foreach (explode("\n", $_room_reasons_raw) as $_line) {
        $_line = trim($_line);
        if ($_line === '') continue;
        $_parts = explode('|', $_line, 2);
        $_room_reasons[] = ['ar' => trim($_parts[0] ?? ''), 'en' => trim($_parts[1] ?? $_parts[0] ?? '')];
    }
}
?>
<div class="modal" id="roomReauditModal" onclick="if(event.target===this)closeRoomReauditModal()">
<div class="sheet" style="max-width:400px">
<h4 style="margin:0 0 10px;color:#9a3412;font-weight:900"><i class="fa-solid fa-flag"></i> <?= $is_ar?'طلب إعادة فتح الغرفة':'Request Room Re-open' ?></h4>
<p style="font-size:12.5px;color:#64748b;margin:0 0 12px"><?= $is_ar?'اختر سبب إعادة فتح الغرفة — يذهب للمدير للاعتماد.':'Select a reason — sent to admin.' ?></p>
<select id="roomReauditReason" class="finp" style="border:1.5px solid #e2e8f0;border-radius:10px;padding:10px;font-family:inherit;font-size:13px;">
<option value="">-- <?= $is_ar?'اختر السبب':'Select reason' ?> --</option>
<?php foreach ($_room_reasons as $_rr): ?>
<option value="<?= e($is_ar ? $_rr['ar'] : $_rr['en']) ?>"><?= e($is_ar ? $_rr['ar'] : $_rr['en']) ?></option>
<?php endforeach; ?>
</select>
<div style="display:flex;gap:8px;margin-top:12px">
<button onclick="submitRoomReauditRequest()" style="flex:1;background:#ea580c;color:#fff;border:none;padding:10px;border-radius:10px;font-weight:800;font-size:13px;cursor:pointer;font-family:inherit"><i class="fa-solid fa-paper-plane"></i> <?= $is_ar?'إرسال الطلب':'Submit' ?></button>
<button onclick="closeRoomReauditModal()" style="flex:1;background:#f1f5f9;color:#64748b;border:none;padding:10px;border-radius:10px;font-weight:800;font-size:13px;cursor:pointer;font-family:inherit"><?= $is_ar?'إلغاء' : 'Cancel' ?></button>
</div>
</div>
</div>
</div>
</div>
<?php endif; ?>