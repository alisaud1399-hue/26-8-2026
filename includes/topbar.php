<?php
//include __DIR__ . '/shell_modern.php';
include __DIR__ . '/command_palette.php';
include __DIR__ . '/pulse_dock.php';
include __DIR__ . '/activity_feed.php';
// ⛔ أُزيل include assistant.php — لأنه API يُرجع JSON ولا يجوز تضمينه في الصفحات

// ... بقية الكود
/**
 * includes/topbar.php — شريط التنقل العلوي (ثنائي اللغة — بلا تكرار)
 */
$_nb     = unread_notifications_count();
$_user   = current_user();
$_rtl    = is_rtl();
$_ptitle = $page_title ?? ($rtl ? 'لوحة التحكم' : 'Dashboard');
$_picon  = $page_icon  ?? 'fa-gauge-high';
$_bc     = $breadcrumb ?? [];
$_prim   = $_user['primary_role'] ?? null;
$_uname  = $_rtl
    ? ($_user['full_name'] ?? '')
    : ($_user['full_name_en'] ?: ($_user['full_name'] ?? ''));
$_urole  = $_rtl
    ? ($_prim['display_name'] ?? '')
    : ($_prim['display_en']   ?? $_prim['display_name'] ?? '');

// قائمة المستخدم المنسدلة
$_menu_items = [
    ['icon'=>'fa-user-circle', 'ar'=>'الملف الشخصي',  'en'=>'My Profile',  'url'=> BASE_URL.'/profile.php',           'class'=>''],
    ['icon'=>'fa-gear',        'ar'=>'الإعدادات',       'en'=>'Settings',    'url'=> BASE_URL.'/settings/index.php',    'class'=>'', 'perm'=>'settings.index'],
    ['sep' => true],
    ['icon'=>'fa-right-from-bracket','ar'=>'تسجيل الخروج','en'=>'Sign Out', 'url'=> BASE_URL.'/auth/logout.php',       'class'=>'td-danger'],
];
?>
<style>
/* ═══ تحميل Font Awesome 6 (CDN) عبر @import ═══
   السبب: بعض الصفحات (مثل risk_assessment) لا تحمّل Font Awesome CSS
   فتحميله هنا يضمن ظهور كل الأيقونات في التوبار والسايدبار دائماً.
   @import يعمل حتى لو كان <style> داخل <body>. */
@import url("https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css");

/* ════════════════════════════════════════════════════════════════════════
   Topbar Font Shield — إجبار التوبار على خط Tajawal دائماً
   بدون هذا الدرع، بعض الصفحات (مثل تقييم المخاطر) تحمّل IBM Plex
   من Google Fonts وتغيّر خط التوبار، وتُخفي أيقونات Font Awesome
   ════════════════════════════════════════════════════════════════════════ */
.topbar, .topbar *,
.topbar .tb-uname, .topbar .tb-urole,
.topbar .bc-title, .topbar .bc-sub, .topbar .bc-icon,
.topbar .tb-w-temp, .topbar .tb-w-city, .topbar .tb-w-city-name, .topbar .tb-w-city-desc,
.topbar .tb-w-meta, .topbar .tb-w-source, .topbar .tb-w-alert-item,
.topbar #tbTime, .topbar #tbHijri, .topbar #tbGreg, .topbar #tbDayAr, .topbar #tbDayEn,
.topbar .td-hd-name, .topbar .td-hd-role, .topbar .td-item {
    font-family: 'Tajawal', 'Cairo', system-ui, sans-serif !important;
}
/* أرقام الساعة والتاريخ الميلادي تبقى Inter (مونوسبيس) */
.topbar #tbTime, .topbar #tbGreg, .topbar .tb-w-temp, .topbar .tb-w-city-temp {
    font-family: 'Inter', monospace !important;
}
/* درع أيقونات Font Awesome في التوبار */
.topbar i[class*="fa-"], .topbar i.fa-solid, .topbar i.fa-regular, .topbar i.fa-brands,
.topbar .td-item i, .topbar .tb-avatar i, .topbar .bc-icon i,
.topbar .tb-w-city-ico i, .topbar .tb-w-alert-item i, .topbar .fa,
.topbar .bellDropdown i, .topbar #bellDropdown i, .topbar #bellList i {
    font-family: 'Font Awesome 6 Free', 'Font Awesome 6 Brands' !important;
    font-style: normal !important;
    font-weight: 900 !important;
}
.topbar i.fa-regular, .topbar .td-item i.fa-regular {
    font-weight: 400 !important;
}
.topbar i.fa-brands, .topbar .td-item i.fa-brands {
    font-family: 'Font Awesome 6 Brands' !important;
    font-weight: 400 !important;
}

/* إخفاء الساعة والتاريخ في الجوال لتوفير مساحة */
@media (max-width: 768px) {
    #tbDateTime { display: none !important; }
    .topbar { padding: 0 12px !important; }
    .bc-title { font-size: 14px !important; }
}

/* شريط التنقل السفلي (Bottom Navigation) */
.mobile-bottom-nav {
    display: none;
    position: fixed; bottom: 0; left: 0; right: 0;
    background: #fff; border-top: 1px solid #e2e8f0;
    padding: 6px 0 calc(6px + env(safe-area-inset-bottom));
    z-index: 998; box-shadow: 0 -4px 15px rgba(0,0,0,0.05);
    justify-content: space-around; align-items: center;
}
@media (max-width: 768px) {
    .mobile-bottom-nav { display: flex; }
    body { padding-bottom: 70px !important; } /* مسافة لتجنب تداخل المحتوى */
}
.mob-nav-item {
    display: flex; flex-direction: column; align-items: center; gap: 3px;
    text-decoration: none; color: #64748b; font-size: 10.5px; font-weight: 700;
    padding: 6px 12px; border-radius: 10px; transition: all 0.2s;
}
.mob-nav-item i { font-size: 18px; }
.mob-nav-item.active { color: #2563eb; background: #eff6ff; }
.td-wrap  { position:relative; }
.td-menu  {
  position:absolute; top:calc(100% + 8px);
  inset-inline-end:0;
  min-width:210px;
  background:var(--card-bg,#fff);
  border:0.5px solid var(--border,#e2e8f0);
  border-radius:var(--r-xl,16px);
  box-shadow:0 8px 28px rgba(0,0,0,.1),0 2px 8px rgba(0,0,0,.06);
  z-index:300; overflow:hidden;
  display:none;
  animation:dropFd .15s ease;
}
@keyframes dropFd{from{opacity:0;transform:translateY(-6px)}to{opacity:1;transform:translateY(0)}}
.td-menu.open { display:block; }

/* ═══ Weather Widget في التوبار (compact) ═══ */
.tb-weather-compact {
  position: relative;
  display: inline-flex; align-items: center; gap: 6px;
  background: linear-gradient(135deg, rgba(59,130,246,.10), rgba(124,58,237,.08));
  border: 1px solid rgba(59,130,246,.18);
  border-radius: 12px;
  padding: 6px 12px;
  margin-inline-start: 8px;
  cursor: pointer;
  font-family: 'Tajawal', sans-serif;
  transition: all 0.15s;
  flex-shrink: 0;
}
.tb-weather-compact:hover {
  background: linear-gradient(135deg, rgba(59,130,246,.15), rgba(124,58,237,.12));
  box-shadow: 0 4px 12px rgba(59,130,246,.18);
}
.tb-weather-compact i { font-size: 14px; color: #3b82f6; }
.tb-w-temp {
  font-size: 13px; font-weight: 800; color: #0f172a;
  font-family: 'Inter', monospace; min-width: 32px;
}
.tb-w-city {
  font-size: 11.5px; color: #475569; font-weight: 600;
  max-width: 70px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;
}
.tb-w-alert { color: #f59e0b; font-size: 11px; animation: dwpulse 1.5s ease-in-out infinite; }
@keyframes dwpulse { 0%, 100% { opacity: 1; } 50% { opacity: 0.4; } }
.tb-w-dropdown {
  position: absolute; top: calc(100% + 8px); inset-inline-end: 0;
  min-width: 280px;
  background: #fff;
  border: 1px solid #e2e8f0;
  border-radius: 14px;
  box-shadow: 0 12px 32px rgba(0,0,0,.12);
  z-index: 400;
  display: none;
  padding: 12px;
  animation: dropFd .15s ease;
}
.tb-w-dropdown.open { display: block; }
.tb-w-city-row {
  display: flex; align-items: center; gap: 10px;
  padding: 8px 4px;
  border-bottom: 1px dashed #f1f5f9;
}
.tb-w-city-row:last-child { border-bottom: none; }
.tb-w-city-ico { font-size: 24px; width: 32px; text-align: center; }
.tb-w-city-info { flex: 1; }
.tb-w-city-name { font-size: 12.5px; font-weight: 700; color: #0f172a; }
.tb-w-city-desc { font-size: 11px; color: #64748b; }
.tb-w-city-temp { font-size: 22px; font-weight: 800; color: #0f172a; font-family: 'Inter', monospace; }
.tb-w-alert-item { background: #fef2f2; color: #991b1b; padding: 6px 8px; border-radius: 7px; margin-top: 6px; font-size: 11px; display: flex; gap: 5px; align-items: center; }
.tb-w-alert-item.warning { background: #fffbeb; color: #92400e; }
.tb-w-alert-item.info { background: #eff6ff; color: #1e40af; }
.tb-w-meta { display: flex; gap: 10px; font-size: 10.5px; color: #94a3b8; margin-top: 4px; }
.tb-w-meta span { display: inline-flex; align-items: center; gap: 3px; }
.tb-w-source { text-align: center; font-size: 9.5px; color: #94a3b8; margin-top: 8px; padding-top: 8px; border-top: 1px solid #f1f5f9; }

@media (max-width: 1100px) { .tb-w-city { display: none; } }
@media (max-width: 768px) { .tb-weather-compact .tb-w-temp { display: none; } }
@media (max-width: 768px) { #tbDateTime { display: none !important; } }
.td-hd { padding:11px 14px 9px; border-bottom:0.5px solid var(--border-light,#f1f5f9); }
.td-hd-name { font-size:13px; font-weight:500; color:var(--text-1,#0f172a); }
.td-hd-role { font-size:11px; color:var(--text-3,#94a3b8); margin-top:1px; }
.td-item {
  display:flex; align-items:center; gap:10px;
  padding:10px 14px; font-size:13.5px;
  color:var(--text-2,#475569); text-decoration:none;
  transition:background .15s; cursor:pointer;
  font-family:'Tajawal',sans-serif; width:100%; border:none; background:none; text-align:inherit;
}
.td-item:hover { background:var(--border-light,#f1f5f9); }
.td-item i { width:18px; text-align:center; color:var(--text-3,#94a3b8); font-size:14px; flex-shrink:0; }
.td-sep { border:none; border-top:0.5px solid var(--border,#e2e8f0); margin:4px 0; }
.td-danger { color:var(--danger,#dc2626)!important; }
.td-danger:hover { background:#fef2f2!important; }
.td-danger i { color:var(--danger,#dc2626)!important; }
</style>

<header class="topbar" role="banner">

  <button class="topbar-toggle" id="sidebarToggle"
    aria-label="<?= $_rtl ? 'فتح/إغلاق القائمة' : 'Toggle sidebar' ?>"
    aria-expanded="false">
    <i class="fa-solid fa-bars" aria-hidden="true"></i>
  </button>

  <div class="breadcrumb" aria-label="<?= $_rtl ? 'مسار التنقل' : 'Breadcrumb' ?>">
    <div class="bc-icon" aria-hidden="true"><i class="fa-solid <?= e($_picon) ?>"></i></div>
    <span class="bc-title"><?= e($_ptitle) ?></span>
    <?php foreach ($_bc as $_b): ?>
    <span class="bc-sep" aria-hidden="true">
      <i class="fa-solid fa-chevron-<?= $_rtl ? 'left' : 'right' ?>"></i>
    </span>
    <?php if (!empty($_b['url'])): ?>
      <a href="<?= e($_b['url']) ?>" class="bc-sub" style="text-decoration:none;color:inherit"><?= e($_b['name']) ?></a>
    <?php else: ?>
      <span class="bc-sub"><?= e($_b['name']) ?></span>
    <?php endif; ?>
    <?php endforeach; ?>
  </div>

  <!-- ── ساعة وتاريخ ── -->
  <div id="tbDateTime" style="display:flex;align-items:center;gap:10px;padding:0 14px;flex-shrink:0">
    <!-- الوقت -->
    <div style="display:flex;align-items:center;gap:6px;background:rgba(21,101,192,.07);border-radius:10px;padding:6px 12px">
      <i class="fa-regular fa-clock" style="color:#1565C0;font-size:13px"></i>
      <span id="tbTime" style="font-size:13.5px;font-weight:700;color:#1565C0;font-family:'Inter',monospace;letter-spacing:.04em">--:--:--</span>
    </div>
    <!-- التاريخ -->
    <div style="display:flex;flex-direction:column;gap:1px;font-family:'Tajawal',sans-serif">
      <div style="display:flex;align-items:center;gap:5px">
        <i class="fa-solid fa-calendar" style="color:#7c3aed;font-size:10px"></i>
        <span id="tbHijri" style="font-size:11.5px;font-weight:700;color:#7c3aed;direction:rtl">—</span>
        <span style="font-size:10px;color:#94a3b8;font-weight:600">هـ</span>
      </div>
      <div style="display:flex;align-items:center;gap:5px">
        <i class="fa-regular fa-calendar-days" style="color:#059669;font-size:10px"></i>
        <span id="tbGreg" style="font-size:11px;color:#059669;font-weight:600;font-family:'Inter',sans-serif">—</span>
        <span style="font-size:10px;color:#94a3b8;font-weight:600">م</span>
      </div>
    </div>
    <!-- اليوم -->
    <div style="display:flex;flex-direction:column;align-items:center;background:#f8fafc;border:1px solid #e2e8f0;border-radius:9px;padding:5px 10px;gap:1px">
      <span id="tbDayAr" style="font-size:11.5px;font-weight:800;color:#0f172a;line-height:1">—</span>
      <span id="tbDayEn" style="font-size:9.5px;color:#94a3b8;font-weight:600;line-height:1">—</span>
    </div>
  </div>

  <!-- ── طقس سريع (Weather Widget — Open-Meteo) ── -->
  <div id="tbWeather" class="tb-weather-compact" onclick="tbWeatherExpand()" title="<?= $_rtl ? 'انقر لتفاصيل الطقس' : 'Click for weather details' ?>">
    <i class="fa-solid fa-cloud" id="tbWeatherIco"></i>
    <span id="tbWeatherTemp" class="tb-w-temp">--°</span>
    <span id="tbWeatherCity" class="tb-w-city">—</span>
    <span id="tbWeatherAlert" class="tb-w-alert" style="display:none"><i class="fa-solid fa-circle-exclamation"></i></span>
    <!-- Dropdown للمدينتين -->
    <div id="tbWeatherDropdown" class="tb-w-dropdown"></div>
  </div>

  <div class="topbar-tools">

    <button class="tb-btn" onclick="location.reload()"
      title="<?= $_rtl ? 'تحديث' : 'Refresh' ?>"
      aria-label="<?= $_rtl ? 'تحديث الصفحة' : 'Refresh page' ?>">
      <i class="fa-solid fa-rotate-right" aria-hidden="true"></i>
    </button>

    <div class="td-wrap" id="bellWrap" style="position:relative">
      <button class="tb-btn" id="bellBtn" type="button"
        title="<?= $_rtl?'الإشعارات':'Notifications' ?>"
        onclick="toggleBell(event)"
        aria-haspopup="true" aria-expanded="false">
        <i class="fa-regular fa-bell" aria-hidden="true"></i>
        <?php if($_nb>0): ?>
        <span class="tb-badge" id="bellBadge"><?= $_nb>99?'99+':$_nb ?></span>
        <?php else: ?>
        <span class="tb-badge" id="bellBadge" style="display:none">0</span>
        <?php endif; ?>
      </button>

      <!-- Dropdown الإشعارات -->
      <div id="bellDropdown" style="display:none;position:absolute;top:calc(100% + 8px);inset-inline-end:0;width:340px;max-width:96vw;background:#fff;border-radius:14px;box-shadow:0 12px 40px rgba(0,0,0,.15);border:1px solid #e2e8f0;z-index:500;overflow:hidden">
        <!-- رأس -->
        <div style="padding:12px 16px;border-bottom:1px solid #f1f5f9;display:flex;align-items:center;justify-content:space-between">
          <div style="font-size:13.5px;font-weight:700;color:#0f172a;display:flex;align-items:center;gap:6px">
            <i class="fa-solid fa-bell" style="color:#1565C0;font-size:12px"></i>
            <?= $_rtl?'الإشعارات':'Notifications' ?>
            <span id="bellUnreadBadge" style="background:#ef4444;color:#fff;font-size:10px;font-weight:700;border-radius:50px;padding:1px 7px;<?= $_nb>0?'':'display:none' ?>"><?= $_nb ?></span>
          </div>
          <a href="<?= BASE_URL ?>/notifications.php?mark_all=1" onclick="markAllRead(event)"
            style="font-size:11.5px;color:#64748b;text-decoration:none;display:flex;align-items:center;gap:4px">
            <i class="fa-solid fa-check-double" style="font-size:10px"></i>
            <?= $_rtl?'قراءة الكل':'Mark all read' ?>
          </a>
        </div>
        <!-- قائمة الإشعارات -->
        <div id="bellList" style="max-height:320px;overflow-y:auto">
          <div style="text-align:center;padding:24px;color:#94a3b8;font-size:13px">
            <i class="fa-solid fa-circle-notch fa-spin" style="font-size:20px;display:block;margin-bottom:8px"></i>
            <?= $_rtl?'جاري التحميل...':'Loading...' ?>
          </div>
        </div>
        <!-- ذيل -->
        <div style="padding:10px 16px;border-top:1px solid #f1f5f9;text-align:center">
          <a href="<?= BASE_URL ?>/notifications.php"
            style="font-size:13px;font-weight:600;color:#1565C0;text-decoration:none;display:flex;align-items:center;justify-content:center;gap:5px">
            <i class="fa-solid fa-list" style="font-size:11px"></i>
            <?= $_rtl?'مشاهدة جميع الإشعارات':'View all notifications' ?>
          </a>
        </div>
      </div>
    </div>

    <style>
    #bellDropdown .nb-item{display:flex;gap:10px;padding:11px 16px;border-bottom:1px solid #f8fafc;cursor:pointer;text-decoration:none;color:inherit;transition:.12s}
    #bellDropdown .nb-item:last-child{border-bottom:none}
    #bellDropdown .nb-item:hover{background:#f8fafc}
    #bellDropdown .nb-item.unread{background:#fffbeb}
    #bellDropdown .nb-ico{width:34px;height:34px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:14px;flex-shrink:0}
    </style>
    <script>
    const _BELL_RTL = <?= $_rtl?'true':'false' ?>;
    const _BELL_BASE = '<?= BASE_URL ?>';
    let _bellLoaded = false;
    let _bellOpen   = false;

    function toggleBell(e){
        e.stopPropagation();
        const dd = document.getElementById('bellDropdown');
        _bellOpen = !_bellOpen;
        dd.style.display = _bellOpen ? 'block' : 'none';
        if(_bellOpen && !_bellLoaded) loadBellNotifs();
    }

    document.addEventListener('click', function(e){
        if(!document.getElementById('bellWrap').contains(e.target)){
            document.getElementById('bellDropdown').style.display='none';
            _bellOpen=false;
        }
    });

    async function loadBellNotifs(){
        _bellLoaded = true;
        try{
            const res = await fetch(_BELL_BASE+'/api/notif_recent.php');
            const data = await res.json();
            const list = document.getElementById('bellList');
            if(!data.items||!data.items.length){
                list.innerHTML='<div style="text-align:center;padding:28px;color:#94a3b8;font-size:13px"><i class="fa-regular fa-bell-slash" style="font-size:28px;display:block;margin-bottom:8px;opacity:.3"></i>'+(_BELL_RTL?'لا توجد إشعارات':'No notifications')+'</div>';
                return;
            }
            const type_icons={committee_request:'fa-users-gear',committee_approved:'fa-check-circle',committee_returned:'fa-rotate-left',committee_rejected:'fa-xmark-circle',committee_completed:'fa-circle-check',member_sign_request:'fa-pen-fancy',info:'fa-circle-info'};
            const type_colors={committee_request:'#7B1FA2',committee_approved:'#16a34a',committee_returned:'#d97706',committee_rejected:'#dc2626',committee_completed:'#16a34a',member_sign_request:'#1565C0',info:'#1565C0'};
            const type_bgs={committee_request:'#F3E5F5',committee_approved:'#F0FDF4',committee_returned:'#FFFBEB',committee_rejected:'#FEF2F2',committee_completed:'#F0FDF4',member_sign_request:'#E3F2FD',info:'#E3F2FD'};
            list.innerHTML = data.items.map(function(n){
                const ico=type_icons[n.type]||'fa-circle-info';
                const clr=type_colors[n.type]||'#1565C0';
                const bg=type_bgs[n.type]||'#E3F2FD';
                const href=n.link?(_BELL_BASE+'/notifications.php?read='+n.id+'&goto='+encodeURIComponent(n.link)):'#';
                return '<a href="'+href+'" class="nb-item'+(n.is_read?'':' unread')+'">'
                    +'<div class="nb-ico" style="background:'+bg+'"><i class="fa-solid '+ico+'" style="color:'+clr+'"></i></div>'
                    +'<div style="flex:1;min-width:0">'
                    +'<div style="font-size:12.5px;font-weight:'+(n.is_read?500:700)+';color:#0f172a;line-height:1.4;white-space:normal">'+(n.title||'')+'</div>'
                    +(n.body?'<div style="font-size:11.5px;color:#64748b;margin-top:2px;white-space:normal">'+n.body.substring(0,60)+(n.body.length>60?'...':'')+'</div>':'')
                    +'<div style="font-size:10.5px;color:#94a3b8;margin-top:3px;font-family:Inter">'+(n.created_at||'').substring(0,16)+'</div>'
                    +'</div>'
                    +(n.is_read?'':'<div style="width:8px;height:8px;border-radius:50%;background:#f59e0b;flex-shrink:0;margin-top:5px"></div>')
                    +'</a>';
            }).join('');
            // تحديث العدد
            const badge=document.getElementById('bellBadge');
            const ubadge=document.getElementById('bellUnreadBadge');
            const cnt=data.unread||0;
            if(cnt>0){badge.textContent=cnt>99?'99+':cnt;badge.style.display='';ubadge.textContent=cnt;ubadge.style.display='';}
            else{badge.style.display='none';ubadge.style.display='none';}
        }catch(e){document.getElementById('bellList').innerHTML='<div style="padding:14px;text-align:center;color:#dc2626;font-size:13px">خطأ في التحميل</div>';}
    }

    function markAllRead(e){
        e.preventDefault();
        fetch(_BELL_BASE+'/notifications.php?mark_all=1').then(function(){
            _bellLoaded=false;
            loadBellNotifs();
        });
    }

    // تحديث تلقائي كل 30 ثانية
    setInterval(function(){
        if(!_bellOpen) _bellLoaded=false; // سيعيد التحميل عند الفتح
        // تحديث العدد فقط
        fetch(_BELL_BASE+'/api/notif_count.php').then(r=>r.json()).then(function(d){
            const badge=document.getElementById('bellBadge');
            const ubadge=document.getElementById('bellUnreadBadge');
            const cnt=d.count||0;
            if(cnt>0){badge.textContent=cnt>99?'99+':cnt;badge.style.display='';ubadge.textContent=cnt;ubadge.style.display='';}
            else{badge.style.display='none';ubadge.style.display='none';}
        }).catch(()=>{});
    }, 30000);
    </script>

    <div class="tb-divider" aria-hidden="true"></div>

    <!-- قائمة المستخدم المنسدلة -->
    <div class="td-wrap" id="userMenuWrap">
      <button class="tb-user" id="userMenuBtn" type="button"
        aria-haspopup="true" aria-expanded="false"
        aria-label="<?= $_rtl ? 'قائمة المستخدم' : 'User menu' ?>">
        <div class="tb-avatar" aria-hidden="true">
          <?php if (!empty($_user['avatar'])): ?>
            <img src="<?= e(UPLOAD_URL . '/avatars/' . $_user['avatar']) ?>" alt="" style="width:100%;height:100%;object-fit:cover;border-radius:8px">
          <?php else: ?>
            <i class="fa-solid fa-user" aria-hidden="true"></i>
          <?php endif; ?>
        </div>
        <div>
          <div class="tb-uname"><?= e($_uname) ?></div>
          <?php if ($_urole): ?>
          <div class="tb-urole"><?= e($_urole) ?></div>
          <?php endif; ?>
        </div>
        <i class="fa-solid fa-chevron-down" style="font-size:10px;color:var(--text-3);margin-<?= $_rtl ? 'right' : 'left' ?>:3px" aria-hidden="true"></i>
      </button>

      <div class="td-menu" id="userMenuDrop" role="menu">
        <div class="td-hd">
          <div class="td-hd-name"><?= e($_uname) ?></div>
          <?php if ($_urole): ?>
          <div class="td-hd-role"><?= e($_urole) ?></div>
          <?php endif; ?>
        </div>

        <?php foreach ($_menu_items as $_mi):
          if (!empty($_mi['sep'])): ?>
          <hr class="td-sep" role="separator">
          <?php continue; endif;
          if (!empty($_mi['perm']) && !can($_mi['perm'], 'view') && !is_admin()) continue;
          $item_lbl = $_rtl ? e($_mi['ar']) : e($_mi['en']);
          $icon_flip = (!$_rtl && in_array($_mi['icon'],['fa-right-from-bracket'])) ? '' : ($_rtl && $_mi['icon']==='fa-right-from-bracket' ? 'style="transform:scaleX(-1)"' : '');
        ?>
        <a href="<?= e($_mi['url']) ?>" class="td-item <?= $_mi['class'] ?>" role="menuitem">
          <i class="fa-solid <?= $_mi['icon'] ?>" aria-hidden="true" <?= $icon_flip ?>></i>
          <?= $item_lbl ?>
        </a>
        <?php endforeach; ?>
      </div>
    </div>

  </div>
</header>

<!-- ═══ Weather Widget JS (Open-Meteo) ═══ -->
<script>
(function() {
  const wIco  = document.getElementById('tbWeatherIco');
  const wTemp = document.getElementById('tbWeatherTemp');
  const wCity = document.getElementById('tbWeatherCity');
  const wAlrt = document.getElementById('tbWeatherAlert');
  const wDrop = document.getElementById('tbWeatherDropdown');
  if (!wIco) return;

  const TB_W_API = <?= json_encode(BASE_URL . '/api/dashboard_widgets.php') ?>;

  function loadWeather() {
    fetch(TB_W_API + '?action=weather', { credentials: 'same-origin' })
      .then(r => r.json())
      .then(j => {
        if (j.error || !j.cities || !j.cities.length) {
          wIco.className = 'fa-solid fa-cloud-question';
          wTemp.textContent = '--°';
          wCity.textContent = '—';
          return;
        }
        const c1 = j.cities[0];
        wIco.className = 'fa-solid ' + (c1.icon || 'fa-cloud');
        wTemp.textContent = (c1.temp != null ? c1.temp : '?') + '°';
        wCity.textContent = c1.city || '—';
        // تنبيه؟ (يعرض dot)
        const totalAlerts = (j.cities || []).reduce((s, c) => s + (c.alerts ? c.alerts.length : 0), 0);
        wAlrt.style.display = totalAlerts > 0 ? 'inline-block' : 'none';

        // ملء dropdown
        let html = '';
        for (const c of j.cities) {
          const ico = c.icon || 'fa-cloud';
          const temp = c.temp != null ? c.temp + '°C' : '—';
          const desc = c.description || '—';
          const feels = c.feels_like != null ? (c.feels_like + '°') : '';
          const hum = c.humidity != null ? c.humidity + '%' : '';
          const wind = c.wind_kmh != null ? c.wind_kmh + ' كم/س' : '';
          const vis = c.visibility_km != null ? c.visibility_km + ' كم' : '';
          let alerts = '';
          if (c.alerts && c.alerts.length) {
            for (const a of c.alerts) {
              alerts += `<div class="tb-w-alert-item ${a.level}"><i class="fa-solid ${a.icon}"></i>${a.msg}</div>`;
            }
          }
          html += `
            <div class="tb-w-city-row">
              <div class="tb-w-city-ico"><i class="fa-solid ${ico}"></i></div>
              <div class="tb-w-city-info">
                <div class="tb-w-city-name">${c.city}</div>
                <div class="tb-w-city-desc">${desc} ${feels?'· كأنه '+feels:''}</div>
                <div class="tb-w-meta"><span>💧 ${hum}</span><span>💨 ${wind}</span><span>👁 ${vis}</span></div>
                ${alerts}
              </div>
              <div class="tb-w-city-temp">${temp}</div>
            </div>`;
        }
        html += `<div class="tb-w-source">مصدر: ${j.provider || 'open-meteo'} · ${j.cached?'من cache':'محدّث'}</div>`;
        wDrop.innerHTML = html;
      })
      .catch(err => { wIco.className = 'fa-solid fa-cloud-question'; });
  }

  loadWeather();
  setInterval(loadWeather, 5 * 60 * 1000);  // كل 5 دقائق
})();

function tbWeatherExpand() {
  const d = document.getElementById('tbWeatherDropdown');
  d.classList.toggle('open');
}
// إغلاق dropdown عند الضغط خارجها
document.addEventListener('click', (e) => {
  const w = document.getElementById('tbWeather');
  if (w && !w.contains(e.target)) {
    const d = document.getElementById('tbWeatherDropdown');
    if (d) d.classList.remove('open');
  }
});
</script>

<!-- شريط التنقل السفلي للجوال -->
<nav class="mobile-bottom-nav">
    <a href="<?= BASE_URL ?>/dashboard.php" class="mob-nav-item <?= ($active_nav ?? '') === 'dashboard' ? 'active' : '' ?>">
        <i class="fa-solid fa-house"></i> <span>الرئيسية</span>
    </a>
    <a href="<?= BASE_URL ?>/assets/index.php" class="mob-nav-item <?= str_starts_with($active_nav ?? '', 'assets') ? 'active' : '' ?>">
        <i class="fa-solid fa-boxes-stacked"></i> <span>الأصول</span>
    </a>
    <a href="<?= BASE_URL ?>/inventory/index.php" class="mob-nav-item <?= str_starts_with($active_nav ?? '', 'inventory') ? 'active' : '' ?>">
        <i class="fa-solid fa-clipboard-check"></i> <span>الجرد</span>
    </a>
    <a href="<?= BASE_URL ?>/complaints/index.php" class="mob-nav-item <?= str_starts_with($active_nav ?? '', 'complaints') ? 'active' : '' ?>">
        <i class="fa-solid fa-bell"></i> <span>البلاغات</span>
    </a>
</nav>
<script>
(function(){
  // ── Sidebar toggle ────────────────────────────────
  const stBtn  = document.getElementById('sidebarToggle');
  const sb     = document.getElementById('luxurySidebar');
  const ovl    = document.getElementById('sidebarOverlay');
  // Force-close sidebar on mobile load
  if (sb && window.innerWidth < 1024) {
    sb.classList.remove('open');
    if (ovl) ovl.classList.remove('on');
    document.body.style.overflow = '';
  }
  if (stBtn && sb && ovl) {
    const openSb  = () => { sb.classList.add('open');   ovl.classList.add('on');  stBtn.setAttribute('aria-expanded','true');  document.body.style.overflow='hidden'; };
    const closeSb = () => { sb.classList.remove('open');ovl.classList.remove('on');stBtn.setAttribute('aria-expanded','false');document.body.style.overflow=''; };
    stBtn.addEventListener('click', () => sb.classList.contains('open') ? closeSb() : openSb());
    ovl.addEventListener('click', closeSb);
    ovl.addEventListener('keydown', e => { if(e.key==='Enter'||e.key===' ') closeSb(); });
  }

  // ── User dropdown menu ────────────────────────────────────
  const umBtn  = document.getElementById('userMenuBtn');
  const umDrop = document.getElementById('userMenuDrop');
  if (umBtn && umDrop) {
    umBtn.addEventListener('click', e => {
      e.stopPropagation();
      const open = umDrop.classList.toggle('open');
      umBtn.setAttribute('aria-expanded', String(open));
    });
    document.addEventListener('click', () => {
      umDrop.classList.remove('open');
      umBtn.setAttribute('aria-expanded','false');
    });
    umDrop.addEventListener('click', e => e.stopPropagation());
    document.addEventListener('keydown', e => {
      if (e.key === 'Escape') {
        umDrop.classList.remove('open');
        umBtn.setAttribute('aria-expanded','false');
        umBtn.focus();
      }
    });
  }
})();
</script>

<script>
// ── ساعة وتاريخ حي ────────────────────────────────────────
(function(){
  const days_ar = ['الأحد','الاثنين','الثلاثاء','الأربعاء','الخميس','الجمعة','السبت'];
  const days_en = ['Sunday','Monday','Tuesday','Wednesday','Thursday','Friday','Saturday'];

  function pad(n){ return String(n).padStart(2,'0'); }

  function updateClock(){
    const now  = new Date();
    const h = pad(now.getHours());
    const m = pad(now.getMinutes());
    const s = pad(now.getSeconds());
    const el = document.getElementById('tbTime');
    if (el) el.textContent = h + ':' + m + ':' + s;
  }

  function updateDate(){
    const now = new Date();
    // الميلادي
    const gregEl = document.getElementById('tbGreg');
    if (gregEl) {
      const y = now.getFullYear();
      const mo= pad(now.getMonth()+1);
      const d = pad(now.getDate());
      gregEl.textContent = y + '/' + mo + '/' + d;
    }
    // الهجري (Intl)
    const hijriEl = document.getElementById('tbHijri');
    if (hijriEl) {
      try {
        const hFmt = new Intl.DateTimeFormat('ar-SA-u-ca-islamic-umalqura',{
          year:'numeric', month:'2-digit', day:'2-digit'
        });
        hijriEl.textContent = hFmt.format(now).replace(/[٠-٩]/g, d => '٠١٢٣٤٥٦٧٨٩'.indexOf(d));
        // أبقِ الأرقام العربية أو حوّلها للإنجليزية — يحسم من اللغة
        hijriEl.textContent = hFmt.format(now);
      } catch(e){ hijriEl.textContent = '—'; }
    }
    // اليوم
    const dayAr = document.getElementById('tbDayAr');
    const dayEn = document.getElementById('tbDayEn');
    if (dayAr) dayAr.textContent = days_ar[now.getDay()];
    if (dayEn) dayEn.textContent = days_en[now.getDay()];
  }

  updateClock(); updateDate();
  setInterval(updateClock, 1000);
  setInterval(updateDate, 60000); // تحديث التاريخ كل دقيقة
})();
</script>
<?php
/* درع المساعد: أي خطأ يُبتلع ويُسطَّر في سجل Apache ولا يُسقط الصفحة */
try {
    ob_start();
    include BASE_PATH . '/includes/ai_assistant.php';
    echo ob_get_clean();
} catch (Throwable $e) {
    ob_end_clean();
    error_log('[AI Assistant] عُطّل تلقائياً: ' . $e->getMessage());
}
?>
