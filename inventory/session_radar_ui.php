<?php
/**
 * inventory/session_radar_ui.php — رادار مراقبة الجلسة (للمدير/المتحكم)
 * يُضمَّن أسفل session.php — يتحدث تلقائياً كل 15 ثانية
 */
if (!defined('PMSH_RADAR_UI')): define('PMSH_RADAR_UI', 1);
$radar_sid = (int)($_GET['id'] ?? 0);
$radar_can = is_admin() || (function_exists('can') && can('inventory.create','manage'));
if ($radar_sid && $radar_can):
?>
<style>
.radar-wrap{max-width:1200px;margin:18px auto;padding:0 14px}
.radar-card{background:#fff;border:2px solid #7c3aed;border-radius:18px;overflow:hidden;margin-bottom:20px}
.radar-head{background:linear-gradient(135deg,#5b21b6,#7c3aed);color:#fff;padding:14px 18px;display:flex;align-items:center;gap:10px;font-weight:900;font-size:15px}
.radar-head .dot{width:10px;height:10px;border-radius:50%;background:#4ade80;animation:rpulse 1.5s infinite}
@keyframes rpulse{0%,100%{opacity:1}50%{opacity:.3}}
.radar-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(280px,1fr));gap:12px;padding:14px}
.rm-card{border:1.5px solid #e2e8f0;border-radius:14px;padding:12px;background:#fafafa}
.rm-card.susp{background:#fef2f2;border-color:#fca5a5}
.rm-head{display:flex;flex-wrap:wrap;gap:6px;align-items:center;margin-bottom:8px}
.rm-head b{font-size:13.5px}
.rb{font-size:10px;font-weight:800;padding:2px 8px;border-radius:99px}
.rb-red{background:#fee2e2;color:#b91c1c}.rb-green{background:#dcfce7;color:#166534}.rb-amber{background:#fef3c7;color:#92400e}
.rm-stats{display:flex;flex-wrap:wrap;gap:6px;font-size:11px;font-weight:700;color:#475569;margin-bottom:6px}
.rm-meta{font-size:10.5px;color:#94a3b8;margin-bottom:8px}
.rm-actions{display:flex;flex-wrap:wrap;gap:5px}
.rm-actions button{border:1px solid #e2e8f0;background:#fff;border-radius:8px;padding:5px 9px;font-size:10.5px;font-weight:800;cursor:pointer;font-family:'Tajawal'}
.rm-actions button.warn{background:#fef2f2;color:#dc2626;border-color:#fecaca}
.rm-actions button.ok{background:#f0fdf4;color:#16a34a;border-color:#bbf7d0}
.radar-events{border-top:1.5px solid #f1f5f9;padding:12px 16px;max-height:180px;overflow-y:auto}
.re-item{font-size:11.5px;color:#475569;padding:4px 0;border-bottom:1px dashed #f1f5f9}
.re-item i{color:#94a3b8;font-style:normal;float:right}
.radar-empty{text-align:center;color:#94a3b8;padding:20px;font-size:12px}
</style>
<div class="radar-wrap">
<div class="radar-card">
<div class="radar-head"><span class="dot"></span><i class="fa-solid fa-tower-broadcast"></i> <?= is_rtl()?'رادار مراقبة الجلسة':'Session Monitoring Radar' ?>
<button onclick="radarLoad()" style="margin-inline-start:auto;background:rgba(255,255,255,.2);border:none;color:#fff;border-radius:8px;padding:5px 10px;cursor:pointer;font-family:'Tajawal'"><i class="fa-solid fa-rotate"></i> <?= is_rtl()?'تحديث':'Refresh' ?></button>
</div>
<div class="radar-grid" id="radarMembers"><div class="radar-empty"><?= is_rtl()?'جاري التحميل…':'Loading…' ?></div></div>
<div class="radar-events" id="radarEvents"></div>
</div>
</div>
<script>
window.RADAR_BASE='<?= BASE_URL ?>';
const RADAR_SID=<?= $radar_sid ?>;
window.IS_AR=<?= is_rtl()?'true':'false' ?>;
const rEsc=s=>(s==null?'':String(s)).replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
const EV_LBL={opened:window.IS_AR?'🔓 دخل الغرفة':'🚪 Entered',resumed:window.IS_AR?'▶️ استأنف':'▶️ Resumed',suspended:window.IS_AR?'⏸ علّق الغرفة':'⏸ Suspended',completed:window.IS_AR?'🔒 أكمل وأقفل':'🔒 Completed',taken_over:window.IS_AR?'🤝 تسلّم القفل':'🤝 Took Over',force_exited:window.IS_AR?'🚪 أُخرج بواسطة الإدارة':'🚪 Force Exited'};
function rFmtSec(s){s=Math.max(0,Math.floor(s));const h=Math.floor(s/3600),m=Math.floor(s%3600/60),x=s%60;return(window.IS_AR?(h?h+'س ':'')+m+'د':(h?h+'h ':'')+m+'m');}
async function radarLoad(){ try{ const r=await fetch(RADAR_BASE+'/inventory/api/session_radar.php?session_id='+RADAR_SID); const j=await r.json(); if(j.ok) radarRender(j); }catch(e){} }
function radarRender(j){
 document.getElementById('radarMembers').innerHTML = j.members.map(m=>{
  const L=m.lock, s=m.stats||{}, al=m.alerts||[];
  let badges='';
  if(m.suspended) badges+='<span class="rb rb-red">⛔ '+(window.IS_AR?'معلّق':'Suspended')+'</span>';
  if(L) badges+='<span class="rb rb-green">🚪 '+rEsc(L.room)+'</span>';
  if(al.includes('expiring')) badges+='<span class="rb rb-amber">⏰ '+(window.IS_AR?'القفل ينتهي قريباً':'Lock expiring')+'</span>';
  if(al.includes('idle')) badges+='<span class="rb rb-amber">⏳ '+(window.IS_AR?'خامل '+m.idle_min+'د':'Idle '+m.idle_min+'m')+'</span>';
  return '<div class="rm-card '+(m.suspended?'susp':'')+'">'
   +'<div class="rm-head"><b>'+rEsc(m.name)+'</b>'+badges+'</div>'
   +'<div class="rm-stats"><span>✅ '+(s.confirmed||0)+'</span><span>❌ '+(s.missing||0)+'</span><span>📍 '+(s.location_changed||0)+'</span><span>🔁 '+(s.custody_changed||0)+'</span><span>🔧 '+(s.condition_damaged||0)+'</span></div>'
   +'<div class="rm-meta">⏱ '+(window.IS_AR?'الوقت الكلي: ':'Total: ')+rFmtSec(m.cumulative_sec||0)+' · 🏁 '+m.completed_rooms+(window.IS_AR?' غرفة':' rooms')+' · '+(s.total||0)+(window.IS_AR?' إجراء':' actions')+' · '+(window.IS_AR?'آخر نشاط: ':'Last: ')+(m.idle_min!==null&&m.idle_min!==undefined?m.idle_min+(window.IS_AR?' د':'m'):'—')+'</div>'
   +'<div class="rm-actions">'
   +(L? '<button onclick="radarKick('+m.user_id+','+L.room_id+')">🚪 '+(window.IS_AR?'إخراج':'Kick')+'</button>'
      +'<button onclick="radarKickBlock('+m.user_id+','+L.room_id+')">🚫 '+(window.IS_AR?'إخراج+منع':'Kick+Block')+'</button>'
      +'<button onclick="radarExtend('+m.user_id+')">⏱ '+(window.IS_AR?'تمديد':'Extend')+'</button>':'')
   +'<button onclick="radarResetSuspends('+m.user_id+')">🔄 '+(window.IS_AR?'إعادة تعيين التعليقات':'Reset Suspends')+'</button>'
   +(m.suspended? '<button class="ok" onclick="radarSuspend('+m.user_id+',0)">✅ '+(window.IS_AR?'فك التعليق':'Unsuspend')+'</button>'
                : '<button class="warn" onclick="radarSuspend('+m.user_id+',1)">⛔ '+(window.IS_AR?'تعليق':'Suspend')+'</button>')
   +'</div></div>';
 }).join('') || '<div class="radar-empty">'+(window.IS_AR?'لا أعضاء':'No members')+'</div>';
 document.getElementById('radarEvents').innerHTML = j.events.map(e=>
  '<div class="re-item"><b>'+rEsc(e.full_name||'')+'</b> '+(EV_LBL[e.event_type]||e.event_type)+' '
  +(e.rname_en||e.rname? ' · '+rEsc(e.rname_en||e.rname):'')+' <i>'+rEsc((e.created_at||'').substr(11,5))+'</i></div>'
 ).join('') || '<div class="radar-empty">'+(window.IS_AR?'لا أحداث':'No events')+'</div>';
}
async function radarPost(d){ d.session_id=RADAR_SID;
 try{
 const r=await fetch(RADAR_BASE+'/inventory/api/session_radar.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify(d)});
 const j=await r.json(); if(!j.ok) alert('⚠ '+(j.error||j.msg||'')); radarLoad();
 }catch(e){ alert('⚠ '+(window.IS_AR?'فشل الاتصال بالخادم':'Server connection failed')); radarLoad(); } }
function radarKick(u,rid){ if(confirm(window.IS_AR?'إخراج العضو من الغرفة الآن؟':'Kick member from room now?')) radarPost({action:'kick',user_id:u,room_id:rid,block:0}); }
function radarKickBlock(u,rid){ if(confirm(window.IS_AR?'إخراج العضو ومنعه من هذه الغرفة؟':'Kick and block from this room?')) radarPost({action:'kick',user_id:u,room_id:rid,block:1}); }
function radarExtend(u){ radarPost({action:'extend',user_id:u,minutes:30}); }
function radarSuspend(u,on){ if(on?confirm(window.IS_AR?'تعليق العضو من الجلسة كاملة؟':'Suspend from entire session?'):true) radarPost({action:on?'suspend':'unsuspend',user_id:u}); }
function radarResetSuspends(u){ if(confirm(window.IS_AR?'إعادة تعيين عدد التعليقات؟ سيتمكن العضو من التعليق مرة أخرى':'Reset suspend count? Member will be able to suspend again')) radarPost({action:'reset_suspends',user_id:u}); }
setInterval(radarLoad,15000); radarLoad();
</script>
<?php endif; endif; ?>