<?php
/**
 * inventory/radar.php — صفحة رادار مراقبة الجلسة المستقلة (6 تبويبات)
 * ملخص / أعضاء / غرف / أحداث / تنبيهات / سجل
 */
require_once dirname(__DIR__) . '/config.php';
page_guard('inventory.index');

$sid = (int)($_GET['id'] ?? 0);
if (!$sid) abort(404);
if (!inv_session_guard($sid)) {
    flash('warning', 'أنت لست عضواً في هذه الجلسة.');
    redirect('/inventory/index.php');
}
if (!(is_admin() || (function_exists('can') && can('inventory.create', 'manage')))) {
    abort(403, 'غير مصرح لك بالوصول للرادار');
}

$st = $pdo->prepare("SELECT session_code, title, status, created_at FROM inventory_sessions WHERE id=?");
$st->execute([$sid]);
$session = $st->fetch(PDO::FETCH_ASSOC);
if (!$session) abort(404);

$rtl = is_rtl();
$page_title = ($rtl ? 'رادار' : 'Radar') . ' — ' . ($session['session_code'] ?? '');
?>
<!DOCTYPE html>
<html lang="<?= e(current_lang()) ?>" dir="<?= $rtl ? 'rtl' : 'ltr' ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
<title><?= e($page_title) ?></title>
<link href="https://fonts.googleapis.com/css2?family=Tajawal:wght@400;500;700;800;900&family=Inter:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<style>
*{margin:0;padding:0;box-sizing:border-box;font-family:'Tajawal',sans-serif;}
body{background:#f1f5f9;min-height:100vh;overflow-x:hidden;}
.eng{font-family:'Inter',sans-serif;}
.rh{background:linear-gradient(135deg,#5b21b6,#7c3aed,#6d28d9);color:#fff;padding:16px 20px;display:flex;align-items:center;gap:12px;position:sticky;top:0;z-index:100;box-shadow:0 4px 20px rgba(91,33,182,.3);}
.rh .dot{width:12px;height:12px;border-radius:50%;background:#4ade80;animation:rpulse 1.5s infinite;flex-shrink:0;}
@keyframes rpulse{0%,100%{opacity:1;transform:scale(1)}50%{opacity:.4;transform:scale(.8);}}
.rh h1{font-size:17px;font-weight:900;flex:1;min-width:0;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
.rh .code{background:rgba(255,255,255,.2);padding:3px 10px;border-radius:6px;font-size:12px;font-weight:800;font-family:'Inter';flex-shrink:0;}
.rh button{background:rgba(255,255,255,.15);border:none;color:#fff;border-radius:10px;padding:8px 12px;cursor:pointer;font-size:16px;flex-shrink:0;}
.rh button:active{background:rgba(255,255,255,.3);}
.tabs{display:flex;background:#fff;border-bottom:2px solid #e2e8f0;overflow-x:auto;-webkit-overflow-scrolling:touch;}
.tabs button{flex:1;min-width:0;padding:12px 6px;border:none;background:none;font-family:'Tajawal';font-size:11px;font-weight:800;color:#64748b;cursor:pointer;border-bottom:3px solid transparent;transition:.2s;white-space:nowrap;}
.tabs button.active{color:#7c3aed;border-bottom-color:#7c3aed;background:#faf5ff;}
.content{padding:14px;max-width:1200px;margin:0 auto;}
.sum-grid{display:grid;grid-template-columns:repeat(2,1fr);gap:10px;margin-bottom:16px;}
@media(min-width:768px){.sum-grid{grid-template-columns:repeat(3,1fr);}}
.sum-card{background:#fff;border-radius:14px;padding:14px;border:1px solid #e2e8f0;text-align:center;}
.sum-card .icon{width:40px;height:40px;border-radius:12px;display:flex;align-items:center;justify-content:center;margin:0 auto 8px;font-size:18px;}
.sum-card .val{font-size:24px;font-weight:900;font-family:'Inter';color:#0f172a;line-height:1;}
.sum-card .label{font-size:11px;color:#64748b;font-weight:700;margin-top:4px;}
.icon-blue{background:#dbeafe;color:#2563eb;}
.icon-green{background:#dcfce7;color:#16a34a;}
.icon-amber{background:#fef3c7;color:#d97706;}
.icon-red{background:#fee2e2;color:#dc2626;}
.icon-purple{background:#ede9fe;color:#7c3aed;}
.mc{background:#fff;border-radius:14px;border:1.5px solid #e2e8f0;padding:14px;margin-bottom:10px;transition:.2s;}
.mc:active{transform:scale(.99);}
.mc.susp{background:#fef2f2;border-color:#fca5a5;}
.mc-head{display:flex;flex-wrap:wrap;gap:6px;align-items:center;margin-bottom:8px;}
.mc-head .name{font-size:14px;font-weight:900;color:#0f172a;}
.mc-head .badge{font-size:9.5px;font-weight:800;padding:2px 8px;border-radius:99px;}
.b-red{background:#fee2e2;color:#b91c1c;}
.b-green{background:#dcfce7;color:#166534;}
.b-amber{background:#fef3c7;color:#92400e;}
.b-blue{background:#dbeafe;color:#1d4ed8;}
.b-purple{background:#ede9fe;color:#6d28d9;}
.mc-stats{display:grid;grid-template-columns:repeat(5,1fr);gap:4px;margin:8px 0;text-align:center;}
.mc-stats span{font-size:10px;font-weight:700;color:#475569;display:flex;flex-direction:column;align-items:center;gap:2px;}
.mc-stats span .num{font-size:14px;font-weight:900;font-family:'Inter';color:#0f172a;line-height:1;}
.mc-meta{font-size:10.5px;color:#94a3b8;margin-bottom:8px;display:flex;flex-wrap:wrap;gap:6px;}
.mc-meta span{display:inline-flex;align-items:center;gap:3px;}
.mc-actions{display:flex;flex-wrap:wrap;gap:5px;}
.mc-actions button{border:1px solid #e2e8f0;background:#fff;border-radius:8px;padding:6px 10px;font-size:11px;font-weight:800;cursor:pointer;font-family:'Tajawal';transition:.15s;}
.mc-actions button:active{transform:scale(.95);}
.mc-actions .warn{background:#fef2f2;color:#dc2626;border-color:#fecaca;}
.mc-actions .ok{background:#f0fdf4;color:#16a34a;border-color:#bbf7d0;}
.ev-wrap{background:#fff;border-radius:14px;border:1.5px solid #e2e8f0;overflow:hidden;margin-bottom:14px;}
.ev-head{padding:12px 14px;border-bottom:1.5px solid #f1f5f9;font-size:13px;font-weight:900;color:#0f172a;display:flex;align-items:center;gap:8px;}
.ev-list{max-height:400px;overflow-y:auto;-webkit-overflow-scrolling:touch;}
.ev-item{padding:10px 14px;border-bottom:1px dashed #f1f5f9;font-size:12px;color:#475569;display:flex;align-items:center;gap:8px;}
.ev-item .ev-name{font-weight:900;color:#0f172a;flex-shrink:0;}
.ev-item .ev-act{flex:1;min-width:0;}
.ev-item .ev-time{font-family:'Inter';font-size:10.5px;color:#94a3b8;flex-shrink:0;}
.status-bar{background:#fff;border-radius:12px;padding:10px 14px;margin-bottom:14px;display:flex;align-items:center;gap:8px;font-size:11.5px;font-weight:700;color:#475569;border:1px solid #e2e8f0;}
.status-bar .live{width:8px;height:8px;border-radius:50%;background:#10b981;animation:rpulse 1.5s infinite;flex-shrink:0;}
.status-bar .timer{font-family:'Inter';font-weight:900;color:#7c3aed;margin-inline-start:auto;}
.empty{text-align:center;color:#94a3b8;padding:40px 20px;font-size:13px;font-weight:700;}
.empty i{font-size:32px;display:block;margin-bottom:8px;color:#cbd5e1;}
.modal-bg{display:none;position:fixed;inset:0;background:rgba(0,0,0,.4);z-index:200;align-items:center;justify-content:center;padding:20px;}
.modal-bg.show{display:flex;}
.modal-box{background:#fff;border-radius:18px;padding:22px;width:100%;max-width:420px;box-shadow:0 20px 60px rgba(0,0,0,.2);}
.modal-box h4{margin:0 0 14px;font-weight:900;font-size:16px;color:#0f172a;}
.modal-box .btns{display:flex;gap:10px;margin-top:16px;}
.modal-box .btns button{flex:1;padding:12px;border:none;border-radius:12px;font-family:'Tajawal';font-weight:900;font-size:13px;cursor:pointer;}
.btn-g{background:linear-gradient(135deg,#16a34a,#15803d);color:#fff;}
.btn-o{background:#f1f5f9;color:#475569;}
@keyframes fadeInUp{from{opacity:0;transform:translateY(10px)}to{opacity:1;transform:translateY(0)}}
.fade-in{animation:fadeInUp .3s ease-out;}
.prog{height:6px;background:#e2e8f0;border-radius:99px;overflow:hidden;margin:10px 0;}
.prog .fg{height:100%;border-radius:99px;background:linear-gradient(90deg,#7c3aed,#a78bfa);transition:width .5s;}
/* Room cards */
.rm{background:#fff;border-radius:14px;border:1.5px solid #e2e8f0;padding:14px;margin-bottom:10px;transition:.2s;}
.rm.active{border-color:#86efac;background:#f0fdf4;}
.rm.completed{border-color:#a5b4fc;background:#eef2ff;}
.rm-head{display:flex;align-items:center;gap:8px;margin-bottom:8px;}
.rm-head .rm-name{font-size:13px;font-weight:900;color:#0f172a;flex:1;}
.rm-head .badge{font-size:9.5px;font-weight:800;padding:2px 8px;border-radius:99px;}
.rm-bar{display:flex;align-items:center;gap:8px;margin-top:8px;}
.rm-bar .bar-track{flex:1;height:8px;background:#e2e8f0;border-radius:99px;overflow:hidden;}
.rm-bar .bar-fill{height:100%;border-radius:99px;transition:width .5s;}
.rm-bar .bar-pct{font-size:11px;font-weight:900;font-family:'Inter';color:#475569;min-width:36px;text-align:end;}
.rm-stats{display:grid;grid-template-columns:repeat(4,1fr);gap:4px;text-align:center;margin-top:8px;}
.rm-stats span{font-size:10px;font-weight:700;color:#475569;display:flex;flex-direction:column;align-items:center;gap:2px;}
.rm-stats span .num{font-size:12px;font-weight:900;font-family:'Inter';color:#0f172a;line-height:1;}
.rm-meta{font-size:10.5px;color:#94a3b8;margin-top:6px;display:flex;flex-wrap:wrap;gap:6px;}
.rm-meta span{display:inline-flex;align-items:center;gap:3px;}
/* Alert cards */
.al{padding:10px 14px;border-bottom:1px dashed #f1f5f9;font-size:12px;display:flex;align-items:center;gap:10px;}
.al .al-icon{width:32px;height:32px;border-radius:10px;display:flex;align-items:center;justify-content:center;font-size:14px;flex-shrink:0;}
.al .al-info{flex:1;min-width:0;}
.al .al-info .al-title{font-weight:900;color:#0f172a;font-size:12px;}
.al .al-info .al-msg{font-size:11px;color:#64748b;margin-top:2px;}
.al-critical .al-icon{background:#fee2e2;color:#dc2626;}
.al-warning .al-icon{background:#fef3c7;color:#d97706;}
.al-info .al-icon{background:#dbeafe;color:#2563eb;}
/* Detail panel */
.detail-panel{display:none;background:#fff;border-radius:14px;border:1.5px solid #e2e8f0;padding:16px;margin-bottom:14px;}
.detail-panel.show{display:block;}
.detail-panel .dp-head{display:flex;align-items:center;gap:10px;margin-bottom:12px;}
.detail-panel .dp-head .dp-name{font-size:15px;font-weight:900;color:#0f172a;flex:1;}
.detail-panel .dp-close{background:#f1f5f9;border:none;border-radius:8px;padding:6px 10px;font-size:14px;cursor:pointer;}
.detail-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:8px;}
.detail-grid .dg-item{text-align:center;padding:10px;background:#f8fafc;border-radius:10px;}
.detail-grid .dg-item .dg-val{font-size:18px;font-weight:900;font-family:'Inter';color:#0f172a;}
.detail-grid .dg-item .dg-lbl{font-size:10px;color:#64748b;font-weight:700;margin-top:2px;}
/* Session health */
.health{display:flex;align-items:center;gap:12px;padding:14px;background:#fff;border-radius:14px;border:1px solid #e2e8f0;margin-bottom:16px;}
.health-ring{width:60px;height:60px;position:relative;flex-shrink:0;}
.health-ring svg{width:100%;height:100%;transform:rotate(-90deg);}
.health-ring circle{fill:none;stroke-width:6;}
.health-ring .bg{stroke:#e2e8f0;}
.health-ring .fg{stroke:#10b981;stroke-linecap:round;transition:stroke-dashoffset .5s;}
.health-pct{position:absolute;inset:0;display:flex;align-items:center;justify-content:center;font-size:16px;font-weight:900;font-family:'Inter';color:#0f172a;}
.health-info{flex:1;}
.health-info .hi-title{font-size:13px;font-weight:900;color:#0f172a;}
.health-info .hi-sub{font-size:11px;color:#64748b;font-weight:600;margin-top:2px;}
</style>
</head>
<body>

<div class="rh">
<span class="dot"></span>
<h1><?= $rtl ? 'رادار المراقبة الحي' : 'Live Monitoring Radar' ?></h1>
<span class="code"><?= e($session['session_code'] ?? '') ?></span>
<button onclick="radarLoad()" title="تحديث"><i class="fa-solid fa-rotate"></i></button>
<a href="<?= BASE_URL ?>/inventory/session.php?id=<?= $sid ?>" style="color:#fff;text-decoration:none;background:rgba(255,255,255,.15);border-radius:10px;padding:8px 12px;font-size:16px;"><i class="fa-solid fa-arrow-right"></i></a>
</div>

<div class="tabs" id="tabs">
<button class="active" data-tab="summary" onclick="switchTab('summary')"><i class="fa-solid fa-chart-pie"></i> <?= $rtl ? 'ملخص' : 'Summary' ?></button>
<button data-tab="members" onclick="switchTab('members')"><i class="fa-solid fa-users"></i> <?= $rtl ? 'أعضاء' : 'Members' ?></button>
<button data-tab="rooms" onclick="switchTab('rooms')"><i class="fa-solid fa-door-open"></i> <?= $rtl ? 'غرف' : 'Rooms' ?></button>
<button data-tab="events" onclick="switchTab('events')"><i class="fa-solid fa-list-timeline"></i> <?= $rtl ? 'أحداث' : 'Events' ?></button>
<button data-tab="alerts" onclick="switchTab('alerts')"><i class="fa-solid fa-triangle-exclamation"></i> <?= $rtl ? 'تنبيهات' : 'Alerts' ?></button>
<button data-tab="log" onclick="switchTab('log')"><i class="fa-solid fa-clipboard-list"></i> <?= $rtl ? 'سجل' : 'Log' ?></button>
</div>

<div class="status-bar" style="margin:14px auto;max-width:1200px;">
<span class="live"></span>
<span id="statusText"><?= $rtl ? 'جاري التحميل…' : 'Loading…' ?></span>
<span class="timer eng" id="sessTimer">--:--:--</span>
</div>

<!-- Summary -->
<div class="content" id="tabSummary">
<div id="healthSection"></div>
<div class="sum-grid" id="quickStats"></div>
<div id="summaryMembers"></div>
</div>

<!-- Members -->
<div class="content" id="tabMembers" style="display:none;">
<div id="memberDetail" class="detail-panel"></div>
<div id="membersList"><div class="empty"><i class="fa-solid fa-spinner fa-spin"></i></div></div>
</div>

<!-- Rooms -->
<div class="content" id="tabRooms" style="display:none;">
<div id="roomDetail" class="detail-panel"></div>
<div id="roomsList"><div class="empty"><i class="fa-solid fa-spinner fa-spin"></i></div></div>
</div>

<!-- Events -->
<div class="content" id="tabEvents" style="display:none;">
<div class="ev-wrap">
<div class="ev-head"><i class="fa-solid fa-tower-broadcast" style="color:#7c3aed;"></i> <?= $rtl ? 'آخر الأحداث' : 'Recent Events' ?></div>
<div class="ev-list" id="eventsList"><div class="empty">…</div></div>
</div>
</div>

<!-- Alerts -->
<div class="content" id="tabAlerts" style="display:none;">
<div class="ev-wrap">
<div class="ev-head"><i class="fa-solid fa-triangle-exclamation" style="color:#dc2626;"></i> <?= $rtl ? 'التنبيهات النشطة' : 'Active Alerts' ?> <span id="alertCount" class="badge b-red" style="margin-inline-start:auto;"></span></div>
<div id="alertsList"><div class="empty">…</div></div>
</div>
</div>

<!-- Log -->
<div class="content" id="tabLog" style="display:none;">
<div class="ev-wrap">
<div class="ev-head"><i class="fa-solid fa-clipboard-list" style="color:#7c3aed;"></i> <?= $rtl ? 'سجل الإجراءات الإدارية' : 'Admin Action Log' ?></div>
<div class="ev-list" id="actionLogList"><div class="empty">…</div></div>
</div>
</div>

<!-- Kick modal -->
<div class="modal-bg" id="kickModal" onclick="if(event.target===this)this.classList.remove('show')">
<div class="modal-box">
<h4><i class="fa-solid fa-user-slash" style="color:#dc2626"></i> <?= $rtl ? 'إخراج العضو' : 'Kick Member' ?></h4>
<p id="kickInfo" style="font-size:13px;color:#475569;font-weight:700;"></p>
<textarea id="kickReason" placeholder="<?= $rtl ? 'سبب الإخراج (إلزامي)' : 'Reason (required)' ?>" style="width:100%;margin-top:10px;padding:10px;border:1.5px solid #e2e8f0;border-radius:10px;font-family:'Tajawal';font-size:12px;font-weight:700;resize:none;height:60px;outline:none;" onfocus="this.style.borderColor='#7c3aed'" onblur="this.style.borderColor='#e2e8f0'"></textarea>
<label style="display:flex;align-items:center;gap:8px;margin-top:10px;font-size:12px;font-weight:800;color:#475569;cursor:pointer;">
<input type="checkbox" id="kickBlock"> <?= $rtl ? 'منعه من دخول هذه الغرفة لاحقاً' : 'Block from this room' ?>
</label>
<div class="btns">
<button class="btn-g" onclick="doKick()"><i class="fa-solid fa-right-from-bracket"></i> <?= $rtl ? 'تأكيد' : 'Confirm' ?></button>
<button class="btn-o" onclick="$('kickModal').classList.remove('show')"><?= $rtl ? 'إلغاء' : 'Cancel' ?></button>
</div>
</div>
</div>

<!-- Suspend modal -->
<div class="modal-bg" id="suspendModal" onclick="if(event.target===this)this.classList.remove('show')">
<div class="modal-box">
<h4><i class="fa-solid fa-ban" style="color:#dc2626"></i> <?= $rtl ? 'تعليق العضو' : 'Suspend Member' ?></h4>
<p id="suspendInfo" style="font-size:13px;color:#475569;font-weight:700;"></p>
<textarea id="suspendReason" placeholder="<?= $rtl ? 'سبب التعليق (إلزامي)' : 'Reason (required)' ?>" style="width:100%;margin-top:10px;padding:10px;border:1.5px solid #e2e8f0;border-radius:10px;font-family:'Tajawal';font-size:12px;font-weight:700;resize:none;height:60px;outline:none;" onfocus="this.style.borderColor='#7c3aed'" onblur="this.style.borderColor='#e2e8f0'"></textarea>
<div class="btns">
<button class="btn-g" style="background:linear-gradient(135deg,#dc2626,#b91c1c);" onclick="doSuspend()"><i class="fa-solid fa-ban"></i> <?= $rtl ? 'تأكيد' : 'Confirm' ?></button>
<button class="btn-o" onclick="$('suspendModal').classList.remove('show')"><?= $rtl ? 'إلغاء' : 'Cancel' ?></button>
</div>
</div>
</div>

<script>
const SID=<?=$sid?>, BASE='<?= BASE_URL ?>', IS_AR=<?= $rtl?'true':'false' ?>;
const $=id=>document.getElementById(id);
const esc=s=>(s==null?'':String(s)).replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
const EV_LBL={opened:IS_AR?'دخل الغرفة':'Entered',resumed:IS_AR?'استأنف':'Resumed',suspended:IS_AR?'علّق':'Suspended',completed:IS_AR?'أكمل وأقفل':'Completed & Locked',taken_over:IS_AR?'تسلّم':'Took Over',force_exited:IS_AR?'أُخرج':'Force Exited',expired:IS_AR?'انتهت الصلاحية':'Expired'};
const ACTION_LBL={kick:'إخراج',suspend:'تعليق',unsuspend:'فك تعليق',block_room:'حجب غرفة',unblock_room:'فك حجب',extend:'تمديد',reset_suspends:'إعادة تعيين'};
function fmtSec(s){s=Math.max(0,Math.floor(s));const h=Math.floor(s/3600),m=Math.floor(s%3600/60);return(h?h+'س ':'')+m+'د';}

let radarData=null;

/* Tabs */
function switchTab(t){
  document.querySelectorAll('.tabs button').forEach(b=>b.classList.toggle('active',b.dataset.tab===t));
  ['Summary','Members','Rooms','Events','Alerts','Log'].forEach(n=>{const el=$('tab'+n);if(el)el.style.display=n.toLowerCase()===t?'':'none';});
  if(t==='members') $('memberDetail').classList.remove('show');
  if(t==='rooms') $('roomDetail').classList.remove('show');
}

/* Timer */
function tickTimer(){
  const el=$('sessTimer');if(!el)return;
  const s=<?= $session['status']==='planning'?'0':time().' - '.strtotime($session['created_at']) ?>;
  const diff=Math.max(0,s);
  const h=Math.floor(diff/3600),m=Math.floor(diff%3600/60),sec=diff%60;
  el.textContent=String(h).padStart(2,'0')+':'+String(m).padStart(2,'0')+':'+String(sec).padStart(2,'0');
}
setInterval(tickTimer,1000);tickTimer();

/* Load */
async function radarLoad(){
  try{
    const r=await fetch(BASE+'/inventory/api/session_radar.php?session_id='+SID);
    const j=await r.json();
    if(j.ok){radarData=j;renderRadar(j);}
  }catch(e){console.error('radarLoad',e);}
}

/* ── Render ── */
function renderRadar(j){
  const ms=j.members||[];
  const rms=j.rooms||[];
  const als=j.alerts||[];
  const totalActions=ms.reduce((a,m)=>a+((m.stats||{}).total||0),0);
  const totalConfirmed=ms.reduce((a,m)=>a+((m.stats||{}).confirmed||0),0);
  const totalMissing=ms.reduce((a,m)=>a+((m.stats||{}).missing||0),0);
  const totalRooms=rms.length;
  const completedRooms=rms.filter(r=>r.status==='completed').length;
  const totalCum=ms.reduce((a,m)=>a+(m.cumulative_sec||0),0);
  const activeCount=ms.filter(m=>m.lock&&!m.suspended).length;
  const suspCount=ms.filter(m=>m.suspended).length;
  const totalAssets=rms.reduce((a,r)=>a+(r.total_assets||0),0);

  /* Status bar */
  const statusText=IS_AR?`活跃 ${activeCount} عضو — ${completedRooms}/${totalRooms} غرفة — ${totalConfirmed} مؤكّد — ${totalMissing} مفقود`:`${activeCount} active — ${completedRooms}/${totalRooms} rooms — ${totalConfirmed} confirmed — ${totalMissing} missing`;
  $('statusText').innerHTML=statusText;

  /* ─── Health Score ─── */
  const scorable=totalAssets||1;
  const healthPct=Math.min(100,Math.round(totalConfirmed*100/scorable));
  const circumference=2*Math.PI*24;
  const offset=circumference-(healthPct/100)*circumference;
  const hColor=healthPct>=80?'#10b981':healthPct>=50?'#f59e0b':'#dc2626';
  $('healthSection').innerHTML=`<div class="health">
    <div class="health-ring"><svg viewBox="0 0 60 60"><circle class="bg" cx="30" cy="30" r="24" /><circle class="fg" cx="30" cy="30" r="24" stroke="${hColor}" stroke-dasharray="${circumference}" stroke-dashoffset="${offset}" /></svg><div class="health-pct eng">${healthPct}%</div></div>
    <div class="health-info"><div class="hi-title">${IS_AR?'صحة الجرد':'Audit Health'}</div><div class="hi-sub">${IS_AR?totalConfirmed+' مؤكّد من '+totalAssets+' أصل — '+totalMissing+' مفقود — '+als.length+' تنبيه':totalConfirmed+'/'+totalAssets+' confirmed — '+totalMissing+' missing — '+als.length+' alerts'}</div></div>
    <div style="flex-shrink:0;font-size:24px;font-weight:900;font-family:Inter;color:${hColor}">${healthPct}%</div></div>`;

  /* Quick stats */
  $('quickStats').innerHTML=`
    <div class="sum-card"><div class="icon icon-purple"><i class="fa-solid fa-users"></i></div><div class="val eng">${ms.length}</div><div class="label">${IS_AR?'أعضاء':'Members'}</div></div>
    <div class="sum-card"><div class="icon icon-green"><i class="fa-solid fa-bolt"></i></div><div class="val eng">${activeCount}</div><div class="label">${IS_AR?'نشطون':'Active'}</div></div>
    <div class="sum-card"><div class="icon icon-blue"><i class="fa-solid fa-door-open"></i></div><div class="val eng">${completedRooms}/${totalRooms}</div><div class="label">${IS_AR?'غرف':'Rooms'}</div></div>
    <div class="sum-card"><div class="icon icon-green"><i class="fa-solid fa-box-open"></i></div><div class="val eng">${totalAssets}</div><div class="label">${IS_AR?'أصول':'Assets'}</div></div>
    <div class="sum-card"><div class="icon icon-red"><i class="fa-solid fa-triangle-exclamation"></i></div><div class="val eng">${totalMissing}</div><div class="label">${IS_AR?'مفقود':'Missing'}</div></div>
    <div class="sum-card"><div class="icon icon-amber"><i class="fa-solid fa-clock"></i></div><div class="val eng">${fmtSec(totalCum)}</div><div class="label">${IS_AR?'الوقت الكلي':'Total Time'}</div></div>`;

  /* Summary members mini */
  $('summaryMembers').innerHTML='<div style="font-weight:900;font-size:13px;color:#0f172a;margin-bottom:10px;">'+(IS_AR?'تقدم الأعضاء':'Member Progress')+'</div>'+ms.map(m=>{
    const s=m.stats||{};
    const pct=s.total?Math.round((s.confirmed||0)*100/s.total):0;
    return `<div style="background:#fff;border-radius:10px;border:1px solid #e2e8f0;padding:10px 12px;margin-bottom:6px;display:flex;align-items:center;gap:10px;">
      <span style="font-size:12px;font-weight:900;color:#0f172a;flex:1;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">${esc(m.name)}</span>
      <div class="prog" style="flex:2;margin:0;"><div class="fg" style="width:${pct}%;background:${pct>=80?'#10b981':pct>=50?'#f59e0b':'#dc2626'}"></div></div>
      <span class="eng" style="font-size:11px;font-weight:900;color:#475569;min-width:32px;text-align:end;">${pct}%</span>
      ${m.suspended?'<span class="badge b-red">معلّق</span>':''}
    </div>`;
  }).join('')||'<div class="empty">—</div>';

  /* ─── Members ─── */
  $('membersList').innerHTML=ms.map(m=>{
    const L=m.lock,s=m.stats||{},al=m.alerts||[];
    let badges='';
    if(m.suspended)badges+='<span class="badge b-red">⛔ معلّق</span>';
    if(L)badges+='<span class="badge b-green">🚪 '+esc(L.room)+'</span>';
    if(al.includes('expiring'))badges+='<span class="badge b-amber">⏰ '+('ينتهي قريباً')+'</span>';
    if(al.includes('idle'))badges+='<span class="badge b-amber">⏳ '+('خامل '+m.idle_min+'د')+'</span>';
    return `<div class="mc fade-in ${m.suspended?'susp':''}" onclick="showMemberDetail(${m.user_id})" style="cursor:pointer;">
    <div class="mc-head"><span class="name">${esc(m.name)}</span>${badges}</div>
    <div class="mc-stats">
    <span><span class="num">${s.confirmed||0}</span>✅</span>
    <span><span class="num">${s.missing||0}</span>❌</span>
    <span><span class="num">${s.location_changed||0}</span>📍</span>
    <span><span class="num">${s.custody_changed||0}</span>🔁</span>
    <span><span class="num">${s.condition_damaged||0}</span>🔧</span>
    </div>
    <div class="mc-meta">
    <span>⏱ ${fmtSec(m.cumulative_sec||0)}</span>
    <span>🏁 ${m.completed_rooms} غرفة</span>
    <span>📊 ${(s.total||0)} إجراء</span>
    </div>
    <div class="mc-actions" onclick="event.stopPropagation()">
    ${L?`<button onclick="openKick(${m.user_id},${L.room_id},'${esc(L.room)}')">🚪 إخراج</button>
      <button onclick="radarExtend(${m.user_id})">⏱ تمديد</button>`:''}
    <button onclick="radarResetSuspends(${m.user_id})">🔄 إعادة تعيين</button>
    ${m.suspended?`<button class="ok" onclick="radarSuspend(${m.user_id},0)">✅ فك التعليق</button>`
                 :`<button class="warn" onclick="radarSuspend(${m.user_id},1,'${esc(m.name).replace(/'/g,"\\'")}')">⛔ تعليق</button>`}
    </div></div>`;
  }).join('')||'<div class="empty">لا أعضاء</div>';

  /* ─── Rooms ─── */
  $('roomsList').innerHTML=rms.map(r=>{
    const pct=r.total_assets?Math.round(r.audited*100/r.total_assets):0;
    const barColor=r.status==='completed'?'#10b981':pct>=80?'#10b981':pct>=40?'#f59e0b':'#dc2626';
    const stBadge=r.status==='completed'?'<span class="badge b-purple">✅ مكتملة</span>':r.status==='active'?'<span class="badge b-green">🟢 نشطة</span>':'<span class="badge b-amber">'+r.status+'</span>';
    return `<div class="rm fade-in ${r.status}" onclick="showRoomDetail(${r.room_id})" style="cursor:pointer;">
    <div class="rm-head"><span class="rm-name">${esc(r.name_en||r.name||'#'+r.room_id)}</span>${stBadge}</div>
    <div class="rm-meta"><span>👤 ${esc(r.locker_name||'—')}</span><span class="eng">⏱ ${fmtSec(r.locked_at?(Date.now()/1000-Date.parse(r.locked_at)/1000):0)}</span></div>
    <div class="rm-bar">
      <div class="bar-track"><div class="bar-fill" style="width:${pct}%;background:${barColor}"></div></div>
      <span class="bar-pct eng">${r.audited}/${r.total_assets} (${pct}%)</span>
    </div>
    <div class="rm-stats">
    <span><span class="num">${r.confirmed||0}</span>✅</span>
    <span><span class="num">${r.missing||0}</span>❌</span>
    <span><span class="num">${r.location_changed||0}</span>📍</span>
    <span><span class="num">${r.surplus||0}</span>📦</span>
    </div></div>`;
  }).join('')||'<div class="empty"><i class="fa-solid fa-door-open"></i> لا غرف مفتوحة</div>';

  /* ─── Events ─── */
  $('eventsList').innerHTML=(j.events||[]).map(e=>
    `<div class="ev-item fade-in"><span class="ev-name">${esc(e.full_name||'')}</span><span class="ev-act">${EV_LBL[e.event_type]||e.event_type} ${e.rname_en||e.rname?'· '+esc(e.rname_en||e.rname):''}</span><span class="ev-time eng">${esc((e.created_at||'').substr(11,5))}</span></div>`
  ).join('')||'<div class="empty">لا أحداث</div>';

  /* ─── Alerts ─── */
  $('alertCount').textContent=als.length;
  $('alertsList').innerHTML=als.map(a=>{
    const icon=a.type==='suspended'?'fa-ban':a.type==='idle'?'fa-clock':a.type==='expiring'?'fa-hourglass-half':a.type==='room_expiring'?'fa-door-open':a.type==='room_stuck'?'fa-triangle-exclamation':'fa-exclamation-circle';
    const lvlCls='al-'+a.level;
    return `<div class="al ${lvlCls} fade-in">
    <div class="al-icon"><i class="fa-solid ${icon}"></i></div>
    <div class="al-info"><div class="al-title">${esc(a.name)}</div><div class="al-msg">${esc(a.msg)}</div></div>
    ${a.user_id?`<button onclick="event.stopPropagation();openKick(${a.user_id},0,'')" style="border:1px solid #fecaca;background:#fef2f2;color:#dc2626;border-radius:8px;padding:4px 8px;font-size:10px;font-weight:800;cursor:pointer;flex-shrink:0;">${IS_AR?'إخراج':'Kick'}</button>`:''}
    </div>`;
  }).join('')||'<div class="empty"><i class="fa-solid fa-shield-check" style="color:#10b981"></i> '+('لا تنبيهات — كل شيء على ما يرام!')+'</div>';

  /* ─── Action Log ─── */
  $('actionLogList').innerHTML=(j.action_log||[]).map(e=>{
    const parts=(e.action||'').split('.');
    const shortAction=parts[parts.length-1]||e.action;
    const lbl=ACTION_LBL[shortAction]||shortAction;
    const target=(e.target||'').replace(/session:\d+\s*/,'').replace(/user:(\d+)/,' #$1').trim();
    const reason=esc(e.details||'');
    const actor=esc(e.full_name||'—');
    const time=esc((e.created_at||'').substr(11,5));
    return `<div class="ev-item fade-in"><span class="badge b-amber" style="font-size:10px;flex-shrink:0;">${lbl}</span><span class="ev-act" style="flex:1;min-width:0;"><span class="ev-name">${actor}</span> · ${target}${reason?' — '+reason:''}</span><span class="ev-time eng">${time}</span></div>`;
  }).join('')||'<div class="empty"><i class="fa-solid fa-clipboard-check"></i> '+('لا إجراءات بعد')+'</div>';
}

/* ─── Drill-down: Member ─── */
function showMemberDetail(uid){
  const m=(radarData||{}).members?.find(x=>x.user_id===uid);
  if(!m)return;
  const s=m.stats||{};
  const L=m.lock;
  $('memberDetail').innerHTML=`<div class="dp-head">
    <span class="dp-name">${esc(m.name)}</span>
    <button class="dp-close" onclick="$('memberDetail').classList.remove('show')"><i class="fa-solid fa-xmark"></i></button>
  </div>
  <div class="detail-grid">
    <div class="dg-item"><div class="dg-val eng">${s.confirmed||0}</div><div class="dg-lbl">${IS_AR?'مؤكّد':'Confirmed'}</div></div>
    <div class="dg-item"><div class="dg-val eng" style="color:#dc2626">${s.missing||0}</div><div class="dg-lbl">${IS_AR?'مفقود':'Missing'}</div></div>
    <div class="dg-item"><div class="dg-val eng" style="color:#3b82f6">${s.location_changed||0}</div><div class="dg-lbl">${IS_AR?'تحول موقع':'Loc Changed'}</div></div>
    <div class="dg-item"><div class="dg-val eng" style="color:#f59e0b">${s.custody_changed||0}</div><div class="dg-lbl">${IS_AR?'تحول عهدة':'Custody'}</div></div>
    <div class="dg-item"><div class="dg-val eng" style="color:#ef4444">${s.condition_damaged||0}</div><div class="dg-lbl">${IS_AR?'متضرر':'Damaged'}</div></div>
    <div class="dg-item"><div class="dg-val eng">${fmtSec(m.cumulative_sec||0)}</div><div class="dg-lbl">${IS_AR?'الوقت':'Time'}</div></div>
  </div>
  <div style="margin-top:10px;font-size:12px;color:#64748b;font-weight:700;">
    ${L?'<i class="fa-solid fa-door-open"></i> '+esc(L.room)+' — منذ '+(L.since||'').substr(11,5):'<i class="fa-solid fa-door-closed"></i> '+IS_AR?'ليس في غرفة':'Not in room'}
    ${m.suspended?'<span class="badge b-red" style="margin-inline-start:6px;">معلّق</span>':''}
  </div>`;
  $('memberDetail').classList.add('show');
}

/* ─── Drill-down: Room ─── */
function showRoomDetail(rid){
  const r=(radarData||{}).rooms?.find(x=>x.room_id===rid);
  if(!r)return;
  const pct=r.total_assets?Math.round(r.audited*100/r.total_assets):0;
  $('roomDetail').innerHTML=`<div class="dp-head">
    <span class="dp-name">${esc(r.name_en||r.name||'#'+r.room_id)}</span>
    <button class="dp-close" onclick="$('roomDetail').classList.remove('show')"><i class="fa-solid fa-xmark"></i></button>
  </div>
  <div class="detail-grid">
    <div class="dg-item"><div class="dg-val eng">${r.total_assets}</div><div class="dg-lbl">${IS_AR?'أصول':'Assets'}</div></div>
    <div class="dg-item"><div class="dg-val eng" style="color:#10b981">${r.audited}</div><div class="dg-lbl">${IS_AR?'تم جرده':'Audited'}</div></div>
    <div class="dg-item"><div class="dg-val eng" style="color:#dc2626">${r.missing||0}</div><div class="dg-lbl">${IS_AR?'مفقود':'Missing'}</div></div>
    <div class="dg-item"><div class="dg-val eng" style="color:#3b82f6">${r.location_changed||0}</div><div class="dg-lbl">${IS_AR?'موقع':'Location'}</div></div>
    <div class="dg-item"><div class="dg-val eng" style="color:#f59e0b">${r.custody_changed||0}</div><div class="dg-lbl">${IS_AR?'عهدة':'Custody'}</div></div>
    <div class="dg-item"><div class="dg-val eng">${pct}%</div><div class="dg-lbl">${IS_AR?'التقدم':'Progress'}</div></div>
  </div>
  <div style="margin-top:10px;font-size:12px;color:#64748b;font-weight:700;">
    <i class="fa-solid fa-user"></i> ${esc(r.locker_name||'—')}
    — ${IS_AR?'الحالة':'Status'}: ${r.status}
    ${r.completed_at?' — '+IS_AR?'أُغلقت':'Closed'+' '+r.completed_at.substr(11,5):''}
  </div>`;
  $('roomDetail').classList.add('show');
}

/* ─── Actions ─── */
async function radarPost(d){d.session_id=SID;
  try{
  const r=await fetch(BASE+'/inventory/api/session_radar.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify(d)});
  const j=await r.json();if(!j.ok)alert('⚠ '+(j.error||j.msg||''));radarLoad();
  }catch(e){alert('⚠ '+(IS_AR?'فشل الاتصال':'Connection failed'));radarLoad();}}

function openKick(uid,rid,room){
  $('kickInfo').innerHTML=(IS_AR?'إخراج من ':'Kick from ')+room;
  $('kickBlock').checked=false;$('kickReason').value='';
  $('kickModal').classList.add('show');
  kickUserId=uid;kickRoomId=rid;
}
let kickUserId=null,kickRoomId=null;
function doKick(){
  const reason=$('kickReason').value.trim();
  if(!reason){alert(IS_AR?'السبب إلزامي':'Required');return;}
  $('kickModal').classList.remove('show');
  radarPost({action:'kick',user_id:kickUserId,room_id:kickRoomId,block:$('kickBlock').checked?1:0,reason});
}
let suspUserId=null;
function radarSuspend(u,on,name){
  if(!on){radarPost({action:'unsuspend',user_id:u});return;}
  suspUserId=u;
  $('suspendInfo').innerHTML=(IS_AR?'تعليق ':'Suspend ')+(name||'');
  $('suspendReason').value='';
  $('suspendModal').classList.add('show');
}
function doSuspend(){
  const reason=$('suspendReason').value.trim();
  if(!reason){alert(IS_AR?'السبب إلزامي':'Required');return;}
  $('suspendModal').classList.remove('show');
  radarPost({action:'suspend',user_id:suspUserId,reason});
}
function radarExtend(u){radarPost({action:'extend',user_id:u,minutes:30});}
function radarResetSuspends(u){if(confirm(IS_AR?'إعادة تعيين التعليقات؟':'Reset suspends?'))radarPost({action:'reset_suspends',user_id:u});}

radarLoad();
setInterval(radarLoad,10000);
</script>
</body>
</html>
