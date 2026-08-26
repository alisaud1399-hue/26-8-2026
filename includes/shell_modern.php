<?php /* Modern Shell v1 — تجربة بصرية قابلة للتراجع */ ?>
<style>
/* ═══ المتغيرات ═══ */
:root{
  --sh-dark:#0f172a;
  --sh-line:rgba(148,163,184,.16);
  --sh-accent:#38bdf8;
  --sh-accent2:#818cf8;
  --sh-glow:0 0 18px rgba(56,189,248,.25);
}

/* ═══ خلفية عامة أعمق ═══ */
body.app-layout{background:#0b1220 !important}
.main-area{background:#f1f5f9 !important}

/* ═══ السايدبار: زجاج داكن عائم ═══ */
.sidebar, aside[class*="sidebar"], .app-sidebar{
  background:linear-gradient(180deg,#0f172a 0%,#111c33 60%,#0f172a 100%) !important;
  border-inline-end:1px solid var(--sh-line) !important;
  box-shadow:4px 0 24px rgba(2,8,20,.35);
  transition:width .3s ease;
}
/* توهج علوي للعلامة */
.sidebar::before, aside[class*="sidebar"]::before{
  content:'';display:block;height:90px;
  background:radial-gradient(220px 90px at 50% 0%,rgba(56,189,248,.18),transparent 70%);
  pointer-events:none;
}

/* عناوين الأقسام */
.sidebar .nav-section-title, .sidebar [class*="section-title"]{
  font-size:10.5px !important;letter-spacing:1.2px;text-transform:uppercase;
  color:#64748b !important;font-weight:800;
}

/* عناصر القائمة: بلاطات ناعمة */
.sidebar a, .sidebar .nav-item a, .sidebar li a{
  border-radius:10px !important;margin:2px 8px;
  color:#cbd5e1 !important;font-weight:600;
  transition:all .22s ease;position:relative;
}
.sidebar a i, .sidebar li a i{
  width:34px;height:34px;border-radius:9px;
  display:inline-flex;align-items:center;justify-content:center;
  background:rgba(148,163,184,.10);color:#94a3b8;
  transition:all .22s ease;margin-inline-end:10px;
}
.sidebar a:hover{background:rgba(56,189,248,.08) !important;color:#fff !important;transform:translateX(-2px)}
.sidebar a:hover i{color:var(--sh-accent);background:rgba(56,189,248,.14)}

/* العنصر النشط: حبة متدرجة + مؤشر ضوئي */
.sidebar a.active, .sidebar li a.active, .sidebar .active{
  background:linear-gradient(90deg,rgba(56,189,248,.18),rgba(129,140,248,.10)) !important;
  color:#fff !important;font-weight:800;
  box-shadow:inset 3px 0 0 var(--sh-accent), var(--sh-glow);
}
.sidebar a.active i{color:var(--sh-accent);background:rgba(56,189,248,.18)}

/* القوائم الفرعية */
.sidebar .nav-submenu a, .sidebar ul ul a{font-size:12.5px;padding:7px 10px}

/* شريط تمرير أنيق */
.sidebar::-webkit-scrollbar{width:5px}
.sidebar::-webkit-scrollbar-thumb{background:rgba(148,163,184,.25);border-radius:99px}

/* ═══ التوب بار: شريط زجاجي فاتح عائم ═══ */
.topbar, .top-bar, header[class*="topbar"], .main-area > header{
  position:sticky !important;top:10px !important;
  margin:10px 14px !important;border-radius:16px !important;
  background:rgba(255,255,255,.78) !important;
  backdrop-filter:blur(14px);-webkit-backdrop-filter:blur(14px);
  border:1px solid rgba(226,232,240,.9) !important;
  box-shadow:0 10px 30px rgba(2,8,20,.08) !important;
}

/* ═══ لمسات على المحتوى ═══ */
.page-content{padding:20px 22px !important}
.page-content .card, .page-content [class*="card"]{
  border-radius:16px !important;border:1px solid #e2e8f0;
  box-shadow:0 2px 10px rgba(2,8,20,.04);
  transition:box-shadow .25s ease, transform .25s ease;
}
.page-content .card:hover{box-shadow:0 10px 26px rgba(2,8,20,.08)}

/* أزرار عامة أكثر نعومة */
.btn, button[class*="btn"]{border-radius:10px !important;transition:all .2s ease}
.btn:hover, button[class*="btn"]:hover{transform:translateY(-1px)}
</style>