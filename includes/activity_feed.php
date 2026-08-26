<?php if (!defined('ACTIVITY_LOADED')): define('ACTIVITY_LOADED', 1);
/* ═══ بوابة الرادار: يظهر فقط لمن يملك صلاحية واحدة على الأقل ═══ */
$_af_show = is_admin()
    || can('complaints.index','view')
    || can('work_orders.index','view')
    || can('assets.index','view')
    || can('inventory.index','view')
    || can('custody.center','view');
if (!$_af_show) return; // لا زر ولا لوحة لمن لا يملك صلاحية
?>
<style>
#af-toggle{position:fixed;left:20px;top:50%;transform:translateY(-50%);z-index:1200;
  width:48px;height:48px;border-radius:50%;border:1px solid rgba(148,163,184,.2);
  background:rgba(15,23,42,.9);backdrop-filter:blur(10px);color:#38bdf8;font-size:18px;
  cursor:pointer;box-shadow:0 8px 24px rgba(2,8,20,.3);transition:.25s}
#af-toggle:hover{transform:translateY(-50%) scale(1.1)}
#af-toggle .badge{position:absolute;top:-4px;right:-4px;width:20px;height:20px;border-radius:50%;
  background:#ef4444;color:#fff;font-size:11px;font-weight:900;display:flex;align-items:center;justify-content:center}
#af-panel{position:fixed;left:-380px;top:0;bottom:0;width:360px;z-index:1201;
  background:rgba(15,23,42,.95);backdrop-filter:blur(20px);border-right:1px solid rgba(148,163,184,.15);
  box-shadow:10px 0 40px rgba(2,8,20,.5);transition:.35s cubic-bezier(.2,.9,.3,1);overflow-y:auto}
#af-panel.open{left:0}
#af-panel::-webkit-scrollbar{width:6px}
#af-panel::-webkit-scrollbar-thumb{background:rgba(148,163,184,.25);border-radius:99px}
#af-head{padding:20px;border-bottom:1px solid rgba(148,163,184,.15);display:flex;align-items:center;gap:12px;
  position:sticky;top:0;background:rgba(15,23,42,.95);backdrop-filter:blur(20px);z-index:1}
#af-head h2{font-size:18px;font-weight:900;color:#e2e8f0;margin:0}
#af-head .dot{width:8px;height:8px;border-radius:50%;background:#22c55e;animation:afPulse 2s infinite}
@keyframes afPulse{0%,100%{opacity:1}50%{opacity:.4}}
#af-close{margin-inline-start:auto;width:32px;height:32px;border-radius:8px;border:1px solid rgba(148,163,184,.2);
  background:rgba(255,255,255,.05);color:#94a3b8;cursor:pointer;transition:.2s}
#af-close:hover{background:rgba(255,255,255,.1);color:#e2e8f0}
#af-list{padding:12px}
.af-item{padding:14px;border-radius:12px;margin-bottom:8px;cursor:pointer;transition:.2s;
  display:flex;gap:12px;align-items:flex-start;text-decoration:none}
.af-item:hover{background:rgba(148,163,184,.08);transform:translateX(4px)}
.af-item .ic{width:40px;height:40px;border-radius:10px;display:flex;align-items:center;justify-content:center;
  font-size:16px;flex-shrink:0}
.af-item .tx{flex:1;min-width:0}
.af-item .tx b{display:block;font-size:13px;font-weight:800;color:#e2e8f0;margin-bottom:3px}
.af-item .tx small{display:block;font-size:11.5px;color:#94a3b8;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.af-item .tm{font-size:10px;color:#64748b;font-weight:700;flex-shrink:0;margin-top:4px}
#af-empty{padding:40px;text-align:center;color:#475569}
@media(max-width:800px){#af-toggle{display:none}#af-panel{display:none}}
</style>

<button id="af-toggle"><i class="fa-solid fa-bolt"></i><span class="badge" id="af-badge">0</span></button>

<div id="af-panel">
  <div id="af-head">
    <span class="dot"></span>
    <h2>الرادار</h2>
    <button id="af-close"><i class="fa-solid fa-xmark"></i></button>
  </div>
  <div id="af-list"></div>
</div>

<script>
(function(){
  const BASE=<?= json_encode(BASE_URL) ?>;
  const tog=document.getElementById('af-toggle'),
        pan=document.getElementById('af-panel'),
        cls=document.getElementById('af-close'),
        list=document.getElementById('af-list'),
        badge=document.getElementById('af-badge');

  if(localStorage.getItem('af_open')==='1')pan.classList.add('open');
  tog.onclick=()=>{pan.classList.toggle('open');localStorage.setItem('af_open',pan.classList.contains('open')?'1':'0');};
  cls.onclick=()=>{pan.classList.remove('open');localStorage.setItem('af_open','0');};

  function load(){
    fetch(BASE+'/api/activity_feed.php').then(r=>r.json()).then(events=>{
      if(!events.length){list.innerHTML='<div id="af-empty">لا نشاط بعد</div>';badge.textContent='0';return;}
      badge.textContent=events.length;
      list.innerHTML=events.map(e=>`<a class="af-item" href="${e.url}">
        <div class="ic" style="background:${hex(e.color,.15)};color:${e.color}"><i class="fa-solid ${e.icon}"></i></div>
        <div class="tx"><b>${e.title}</b><small>${e.desc}</small></div>
        <span class="tm">${e.ago}</span></a>`).join('');
    }).catch(()=>{});
  }
  function hex(h,a){const n=parseInt(h.slice(1),16);return `rgba(${n>>16&255},${n>>8&255},${n&255},${a})`;}

  load();
  setInterval(load,30000); // تحديث كل 30 ثانية
})();
</script>
<?php endif; ?>