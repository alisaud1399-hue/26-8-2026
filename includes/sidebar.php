<?php
/**
 * includes/sidebar.php — الشريط الجانبي (نسخة الفخامة مع تأثير بناء الشعار)
 * تم الإصلاح: العدادات الآن تقرأ بذكاء بناءً على قسم المستخدم أو فريق الصيانة
 */
$_u    = current_user();
$_nb   = unread_notifications_count();
$_rtl  = is_rtl();
$_lang = current_lang();

// ── بنية التنقل مع دعم اللغتين (بالمسميات الأصلية) ──────────────────────────────
$_nav = [
    'core' => [
        'label' => null, 'label_en' => null,
        'items' => [
            ['code'=>'dashboard','ar'=>'لوحة التحكم','en'=>'Dashboard','icon'=>'fa-gauge-high','url'=>BASE_URL.'/dashboard.php', 'color'=>'#2563eb', 'bg'=>'#eff6ff'],
        ],
    ],
    'assets_mod' => [
        'label' => 'الأصول', 'label_en' => 'Assets',
        'items' => [
            ['code'=>'assets.index','ar'=>'الأصول','en'=>'Assets','icon'=>'fa-boxes-stacked','url'=>BASE_URL.'/assets/index.php', 'color'=>'#7c3aed', 'bg'=>'#f5f3ff'],
            ['code'=>'assets.criticality_bulk','ar'=>'تحديث فئة الحساسية (A/B/C)','en'=>'Bulk Criticality Update','icon'=>'fa-shield-halved','url'=>BASE_URL.'/assets/criticality_bulk.php', 'color'=>'#dc2626', 'bg'=>'#fef2f2'],
            ['code'=>'assets.risk_assessment','ar'=>'تقييم المخاطر (Risk Score)','en'=>'Risk Assessment','icon'=>'fa-gauge-high','url'=>BASE_URL.'/assets/risk_assessment.php', 'color'=>'#b91c1c', 'bg'=>'#fef2f2'],
            // ملاحظة: 'تقارير العهدة' نُقلت من هنا إلى مجموعة 'التقارير' (تقاريرها استعراضية بحتة)
            ['code'=>'assets.device_dossier','ar'=>'ملف الجهاز الموحَّد','en'=>'Device Dossier','icon'=>'fa-file-medical','url'=>BASE_URL.'/assets/device_dossier.php', 'color'=>'#0e7490', 'bg'=>'#ecfeff'],
			['code'=>'inventory.locations', 'ar'=>'إدارة المواقع', 'en'=>'Locations', 'icon'=>'fa-map-location-dot','url'=>BASE_URL.'/inventory/locations/index.php', 'color'=>'#0891b2', 'bg'=>'#ecfeff'],
        ],
    ],
    'cycle' => [
        'label' => 'دورة الأصل', 'label_en' => 'Asset Cycle',
        'items' => [
            ['code'=>'committees.index',    'ar'=>'اللجان',          'en'=>'Committees',           'icon'=>'fa-users-gear',        'url'=>BASE_URL.'/committees/index.php', 'color'=>'#059669', 'bg'=>'#ecfdf5'],
            ['code'=>'receiving.index',     'ar'=>'محاضر الاستلام',  'en'=>'Receiving Minutes',    'icon'=>'fa-truck-ramp-box',    'url'=>BASE_URL.'/receiving/index.php', 'color'=>'#d97706', 'bg'=>'#fffbeb'],
            ['code'=>'installation.index',  'ar'=>'التركيب والعهدة', 'en'=>'Installation & Custody','icon'=>'fa-screwdriver-wrench','url'=>BASE_URL.'/installation/index.php', 'color'=>'#0891b2', 'bg'=>'#ecfeff'],
            ['code'=>'non_medical_codes',  'ar'=>'أرقام الأصناف (غير طبية)','en'=>'Non-Medical Codes','icon'=>'fa-barcode','url'=>BASE_URL.'/assets/non_medical_codes.php', 'color'=>'#0891b2', 'bg'=>'#ecfeff'],
            ['code'=>'custody.center',      'ar'=>'مركز العهدة',          'en'=>'Custody Center',     'icon'=>'fa-boxes-packing','url'=>BASE_URL.'/assets/custody_center.php', 'color'=>'#0F766E', 'bg'=>'#E0F2EF'],
            ['code'=>'custody_transfer',    'ar'=>'نقل العهد (توزيع أولي)','en'=>'Custody Transfer','icon'=>'fa-truck-ramp','url'=>BASE_URL.'/assets/custody_transfer.php', 'color'=>'#94a3b8', 'bg'=>'#f1f5f9'],
            ['code'=>'disposal.index',      'ar'=>'إدارة التخلص (نموذج 9+10)','en'=>'Asset Disposal','icon'=>'fa-trash-can','url'=>BASE_URL.'/disposal/index.php', 'color'=>'#dc2626', 'bg'=>'#fef2f2'],
            ['code'=>'inventory.index',     'ar'=>'الجرد الشامل',    'en'=>'Asset Inventory',      'icon'=>'fa-clipboard-check',   'url'=>BASE_URL.'/inventory/index.php', 'color'=>'#6366f1', 'bg'=>'#eef2ff'],
			['code'=>'settings.index', 'ar'=>'إدارة المواقع', 'en'=>'Locations', 'icon'=>'fa-map-location-dot', 'url'=>BASE_URL.'/settings/locations.php', 'color'=>'#0ea5e9', 'bg'=>'#e0f2fe'],
			['code'=>'inventory.index', 'ar'=>'نقل الأجهزة', 'en'=>'Asset Transfer', 'icon'=>'fa-right-left', 'url'=>BASE_URL.'/inventory/transfer.php', 'color'=>'#8b5cf6', 'bg'=>'#f5f3ff'],
			// ['code'=>'inventory.index', 'ar'=>'طلبات الاستثناءات', 'en'=>'Exception Requests', 'icon'=>'fa-clipboard-list', 'url'=>BASE_URL.'/inventory/requests.php', 'color'=>'#ea580c', 'bg'=>'#fff7ed'],
			
        ],
    ],
    'support' => [
        'label' => 'الدعم', 'label_en' => 'Support',
        'items' => [
            ['code'=>'helpdesk', 'ar'=>'نظام التذاكر الذكي', 'en'=>'Smart Helpdesk', 'icon'=>'fa-ticket', 'url'=>BASE_URL.'/helpdesk/index.php', 'color'=>'#4338ca', 'bg'=>'#eef2ff'],
        ],
    ],
    'maint' => [
        'label' => 'الصيانة', 'label_en' => 'Maintenance',
        'items' => [
            ['code'=>'complaints.index',  'ar'=>'البلاغات',    'en'=>'Complaints',   'icon'=>'fa-bell',           'url'=>BASE_URL.'/complaints/index.php', 'color'=>'#dc2626', 'bg'=>'#fef2f2'],
            ['code'=>'work_orders.index', 'ar'=>'أوامر العمل', 'en'=>'Work Orders',  'icon'=>'fa-clipboard-list', 'url'=>BASE_URL.'/complaints/wo_list.php', 'color'=>'#e11d48', 'bg'=>'#fff1f2'],
            ['code'=>'pm.schedules',      'ar'=>'الصيانة الدورية (PM)', 'en'=>'PM Schedules', 'icon'=>'fa-calendar-check', 'url'=>BASE_URL.'/maintenance/pm_schedules.php', 'color'=>'#16a34a', 'bg'=>'#f0fdf4'],
            ['code'=>'pm.quick',          'ar'=>'إضافة PM سريع',     'en'=>'Quick PM Add', 'icon'=>'fa-bolt',         'url'=>BASE_URL.'/maintenance/pm_quick.php',     'color'=>'#7c3aed', 'bg'=>'#f5f3ff'],
            ['code'=>'pm.reports',         'ar'=>'تقارير PM',          'en'=>'PM Reports',  'icon'=>'fa-chart-pie',   'url'=>BASE_URL.'/maintenance/pm_reports.php',  'color'=>'#0891b2', 'bg'=>'#ecfeff'],
            ['code'=>'pm.templates',      'ar'=>'قوالب PM',          'en'=>'PM Templates', 'icon'=>'fa-list-check',  'url'=>BASE_URL.'/admin/pm_templates.php', 'color'=>'#0f3460', 'bg'=>'#eef2ff'],
            ['code'=>'contractors.index', 'ar'=>'شركات الصيانة والعقود', 'en'=>'Contractors', 'icon'=>'fa-building-shield', 'url'=>BASE_URL.'/contractors/index.php', 'color'=>'#9a3412', 'bg'=>'#fff7ed'],
        ],
    ],
    'reports_mod' => [
        'label' => 'التقارير', 'label_en' => 'Reports',
        'items' => [
            // صفحة الدخول للـ hub موحّد — كل مجموعات التقارير (الأصول، العهد، الجرد، ...) ستفتح من هنا
            ['code'=>'reports.assets','ar'=>'تقارير الأصول','en'=>'Asset Reports','icon'=>'fa-chart-pie','url'=>BASE_URL.'/reports/assets/index.php', 'color'=>'#4f46e5', 'bg'=>'#eef2ff'],
            // تقارير العهدة — تأشر على الـ hub الجديد (نمط reports/custody)
            ['code'=>'reports.custody','ar'=>'تقارير العهدة','en'=>'Custody Reports','icon'=>'fa-handshake','url'=>BASE_URL.'/reports/custody/index.php', 'color'=>'#059669', 'bg'=>'#ecfdf5'],
            ['code'=>'complaints.reports','ar'=>'تقارير البلاغات','en'=>'Complaint Reports','icon'=>'fa-chart-line','url'=>BASE_URL.'/reports/complaints/index.php', 'color'=>'#ea580c', 'bg'=>'#fff7ed'],
            ['code'=>'reports.risk','ar'=>'تقارير المخاطر','en'=>'Risk Reports','icon'=>'fa-triangle-exclamation','url'=>BASE_URL.'/reports/risk/index.php', 'color'=>'#b91c1c', 'bg'=>'#fef2f2'],
            ['code'=>'reports.inventory','ar'=>'تقارير الجرد','en'=>'Inventory Reports','icon'=>'fa-barcode','url'=>BASE_URL.'/reports/inventory/index.php', 'color'=>'#0d9488', 'bg'=>'#ecfdf5'],
            ['code'=>'reports.maintenance','ar'=>'تقارير الصيانة','en'=>'Maintenance Reports','icon'=>'fa-screwdriver-wrench','url'=>BASE_URL.'/reports/maintenance/index.php', 'color'=>'#0891b2', 'bg'=>'#ecfeff'],
			['code'=>'reports.receiving','ar'=>'تقارير الاستلام والتشغيل','en'=>'Receiving Reports','icon'=>'fa-truck-ramp-box','url'=>BASE_URL.'/reports/receiving/index.php', 'color'=>'#a16207', 'bg'=>'#fffbeb'],
            ['code'=>'reports.disposal','ar'=>'تقارير التخلص','en'=>'Disposal Reports','icon'=>'fa-trash-can','url'=>BASE_URL.'/reports/disposal/index.php', 'color'=>'#7c3aed', 'bg'=>'#f5f3ff'],
            ['code'=>'reports.helpdesk','ar'=>'تقارير التذاكر','en'=>'Helpdesk Reports','icon'=>'fa-headset','url'=>BASE_URL.'/reports/helpdesk/index.php', 'color'=>'#0ea5e9', 'bg'=>'#eff6ff'],
        ],
    ],
    'admin_mod' => [
        'label' => 'الإدارة', 'label_en' => 'Administration',
        'items' => [
            // مركز NUPCو — البوابة الموحدة لكل خدمات NUPCO (مع شعارهم الرسمي)
            ['code'=>'nupco.hub',          'ar'=>'مركز NUPCO',          'en'=>'NUPCO Center',    'icon'=>'img:nupco-logo.png', 'url'=>BASE_URL.'/nupco/index.php', 'bilingual'=>true, 'color'=>'#ea580c', 'bg'=>'#fff7ed'],
            ['code'=>'complaints.settings','ar'=>'إعدادات البلاغات والتصعيد','en'=>'Complaint Settings','icon'=>'fa-sliders','url'=>BASE_URL.'/complaints/settings.php', 'color'=>'#b45309', 'bg'=>'#fffbeb'],
            // مخفية بتاريخ 2026-08-23: أصبحت «مفاتيح رئيسية» والتخصيص الفعلي في إعدادات كل فريق عند إنشاء الجلسة
            ['code'=>'inventory.settings','ar'=>'إعدادات الجرد','en'=>'Inventory Settings','icon'=>'fa-clipboard-check',  'url'=>BASE_URL.'/inventory/settings.php', 'color'=>'#6366f1', 'bg'=>'#eef2ff'],
            ['code'=>'users.index',        'ar'=>'المستخدمون',        'en'=>'Users',          'icon'=>'fa-users',       'url'=>BASE_URL.'/users/index.php', 'color'=>'#475569', 'bg'=>'#f1f5f9'],
            ['code'=>'roles.index',        'ar'=>'الأدوار والصلاحيات','en'=>'Roles & Perms',  'icon'=>'fa-user-shield', 'url'=>BASE_URL.'/roles/index.php', 'color'=>'#475569', 'bg'=>'#f1f5f9'],
            ['code'=>'departments.index',  'ar'=>'الهيكل التنظيمي',   'en'=>'Departments',    'icon'=>'fa-sitemap',     'url'=>BASE_URL.'/departments/index.php', 'color'=>'#475569', 'bg'=>'#f1f5f9'],
            ['code'=>'dept_assignments','ar'=>'تكليفات رؤساء الأقسام','en'=>'Dept. Assignments','icon'=>'fa-user-tie','url'=>BASE_URL.'/department_assignments/index.php', 'color'=>'#475569', 'bg'=>'#f1f5f9'],
            ['code'=>'settings.index',     'ar'=>'الإعدادات',          'en'=>'Settings',       'icon'=>'fa-gear',        'url'=>BASE_URL.'/settings/index.php', 'color'=>'#334155', 'bg'=>'#e2e8f0'],
  ['code'=>'settings.assistant_kb','ar'=>'إدارة معرفة المساعد','en'=>'Assistant KB',  'icon'=>'fa-robot',       'url'=>BASE_URL.'/settings/assistant_kb.php', 'color'=>'#8b5cf6', 'bg'=>'#f5f3ff'],
        ],
    ],
    'docs_mod' => [
        'label' => 'العروض', 'label_en' => 'Presentations',
        'items' => [
            ['code'=>'docs.presentation', 'ar'=>'نظرة شاملة (15 شريحة)', 'en'=>'System Overview', 'icon'=>'fa-presentation-screen', 'url'=>BASE_URL.'/presentations/pmsh_overview.html', 'color'=>'#7c3aed', 'bg'=>'#f5f3ff'],
            ['code'=>'docs.risk_guide', 'ar'=>'شرح Risk Score (20 شريحة)', 'en'=>'Risk Score Guide', 'icon'=>'fa-gauge-high', 'url'=>BASE_URL.'/presentations/risk_score_guide.html', 'color'=>'#b91c1c', 'bg'=>'#fef2f2'],
        ],
    ],
];

// ── عدادات البلاغات (الإصدار الذكي) ──────────────────────────────────────────
$_badges = [];
if ($_nb > 0) $_badges['notifications'] = $_nb;

try {
    global $pdo;
    $sb_can_all  = can_see_all();
    $sb_can_manage = can('complaints.index', 'manage'); // إذا كان مهندس صيانة
    $sb_dept_id  = (int)($_u['department_id'] ?? 0);
    $sb_uid      = (int)($_u['id'] ?? 0);

    // للمُرسل: احسب فقط البلاغات المفتوحة (التي لم يستلمها المهندس بعد)
    if (!$sb_can_manage && !$sb_can_all) {
        $sb_stmt = $pdo->prepare("SELECT COUNT(*) FROM complaints WHERE status = 'open' AND dept_id = ?");
        $sb_stmt->execute([$sb_dept_id]);
        $comp_count = (int)$sb_stmt->fetchColumn();
    } else {
        // للمهندس: احسب البلاغات النشطة التي تخصه
        $sb_stmt = $pdo->prepare("SELECT COUNT(*) FROM complaints WHERE status IN ('open','acknowledged','in_progress','escalated')");
        $sb_stmt->execute();
        $comp_count = (int)$sb_stmt->fetchColumn();
    }
    
    if ($comp_count > 0) $_badges['complaints.index'] = $comp_count;

} catch (Exception $e) {}
try {
    $c = $pdo->query("SELECT COUNT(*) FROM work_orders WHERE status NOT IN ('closed','cancelled')")->fetchColumn();
    if ($c > 0) $_badges['work_orders.index'] = $c;
} catch (PDOException $e) {}

$_active = $active_nav ?? 'dashboard';
$_prim   = $_u['primary_role'] ?? null;
$_pname  = $_rtl
    ? ($_prim['display_name'] ?? 'مستخدم')
    : ($_prim['display_en']   ?? $_prim['display_name'] ?? 'User');
$_uname  = $_rtl
    ? ($_u['full_name'] ?? 'مستخدم')
    : ($_u['full_name_en'] ?: ($_u['full_name'] ?? 'User'));

$_app_ar = 'إدارة الأصول والبلاغات';
$_app_en = 'Asset & Complaint Mgmt';
?>

<style>
    /* ════════════════════════════════════════════════════════════════════════
       Sidebar Font Shield — إجبار السايدبار على Tajawal + FA icons دائماً
       ════════════════════════════════════════════════════════════════════════ */
    #luxurySidebar, #luxurySidebar *,
    #luxurySidebar .lx-brand-text, #luxurySidebar .lx-brand-text h3, #luxurySidebar .lx-brand-text span,
    #luxurySidebar .lx-group-title,
    #luxurySidebar .lx-link, #luxurySidebar .lx-link-text, #luxurySidebar .lx-link-ar, #luxurySidebar .lx-link-en,
    #luxurySidebar .lx-user-card, #luxurySidebar .lx-user-name, #luxurySidebar .lx-user-role,
    #luxurySidebar .lx-badge {
        font-family: 'Tajawal', 'Cairo', system-ui, sans-serif !important;
    }
    #luxurySidebar .lx-link-en {
        font-family: 'Inter', system-ui, sans-serif !important;
    }
    /* درع أيقونات Font Awesome في السايدبار */
    #luxurySidebar i[class*="fa-"],
    #luxurySidebar i.fa-solid, #luxurySidebar i.fa-regular, #luxurySidebar i.fa-brands,
    #luxurySidebar .lx-link-icon-wrap i,
    #luxurySidebar .lx-user-card i, #luxurySidebar .lx-brand i {
        font-family: 'Font Awesome 6 Free', 'Font Awesome 6 Brands' !important;
        font-style: normal !important;
        font-weight: 900 !important;
    }
    #luxurySidebar i.fa-regular {
        font-weight: 400 !important;
    }

    #luxurySidebar {
        background: #ffffff !important; 
        border-inline-end: 1px solid #e2e8f0 !important;
        font-family: 'Tajawal', 'Cairo', system-ui, sans-serif !important;
        display: flex !important;
        flex-direction: column !important;
        height: 100vh !important;
        margin-top: 0 !important;
        top: 0 !important;
        width: 270px !important;
        min-width: 270px !important;
        flex-shrink: 0 !important;
        z-index: 999 !important;
        box-shadow: 2px 0 24px rgba(15, 23, 42, 0.03) !important;
    }
    #luxurySidebar .lx-brand {
        display: flex !important;
        flex-direction: column !important;
        align-items: center !important;
        justify-content: center !important;
        gap: 10px !important;
        padding: 24px 15px 15px !important;
        text-decoration: none !important;
        border-bottom: 1px solid #f1f5f9 !important;
        overflow: hidden !important;
    }
    #luxurySidebar .lx-logo-img {
        width: 75px !important; 
        height: auto !important;
        max-height: 75px !important;
        object-fit: contain !important;
        animation: buildUp 1.2s cubic-bezier(0.16, 1, 0.3, 1) forwards, glowSweep 1.5s ease-in-out 1s 1 forwards !important;
        opacity: 0;
    }
    #luxurySidebar .lx-brand:hover .lx-logo-img {
        transform: translateY(-2px) scale(1.05) !important;
        transition: transform 0.3s ease !important;
    }
    @keyframes buildUp {
        0% { opacity: 0; transform: translateY(40px) scale(0.7); filter: blur(10px); clip-path: inset(100% 0 0 0); }
        60% { opacity: 1; transform: translateY(-5px) scale(1.05); filter: blur(0px); clip-path: inset(0 0 0 0); }
        100% { opacity: 1; transform: translateY(0) scale(1); clip-path: inset(0 0 0 0); }
    }
    @keyframes glowSweep {
        0% { filter: drop-shadow(0 0 0 rgba(59, 130, 246, 0)); }
        50% { filter: drop-shadow(0 0 20px rgba(59, 130, 246, 0.7)); }
        100% { filter: drop-shadow(0 4px 8px rgba(0,0,0,0.06)); }
    }
    #luxurySidebar .lx-logo-text { text-align: center !important; animation: fadeInText 1s ease 0.8s both !important; }
    @keyframes fadeInText { 0% { opacity: 0; transform: translateY(10px); } 100% { opacity: 1; transform: translateY(0); } }
    #luxurySidebar .lx-logo-text h3 { color: #0f172a !important; font-size: 14px !important; font-weight: 800 !important; margin: 0 !important; }
    #luxurySidebar .lx-logo-text span { color: #64748b !important; font-size: 11px !important; font-weight: 600 !important; display: block !important; margin-top: 4px !important; }
    #luxurySidebar .lx-nav-container { flex: 1 !important; overflow-y: auto !important; padding: 12px 16px !important; scrollbar-width: thin !important; scrollbar-color: #cbd5e1 transparent !important; }
    #luxurySidebar .lx-group-title { color: #94a3b8 !important; font-size: 11px !important; font-weight: 700 !important; margin: 20px 0 8px 0 !important; padding-inline-start: 4px !important; }
    #luxurySidebar .lx-link { display: flex !important; align-items: center !important; padding: 8px 10px !important; margin-bottom: 4px !important; border-radius: 10px !important; color: #475569 !important; text-decoration: none !important; transition: all 0.2s ease !important; }
    #luxurySidebar .lx-link-icon-wrap { width: 32px !important; height: 32px !important; border-radius: 8px !important; display: flex !important; align-items: center !important; justify-content: center !important; margin-inline-end: 12px !important; font-size: 14px !important; transition: all 0.2s ease !important; overflow: hidden !important; }
    #luxurySidebar .lx-link-img { width: 26px !important; height: 26px !important; object-fit: contain !important; }
    #luxurySidebar .lx-link.has-img-icon .lx-link-icon-wrap { background: #fff !important; border: 1px solid #fed7aa !important; box-shadow: 0 1px 3px rgba(234,88,12,.15) !important; }
    #luxurySidebar .lx-link.active.has-img-icon .lx-link-icon-wrap { background: #fff !important; border-color: #fb923c !important; box-shadow: 0 2px 8px rgba(234,88,12,.3) !important; }
    #luxurySidebar .lx-link-bilingual { flex: 1 !important; display: flex !important; flex-direction: column !important; gap: 1px !important; min-width: 0 !important; line-height: 1.2 !important; }
    #luxurySidebar .lx-link-ar { font-size: 13px !important; font-weight: 700 !important; color: inherit !important; font-family: 'Tajawal', sans-serif !important; }
    #luxurySidebar .lx-link-en { font-size: 10.5px !important; font-weight: 500 !important; color: #94a3b8 !important; font-family: 'Inter', sans-serif !important; letter-spacing: .2px !important; }
    #luxurySidebar .lx-link.active .lx-link-en { color: #60a5fa !important; }
    #luxurySidebar .lx-link-text { flex: 1 !important; font-size: 13px !important; font-weight: 600 !important; }
    #luxurySidebar .lx-link:hover:not(.active) { background-color: #f8fafc !important; color: #0f172a !important; }
    #luxurySidebar .lx-link:hover:not(.active) .lx-link-icon-wrap { transform: scale(1.05) !important; }
    #luxurySidebar .lx-link.active { background-color: #eff6ff !important; color: #1d4ed8 !important; }
    #luxurySidebar .lx-link.active .lx-link-text { font-weight: 800 !important; }
    #luxurySidebar .lx-link.active.bilingual .lx-link-ar { font-weight: 800 !important; }
    #luxurySidebar .lx-link.active .lx-link-icon-wrap { background-color: #3b82f6 !important; color: #ffffff !important; box-shadow: 0 4px 12px rgba(59, 130, 246, 0.25) !important; }
    #luxurySidebar .lx-badge { background: #f1f5f9 !important; color: #475569 !important; font-size: 11px !important; font-weight: 700 !important; padding: 2px 8px !important; border-radius: 20px !important; }
    #luxurySidebar .lx-link.active .lx-badge { background: #dbeafe !important; color: #1d4ed8 !important; }
    #luxurySidebar .lx-badge.alert-pulse { background: #ef4444 !important; color: white !important; animation: lxPulse 2s infinite !important; }
    @keyframes lxPulse { 0% { box-shadow: 0 0 0 0 rgba(239, 68, 68, 0.3); } 70% { box-shadow: 0 0 0 6px rgba(239, 68, 68, 0); } 100% { box-shadow: 0 0 0 0 rgba(239, 68, 68, 0); } }
    #luxurySidebar .lx-user-card { padding: 12px !important; margin: 16px !important; background: #f8fafc !important; border: 1px solid #f1f5f9 !important; border-radius: 12px !important; display: flex !important; align-items: center !important; gap: 12px !important; transition: all 0.2s ease !important; }
    #luxurySidebar .lx-user-card:hover { background: #ffffff !important; box-shadow: 0 4px 12px rgba(0,0,0,0.04) !important; border-color: #e2e8f0 !important; }
    #luxurySidebar .lx-user-avatar { position: relative !important; width: 38px !important; height: 38px !important; background: #e2e8f0 !important; border-radius: 10px !important; display: flex !important; align-items: center !important; justify-content: center !important; font-size: 16px !important; color: #475569 !important; }
    #luxurySidebar .lx-user-status { position: absolute !important; bottom: -2px !important; right: -2px !important; width: 10px !important; height: 10px !important; background: #10b981 !important; border: 2px solid #ffffff !important; border-radius: 50% !important; }
    #luxurySidebar .lx-user-info { flex: 1 !important; overflow: hidden !important; }
    #luxurySidebar .lx-user-name { color: #0f172a !important; font-size: 13px !important; font-weight: 700 !important; white-space: nowrap !important; overflow: hidden !important; text-overflow: ellipsis !important; }
    #luxurySidebar .lx-user-role { color: #64748b !important; font-size: 11px !important; font-weight: 500 !important; }
/* تحويل الشريط الجانبي لـ Drawer في الجوال */
@media (max-width: 1024px) {
    #luxurySidebar {
        transform: translateX(100%) !important; /* في RTL ينزلق لليمين */
        box-shadow: -5px 0 25px rgba(0,0,0,0.15) !important;
        transition: transform 0.3s cubic-bezier(0.4, 0, 0.2, 1) !important;
    }
    [dir="ltr"] #luxurySidebar {
        transform: translateX(-100%) !important;
    }
    #luxurySidebar.open {
        transform: translateX(0) !important;
    }
    #sidebarOverlay.on {
        display: block !important;
        position: fixed; inset: 0;
        background: rgba(0,0,0,0.5);
        z-index: 998;
    }
    /* تصغير الخط قليلاً في الجوال */
    #luxurySidebar .lx-link-text { font-size: 12.5px !important; }
}
</style>

<aside id="luxurySidebar" class="sidebar" aria-label="<?= $_rtl ? 'قائمة التنقل' : 'Main navigation' ?>">
  <a class="lx-brand" href="<?= BASE_URL ?>/dashboard.php">
    <img src="<?= BASE_URL ?>/logo.png" alt="Hospital Logo" class="lx-logo-img">
    <div class="lx-logo-text">
      <h3><?= $_rtl ? e(get_setting('hospital_name','مستشفى الأمير مشاري')) : 'Prince Mishari Hospital' ?></h3>
      <span><?= $_rtl ? e($_app_ar) : e($_app_en) ?></span>
    </div>
  </a>

  <nav class="lx-nav-container" role="navigation">
    <?php foreach ($_nav as $_gid => $_group): ?>
      <?php
      $_vis = [];
      foreach ($_group['items'] as $_item) {
          if ($_item['code'] === 'dashboard' || can($_item['code'], 'view')) {
              $_vis[] = $_item;
          }
      }
      if (empty($_vis)) continue;
      $_lbl = $_rtl ? $_group['label'] : $_group['label_en'];
      ?>
      
      <?php if ($_lbl): ?>
      <div class="lx-group-title"><?= e($_lbl) ?></div>
      <?php endif; ?>

      <?php foreach ($_vis as $_item):
        $_is_act = ($_active === $_item['code'] || str_starts_with($_active, explode('.', $_item['code'])[0] . '.'));
        $_bdg    = $_badges[$_item['code']] ?? null;
        $_name   = $_rtl ? $_item['ar'] : $_item['en'];

        $i_color = $_item['color'] ?? '#64748b';
        $i_bg    = $_item['bg'] ?? '#f1f5f9';
        $is_alert = in_array($_item['code'], ['complaints.index', 'work_orders.index']);
        $is_bilingual = !empty($_item['bilingual']);
        $is_img_icon = str_starts_with($_item['icon'] ?? '', 'img:');
        $img_path = $is_img_icon ? BASE_URL . '/images/' . substr($_item['icon'], 4) : '';
      ?>
      <a href="<?= e($_item['url']) ?>" class="lx-link <?= $_is_act ? 'active' : '' ?> <?= $is_bilingual ? 'bilingual' : '' ?> <?= $is_img_icon ? 'has-img-icon' : '' ?>">
        <span class="lx-link-icon-wrap" style="<?= !$is_img_icon && !$_is_act ? "color: {$i_color}; background-color: {$i_bg};" : "" ?>">
            <?php if ($is_img_icon): ?>
              <img src="<?= e($img_path) ?>" alt="" class="lx-link-img">
            <?php else: ?>
              <i class="fa-solid <?= e($_item['icon']) ?>"></i>
            <?php endif; ?>
        </span>
        <?php if ($is_bilingual): ?>
          <span class="lx-link-bilingual">
            <span class="lx-link-ar"><?= e($_item['ar']) ?></span>
            <span class="lx-link-en"><?= e($_item['en']) ?></span>
          </span>
        <?php else: ?>
          <span class="lx-link-text"><?= e($_name) ?></span>
        <?php endif; ?>

        <?php if ($_bdg): ?>
        <span class="lx-badge <?= $is_alert ? 'alert-pulse' : '' ?>">
            <?= $_bdg > 99 ? '99+' : $_bdg ?>
        </span>
        <?php endif; ?>
      </a>
      <?php endforeach; ?>
    <?php endforeach; ?>
  </nav>

  <div class="lx-user-card">
    <div class="lx-user-avatar">
      <i class="fa-solid fa-user-doctor"></i>
      <div class="lx-user-status"></div>
    </div>
    <div class="lx-user-info">
      <div class="lx-user-name"><?= e($_uname) ?></div>
      <div class="lx-user-role"><?= e($_pname) ?></div>
    </div>
  </div>
</aside>

<div class="sidebar-overlay" id="sidebarOverlay" tabindex="0"></div>

<!-- ═══════════════════════════════════════════════════════════════════
     الجوال فقط — إضافة في آخر الملف بلا أي لمس لما فوق.
     لا @media، لا تعديل على أي سطر من الأصل. جافاسكريبت خالص يضبط
     الأنماط مباشرة وقت التشغيل (يفوز دائماً على أي !important في
     الأنماط أعلاه، وهذه قاعدة ثابتة في كل المتصفحات).
     ═══════════════════════════════════════════════════════════════════ -->
<button type="button" id="sbFloatBtn" aria-label="القائمة" style="display:none">
  <i class="fa-solid fa-bars"></i>
</button>
<script>
(function(){
  var sb  = document.getElementById('luxurySidebar');
  var ov  = document.getElementById('sidebarOverlay');
  var btn = document.getElementById('sbFloatBtn');
  if (!sb || !ov || !btn) return;

  var isRTL = (document.documentElement.getAttribute('dir') || 'rtl') === 'rtl';
  var open  = false;

  function imp(el, prop, val){ el.style.setProperty(prop, val, 'important'); }
  function clr(el, prop){ el.style.removeProperty(prop); }
  var PROPS = ['display','position','top','right','left','width','height',
               'max-width','min-width','flex','z-index','transition','transform'];

  function goMobile(){
    imp(btn,'display','flex'); imp(btn,'align-items','center'); imp(btn,'justify-content','center');
    imp(btn,'position','fixed'); imp(btn,'top','10px');
    imp(btn, isRTL ? 'right':'left', '10px');
    imp(btn,'width','44px'); imp(btn,'height','44px'); imp(btn,'z-index','1100');
    imp(btn,'border','none'); imp(btn,'border-radius','12px'); imp(btn,'cursor','pointer');
    imp(btn,'background','#0d47a1'); imp(btn,'color','#fff'); imp(btn,'font-size','18px');
    imp(btn,'box-shadow','0 3px 12px rgba(13,71,161,.4)');

    imp(sb,'position','fixed'); imp(sb,'top','0'); imp(sb,'height','100vh');
    imp(sb,'width','82vw'); imp(sb,'max-width','320px'); imp(sb,'min-width','0');
    imp(sb,'flex','none'); imp(sb,'z-index','1300');
    imp(sb,'transition','transform .28s cubic-bezier(.16,1,.3,1)');
    imp(sb, isRTL ? 'right':'left', '0');
    imp(sb,'transform', 'translateX(' + (open ? '0' : (isRTL?'105%':'-105%')) + ')');

    // التعتيم: نفرض كل خصائصه صراحة — لا نعتمد على app.css إطلاقاً
    // (هو تحديداً ما فشل: الشعار والقائمة داخل sidebar.php نجحا لأنهما
    // مضمَّنان هنا، بينما التعتيم كان يعتمد على ملف خارجي منفصل)
    imp(ov,'position','fixed'); imp(ov,'top','0'); imp(ov,'right','0');
    imp(ov,'bottom','0'); imp(ov,'left','0');
    imp(ov,'background','rgba(0,0,0,.5)'); imp(ov,'cursor','pointer');
    imp(ov,'z-index','1250');
    imp(ov,'display', open ? 'block' : 'none');
    document.body.style.overflow = open ? 'hidden' : '';
  }
  function goDesktop(){
    PROPS.forEach(function(p){ clr(sb,p); clr(btn,p); });
    ['position','top','right','bottom','left','background','cursor','display','z-index']
      .forEach(function(p){ clr(ov,p); });
    document.body.style.overflow = '';
    open = false;
  }
  function render(){
    window.matchMedia('(max-width: 1024px)').matches ? goMobile() : goDesktop();
  }
  function toggle(v){ open = v; render(); }

  btn.addEventListener('click', function(){ toggle(!open); });
  ov.addEventListener('click', function(){ toggle(false); });
  sb.querySelectorAll('a').forEach(function(a){
    a.addEventListener('click', function(){ toggle(false); });
  });

  render();
  var t;
  window.addEventListener('resize', function(){ clearTimeout(t); t = setTimeout(render, 120); });
})();
</script>