<?php if (!defined('PULSE_LOADED')): define('PULSE_LOADED', 1); ?>
<style>
#pulse-dock{position:fixed;bottom:18px;left:50%;transform:translateX(-50%);z-index:900;
  display:flex;align-items:center;gap:6px;padding:8px 12px;
  background:rgba(15,23,42,.85);backdrop-filter:blur(16px);
  border:1px solid rgba(148,163,184,.2);border-radius:99px;
  box-shadow:0 14px 40px rgba(2,8,20,.45);transition:.3s}
#pulse-dock.hidden{transform:translate(-50%,90px);opacity:0;pointer-events:none}
.pd-item{display:flex;align-items:center;gap:7px;padding:6px 11px;border-radius:99px;cursor:pointer;transition:.2s;text-decoration:none}
.pd-item:hover{background:rgba(148,163,184,.12)}
.pd-item i{font-size:13px}
.pd-item b{font-size:14px;font-weight:800;color:#e2e8f0}
.pd-item small{font-size:10px;color:#64748b;font-weight:700}
.pd-sep{width:1px;height:22px;background:rgba(148,163,184,.18)}
#pd-beacon{width:9px;height:9px;border-radius:50%;background:#22c55e;flex-shrink:0}
#pd-beacon.danger{background:#ef4444;animation:pdPulse 1.2s infinite}
@keyframes pdPulse{0%{box-shadow:0 0 0 0 rgba(239,68,68,.5)}70%{box-shadow:0 0 0 9px rgba(239,68,68,0)}100%{box-shadow:0 0 0 0 rgba(239,68,68,0)}}
#pd-spark{width:64px;height:24px}
#pd-trend{font-size:10px;font-weight:900}
/* اللوحة المنبثقة */
#pd-pop{position:fixed;bottom:76px;left:50%;transform:translateX(-50%) translateY(10px);z-index:901;
  width:320px;max-height:300px;overflow-y:auto;background:rgba(15,23,42,.95);backdrop-filter:blur(20px);
  border:1px solid rgba(148,163,184,.2);border-radius:16px;box-shadow:0 20px 50px rgba(2,8,20,.5);
  opacity:0;pointer-events:none;transition:.25s}
#pd-pop.open{opacity:1;pointer-events:auto;transform:translateX(-50%) translateY(0)}
#pd-pop::-webkit-scrollbar{width:5px}#pd-pop::-webkit-scrollbar-thumb{background:rgba(148,163,184,.25);border-radius:99px}
.pd-pop-h{padding:12px 16px;border-bottom:1px solid rgba(148,163,184,.15);font-size:12px;font-weight:800;color:#e2e8f0;
  display:flex;justify-content:space-between;position:sticky;top:0;background:rgba(15,23,42,.95)}
.pd-al{display:flex;gap:10px;align-items:center;padding:10px 16px;text-decoration:none;transition:.15s}
.pd-al:hover{background:rgba(148,163,184,.1)}
.pd-al i{width:30px;height:30px;border-radius:8px;display:flex;align-items:center;justify-content:center;font-size:12px;flex-shrink:0}
.pd-al b{display:block;font-size:12px;color:#e2e8f0}
.pd-al small{font-size:10.5px;color:#94a3b8}
#pd-toggle{position:fixed;bottom:18px;left:50%;transform:translateX(-50%);z-index:899;
  width:40px;height:26px;border-radius:99px;border:1px solid rgba(148,163,184,.25);
  background:rgba(15,23,42,.85);color:#94a3b8;font-size:10px;cursor:pointer;display:none}
#pd-toggle.show{display:block}
@media(max-width:900px){#pulse-dock{display:none}#pd-pop{display:none}}
</style>

<div id="pd-pop"><div class="pd-pop-h"><span>تنبيهات لحظية</span><span id="pd-trend"></span></div><div id="pd-list"></div></div>

<div id="pulse-dock">
  <span id="pd-beacon"></span>
  <a class="pd-item" href="<?= BASE_URL ?>/reports/maintenance/overview.php"><i class="fa-solid fa-screwdriver-wrench" style="color:#38bdf8"></i><b id="v-wo">0</b><small>أعمال</small></a>
  <a class="pd-item" href="<?= BASE_URL ?>/reports/complaints/overview.php"><i class="fa-solid fa-bolt" style="color:#f59e0b"></i><b id="v-crit">0</b><small>حرجة</small></a>
  <a class="pd-item" href="<?= BASE_URL ?>/reports/complaints/overview.php"><i class="fa-solid fa-stopwatch" style="color:#f43f5e"></i><b id="v-sla">0</b><small>SLA</small></a>
  <a class="pd-item" href="<?= BASE_URL ?>/reports/maintenance/overview.php"><i class="fa-solid fa-calendar-xmark" style="color:#a78bfa"></i><b id="v-pm">0</b><small>PM</small></a>
  <a class="pd-item" href="<?= BASE_URL ?>/reports/custody/overview.php"><i class="fa-solid fa-handshake" style="color:#34d399"></i><b id="v-noemp">0</b><small>بلا عهدة</small></a>
  <a class="pd-item" href="<?= BASE_URL ?>/reports/inventory/overview.php"><i class="fa-solid fa-clipboard-check" style="color:#22d3ee"></i><b id="v-inv">0</b><small>جرد</small></a>
  <span class="pd-sep"></span>
  <canvas id="pd-spark" title="بلاغات آخر 7 أيام"></canvas>
  <span class="pd-sep"></span>
  <button class="pd-item" id="pd-expand" style="border:none;background:none" title="تنبيهات"><i class="fa-solid fa-chevron-up"></i></button>
  <button class="pd-item" id="pd-hide" style="border:none;background:none"><i class="fa-solid fa-chevron-down"></i></button>
</div>
<button id="pd-toggle"><i class="fa-solid fa-wave-square"></i></button>

<script>
(function(){
  const BASE=<?= json_encode(BASE_URL) ?>;
  const dock=document.getElementById('pulse-dock'), tog=document.getElementById('pd-toggle'),
        beacon=document.getElementById('pd-beacon'), cv=document.getElementById('pd-spark'),
        pop=document.getElementById('pd-pop'), list=document.getElementById('pd-list'),
        trend=document.getElementById('pd-trend');

  if(localStorage.getItem('pd_hidden')==='1'){dock.classList.add('hidden');tog.classList.add('show');}
  document.getElementById('pd-hide').onclick=()=>{dock.classList.add('hidden');tog.classList.add('show');localStorage.setItem('pd_hidden','1');};
  tog.onclick=()=>{dock.classList.remove('hidden');tog.classList.remove('show');localStorage.setItem('pd_hidden','0');};
  document.getElementById('pd-expand').onclick=()=>pop.classList.toggle('open');

  function drawSpark(d){
    const ctx=cv.getContext('2d'),W=cv.width=64*2,H=cv.height=24*2;
    ctx.clearRect(0,0,W,H);
    const max=Math.max(...d,1),n=d.length;
    ctx.beginPath();
    d.forEach((v,i)=>{const x=(i/(n-1))*(W-8)+4,y=H-6-(v/max)*(H-12);i?ctx.lineTo(x,y):ctx.moveTo(x,y);});
    ctx.strokeStyle='#38bdf8';ctx.lineWidth=3;ctx.lineJoin='round';ctx.stroke();
    ctx.lineTo(W-4,H);ctx.lineTo(4,H);ctx.closePath();ctx.fillStyle='rgba(56,189,248,.15)';ctx.fill();
  }

  function load(){
    fetch(BASE+'/api/pulse.php').then(r=>r.json()).then(o=>{
      if(!o)return;
      document.getElementById('v-wo').textContent=o.wo;
      document.getElementById('v-crit').textContent=o.crit;
      document.getElementById('v-sla').textContent=o.sla;
      document.getElementById('v-pm').textContent=o.pm;
      document.getElementById('v-noemp').textContent=o.no_emp;
      document.getElementById('v-inv').textContent=o.inv;
      beacon.classList.toggle('danger',(o.crit>0||o.sla>0||o.pm>0));
      if(o.spark)drawSpark(o.spark);
      // الاتجاه
      const d=o.today-o.yest;
      trend.innerHTML = d>0?`<span style="color:#f87171">▲ +${d} اليوم</span>` : d<0?`<span style="color:#4ade80">▼ ${d} اليوم</span>` : `<span style="color:#94a3b8">— مستقر</span>`;
      // التنبيهات
      list.innerHTML = (o.alerts&&o.alerts.length)
        ? o.alerts.map(a=>`<a class="pd-al" href="${a.u}"><i style="background:${a.c}22;color:${a.c}" class="fa-solid ${a.ic}"></i><div><b>${a.t}</b><small>${a.d}</small></div></a>`).join('')
        : '<div style="padding:20px;text-align:center;color:#475569;font-size:12px">لا تنبيهات حالية ✔</div>';
    }).catch(()=>{});
  }
  load(); setInterval(load,45000);
})();
</script>
<?php endif; ?>