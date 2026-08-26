<?php if (!defined('CP_LOADED')): define('CP_LOADED', 1); ?>
<style>
/* ═══ Command Palette — زجاج داكن ═══ */
#cp-fab{position:fixed;bottom:22px;inset-inline-start:22px;z-index:999;
  width:52px;height:52px;border-radius:16px;border:1px solid rgba(56,189,248,.4);
  background:linear-gradient(135deg,#0ea5e9,#6366f1);color:#fff;font-size:18px;
  cursor:pointer;box-shadow:0 10px 30px rgba(14,165,233,.35);transition:.25s}
#cp-fab:hover{transform:translateY(-3px) scale(1.04)}
#cp-fab kbd{font-size:9px;font-weight:800;opacity:.85}
#cp-overlay{position:fixed;inset:0;z-index:1000;display:none;
  background:rgba(2,8,20,.55);backdrop-filter:blur(6px);
  justify-content:center;align-items:flex-start;padding-top:12vh}
#cp-overlay.open{display:flex}
#cp-box{width:min(640px,92vw);background:rgba(15,23,42,.92);backdrop-filter:blur(20px);
  border:1px solid rgba(148,163,184,.2);border-radius:18px;overflow:hidden;
  box-shadow:0 30px 80px rgba(2,8,20,.6);animation:cpIn .25s cubic-bezier(.2,.9,.3,1.2)}
@keyframes cpIn{from{opacity:0;transform:translateY(-14px) scale(.97)}to{opacity:1;transform:none}}
#cp-head{display:flex;align-items:center;gap:12px;padding:16px 18px;border-bottom:1px solid rgba(148,163,184,.15)}
#cp-head i{color:#38bdf8;font-size:16px}
#cp-input{flex:1;background:transparent;border:none;outline:none;color:#e2e8f0;
  font-size:16px;font-family:'Tajawal';font-weight:600}
#cp-input::placeholder{color:#475569}
#cp-kbd{font-size:10px;color:#64748b;border:1px solid rgba(148,163,184,.25);
  padding:3px 8px;border-radius:6px;font-weight:800}
#cp-list{max-height:46vh;overflow-y:auto;padding:8px}
#cp-list::-webkit-scrollbar{width:5px}
#cp-list::-webkit-scrollbar-thumb{background:rgba(148,163,184,.25);border-radius:99px}
.cp-group{padding:10px 12px 4px;font-size:10.5px;font-weight:800;letter-spacing:1px;
  color:#64748b;text-transform:uppercase}
.cp-item{display:flex;align-items:center;gap:12px;padding:11px 12px;border-radius:12px;
  cursor:pointer;color:#cbd5e1;transition:.15s}
.cp-item .ic{width:36px;height:36px;border-radius:10px;flex-shrink:0;display:flex;
  align-items:center;justify-content:center;font-size:15px;
  background:rgba(56,189,248,.12);color:#38bdf8;border:1px solid rgba(56,189,248,.2)}
.cp-item .tx{flex:1;min-width:0}
.cp-item .tx b{display:block;font-size:14px;font-weight:700;color:#e2e8f0}
.cp-item .tx small{display:block;font-size:11px;color:#64748b;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.cp-item .go{color:#38bdf8;font-size:12px;opacity:0;transition:.15s}
.cp-item.sel{background:rgba(56,189,248,.12)}
.cp-item.sel .go{opacity:1}
.cp-item .crit{font-size:10px;font-weight:800;padding:2px 8px;border-radius:6px}
#cp-foot{display:flex;gap:16px;padding:10px 18px;border-top:1px solid rgba(148,163,184,.15);
  font-size:11px;color:#64748b}
#cp-foot kbd{border:1px solid rgba(148,163,184,.25);padding:1px 6px;border-radius:5px;font-weight:800}
#cp-empty{padding:30px;text-align:center;color:#475569;font-size:13px}
</style>

<button id="cp-fab" title="لوحة الأوامر (Ctrl+K)"><i class="fa-solid fa-magnifying-glass"></i></button>

<div id="cp-overlay">
  <div id="cp-box">
    <div id="cp-head">
      <i class="fa-solid fa-bolt"></i>
      <input id="cp-input" placeholder="اكتب أمراً، صفحة، أو تاج أصل..." autocomplete="off">
      <span id="cp-kbd">ESC</span>
    </div>
    <div id="cp-list"></div>
    <div id="cp-foot">
      <span><kbd>↑↓</kbd> تنقل</span><span><kbd>Enter</kbd> فتح</span>
      <span><kbd>Ctrl+K</kbd> فتح/إغلاق</span>
    </div>
  </div>
</div>

<script>
(function(){
  const BASE=<?= json_encode(BASE_URL) ?>;
  const ov=document.getElementById('cp-overlay'), inp=document.getElementById('cp-input'),
        list=document.getElementById('cp-list'), fab=document.getElementById('cp-fab');
  let items=[], sel=0, fetchTimer=null;

  const norm=s=>(s||'').toLowerCase().replace(/[\u064B-\u0652]/g,'')
      .replace(/[أإ]/g,'ا').replace(/ة/g,'ه').trim();

  // ═══ القائمة الثابتة: تنقل + إجراءات ═══
  const NAV=[
    {ic:'fa-gauge-high',t:'لوحة التحكم',s:'Dashboard',u:BASE+'/dashboard.php',k:'لوحه تحكم رئيسيه dashboard home'},
    {ic:'fa-boxes-stacked',t:'قائمة الأصول',s:'Assets',u:BASE+'/assets/index.php',k:'اصول assets قائمه'},
    {ic:'fa-chart-bar',t:'تقرير الأصول',s:'Assets Report',u:BASE+'/reports/assets/overview.php',k:'تقرير اصول report'},
    {ic:'fa-handshake',t:'تقرير العهدة',s:'Custody',u:BASE+'/reports/custody/overview.php',k:'عهده custody'},
    {ic:'fa-bell',t:'تحليل البلاغات',s:'Complaints',u:BASE+'/reports/complaints/overview.php',k:'بلاغات شكاوي'},
    {ic:'fa-screwdriver-wrench',t:'تحليل الصيانة',s:'Maintenance',u:BASE+'/reports/maintenance/overview.php',k:'صيانه وقائيه'},
    {ic:'fa-clipboard-list',t:'تحليل الجرد',s:'Inventory',u:BASE+'/reports/inventory/overview.php',k:'جرد مطابقه'},
    {ic:'fa-truck-ramp-box',t:'الاستلام والتشغيل',s:'Receiving',u:BASE+'/reports/receiving/index.php',k:'استلام تشغيل توريد'},
    {ic:'fa-trash-can',t:'تحليل التخلص',s:'Disposal',u:BASE+'/reports/disposal/overview.php',k:'تخلص تكهين اتلاف'},
    {ic:'fa-triangle-exclamation',t:'تحليل المخاطر',s:'Risk',u:BASE+'/reports/risk/distribution.php',k:'مخاطر risk'},
    {ic:'fa-headset',t:'تحليل التذاكر',s:'Helpdesk',u:BASE+'/reports/helpdesk/overview.php',k:'تذاكر helpdesk'},
  ];
  const ACT=[
    {ic:'fa-expand',t:'ملء الشاشة',s:'Fullscreen',run:()=>{document.fullscreenElement?document.exitFullscreen():document.documentElement.requestFullscreen();}},
    {ic:'fa-print',t:'طباعة الصفحة الحالية',s:'Print',run:()=>window.print()},
    {ic:'fa-link',t:'نسخ رابط الصفحة',s:'Copy URL',run:()=>{navigator.clipboard.writeText(location.href);}},
    {ic:'fa-rotate-left',t:'تحديث الصفحة',s:'Reload',run:()=>location.reload()},
  ];

  function open(){ov.classList.add('open');inp.value='';render('');setTimeout(()=>inp.focus(),50);}
  function close(){ov.classList.remove('open');}
  function toggle(){ov.classList.contains('open')?close():open();}

  fab.onclick=toggle;
  ov.addEventListener('mousedown',e=>{if(e.target===ov)close();});
  document.addEventListener('keydown',e=>{
    if((e.ctrlKey||e.metaKey)&&e.key.toLowerCase()==='k'){e.preventDefault();toggle();return;}
    if(!ov.classList.contains('open'))return;
    if(e.key==='Escape')close();
    else if(e.key==='ArrowDown'){e.preventDefault();sel=Math.min(items.length-1,sel+1);paint();}
    else if(e.key==='ArrowUp'){e.preventDefault();sel=Math.max(0,sel-1);paint();}
    else if(e.key==='Enter'){e.preventDefault();if(items[sel])pick(items[sel]);}
  });

  inp.addEventListener('input',()=>{
    clearTimeout(fetchTimer);
    fetchTimer=setTimeout(()=>render(inp.value),180);
  });

  function match(item,q){return !q||norm(item.t).includes(q)||norm(item.s).includes(q)||norm(item.k||'').includes(q);}

  function render(q){
    const nq=norm(q); items=[]; let html='';
    const nav=NAV.filter(i=>match(i,nq));
    const act=ACT.filter(i=>match(i,nq));
    if(nav.length){html+='<div class="cp-group">تنقّل سريع</div>';
      nav.forEach(i=>{items.push(i);html+=row(i,items.length-1);});}
    if(act.length){html+='<div class="cp-group">إجراءات</div>';
      act.forEach(i=>{items.push(i);html+=row(i,items.length-1);});}
    // أصول (AJAX)
    html+='<div id="cp-assets"></div>';
    list.innerHTML=html||'<div id="cp-empty">لا نتائج</div>';
    sel=0;paint();
    if(nq.length>=2){
      fetch(BASE+'/api/cp_search.php?q='+encodeURIComponent(q))
        .then(r=>r.json()).then(rows=>{
          const box=document.getElementById('cp-assets');if(!box)return;
          if(!rows.length)return;
          let h='<div class="cp-group">الأصول</div>';
          rows.forEach(r=>{
            const it={ic:'fa-box',t:r.tag_number,s:(r.description_ar||r.description||''),
              u:BASE+'/assets/view.php?id='+r.id,
              crit:r.criticality_class,hs:r.health_score};
            items.push(it);h+=row(it,items.length-1,true);
          });
          box.innerHTML=h;paint();
        }).catch(()=>{});
    }
  }

  function row(i,idx,isAsset){
    const crit=i.crit?`<span class="crit" style="background:${i.crit==='A'?'#fee2e2;color:#dc2626':i.crit==='B'?'#fef3c7;color:#d97706':'#dcfce7;color:#16a34a'}">${i.crit}</span>`:'';
    return `<div class="cp-item" data-i="${idx}">
      <div class="ic"><i class="fa-solid ${i.ic}"></i></div>
      <div class="tx"><b>${i.t}</b><small>${i.s||''}</small></div>
      ${crit}<span class="go"><i class="fa-solid fa-arrow-left"></i></span></div>`;
  }

  function paint(){
    list.querySelectorAll('.cp-item').forEach(el=>{
      el.classList.toggle('sel',+el.dataset.i===sel);
    });
    const s=list.querySelector('.cp-item.sel');if(s)s.scrollIntoView({block:'nearest'});
  }

  list.addEventListener('click',e=>{
    const el=e.target.closest('.cp-item');if(!el)return;
    pick(items[+el.dataset.i]);
  });
  list.addEventListener('mousemove',e=>{
    const el=e.target.closest('.cp-item');if(!el)return;
    sel=+el.dataset.i;paint();
  });

  function pick(i){close();if(i.run)i.run();else if(i.u)location.href=i.u;}
})();
</script>
<?php endif; ?>