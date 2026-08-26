<?php
/**
 * settings/index.php — إعدادات النظام (Admin فقط)
 */
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/_utils.php';
page_guard('settings.index');
if (!is_admin()) {
    flash('danger', is_rtl()?'المديرون فقط':'Admins only');
    header('Location: ' . BASE_URL . '/dashboard.php'); exit;
}

$rtl = is_rtl();

// ── جداول القوائم القابلة للإدارة ──────────────────────────
$lookup_tables = [
    'supply_types'        => ['ar'=>'أنواع التوريد',         'en'=>'Supply Types',         'icon'=>'fa-truck'],
    'committee_types'     => ['ar'=>'أنواع اللجان',          'en'=>'Committee Types',       'icon'=>'fa-users-gear'],
    'receiving_doc_types' => ['ar'=>'مصادر توريد المحاضر',   'en'=>'Receiving Doc Types',   'icon'=>'fa-file-contract'],
];


// ── جلب الأدوار وصلاحيات سير العمل ──────────────────────────
$all_roles = $pdo->query("SELECT id,name,display_name FROM roles WHERE is_active=1 ORDER BY sort_order")->fetchAll();

// تعريف ميزات سير العمل — نجلب ppid مباشرة من DB
$workflow_features = [];
$wf_raw = [
    'committees' => [
        'label' => $rtl?'وحدة اللجان':'Committees Module',
        'icon'  => 'fa-users-gear',
        'items' => [
            ['page'=>'committees.index','action'=>'create',          'ar'=>'إنشاء لجنة جديدة',            'en'=>'Create committee'],
            ['page'=>'committees.index','action'=>'direct_activate', 'ar'=>'تفعيل مباشر بدون اعتماد ⚡', 'en'=>'Direct activation'],
            ['page'=>'committees.index','action'=>'edit',            'ar'=>'تعديل اللجنة',                'en'=>'Edit committee'],
            ['page'=>'committees.approve','action'=>'view',          'ar'=>'اعتماد/رفض اللجان (تنفيذي)', 'en'=>'Approve committees'],
        ],
    ],
    'receiving' => [
        'label' => $rtl?'وحدة محاضر الاستلام':'Receiving Module',
        'icon'  => 'fa-truck-ramp-box',
        'items' => [
            ['page'=>'receiving.index','action'=>'create', 'ar'=>'إنشاء محضر استلام', 'en'=>'Create minute'],
            ['page'=>'receiving.index','action'=>'edit',   'ar'=>'تعديل المحضر',     'en'=>'Edit minute'],
            ['page'=>'receiving.index','action'=>'approve','ar'=>'التوقيع والاعتماد', 'en'=>'Sign & approve'],
        ],
    ],
];

// جلب page_permission_id من DB لكل ميزة (بالاسم الفعلي من الجدول)
foreach ($wf_raw as $mod => $mdata) {
    $features_resolved = [];
    foreach ($mdata['items'] as $feat) {
        // بحث بالكود أولاً
        $s=$pdo->prepare("SELECT pp.id FROM page_permissions pp JOIN pages p ON p.id=pp.page_id WHERE p.code=? AND pp.action=? LIMIT 1");
        $s->execute([$feat['page'],$feat['action']]); $ppid=(int)$s->fetchColumn();
        // إذا لم يُوجد — ابحث بالـ action فقط عبر صفحات الـ module المشابه
        if(!$ppid){
            $mod_key=explode('.',$feat['page'])[0]; // committees or receiving
            $s2=$pdo->prepare("SELECT pp.id FROM page_permissions pp JOIN pages p ON p.id=pp.page_id WHERE p.code LIKE ? AND pp.action=? LIMIT 1");
            $s2->execute([$mod_key.'%',$feat['action']]); $ppid=(int)$s2->fetchColumn();
        }
        // إذا لا يزال غير موجود — أنشئه
        if(!$ppid){
            $pg=$pdo->prepare("SELECT id FROM pages WHERE code=? OR code LIKE ? LIMIT 1");
            $pg->execute([$feat['page'],explode('.',$feat['page'])[0].'%']); $pg_id=(int)$pg->fetchColumn();
            if($pg_id){
                $pdo->prepare("INSERT IGNORE INTO page_permissions (page_id,action,display_name,display_en,is_active) VALUES(?,?,?,?,1)")
                    ->execute([$pg_id,$feat['action'],$feat['ar'],$feat['en']]);
                $s3=$pdo->prepare("SELECT id FROM page_permissions WHERE page_id=? AND action=? LIMIT 1");
                $s3->execute([$pg_id,$feat['action']]); $ppid=(int)$s3->fetchColumn();
            }
        }
        $features_resolved[]=$feat+['ppid'=>$ppid];
    }
    $workflow_features[$mod]=['label'=>$mdata['label'],'icon'=>$mdata['icon'],'features'=>$features_resolved];
}

// جلب الصلاحيات الحالية لكل دور
$current_perms_by_ppid = [];
$sp=$pdo->query("SELECT role_id, page_permission_id FROM role_permissions")->fetchAll();
foreach($sp as $row) $current_perms_by_ppid[$row['page_permission_id']][$row['role_id']]=true;
// legacy lookup by code+action
$current_perms = [];
$sp2=$pdo->query("SELECT r.name AS role_name, p.code AS page_code, pp.action FROM role_permissions rp INNER JOIN roles r ON r.id=rp.role_id INNER JOIN page_permissions pp ON pp.id=rp.page_permission_id INNER JOIN pages p ON p.id=pp.page_id")->fetchAll();
foreach($sp2 as $row) $current_perms[$row['role_name']][$row['page_code']][$row['action']]=true;

// ── POST: CRUD على القوائم ──────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && verify_csrf()) {
    $action = $_POST['action'] ?? '';
    $table  = $_POST['tbl']    ?? '';

    // ── توجيه إجراءات التصنيفات/المواقع لملف القسم المستقل ──
    if (in_array($action, ['cat_save','cat_delete','loc_save','loc_delete'], true)) {
        define('PMSH_SETTINGS_SECTION', true);
        require_once dirname(__FILE__) . '/_sections/lookup_admin.php';
        exit;
    }

    // منح/سحب صلاحية خاصة لمستخدم محدد
    if ($action === 'grant_user_perm') {
        $target_uid = (int)($_POST['target_user_id'] ?? 0);
        $page_code  = $_POST['page_code']   ?? '';
        $perm_act   = $_POST['perm_action'] ?? '';
        $grant      = (int)($_POST['grant'] ?? 1);
        $reason     = trim($_POST['reason'] ?? '');
        if ($target_uid && $page_code && $perm_act) {
            $ppid=$pdo->prepare("SELECT pp.id FROM page_permissions pp INNER JOIN pages p ON p.id=pp.page_id WHERE p.code=? AND pp.action=? LIMIT 1");
            $ppid->execute([$page_code,$perm_act]); $ppid=(int)$ppid->fetchColumn();
            if ($ppid) {
                if ($grant) {
                    $pdo->prepare("INSERT INTO user_permission_overrides (user_id,page_permission_id,granted,reason,granted_by) VALUES(?,?,1,?,?) ON DUPLICATE KEY UPDATE granted=1,reason=?,granted_by=?")
                        ->execute([$target_uid,$ppid,$reason?:null,user_id(),$reason?:null,user_id()]);
                    flash('success',$rtl?'تم منح الصلاحية للمستخدم ✅':'Permission granted to user ✅');
                } else {
                    $pdo->prepare("DELETE FROM user_permission_overrides WHERE user_id=? AND page_permission_id=?")->execute([$target_uid,$ppid]);
                    flash('success',$rtl?'تم سحب الصلاحية من المستخدم':'Permission revoked from user');
                }
            }
        }
        header('Location:'.$_SERVER['REQUEST_URI'].'#workflow'); exit;
    }

    // معالجة حفظ الصلاحيات
    if ($action === 'save_workflow') {
        $ppid        = (int)($_POST['ppid']        ?? 0);
        $roles_on    = $_POST['roles_on']           ?? [];

        if ($ppid) {
            // دائماً أبقِ Admin مُضمَّناً
            $admin_id=(int)$pdo->query("SELECT id FROM roles WHERE name='admin' LIMIT 1")->fetchColumn();
            if($admin_id && !in_array((string)$admin_id,array_map('strval',$roles_on)))
                $roles_on[]=$admin_id;

            $pdo->prepare("DELETE FROM role_permissions WHERE page_permission_id=?")->execute([$ppid]);
            $si=$pdo->prepare("INSERT IGNORE INTO role_permissions (role_id,page_permission_id) VALUES(?,?)");
            foreach($roles_on as $rid) if((int)$rid) $si->execute([(int)$rid,$ppid]);
            flash('success',$rtl?'✅ تم حفظ الصلاحيات بنجاح':'✅ Permissions saved');
        } else {
            flash('danger',$rtl?'لم يتم العثور على الصلاحية — تأكد من تشغيل migrations':'Permission not found in DB');
        }
        header('Location:'.$_SERVER['REQUEST_URI'].'#workflow'); exit;
    }

    // ── اللجان الثابتة (قبل فحص الجداول) ──────────────────────
    if(in_array($action,['sc_create','sc_add_member','sc_del_member'])){
        if($action==='sc_create'){
            $sct=$_POST['sc_type']??'';
            $scn=trim($_POST['sc_name']??'');
            $scd=$_POST['sc_start_date']??date('Y-m-d');
            if($sct&&$scn){
                $pdo->prepare("UPDATE standing_committees SET end_date=DATE_SUB(?,INTERVAL 1 DAY) WHERE maintenance_type=? AND end_date IS NULL")->execute([$scd,$sct]);
                $pdo->prepare("INSERT INTO standing_committees (maintenance_type,name,start_date,created_by) VALUES(?,?,?,?)")->execute([$sct,$scn,$scd,$uid]);
                $scid=(int)$pdo->lastInsertId();
                $roles_in=$_POST['sc_roles']??[];
                $users_in=$_POST['sc_users']??[];
                $si=$pdo->prepare("INSERT IGNORE INTO standing_committee_members (committee_id,user_id,role,sort_order) VALUES(?,?,?,?)");
                foreach($users_in as $i=>$mu) if((int)$mu) $si->execute([$scid,(int)$mu,$roles_in[$i]??'عضو',$i]);
                flash('success',$rtl?'✅ تم إنشاء اللجنة الثابتة':'✅ Standing committee created');
            }
        } elseif($action==='sc_add_member'){
            $scid=(int)($_POST['sc_id']??0);
            $muid=(int)($_POST['m_user']??0);
            $mrole=$_POST['m_role']??'عضو';
            if($scid&&$muid){
                $cnt=(int)$pdo->prepare("SELECT COUNT(*) FROM standing_committee_members WHERE committee_id=?")->execute([$scid]);
                $pdo->prepare("INSERT IGNORE INTO standing_committee_members (committee_id,user_id,role,sort_order) VALUES(?,?,?,99)")->execute([$scid,$muid,$mrole]);
                flash('success',$rtl?'✅ تمت إضافة العضو':'✅ Member added');
            }
        } elseif($action==='sc_del_member'){
            $memid=(int)($_POST['mem_id']??0);
            if($memid) $pdo->prepare("DELETE FROM standing_committee_members WHERE id=?")->execute([$memid]);
            flash('success',$rtl?'تم حذف العضو':'Member removed');
        }
        header('Location:'.$_SERVER['REQUEST_URI'].'#standing'); exit;
    }

    // ── حفظ الإعدادات العامة (شامل رفع الشعار) — قبل فحص الجداول ──
    if ($action === 'save_settings') {
        $settings_map = [
            'hospital_name'      => trim($_POST['hospital_name']       ?? ''),
            'hospital_name_en'   => trim($_POST['hospital_name_en']    ?? ''),
            'hospital_phone'     => trim($_POST['hospital_phone']       ?? ''),
            'hospital_email'     => trim($_POST['hospital_email']       ?? ''),
            'items_per_page'     => (string)(int)($_POST['items_per_page'] ?? 50),
            'allow_registration' => isset($_POST['allow_registration']) ? '1' : '0',
        ];
        foreach ($settings_map as $key => $val) {
            $pdo->prepare("INSERT INTO system_settings (setting_key,setting_value) VALUES (?,?) ON DUPLICATE KEY UPDATE setting_value=?")
                ->execute([$key, $val, $val]);
        }
        // ── رفع الشعار (logo upload) ──
        if (!empty($_FILES['hospital_logo']['name']) && $_FILES['hospital_logo']['error'] === UPLOAD_ERR_OK) {
            $f = $_FILES['hospital_logo'];
            $ext = strtolower(pathinfo($f['name'], PATHINFO_EXTENSION));
            $allowed = ['png', 'jpg', 'jpeg', 'svg', 'webp'];
            if (in_array($ext, $allowed) && $f['size'] <= 2 * 1024 * 1024) {
                $upload_dir = BASE_PATH . '/uploads/branding/';
                if (!is_dir($upload_dir)) mkdir($upload_dir, 0755, true);
                $new_name = 'hospital_logo_' . time() . '.' . $ext;
                $target = $upload_dir . $new_name;
                if (move_uploaded_file($f['tmp_name'], $target)) {
                    $logo_url = '/uploads/branding/' . $new_name;
                    $pdo->prepare("INSERT INTO system_settings (setting_key,setting_value) VALUES ('hospital_logo',?) ON DUPLICATE KEY UPDATE setting_value=?")
                        ->execute([$logo_url, $logo_url]);
                }
            }
        }
        // ── حذف الشعار إذا طلب المستخدم ──
        if (isset($_POST['remove_logo']) && $_POST['remove_logo'] === '1') {
            $old = $sys['hospital_logo'] ?? '';
            if ($old && str_starts_with($old, '/uploads/branding/')) {
                @unlink(BASE_PATH . $old);
            }
            $pdo->prepare("DELETE FROM system_settings WHERE setting_key='hospital_logo'")->execute();
        }
        flash('success', $rtl?'تم حفظ الإعدادات':'Settings saved');
        header('Location: ' . $_SERVER['REQUEST_URI'] . '#general'); exit;
    }

    // التحقق من أن الجدول مسموح به

    if (!array_key_exists($table, $lookup_tables)) {
        flash('danger', $rtl?'جدول غير مسموح':'Invalid table');
        header('Location: ' . $_SERVER['REQUEST_URI']); exit;
    }

    if ($action === 'add') {
        $name    = trim($_POST['name']    ?? '');
        $name_en = trim($_POST['name_en'] ?? '');
        if ($name) {
            $max = (int)$pdo->query("SELECT COALESCE(MAX(sort_order),0) FROM `$table`")->fetchColumn();
            $pdo->prepare("INSERT INTO `$table` (name, name_en, sort_order, is_active) VALUES (?,?,?,1)")
                ->execute([$name, $name_en ?: null, $max + 1]);
            flash('success', $rtl?'تمت الإضافة':'Added successfully');
        }
    } elseif ($action === 'edit') {
        $id      = (int)$_POST['id'];
        $name    = trim($_POST['name']    ?? '');
        $name_en = trim($_POST['name_en'] ?? '');
        $sort    = (int)$_POST['sort_order'];
        $active  = isset($_POST['is_active']) ? 1 : 0;
        if ($name && $id) {
            if ($table === 'committee_types') {
                $req_approval = isset($_POST['requires_approval']) ? 1 : 0;
                $pdo->prepare("UPDATE `$table` SET name=?,name_en=?,sort_order=?,is_active=?,requires_approval=? WHERE id=?")
                    ->execute([$name, $name_en ?: null, $sort, $active, $req_approval, $id]);
            } else {
                $pdo->prepare("UPDATE `$table` SET name=?,name_en=?,sort_order=?,is_active=? WHERE id=?")
                    ->execute([$name, $name_en ?: null, $sort, $active, $id]);
            }
            flash('success', $rtl?'تم التعديل':'Updated');
        }
    } elseif ($action === 'delete') {
        $id = (int)$_POST['id'];
        if ($id) {
            $pdo->prepare("DELETE FROM `$table` WHERE id=?")->execute([$id]);
            flash('success', $rtl?'تم الحذف':'Deleted');
        }
    }

    header('Location: ' . $_SERVER['REQUEST_URI'] . '#' . $table); exit;
}


// ── اللجان الثابتة ────────────────────────────────────────────
$sc_types = ['medical'=>'صيانة طبية','general'=>'صيانة عامة','it'=>'تقنية المعلومات'];
$sc_data  = [];
foreach(array_keys($sc_types) as $sct){
    $q=$pdo->prepare("SELECT sc.id,sc.name,sc.start_date,sc.end_date FROM standing_committees sc WHERE sc.maintenance_type=? ORDER BY sc.id DESC LIMIT 1");
    $q->execute([$sct]); $sc=$q->fetch();
    $mems=[];
    if($sc){
        $mq=$pdo->prepare("SELECT scm.id,scm.role,scm.sort_order,u.id AS uid,u.full_name AS uname FROM standing_committee_members scm INNER JOIN users u ON u.id=scm.user_id WHERE scm.committee_id=? ORDER BY scm.sort_order,scm.role");
        $mq->execute([$sc['id']]); $mems=$mq->fetchAll();
    }
    $sc_data[$sct]=['committee'=>$sc,'members'=>$mems];
}
// قائمة المستخدمين لإضافة أعضاء
$all_users_list=$pdo->query("SELECT id,full_name AS name FROM users WHERE is_active=1 ORDER BY full_name")->fetchAll();

// ── جلب البيانات ────────────────────────────────────────────
$data = [];
foreach (array_keys($lookup_tables) as $tbl) {
    $data[$tbl] = $pdo->query("SELECT * FROM `$tbl` ORDER BY sort_order, id")->fetchAll();
}

// إعدادات النظام
$sys = [];
foreach ($pdo->query("SELECT setting_key AS key_name, setting_value AS value FROM system_settings")->fetchAll() as $row) {
    $sys[$row['key_name']] = $row['value'];
}

$page_title = $rtl ? 'إعدادات النظام' : 'System Settings';
$page_icon  = 'fa-gear';
$active_nav = 'settings.index';
$breadcrumb = [];
$flash_msgs = get_flash();
?>

<!DOCTYPE html>
<html lang="<?= e(lang_attr()) ?>" dir="<?= e(lang_dir()) ?>">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1.0">
<title><?= e($page_title) ?> — <?= e(get_setting('hospital_name','PMSH')) ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Tajawal:wght@400;500;700;800&family=Inter:wght@400;500;600&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<link rel="stylesheet" href="<?= BASE_URL ?>/css/app.css">
<style>
/* --- متغيرات التصميم المتقدمة (Design System) --- */
:root {
  --sys-bg: #f8fafc;
  --card-bg: #ffffff;
  --text-main: #0f172a;
  --text-muted: #64748b;
  --text-light: #94a3b8;
  --border-light: #e2e8f0;
  --border-focus: #3b82f6;
  --primary: #2563eb;
  --primary-hover: #1d4ed8;
  --primary-glow: rgba(37, 99, 235, 0.15);
  --danger: #ef4444;
  --danger-bg: #fef2f2;
  --success: #10b981;
  --success-bg: #ecfdf5;
  --warning: #f59e0b;
  --warning-bg: #fffbeb;
  
  --shadow-sm: 0 1px 2px 0 rgba(0, 0, 0, 0.05);
  --shadow-md: 0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -1px rgba(0, 0, 0, 0.06);
  --shadow-lg: 0 10px 15px -3px rgba(0, 0, 0, 0.1), 0 4px 6px -2px rgba(0, 0, 0, 0.05);
  --radius-md: 8px;
  --radius-lg: 12px;
  --radius-xl: 16px;
  --radius-pill: 9999px;
  --transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1);
}

body.app-layout { background-color: var(--sys-bg); font-family: 'Tajawal', 'Inter', sans-serif; }
.page-content { padding: 24px; max-width: 1400px; margin: 0 auto; }

/* --- التنبيهات (Alerts) --- */
.alert { padding: 16px; border-radius: var(--radius-md); font-weight: 600; font-size: 14px; display: flex; align-items: center; gap: 12px; margin-bottom: 24px; animation: slideDown 0.4s ease-out; box-shadow: var(--shadow-sm); }
.alert-success { background: var(--success-bg); color: #047857; border-inline-start: 4px solid var(--success); }
.alert-danger { background: var(--danger-bg); color: #b91c1c; border-inline-start: 4px solid var(--danger); }
@keyframes slideDown { from { opacity: 0; transform: translateY(-10px); } to { opacity: 1; transform: translateY(0); } }

/* --- التبويبات (Tabs Navigation) --- */
.tabs-bar { display: flex; gap: 8px; padding-bottom: 24px; overflow-x: auto; -webkit-overflow-scrolling: touch; scrollbar-width: none; border-bottom: 1px solid var(--border-light); margin-bottom: 24px; }
.tabs-bar::-webkit-scrollbar { display: none; }
.tab-pill { display: inline-flex; align-items: center; gap: 8px; padding: 12px 20px; border-radius: var(--radius-pill); border: 1px solid transparent; background: transparent; color: var(--text-muted); font-family: inherit; font-size: 14px; font-weight: 600; cursor: pointer; white-space: nowrap; transition: var(--transition); }
.tab-pill:hover { background: var(--border-light); color: var(--text-main); }
.tab-pill.active { background: var(--card-bg); color: var(--primary); border-color: var(--border-light); box-shadow: var(--shadow-sm); }
.tab-pill i { font-size: 14px; }
.tab-badge { font-size: 11px; font-weight: 700; padding: 2px 8px; border-radius: var(--radius-pill); font-family: 'Inter'; transition: var(--transition); }
.tab-panel { display: none; animation: fadeIn 0.3s ease-out; }
.tab-panel.active { display: block; }
@keyframes fadeIn { from { opacity: 0; transform: translateY(5px); } to { opacity: 1; transform: translateY(0); } }

/* --- البطاقات (Cards) --- */
.s-card { background: var(--card-bg); border-radius: var(--radius-xl); box-shadow: var(--shadow-md); border: 1px solid rgba(226, 232, 240, 0.8); margin-bottom: 24px; overflow: hidden; transition: var(--transition); }
.s-card:hover { box-shadow: var(--shadow-lg); }
.s-card-head { padding: 20px 24px; border-bottom: 1px solid var(--border-light); display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 12px; background: rgba(248, 250, 252, 0.4); }
.s-card-title { font-size: 16px; font-weight: 700; color: var(--text-main); display: flex; align-items: center; gap: 10px; }
.s-card-title i { color: var(--primary); font-size: 18px; padding: 8px; background: var(--primary-glow); border-radius: var(--radius-md); }

/* --- الشبكات والنماذج (Forms & Grids) --- */
.sg-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 20px; padding: 24px; }
.sgg, .fg { display: flex; flex-direction: column; gap: 8px; }
.sgg label, .fg label { font-size: 13px; font-weight: 700; color: var(--text-muted); display: flex; align-items: center; gap: 6px; }
.sgi, .mfi, .lmi, .add-row input { height: 44px; padding: 0 16px; border: 1px solid var(--border-light); border-radius: var(--radius-md); font-family: inherit; font-size: 14px; color: var(--text-main); background: #fdfdfd; transition: var(--transition); width: 100%; box-sizing: border-box; }
.sgi:hover, .mfi:hover, .lmi:hover, .add-row input:hover { border-color: #cbd5e1; }
.sgi:focus, .mfi:focus, .lmi:focus, .add-row input:focus { border-color: var(--primary); outline: none; box-shadow: 0 0 0 3px var(--primary-glow); background: var(--card-bg); }

/* --- الأزرار (Buttons) --- */
.s-btn { display: inline-flex; align-items: center; justify-content: center; gap: 8px; padding: 10px 20px; border-radius: var(--radius-md); font-family: inherit; font-size: 14px; font-weight: 600; cursor: pointer; transition: var(--transition); border: 1px solid transparent; text-decoration: none; }
.s-btn-primary { background: var(--primary); color: #fff; box-shadow: 0 2px 4px rgba(37,99,235,0.2); }
.s-btn-primary:hover { background: var(--primary-hover); transform: translateY(-1px); box-shadow: 0 4px 6px rgba(37,99,235,0.3); }
.s-btn-outline { background: var(--card-bg); border-color: var(--border-light); color: var(--text-muted); }
.s-btn-outline:hover { background: var(--sys-bg); color: var(--text-main); border-color: #cbd5e1; }
.ab { width: 34px; height: 34px; border-radius: var(--radius-md); border: 1px solid; display: inline-flex; align-items: center; justify-content: center; cursor: pointer; font-size: 13px; transition: var(--transition); background: var(--card-bg); }
.ab-edit { color: var(--warning); border-color: #fcd34d; } .ab-edit:hover { background: var(--warning); color: #fff; }
.ab-del { color: var(--danger); border-color: #fca5a5; } .ab-del:hover { background: var(--danger); color: #fff; }

/* --- الجداول (Tables) --- */
.lt-table { width: 100%; border-collapse: separate; border-spacing: 0; }
.lt-table th { font-size: 12px; font-weight: 700; color: var(--text-muted); text-transform: uppercase; letter-spacing: 0.5px; padding: 16px 24px; background: rgba(248, 250, 252, 0.8); border-bottom: 1px solid var(--border-light); text-align: start; }
.lt-table td { padding: 16px 24px; font-size: 14px; border-bottom: 1px solid var(--sys-bg); vertical-align: middle; color: var(--text-main); transition: background 0.15s; }
.lt-table tr:last-child td { border-bottom: none; }
.lt-table tr:hover td { background: var(--sys-bg); }
.status-dot { width: 10px; height: 10px; border-radius: 50%; display: inline-block; }
.add-row { display: flex; gap: 12px; padding: 16px 24px; background: rgba(248, 250, 252, 0.5); border-top: 1px solid var(--border-light); align-items: center; flex-wrap: wrap; }

/* --- النوافذ المنبثقة (Modals) --- */
.lt-modal { display: none; position: fixed; inset: 0; z-index: 9999; background: rgba(15, 23, 42, 0.6); backdrop-filter: blur(8px); -webkit-backdrop-filter: blur(8px); align-items: center; justify-content: center; padding: 20px; opacity: 0; transition: opacity 0.3s ease; }
.lt-modal.open { display: flex; opacity: 1; }
.lt-modal-box { background: var(--card-bg); border-radius: var(--radius-xl); width: 100%; max-width: 500px; padding: 32px; box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.25); transform: scale(0.95); transition: transform 0.3s cubic-bezier(0.34, 1.56, 0.64, 1); border: 1px solid rgba(255,255,255,0.1); }
.lt-modal.open .lt-modal-box { transform: scale(1); }
.lt-modal-head { display: flex; align-items: center; justify-content: space-between; padding-bottom: 20px; border-bottom: 1px solid var(--border-light); margin-bottom: 24px; font-size: 18px; font-weight: 700; color: var(--text-main); }
.lm-title { font-size: 18px; font-weight: 700; margin-bottom: 24px; color: var(--text-main); display: flex; align-items: center; gap: 10px; }
.lm-grid { display: flex; flex-direction: column; gap: 16px; }

/* --- بطاقة اختبار الذكاء الاصطناعي (AI Diagnostic) --- */
.ai-test-card { border-radius: var(--radius-lg); overflow: hidden; transition: all 0.4s ease; margin-top: 24px; }
.ai-test-card.idle { background: #faf5ff; border: 1px dashed #d8b4fe; }
.ai-test-card.testing { background: #eff6ff; border: 1px solid #93c5fd; box-shadow: 0 0 15px rgba(59, 130, 246, 0.2); }
.ai-test-card.success { background: var(--success-bg); border: 1px solid #6ee7b7; }
.ai-test-card.error { background: var(--danger-bg); border: 1px solid #fca5a5; }
.ai-test-inner { padding: 24px; display: flex; align-items: flex-start; gap: 20px; }
.ai-test-icon { width: 56px; height: 56px; border-radius: var(--radius-lg); display: flex; align-items: center; justify-content: center; font-size: 24px; flex-shrink: 0; transition: var(--transition); }
.ai-test-icon.idle { background: #f3e8ff; color: #9333ea; }
.ai-test-icon.testing { background: #dbeafe; color: var(--primary); }
.ai-test-icon.success { background: #d1fae5; color: var(--success); }
.ai-test-icon.error { background: #fee2e2; color: var(--danger); }
.ai-test-info { flex: 1; min-width: 0; }
.ai-test-title { font-size: 16px; font-weight: 700; margin-bottom: 4px; color: var(--text-main); }
.ai-test-sub { font-size: 13px; color: var(--text-muted); line-height: 1.6; }
.ai-test-meta { display: flex; gap: 10px; margin-top: 16px; flex-wrap: wrap; }
.ai-test-tag { font-size: 12px; font-weight: 600; padding: 4px 12px; border-radius: var(--radius-pill); display: inline-flex; align-items: center; gap: 6px; box-shadow: var(--shadow-sm); }
.ai-test-preview { margin-top: 16px; padding: 12px 16px; background: rgba(255,255,255,0.8); border-radius: var(--radius-md); font-size: 13px; color: var(--text-muted); font-family: 'Inter', monospace; border: 1px solid var(--border-light); }

/* --- تخصيصات إضافية --- */
.custom-checkbox { display: flex; align-items: center; gap: 10px; cursor: pointer; padding: 10px 14px; border: 1px solid var(--border-light); border-radius: var(--radius-md); transition: var(--transition); background: var(--sys-bg); }
.custom-checkbox:hover { border-color: var(--primary); background: #fff; }
.custom-checkbox input[type="checkbox"] { width: 18px; height: 18px; accent-color: var(--primary); cursor: pointer; }
.custom-checkbox span { font-size: 14px; font-weight: 600; color: var(--text-main); }
</style>
</head><body class="app-layout">
<?php include BASE_PATH.'/includes/sidebar.php'; ?>
<div class="main-area" id="mainArea">
<?php include BASE_PATH.'/includes/topbar.php'; ?>
<main class="page-content">

<?php foreach($flash_msgs as $fm): ?>
<div class="alert alert-<?= e($fm['type']) ?>">
  <i class="fa-solid fa-circle-<?= $fm['type']==='success'?'check':'exclamation' ?>"></i>
  <span><?= e($fm['message']) ?></span>
</div>
<?php endforeach; ?>

<div class="tabs-bar" id="tabsBar">
  <button type="button" class="tab-pill active" data-tab="hospital">
    <i class="fa-solid fa-hospital"></i> <?= $rtl?'المستشفى':'Hospital' ?>
  </button>
  <button type="button" class="tab-pill" data-tab="ai">
    <i class="fa-solid fa-robot"></i> <?= $rtl?'الذكاء الاصطناعي':'AI' ?>
    <span class="tab-badge" style="background:#f3e8ff;color:#9333ea" id="aiProviderBadge"><?= strtoupper(ai_provider()) ?></span>
  </button>
  <button type="button" class="tab-pill" data-tab="workflow">
    <i class="fa-solid fa-gears"></i> <?= $rtl?'سير العمل':'Workflow' ?>
  </button>
  <button type="button" class="tab-pill" data-tab="standing">
    <i class="fa-solid fa-users-gear"></i> <?= $rtl?'اللجان':'Standing' ?>
  </button>
  <button type="button" class="tab-pill" data-tab="lookups">
    <i class="fa-solid fa-list"></i> <?= $rtl?'القوائم':'Lookups' ?>
  </button>
  <button type="button" class="tab-pill" data-tab="categories">
    <i class="fa-solid fa-folder-tree"></i> <?= $rtl?'التصنيفات':'Categories' ?>
  </button>
  <button type="button" class="tab-pill" data-tab="locations">
    <i class="fa-solid fa-location-dot"></i> <?= $rtl?'المواقع':'Locations' ?>
  </button>
</div>

<!-- TAB: Hospital -->
<div class="tab-panel active" id="tab-hospital">
  <div class="s-card">
    <div class="s-card-head">
      <div class="s-card-title"><i class="fa-solid fa-hospital"></i><?= $rtl?'إعدادات المستشفى الأساسية':'Hospital General Settings' ?></div>
    </div>
    <form method="POST" action="" enctype="multipart/form-data">
      <?= csrf_input() ?>
      <input type="hidden" name="action" value="save_settings">
      <input type="hidden" name="tbl" value="system_settings">
      <div class="sg-grid">
        <div class="sgg">
          <label><i class="fa-solid fa-language"></i> <?= $rtl?'اسم المستشفى (عربي)':'Hospital Name (AR)' ?></label>
          <input type="text" name="hospital_name" class="sgi" value="<?= e($sys['hospital_name']??'') ?>" placeholder="<?= $rtl?'مستشفى...':'Hospital...' ?>">
        </div>
        <div class="sgg">
          <label><i class="fa-solid fa-language"></i> <?= $rtl?'Hospital Name (EN)':'Hospital Name (EN)' ?></label>
          <input type="text" name="hospital_name_en" class="sgi" value="<?= e($sys['hospital_name_en']??'') ?>" placeholder="Hospital...">
        </div>
        <div class="sgg">
          <label><i class="fa-solid fa-phone"></i> <?= $rtl?'رقم الهاتف':'Phone Number' ?></label>
          <input type="tel" name="hospital_phone" class="sgi" value="<?= e($sys['hospital_phone']??'') ?>" placeholder="+966...">
        </div>
        <div class="sgg">
          <label><i class="fa-solid fa-envelope"></i> <?= $rtl?'البريد الإلكتروني':'Email Address' ?></label>
          <input type="email" name="hospital_email" class="sgi" value="<?= e($sys['hospital_email']??'') ?>" placeholder="info@hospital.com">
        </div>
        <div class="sgg">
          <label><i class="fa-solid fa-list-ol"></i> <?= $rtl?'عناصر الصفحة (Pagination)':'Items per page' ?></label>
          <input type="number" name="items_per_page" class="sgi" min="10" max="200" value="<?= e($sys['items_per_page']??50) ?>">
        </div>
        <div class="sgg" style="justify-content: flex-end;">
          <label class="custom-checkbox">
            <input type="checkbox" id="allow_reg" name="allow_registration" <?= ($sys['allow_registration']??'1')==='1'?'checked':'' ?>>
            <span><?= $rtl?'السماح بالتسجيل الذاتي للمستخدمين':'Allow self-registration for users' ?></span>
          </label>
        </div>
      </div>
      
      <!-- Logo Upload Section -->
      <div style="padding: 24px; border-top: 1px solid var(--border-light); background: rgba(248, 250, 252, 0.6);">
        <label style="font-size: 14px; font-weight: 700; color: var(--text-main); margin-bottom: 12px; display: flex; align-items: center; gap: 8px;">
          <i class="fa-solid fa-image" style="color: var(--primary);"></i>
          <?= $rtl?'شعار المستشفى (يظهر في التقارير والطباعة)':'Hospital Logo (shown in reports & print)' ?>
        </label>
        <div style="display: flex; align-items: center; gap: 20px; flex-wrap: wrap; background: #fff; padding: 16px; border-radius: var(--radius-lg); border: 1px solid var(--border-light); box-shadow: var(--shadow-sm);">
          <?php if (!empty($sys['hospital_logo'])): ?>
            <div style="padding: 8px; border: 1px solid var(--border-light); border-radius: var(--radius-md); background: var(--sys-bg);">
              <img src="<?= BASE_URL . e($sys['hospital_logo']) ?>" alt="logo" style="height: 60px; width: auto; max-width: 150px; object-fit: contain; display: block;">
            </div>
            <label class="custom-checkbox" style="border-color: #fca5a5; background: #fef2f2;">
              <input type="checkbox" name="remove_logo" value="1" style="accent-color: var(--danger);">
              <span style="color: var(--danger);"><i class="fa-solid fa-trash-can"></i> <?= $rtl?'حذف الشعار الحالي':'Remove current logo' ?></span>
            </label>
          <?php else: ?>
            <div style="width: 80px; height: 60px; background: var(--sys-bg); border: 1.5px dashed #cbd5e1; border-radius: var(--radius-md); display: flex; align-items: center; justify-content: center; color: var(--text-light); font-size: 24px;">
              <i class="fa-solid fa-image"></i>
            </div>
          <?php endif; ?>
          <div style="flex: 1; min-width: 250px;">
            <input type="file" name="hospital_logo" accept="image/png,image/jpeg,image/svg+xml,image/webp" class="sgi" style="padding: 8px; height: auto;">
            <div style="font-size: 12px; color: var(--text-muted); margin-top: 8px; font-weight: 600;">
              <i class="fa-solid fa-circle-info"></i> <?= $rtl?'الصيغ المدعومة: PNG, JPG, SVG. الحد الأقصى: 2MB':'Supported formats: PNG, JPG, SVG. Max size: 2MB' ?>
            </div>
          </div>
        </div>
      </div>
      <div style="padding: 20px 24px; display: flex; justify-content: flex-end; border-top: 1px solid var(--border-light); background: #fff;">
        <button type="submit" class="s-btn s-btn-primary"><i class="fa-solid fa-floppy-disk"></i> <?= $rtl?'حفظ التغييرات':'Save Changes' ?></button>
      </div>
    </form>
  </div>
</div>

<!-- TAB: AI Provider -->
<?php
  $ai_provider = ai_provider();
  $ai_model    = ai_model();
  $ai_base_url = ai_base_url();
  $has_db_key  = (string)get_setting('groq_api_key', '') !== '';
  $masked_key  = $has_db_key ? '••••••••••••' . substr(ai_key(), -4) : '';
?>
<div class="tab-panel" id="tab-ai">
  <div class="s-card" style="border-inline-start: 4px solid #9333ea;">
    <div class="s-card-head">
      <div class="s-card-title">
        <i class="fa-solid fa-robot" style="color: #9333ea; background: #f3e8ff;"></i>
        <?= $rtl?'إعدادات مزود الذكاء الاصطناعي (AI Integration)':'AI Provider Integration Settings' ?>
      </div>
    </div>
    
    <div style="padding: 16px 24px; background: #faf5ff; border-bottom: 1px solid #e9d5ff; font-size: 13.5px; color: #6b21a8; display: flex; gap: 12px; align-items: flex-start;">
      <i class="fa-solid fa-shield-halved" style="font-size: 18px; margin-top: 2px;"></i>
      <div>
        <div style="font-weight: 700; margin-bottom: 4px;"><?= $rtl?'تشفير وحماية البيانات':'Data Encryption & Security' ?></div>
        <?= $rtl?'المفتاح يُشفّر في قاعدة البيانات (AES-256). جميع الخدمات تقرأ من نفس المصدر، تغيير واحد هنا ينعكس على النظام بأكمله.':'Key is encrypted in DB (AES-256). One change here updates everything globally.' ?>
        <div style="margin-top: 8px; font-weight: 600;">
          <?php if (!$has_db_key): ?>
            <span style="color: var(--warning); background: var(--warning-bg); padding: 4px 10px; border-radius: var(--radius-pill); border: 1px solid #fde68a;"><i class="fa-solid fa-triangle-exclamation"></i> <?= $rtl?'يُستخدم المفتاح من config.php حالياً':'Currently using key from config.php (fallback)' ?></span>
          <?php else: ?>
            <span style="color: var(--success); background: var(--success-bg); padding: 4px 10px; border-radius: var(--radius-pill); border: 1px solid #a7f3d0;"><i class="fa-solid fa-lock"></i> <?= $rtl?'المفتاح محفوظ بأمان في قاعدة البيانات':'Key is securely read from DB (encrypted)' ?></span>
          <?php endif; ?>
        </div>
      </div>
    </div>

    <form id="aiSettingsForm" style="padding: 24px;">
      <?= csrf_input() ?>
      <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 20px;">
        <div class="fg">
          <label><?= $rtl?'مزود الخدمة (Provider)':'Provider' ?></label>
          <select name="provider" id="aiProvider" class="mfi" onchange="onProviderChange()">
            <option value="groq" <?= $ai_provider==='groq'?'selected':'' ?>>Groq (<?= $rtl?'أداء فائق السرعة':'Ultra Fast' ?>)</option>
            <option value="openai" <?= $ai_provider==='openai'?'selected':'' ?>>OpenAI (<?= $rtl?'أعلى دقة وجودة':'Highest Quality' ?>)</option>
            <option value="deepseek" <?= $ai_provider==='deepseek'?'selected':'' ?>>DeepSeek (<?= $rtl?'اقتصادي وفعال':'Cost Effective' ?>)</option>
            <option value="custom" <?= $ai_provider==='custom'?'selected':'' ?>><?= $rtl?'مخصص (OpenAI-compatible)':'Custom (OpenAI-compatible)' ?></option>
          </select>
        </div>
        <div class="fg">
          <label><?= $rtl?'نموذج الذكاء الاصطناعي (Model)':'AI Model' ?></label>
          <div style="display:flex;gap:0;align-items:stretch">
            <input type="text" name="model" id="aiModel" class="mfi" value="<?= e($ai_model) ?>" placeholder="e.g. gpt-4o-mini" style="flex:1;border-top-right-radius:0;border-bottom-right-radius:0;border-right:none">
            <button type="button" id="discoverModelsBtn" onclick="discoverModels()" class="s-btn s-btn-outline" style="border-top-left-radius:0;border-bottom-left-radius:0;padding:0 14px;font-size:12px;white-space:nowrap;border-left:1px solid #e2e8f0" title="<?= $rtl?'استكشاف النماذج المتاحة من المزود':'Discover available models from provider' ?>">
              <i class="fa-solid fa-wand-magic-sparkles"></i> <?= $rtl?'استكشاف':'Discover' ?>
            </button>
          </div>
          <div id="modelsDropdown" style="display:none;margin-top:6px;background:#fff;border:1.5px solid #e2e8f0;border-radius:10px;max-height:240px;overflow-y:auto;box-shadow:0 4px 16px rgba(0,0,0,.08)">
            <div id="modelsLoading" style="padding:16px;text-align:center;color:#94a3b8;font-size:12px">
              <i class="fa-solid fa-circle-notch fa-spin"></i> <?= $rtl?'جاري جلب النماذج...':'Loading models...' ?>
            </div>
            <div id="modelsList"></div>
          </div>
        </div>
        <div class="fg" style="grid-column: 1 / -1;">
          <label><?= $rtl?'مفتاح الربط (API Key)':'API Key' ?></label>
          <div style="display: flex; gap: 8px; align-items: center;">
            <input type="password" name="api_key" id="aiKey" class="mfi" style="flex: 1; font-family: monospace; letter-spacing: 1px;" dir="ltr"
                   placeholder="<?= $has_db_key ? 'اتركه فارغاً للإبقاء على المفتاح الحالي' : 'sk-... or gsk-...' ?>" value="">
            <?php if ($has_db_key): ?>
            <button type="button" onclick="toggleShowKey()" class="s-btn s-btn-outline" style="height: 44px; min-width: 120px;" title="Show last 4">
              <i class="fa-solid fa-eye"></i> <?= e($masked_key) ?>
            </button>
            <label class="custom-checkbox" style="height: 44px; margin: 0; border-color: #fca5a5; color: var(--danger);">
              <input type="checkbox" name="clear_key" value="1" style="accent-color: var(--danger);"> <?= $rtl?'حذف':'Delete' ?>
            </label>
            <?php endif; ?>
          </div>
        </div>
        <div class="fg" style="grid-column: 1 / -1;">
          <label><?= $rtl?'الرابط الأساسي (Base URL)':'Base URL' ?></label>
          <input type="url" name="base_url" id="aiBaseUrl" class="mfi" value="<?= e($ai_base_url) ?>" dir="ltr" placeholder="https://api.openai.com/v1" style="font-family: monospace;">
        </div>
      </div>
      <div style="display: flex; gap: 12px; margin-top: 24px; align-items: center; flex-wrap: wrap; padding-top: 20px; border-top: 1px solid var(--border-light);">
        <button type="button" class="s-btn s-btn-primary" onclick="saveAISettings()" id="aiSaveBtn">
          <i class="fa-solid fa-floppy-disk"></i> <?= $rtl?'حفظ وتطبيق':'Save & Apply' ?>
        </button>
        <button type="button" class="s-btn s-btn-outline" onclick="testAIConnection()" id="aiTestBtn" style="border-color: #d8b4fe; color: #9333ea;">
          <i class="fa-solid fa-plug-circle-bolt"></i> <?= $rtl?'اختبار الاتصال':'Test Connection' ?>
        </button>
        <span id="aiStatus" style="font-size: 14px; font-weight: 600; margin-inline-start: auto;"></span>
      </div>
    </form>
  </div>

  <!-- AI Diagnostic Card -->
  <div class="ai-test-card idle" id="aiTestCard">
    <div class="ai-test-inner">
      <div class="ai-test-icon idle" id="aiTestIcon"><i class="fa-solid fa-plug-circle-bolt"></i></div>
      <div class="ai-test-info">
        <div class="ai-test-title" id="aiTestTitle"><?= $rtl?'النظام جاهز لاختبار الاتصال':'Ready to test API connection' ?></div>
        <div class="ai-test-sub" id="aiTestSub"><?= $rtl?'اضغط على زر "اختبار الاتصال" للتحقق من صحة الإعدادات والمفتاح المدخل':'Click "Test Connection" to verify provider settings and API key validity' ?></div>
        <div class="ai-test-meta" id="aiTestMeta" style="display:none"></div>
        <div class="ai-test-preview" id="aiTestPreview" style="display:none"></div>
      </div>
    </div>
  </div>
</div>

<!-- TAB: Workflow -->
<div class="tab-panel" id="tab-workflow">
  <div class="s-card">
    <div class="s-card-head">
      <div class="s-card-title"><i class="fa-solid fa-gears"></i><?= $rtl?'صلاحيات ومسارات العمل (Role-based Workflow)':'Role-based Workflow Settings' ?></div>
    </div>
    <div style="padding: 24px;">
      <div class="alert alert-warning" style="background: var(--warning-bg); border: 1px solid #fde68a; color: #92400e;">
        <i class="fa-solid fa-triangle-exclamation" style="font-size: 18px;"></i>
        <div>
          <strong><?= $rtl?'تنبيه هام:':'Important Notice:' ?></strong><br>
          <?= $rtl?'هذه الإعدادات تتحكم ديناميكياً في سلوك الإجراءات لكل الأدوار. أي تغيير هنا يسري فوراً على جميع المستخدمين.':'These settings dynamically control workflow behaviors. Changes take effect immediately for all users.' ?>
        </div>
      </div>

      <?php foreach($workflow_features as $mod=>$mdata): ?>
      <div style="margin-bottom: 24px; border: 1px solid var(--border-light); border-radius: var(--radius-lg); overflow: hidden; box-shadow: var(--shadow-sm);">
        <div style="padding: 16px 20px; background: rgba(248, 250, 252, 0.8); border-bottom: 1px solid var(--border-light); font-size: 15px; font-weight: 700; color: var(--text-main); display: flex; align-items: center; gap: 10px;">
          <i class="fa-solid <?= $mdata['icon'] ?>" style="color: var(--primary); background: var(--primary-glow); padding: 6px; border-radius: 6px;"></i>
          <?= $mdata['label'] ?>
        </div>
        
        <div style="background: #fff;">
          <?php foreach($mdata['features'] as $feat):
            $ppid_feat = $feat['ppid'] ?? 0;
            $flabel = $rtl ? $feat['ar'] : $feat['en'];
          ?>
          <div style="padding: 16px 20px; border-bottom: 1px solid var(--sys-bg); display: flex; align-items: center; gap: 24px; flex-wrap: wrap;">
            <div style="min-width: 220px; flex: 1;">
              <div style="font-size: 14px; font-weight: 700; color: var(--text-main); margin-bottom: 4px;"><?= e($flabel) ?></div>
              <div style="font-size: 12px; color: var(--text-muted); font-family: 'Inter', sans-serif;">
                <?php if(!$ppid_feat): ?>
                <span style="color: var(--danger); background: var(--danger-bg); padding: 2px 8px; border-radius: 4px;"><i class="fa-solid fa-triangle-exclamation"></i> <?= $rtl?'غير موجودة بالجدول':'Not in DB' ?></span>
                <?php else: ?>
                <span style="color: var(--success); background: var(--success-bg); padding: 2px 8px; border-radius: 4px; border: 1px solid #a7f3d0;"><i class="fa-solid fa-fingerprint"></i> ID: <?= $ppid_feat ?></span>
                <span style="margin-inline-start: 8px; opacity: 0.7;"><?= e($feat['page']) ?> <i class="fa-solid fa-arrow-right-long" style="font-size: 10px;"></i> <?= e($feat['action']) ?></span>
                <?php endif; ?>
              </div>
            </div>
            
            <?php if($ppid_feat): ?>
            <form method="POST" style="flex: 2; min-width: 300px;">
              <?= csrf_input() ?>
              <input type="hidden" name="action" value="save_workflow">
              <input type="hidden" name="ppid" value="<?= $ppid_feat ?>">
              <div style="display: flex; flex-wrap: wrap; gap: 10px; align-items: center;">
                <?php foreach($all_roles as $role):
                  $has = !empty($current_perms_by_ppid[$ppid_feat][$role['id']]);
                  $isDirect = $feat['action']==='direct_activate';
                  $isAdmin  = $role['name']==='admin';
                  
                  // Styling variables
                  $bgc = $has ? ($isDirect ? '#fef9c3' : '#eff6ff') : '#f1f5f9';
                  $bdc = $has ? ($isDirect ? '#fde047' : '#bfdbfe') : '#e2e8f0';
                  $txc = $has ? ($isDirect ? '#854d0e' : '#1e40af') : '#64748b';
                ?>
                <label style="display: flex; align-items: center; gap: 6px; cursor: <?= $isAdmin?'not-allowed':'pointer' ?>; background: <?= $bgc ?>; border-radius: var(--radius-pill); padding: 6px 14px; border: 1px solid <?= $bdc ?>; transition: var(--transition); box-shadow: <?= $has ? 'var(--shadow-sm)' : 'none' ?>;">
                  <input type="checkbox" name="roles_on[]" value="<?= $role['id'] ?>" <?= $has?'checked':'' ?> <?= $isAdmin?'checked disabled':'' ?> style="width: 16px; height: 16px; accent-color: <?= $isDirect?'#ca8a04':'var(--primary)' ?>; cursor: inherit;">
                  <span style="font-size: 13px; font-weight: 700; color: <?= $txc ?>"><?= e($role['display_name']??$role['name']) ?></span>
                </label>
                <?php endforeach; ?>
                <button type="submit" class="s-btn s-btn-primary" style="margin-inline-start: auto; padding: 6px 16px; font-size: 13px;">
                  <?= $rtl?'حفظ التعديل':'Save' ?>
                </button>
              </div>
            </form>
            <?php endif; ?>
          </div>
          <?php endforeach; ?>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
  </div>

  <!-- User-specific grants -->
  <div class="s-card" style="border-inline-start: 4px solid #4f46e5;">
    <div class="s-card-head">
      <div class="s-card-title" style="color: #4f46e5;">
        <i class="fa-solid fa-user-shield" style="background: #e0e7ff;"></i>
        <?= $rtl?'صلاحيات استثنائية للمستخدمين (Overrides)':'User-Specific Permission Overrides' ?>
      </div>
    </div>
    <div style="padding: 24px;">
      <form method="POST" style="background: rgba(248, 250, 252, 0.8); border: 1px solid var(--border-light); border-radius: var(--radius-lg); padding: 20px; margin-bottom: 24px;">
        <?= csrf_input() ?>
        <input type="hidden" name="action" value="grant_user_perm">
        <input type="hidden" name="grant" value="1">
        <div style="font-size: 15px; font-weight: 700; color: var(--text-main); margin-bottom: 16px; display: flex; align-items: center; gap: 8px;">
          <i class="fa-solid fa-plus-circle" style="color: #4f46e5;"></i> <?= $rtl?'منح صلاحية استثنائية':'Grant Override Permission' ?>
        </div>
        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 16px; align-items: end;">
          <div class="fg">
            <label><?= $rtl?'المستخدم':'User' ?></label>
            <select name="target_user_id" class="mfi" required>
              <option value=""><?= $rtl?'— اختر المستخدم —':'— Select User —' ?></option>
              <?php
              $all_users=$pdo->query("SELECT u.id,u.full_name,r.display_name AS role_name FROM users u LEFT JOIN user_roles ur ON ur.user_id=u.id AND ur.is_primary=1 LEFT JOIN roles r ON r.id=ur.role_id WHERE u.is_active=1 ORDER BY u.full_name")->fetchAll();
              foreach($all_users as $u):
              ?>
              <option value="<?= $u['id'] ?>"><?= e($u['full_name']) ?> — (<?= e($u['role_name']??'بلا دور') ?>)</option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="fg">
            <label><?= $rtl?'الصلاحية المطلوبة':'Target Permission' ?></label>
            <select name="page_code" class="mfi" required onchange="document.getElementById('grantAction').value=this.options[this.selectedIndex].dataset.action">
              <option value=""><?= $rtl?'— اختر الصلاحية —':'— Select Permission —' ?></option>
              <?php foreach($workflow_features as $mdata): foreach($mdata['features'] as $feat): ?>
              <option value="<?= e($feat['page']) ?>" data-action="<?= e($feat['action']) ?>"><?= e($rtl?$feat['ar']:$feat['en']) ?></option>
              <?php endforeach; endforeach; ?>
            </select>
            <input type="hidden" name="perm_action" id="grantAction">
          </div>
          <div class="fg">
            <label><?= $rtl?'سبب المنح (اختياري)':'Reason (Optional)' ?></label>
            <input type="text" name="reason" class="mfi" placeholder="<?= $rtl?'مثال: تكليف مؤقت...':'e.g. Temporary assignment...' ?>">
          </div>
          <button type="submit" class="s-btn s-btn-primary" style="height: 44px; background: #4f46e5; border-color: #4f46e5; box-shadow: 0 4px 14px rgba(79, 70, 229, 0.25);">
            <i class="fa-solid fa-shield-check"></i> <?= $rtl?'تنفيذ المنح':'Grant Access' ?>
          </button>
        </div>
      </form>

      <?php
      $granted=$pdo->query("SELECT upo.*,u.full_name,p.code AS page_code,pp.action,g.full_name AS gbn FROM user_permission_overrides upo INNER JOIN users u ON u.id=upo.user_id INNER JOIN page_permissions pp ON pp.id=upo.page_permission_id INNER JOIN pages p ON p.id=pp.page_id LEFT JOIN users g ON g.id=upo.granted_by WHERE upo.granted=1 ORDER BY upo.granted_at DESC")->fetchAll();
      ?>
      <?php if($granted): ?>
      <div style="font-size: 14px; font-weight: 700; color: var(--text-muted); margin-bottom: 12px; text-transform: uppercase; letter-spacing: 0.5px;"><?= $rtl?'سجل الصلاحيات الاستثنائية النشطة':'Active Overrides Log' ?></div>
      <div style="display: flex; flex-direction: column; gap: 8px;">
        <?php foreach($granted as $g):
          $fl='';
          foreach($workflow_features as $m) foreach($m['features'] as $f) if($f['page']===$g['page_code']&&$f['action']===$g['action']) $fl=$rtl?$f['ar']:$f['en'];
        ?>
        <div style="display: flex; align-items: center; flex-wrap: wrap; gap: 12px; padding: 12px 16px; background: #fff; border: 1px solid #e0e7ff; border-radius: var(--radius-md); box-shadow: var(--shadow-sm); transition: var(--transition);">
          <i class="fa-solid fa-user-check" style="color: #4f46e5; font-size: 16px; background: #eef2ff; padding: 8px; border-radius: var(--radius-md);"></i>
          <div style="flex: 1; min-width: 200px;">
            <div style="font-weight: 700; color: var(--text-main); font-size: 14px; margin-bottom: 4px;"><?= e($g['full_name']) ?></div>
            <div style="font-size: 12px; color: var(--text-muted);">
              <?= $rtl?'مُنحت بواسطة:':'Granted by:' ?> <?= e($g['gbn']??'النظام') ?> <span style="margin: 0 4px;">•</span> <span style="font-family: 'Inter';"><?= substr($g['granted_at'],0,10) ?></span>
            </div>
          </div>
          
          <div style="display: flex; flex-direction: column; align-items: flex-end; gap: 4px; min-width: 150px;">
             <span style="background: #eef2ff; color: #3730a3; padding: 4px 12px; border-radius: var(--radius-pill); font-size: 12px; font-weight: 700; border: 1px solid #c7d2fe;">
               <?= e($fl?:$g['page_code'].'/'.$g['action']) ?>
             </span>
             <?php if($g['reason']): ?>
               <span style="color: var(--warning); font-size: 11px; font-weight: 600;"><i class="fa-solid fa-quote-right"></i> <?= e($g['reason']) ?></span>
             <?php endif; ?>
          </div>
          
          <form method="POST" style="margin: 0; margin-inline-start: auto;" onsubmit="return confirm('<?= $rtl?'تأكيد سحب الصلاحية الاستثنائية؟':'Confirm revoke?' ?>')">
            <?= csrf_input() ?>
            <input type="hidden" name="action" value="grant_user_perm">
            <input type="hidden" name="grant" value="0">
            <input type="hidden" name="target_user_id" value="<?= $g['user_id'] ?>">
            <input type="hidden" name="page_code" value="<?= $g['page_code'] ?>">
            <input type="hidden" name="perm_action" value="<?= $g['action'] ?>">
            <button type="submit" class="s-btn" style="background: #fef2f2; border: 1px solid #fecaca; color: var(--danger); padding: 6px 12px;">
              <i class="fa-solid fa-xmark"></i> <?= $rtl?'سحب الصلاحية':'Revoke' ?>
            </button>
          </form>
        </div>
        <?php endforeach; ?>
      </div>
      <?php else: ?>
      <div style="text-align: center; padding: 40px 20px; background: rgba(248, 250, 252, 0.5); border-radius: var(--radius-lg); border: 1px dashed var(--border-light);">
        <i class="fa-solid fa-shield-halved" style="font-size: 32px; color: var(--text-light); margin-bottom: 12px; display: block;"></i>
        <div style="font-size: 15px; font-weight: 700; color: var(--text-muted);"><?= $rtl?'لا توجد استثناءات حالية':'No active overrides' ?></div>
        <div style="font-size: 13px; color: var(--text-light); margin-top: 4px;"><?= $rtl?'جميع المستخدمين يتبعون صلاحيات أدوارهم الافتراضية.':'All users are following their default role permissions.' ?></div>
      </div>
      <?php endif; ?>
    </div>
  </div>
</div>

<!-- TAB: Standing Committees -->
<div class="tab-panel" id="tab-standing">
  <div class="s-card">
    <div class="s-card-head">
      <div class="s-card-title"><i class="fa-solid fa-users-gear"></i><?= $rtl?'إدارة اللجان الثابتة':'Standing Committees Management' ?></div>
    </div>
    <div style="padding: 24px;">
      <div class="alert alert-success" style="background: #f0fdf4; border: 1px solid #bbf7d0; color: #166534;">
        <i class="fa-solid fa-circle-info" style="font-size: 18px;"></i>
        <div>
          <strong><?= $rtl?'معلومة نظامية:':'System Info:' ?></strong><br>
          <?= $rtl?'عند إنشاء لجنة جديدة من نفس النوع، يقوم النظام تلقائياً بإنهاء اللجنة السابقة مع الاحتفاظ بسجلها التاريخي كاملاً للرجوع إليه.':'Creating a new committee automatically closes the previous one of the same type, preserving historical records.' ?>
        </div>
      </div>

      <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(350px, 1fr)); gap: 24px;">
        <?php
        $scIcons=['medical'=>'fa-heart-pulse','general'=>'fa-screwdriver-wrench','it'=>'fa-laptop-code'];
        $scColors=['medical'=>'#2563eb','general'=>'#059669','it'=>'#7c3aed'];
        $scBg=['medical'=>'#eff6ff','general'=>'#ecfdf5','it'=>'#f5f3ff'];
        $scBorder=['medical'=>'#bfdbfe','general'=>'#a7f3d0','it'=>'#ddd6fe'];
        
        foreach($sc_types as $sct=>$scLabel):
          $sd=$sc_data[$sct];
          $comm=$sd['committee']; $mems=$sd['members'];
        ?>
        <div style="border: 1px solid var(--border-light); border-radius: var(--radius-xl); overflow: hidden; background: #fff; box-shadow: var(--shadow-sm); display: flex; flex-direction: column;">
          
          <!-- Committee Header -->
          <div style="padding: 16px 20px; background: <?= $scBg[$sct] ?>; border-bottom: 1px solid <?= $scBorder[$sct] ?>; display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 12px;">
            <div style="display: flex; align-items: center; gap: 12px;">
              <div style="width: 40px; height: 40px; background: #fff; border-radius: 10px; display: flex; align-items: center; justify-content: center; box-shadow: 0 2px 4px rgba(0,0,0,0.05);">
                <i class="fa-solid <?= $scIcons[$sct] ?>" style="color: <?= $scColors[$sct] ?>; font-size: 18px;"></i>
              </div>
              <div>
                <div style="font-size: 15px; font-weight: 800; color: <?= $scColors[$sct] ?>; margin-bottom: 4px;"><?= $scLabel ?></div>
                <?php if($comm&&!$comm['end_date']): ?>
                  <div style="font-size: 11px; font-weight: 700; color: #fff; background: <?= $scColors[$sct] ?>; display: inline-block; padding: 2px 8px; border-radius: var(--radius-pill);"><i class="fa-regular fa-calendar-check"></i> <?= $rtl?'لجنة نشطة منذ':'Active since' ?> <?= $comm['start_date'] ?></div>
                <?php else: ?>
                  <div style="font-size: 11px; font-weight: 700; color: var(--text-muted); background: #e2e8f0; display: inline-block; padding: 2px 8px; border-radius: var(--radius-pill);"><i class="fa-solid fa-power-off"></i> <?= $rtl?'لا توجد لجنة نشطة':'No active committee' ?></div>
                <?php endif; ?>
              </div>
            </div>
            <button type="button" onclick="openScCreate('<?= $sct ?>','<?= $scLabel ?>')" class="s-btn" style="background: #fff; color: <?= $scColors[$sct] ?>; border: 1px solid <?= $scBorder[$sct] ?>; box-shadow: var(--shadow-sm);">
              <i class="fa-solid fa-plus"></i> <?= $rtl?'تشكيل لجنة':'Form New' ?>
            </button>
          </div>
          
          <!-- Committee Body -->
          <div style="padding: 20px; flex: 1; display: flex; flex-direction: column;">
            <?php if($comm): ?>
              <div style="font-size: 14px; font-weight: 700; color: var(--text-main); margin-bottom: 12px; display: flex; justify-content: space-between;">
                <span><?= e($comm['name']) ?></span>
                <span style="color: var(--text-light); font-size: 12px;">#<?= $comm['id'] ?></span>
              </div>
            <?php endif; ?>

            <?php if($comm&&$mems): ?>
            <div style="display: flex; flex-direction: column; gap: 8px; margin-bottom: 20px;">
              <?php foreach($mems as $m): ?>
              <div style="display: flex; align-items: center; gap: 12px; padding: 10px 14px; background: rgba(248, 250, 252, 0.7); border: 1px solid var(--border-light); border-radius: var(--radius-md); transition: var(--transition);">
                <div style="width: 32px; height: 32px; border-radius: 50%; background: #e2e8f0; display: flex; align-items: center; justify-content: center; color: var(--text-muted);">
                  <i class="fa-solid fa-user"></i>
                </div>
                <div style="flex: 1;">
                  <div style="font-size: 13.5px; font-weight: 700; color: var(--text-main);"><?= e($m['uname']) ?></div>
                </div>
                <span style="font-size: 11.5px; font-weight: 700; padding: 4px 10px; background: <?= $scBg[$sct] ?>; color: <?= $scColors[$sct] ?>; border-radius: var(--radius-pill); border: 1px solid <?= $scBorder[$sct] ?>;"><?= e($m['role']) ?></span>
                <form method="POST" style="margin: 0;" onsubmit="return confirm('<?= $rtl?'هل أنت متأكد من حذف هذا العضو؟':'Remove member?' ?>')">
                  <?= csrf_input() ?>
                  <input type="hidden" name="action" value="sc_del_member">
                  <input type="hidden" name="mem_id" value="<?= $m['id']??'' ?>">
                  <button type="submit" class="ab ab-del" style="width: 28px; height: 28px; font-size: 11px; border: none; background: transparent; color: var(--text-light);" title="Remove">
                    <i class="fa-solid fa-xmark"></i>
                  </button>
                </form>
              </div>
              <?php endforeach; ?>
            </div>
            
            <?php if($comm): ?>
            <form method="POST" style="display: flex; flex-direction: column; gap: 10px; margin-top: auto; padding-top: 16px; border-top: 1px dashed var(--border-light);">
              <?= csrf_input() ?>
              <input type="hidden" name="action" value="sc_add_member">
              <input type="hidden" name="sc_id" value="<?= $comm['id'] ?>">
              <div style="font-size: 12px; font-weight: 700; color: var(--text-muted);"><i class="fa-solid fa-user-plus"></i> <?= $rtl?'إضافة عضو جديد للحالية':'Add member to current' ?></div>
              <div style="display: flex; gap: 8px;">
                <select name="m_user" class="mfi" style="flex: 2; height: 40px;" required>
                  <option value=""><?= $rtl?'— اختر الموظف —':'— Select Employee —' ?></option>
                  <?php foreach($all_users_list as $u): ?>
                  <option value="<?= $u['id'] ?>"><?= e($u['name']) ?></option>
                  <?php endforeach; ?>
                </select>
                <select name="m_role" class="mfi" style="flex: 1; height: 40px;">
                  <option value="رئيس"><?= $rtl?'رئيس':'Head' ?></option>
                  <option value="عضو فني"><?= $rtl?'عضو فني':'Technical' ?></option>
                  <option value="عضو" selected><?= $rtl?'عضو':'Member' ?></option>
                  <option value="أمين مستودع"><?= $rtl?'أمين مستودع':'Storekeeper' ?></option>
                </select>
              </div>
              <button type="submit" class="s-btn s-btn-primary" style="width: 100%; height: 40px; background: <?= $scColors[$sct] ?>; border-color: <?= $scColors[$sct] ?>;">
                <?= $rtl?'إضافة العضو':'Add Member' ?>
              </button>
            </form>
            <?php endif; ?>
            
            <?php else: ?>
            <div style="text-align: center; padding: 40px 20px; color: var(--text-light); margin: auto;">
              <div style="width: 64px; height: 64px; background: var(--sys-bg); border-radius: 50%; display: flex; align-items: center; justify-content: center; margin: 0 auto 16px;">
                <i class="fa-solid fa-users-slash" style="font-size: 24px; opacity: 0.5;"></i>
              </div>
              <div style="font-size: 14px; font-weight: 700; color: var(--text-muted); margin-bottom: 4px;"><?= $rtl?'لا توجد بيانات':'No data found' ?></div>
              <div style="font-size: 13px;"><?= $rtl?'قم بتشكيل لجنة جديدة للبدء':'Form a new committee to begin' ?></div>
            </div>
            <?php endif; ?>
          </div>
        </div>
        <?php endforeach; ?>
      </div>
    </div>
  </div>
</div>

<!-- TAB: Lookup Tables -->
<div class="tab-panel" id="tab-lookups">
  <?php foreach($lookup_tables as $tbl => $info): ?>
  <div class="s-card" id="<?= $tbl ?>">
    <div class="s-card-head">
      <div class="s-card-title">
        <i class="fa-solid <?= $info['icon'] ?>"></i>
        <?= $rtl?e($info['ar']):e($info['en']) ?>
        <span style="background: var(--sys-bg); color: var(--text-muted); font-size: 12px; font-weight: 600; padding: 2px 10px; border-radius: var(--radius-pill); border: 1px solid var(--border-light); margin-inline-start: 8px;">
          <?= count($data[$tbl]) ?> <?= $rtl?'عناصر':'items' ?>
        </span>
      </div>
    </div>
    
    <div style="overflow-x: auto;">
      <table class="lt-table">
        <thead>
          <tr>
            <th style="width: 60px;">#</th>
            <th><?= $rtl?'الاسم (عربي)':'Arabic Name' ?></th>
            <th><?= $rtl?'الاسم (إنجليزي)':'English Name' ?></th>
            <th style="width: 100px; text-align: center;"><?= $rtl?'الترتيب':'Sort' ?></th>
            <th style="width: 150px;"><?= $rtl?'الحالة':'Status' ?></th>
            <th style="width: 120px; text-align: end;"><?= $rtl?'إجراءات':'Actions' ?></th>
          </tr>
        </thead>
        <tbody>
        <?php if(empty($data[$tbl])): ?>
        <tr><td colspan="6" style="text-align: center; padding: 40px; color: var(--text-muted); font-weight: 600;">
          <i class="fa-solid fa-inbox" style="font-size: 24px; color: var(--text-light); margin-bottom: 12px; display: block;"></i>
          <?= $rtl?'القائمة فارغة':'List is empty' ?>
        </td></tr>
        <?php else: foreach($data[$tbl] as $row): ?>
        <tr>
          <td style="color: var(--text-light); font-weight: 600; font-family: 'Inter';"><?= str_pad($row['id'], 2, '0', STR_PAD_LEFT) ?></td>
          <td style="font-weight: 700; color: var(--text-main);"><?= e($row['name']) ?></td>
          <td style="color: var(--text-muted);"><?= e($row['name_en']??'—') ?></td>
          <td style="font-family: 'Inter'; color: var(--text-muted); text-align: center; font-weight: 600;">
            <span style="background: var(--sys-bg); padding: 2px 8px; border-radius: 4px; border: 1px solid var(--border-light);"><?= $row['sort_order'] ?></span>
          </td>
          <td>
            <span style="display: inline-flex; align-items: center; gap: 6px; font-size: 12px; font-weight: 700; padding: 4px 10px; border-radius: var(--radius-pill); background: <?= $row['is_active'] ? 'var(--success-bg)' : 'var(--sys-bg)' ?>; color: <?= $row['is_active'] ? 'var(--success)' : 'var(--text-muted)' ?>; border: 1px solid <?= $row['is_active'] ? '#a7f3d0' : 'var(--border-light)' ?>;">
              <span class="status-dot" style="background: currentColor;"></span>
              <?= $row['is_active'] ? ($rtl?'نشط':'Active') : ($rtl?'معطّل':'Inactive') ?>
            </span>
          </td>
          <td>
            <div style="display: flex; gap: 8px; justify-content: flex-end;">
              <button type="button" class="ab ab-edit" title="<?= $rtl?'تعديل':'Edit' ?>"
                onclick="openLtEdit('<?= $tbl ?>',<?= $row['id'] ?>,<?= htmlspecialchars(json_encode($row['name'])) ?>,<?= htmlspecialchars(json_encode($row['name_en']??'')) ?>,<?= $row['sort_order'] ?>,<?= $row['is_active'] ?>,<?= $tbl==='committee_types'?(int)($row['requires_approval']??1):1 ?>)">
                <i class="fa-solid fa-pen-to-square"></i>
              </button>
              <form method="POST" style="display: inline; margin: 0;" onsubmit="return confirm('<?= $rtl?'هل أنت متأكد من الحذف نهائياً؟':'Are you sure you want to delete this?' ?>')">
                <?= csrf_input() ?>
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="tbl" value="<?= $tbl ?>">
                <input type="hidden" name="id" value="<?= $row['id'] ?>">
                <button type="submit" class="ab ab-del" title="<?= $rtl?'حذف':'Delete' ?>"><i class="fa-solid fa-trash-can"></i></button>
              </form>
            </div>
          </td>
        </tr>
        <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
    
    <form method="POST" class="add-row">
      <?= csrf_input() ?>
      <input type="hidden" name="action" value="add">
      <input type="hidden" name="tbl" value="<?= $tbl ?>">
      <div style="display: flex; align-items: center; gap: 8px; font-size: 14px; font-weight: 700; color: var(--primary); padding-inline-end: 16px;">
        <i class="fa-solid fa-plus-circle"></i> <?= $rtl?'إضافة جديد:':'Add New:' ?>
      </div>
      <input type="text" name="name" placeholder="<?= $rtl?'الاسم (عربي) *':'Arabic Name *' ?>" required style="flex: 2; min-width: 200px;">
      <input type="text" name="name_en" placeholder="<?= $rtl?'الاسم (إنجليزي)':'English Name' ?>" style="flex: 2; min-width: 200px;">
      <button type="submit" class="s-btn s-btn-primary" style="white-space: nowrap;"><i class="fa-solid fa-check"></i> <?= $rtl?'حفظ وإضافة':'Save & Add' ?></button>
    </form>
  </div>
  <?php endforeach; ?>
</div>

<!-- TAB: Categories + Locations (from lookup_admin.php) -->
<div class="tab-panel" id="tab-data">
  <?php
    if (!defined('PMSH_SETTINGS_SECTION')) define('PMSH_SETTINGS_SECTION', true);
    require_once dirname(__FILE__) . '/_sections/lookup_admin.php';
  ?>
</div>

</main>
</div>

<!-- ================= Modals ================= -->

<!-- SC Modal (Fixed click-outside bug) -->
<div id="scModal" class="lt-modal" onclick="if(event.target===this) closeScModal();">
  <div class="lt-modal-box" style="max-width: 550px;">
    <div class="lt-modal-head">
      <div style="display: flex; align-items: center; gap: 12px;">
        <i class="fa-solid fa-users-viewfinder" style="color: var(--primary); background: var(--primary-glow); padding: 8px; border-radius: var(--radius-md);"></i>
        <span id="scModalTitle" style="font-size: 18px;">Create Committee</span>
      </div>
      <button type="button" onclick="closeScModal()" style="background: none; border: none; font-size: 20px; cursor: pointer; color: var(--text-light); transition: var(--transition); width: 36px; height: 36px; border-radius: var(--radius-md);" onmouseover="this.style.background='var(--sys-bg)'; this.style.color='var(--text-main)'" onmouseout="this.style.background='none'; this.style.color='var(--text-light)'"><i class="fa-solid fa-xmark"></i></button>
    </div>
    <form method="POST">
      <?= csrf_input() ?>
      <input type="hidden" name="action" value="sc_create">
      <input type="hidden" name="sc_type" id="scModalType">
      
      <div class="lm-grid">
        <div class="fg">
          <label><?= $rtl?'مسمى اللجنة':'Committee Name' ?> <span style="color: var(--danger);">*</span></label>
          <input type="text" name="sc_name" class="mfi" required placeholder="<?= $rtl?'مثال: لجنة استلام الأجهزة الطبية 2025':'e.g. Medical Equip. Receiving 2025' ?>">
        </div>
        <div class="fg">
          <label><?= $rtl?'تاريخ التفعيل (البداية)':'Activation Date' ?> <span style="color: var(--danger);">*</span></label>
          <input type="date" name="sc_start_date" class="mfi" required value="<?= date('Y-m-d') ?>">
        </div>
        
        <div style="margin-top: 12px; padding: 16px; background: rgba(248, 250, 252, 0.8); border: 1px solid var(--border-light); border-radius: var(--radius-lg);">
          <div style="font-size: 14px; font-weight: 700; color: var(--text-main); margin-bottom: 12px; display: flex; align-items: center; justify-content: space-between;">
            <span><i class="fa-solid fa-users-line" style="color: var(--text-muted); margin-inline-end: 6px;"></i> <?= $rtl?'أعضاء اللجنة (التشكيل المبدئي)':'Initial Members' ?></span>
          </div>
          
          <div id="scMembersAdd" style="display: flex; flex-direction: column; gap: 12px;">
            <div class="sc-mem-row" style="display: flex; gap: 10px; align-items: center;">
              <select name="sc_users[]" class="mfi" style="flex: 2;">
                <option value=""><?= $rtl?'— اختر الموظف —':'— Select Employee —' ?></option>
                <?php foreach($all_users_list as $u): ?>
                <option value="<?= $u['id'] ?>"><?= e($u['name']) ?></option>
                <?php endforeach; ?>
              </select>
              <select name="sc_roles[]" class="mfi" style="flex: 1;">
                <option value="رئيس"><?= $rtl?'رئيس لجنة':'Head' ?></option>
                <option value="عضو فني"><?= $rtl?'عضو فني':'Technical' ?></option>
                <option value="عضو" selected><?= $rtl?'عضو':'Member' ?></option>
                <option value="أمين مستودع"><?= $rtl?'أمين مستودع':'Storekeeper' ?></option>
              </select>
              <button type="button" class="ab ab-del" onclick="if(document.querySelectorAll('.sc-mem-row').length>1) this.parentElement.remove();" style="width: 44px; height: 44px;"><i class="fa-solid fa-minus"></i></button>
            </div>
          </div>
          
          <button type="button" onclick="addScMemberRow()" class="s-btn s-btn-outline" style="width: 100%; margin-top: 12px; border: 1.5px dashed #cbd5e1;">
            <i class="fa-solid fa-plus"></i> <?= $rtl?'إضافة سطر لعضو آخر':'Add another member row' ?>
          </button>
        </div>
      </div>
      
      <div style="display: flex; gap: 12px; justify-content: flex-end; margin-top: 24px; padding-top: 20px; border-top: 1px solid var(--border-light);">
        <button type="button" onclick="closeScModal()" class="s-btn s-btn-outline"><?= $rtl?'إلغاء':'Cancel' ?></button>
        <button type="submit" class="s-btn s-btn-primary"><i class="fa-solid fa-check"></i> <?= $rtl?'اعتماد التشكيل':'Approve Formation' ?></button>
      </div>
    </form>
  </div>
</div>

<!-- LT Edit Modal -->
<div class="lt-modal" id="ltModal" onclick="if(event.target===this) closeLtModal()">
  <div class="lt-modal-box">
    <div class="lt-modal-head">
      <div class="lm-title" style="margin: 0;"><i class="fa-solid fa-pen-to-square" style="color: var(--primary); background: var(--primary-glow); padding: 8px; border-radius: var(--radius-md);"></i> <?= $rtl?'تعديل بيانات العنصر':'Edit Item Details' ?></div>
      <button type="button" onclick="closeLtModal()" style="background: none; border: none; font-size: 20px; cursor: pointer; color: var(--text-light); transition: var(--transition); width: 36px; height: 36px; border-radius: var(--radius-md);" onmouseover="this.style.background='var(--sys-bg)'; this.style.color='var(--text-main)'" onmouseout="this.style.background='none'; this.style.color='var(--text-light)'"><i class="fa-solid fa-xmark"></i></button>
    </div>
    
    <form method="POST">
      <?= csrf_input() ?>
      <input type="hidden" name="action" value="edit">
      <input type="hidden" name="tbl" id="lmTbl">
      <input type="hidden" name="id" id="lmId">
      
      <div class="lm-grid">
        <div class="fg">
          <label><?= $rtl?'الاسم (عربي)':'Arabic Name' ?> <span style="color: var(--danger);">*</span></label>
          <input type="text" name="name" id="lmName" class="lmi" required>
        </div>
        <div class="fg">
          <label><?= $rtl?'الاسم (إنجليزي)':'English Name' ?></label>
          <input type="text" name="name_en" id="lmNameEn" class="lmi">
        </div>
        <div class="fg">
          <label><?= $rtl?'ترتيب العرض (Sort Order)':'Sort Order' ?></label>
          <input type="number" name="sort_order" id="lmSort" class="lmi" min="0">
        </div>
        
        <div style="display: flex; flex-direction: column; gap: 12px; margin-top: 8px; padding: 16px; background: var(--sys-bg); border-radius: var(--radius-lg); border: 1px solid var(--border-light);">
          <label class="custom-checkbox" style="background: #fff;">
            <input type="checkbox" name="is_active" id="lmActive">
            <span><?= $rtl?'حالة العنصر (نشط / مفعل)':'Item Status (Active)' ?></span>
          </label>
          
          <div id="reqApprovalRow" style="display: none;">
            <label class="custom-checkbox" style="background: var(--warning-bg); border-color: #fde68a;">
              <input type="checkbox" name="requires_approval" id="lmReqApproval" style="accent-color: var(--warning);">
              <div>
                <span style="color: #92400e; display: block; margin-bottom: 2px;"><?= $rtl?'يتطلب اعتماد تنفيذي':'Requires Executive Approval' ?></span>
                <span style="font-size: 11px; font-weight: 500; color: #b45309; white-space: normal; line-height: 1.4; display: block;">
                  <?= $rtl?'إذا لم يتم التحديد، ستُفعل اللجان من هذا النوع مباشرة.':'If unchecked, committees of this type will activate instantly.' ?>
                </span>
              </div>
            </label>
          </div>
        </div>
      </div>
      
      <div style="display: flex; gap: 12px; justify-content: flex-end; margin-top: 24px; padding-top: 20px; border-top: 1px solid var(--border-light);">
        <button type="button" class="s-btn s-btn-outline" onclick="closeLtModal()"><?= $rtl?'إلغاء':'Cancel' ?></button>
        <button type="submit" class="s-btn s-btn-primary"><i class="fa-solid fa-floppy-disk"></i> <?= $rtl?'حفظ التعديلات':'Save Changes' ?></button>
      </div>
    </form>
  </div>
</div>

<?php include BASE_PATH.'/includes/perm_modal.php'; ?>
<script>
var RTL = <?= $rtl ? 'true' : 'false' ?>;
var AI_PROVIDER_DEFAULTS = <?= json_encode([
    'groq' => ['model'=>'openai/gpt-oss-20b','base_url'=>'https://api.groq.com/openai/v1'],
    'openai' => ['model'=>'gpt-4o-mini','base_url'=>'https://api.openai.com/v1'],
    'deepseek' => ['model'=>'deepseek-chat','base_url'=>'https://api.deepseek.com/v1'],
    'custom' => ['model'=>'','base_url'=>''],
], JSON_UNESCAPED_UNICODE) ?>;

/* --- Tab Switching Logic (Refactored for Reliability) --- */
function initTabs() {
    const pills = document.querySelectorAll('.tab-pill');
    const panels = document.querySelectorAll('.tab-panel');

    function activateTab(tabId) {
        // Remove active class from all
        pills.forEach(p => p.classList.remove('active'));
        panels.forEach(p => p.classList.remove('active'));

        // Add active class to clicked pill
        const pill = document.querySelector('.tab-pill[data-tab="' + tabId + '"]');
        if (pill) pill.classList.add('active');

        // Handle special mapping for data tabs
        const panelId = (tabId === 'categories' || tabId === 'locations') ? 'data' : tabId;
        const activePanel = document.getElementById('tab-' + panelId);
        if (activePanel) activePanel.classList.add('active');

        // Update URL safely
        if (window.history && window.history.replaceState) {
            window.history.replaceState(null, '', '#' + tabId);
        } else {
            window.location.hash = tabId;
        }

        // Scroll animation for inner sections
        if (tabId === 'categories' || tabId === 'locations') {
            const anchor = document.getElementById(tabId);
            if (anchor) {
                setTimeout(() => anchor.scrollIntoView({behavior: 'smooth', block: 'start'}), 150);
            }
        }
    }

    // Attach Event Listeners instead of inline onclick
    pills.forEach(pill => {
        pill.addEventListener('click', function(e) {
            e.preventDefault();
            activateTab(this.getAttribute('data-tab'));
        });
    });

    // Check Initial URL Hash
    const hash = location.hash.replace('#', '');
    const map = {
        'general':'hospital', 'hospital':'hospital',
        'ai-settings':'ai', 'ai':'ai',
        'workflow':'workflow', 'standing':'standing',
        'supply_types':'lookups', 'committee_types':'lookups', 'receiving_doc_types':'lookups', 'lookups':'lookups',
        'categories':'categories', 'locations':'locations'
    };
    
    activateTab(map[hash] || 'hospital');
}

// Ensure DOM is fully loaded before hooking tabs
if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initTabs);
} else {
    initTabs();
}


/* --- AI Settings Handlers --- */
function onProviderChange() {
  var p = document.getElementById('aiProvider').value;
  var def = AI_PROVIDER_DEFAULTS[p];
  if (def && !document.getElementById('aiModel').value.trim()) {
    document.getElementById('aiModel').value = def.model;
  }
  if (def && !document.getElementById('aiBaseUrl').value.trim()) {
    document.getElementById('aiBaseUrl').value = def.base_url;
  }
  // Hide models dropdown when provider changes
  var dd = document.getElementById('modelsDropdown');
  if (dd) dd.style.display = 'none';
}

/* --- Model Discovery --- */
var _modelsCache = null;
var _modelsProvider = null;

async function discoverModels() {
  var dropdown = document.getElementById('modelsDropdown');
  var list = document.getElementById('modelsList');
  var loading = document.getElementById('modelsLoading');
  var btn = document.getElementById('discoverModelsBtn');

  // Toggle dropdown
  if (dropdown.style.display === 'block') {
    dropdown.style.display = 'none';
    return;
  }

  var provider = document.getElementById('aiProvider').value;
  var apiKey = document.getElementById('aiKey').value.trim();
  var baseUrl = document.getElementById('aiBaseUrl').value.trim();

  // Show dropdown
  dropdown.style.display = 'block';
  loading.style.display = 'block';
  list.innerHTML = '';
  btn.disabled = true;
  btn.innerHTML = '<i class="fa-solid fa-circle-notch fa-spin"></i>';

  // Check cache
  if (_modelsCache && _modelsProvider === provider) {
    renderModels(_modelsCache);
    loading.style.display = 'none';
    btn.disabled = false;
    btn.innerHTML = '<i class="fa-solid fa-wand-magic-sparkles"></i> ' + (RTL ? 'استكشاف' : 'Discover');
    return;
  }

  try {
    var fd = new FormData();
    fd.append('provider', provider);
    if (apiKey) fd.append('api_key', apiKey);
    if (baseUrl) fd.append('base_url', baseUrl);

    var r = await fetch('api/list_ai_models.php', { method: 'POST', body: fd });
    var d = await r.json();

    if (d.ok && d.models) {
      _modelsCache = d.models;
      _modelsProvider = provider;
      renderModels(d.models, d.latency_ms, d.count);
      loading.style.display = 'none';
    } else {
      loading.innerHTML = '<div style="color:#dc2626;padding:12px;font-size:12px"><i class="fa-solid fa-circle-xmark"></i> ' + (d.error_msg || d.error_msg_en || 'Failed') + '</div>';
    }
  } catch (e) {
    loading.innerHTML = '<div style="color:#dc2626;padding:12px;font-size:12px"><i class="fa-solid fa-wifi"></i> ' + e.message + '</div>';
  } finally {
    btn.disabled = false;
    btn.innerHTML = '<i class="fa-solid fa-wand-magic-sparkles"></i> ' + (RTL ? 'استكشاف' : 'Discover');
  }
}

function renderModels(models, latency, count) {
  var list = document.getElementById('modelsList');
  var currentModel = document.getElementById('aiModel').value.trim();

  var html = '';
  if (count !== undefined) {
    html += '<div style="padding:8px 12px;background:#f8fafc;border-bottom:1px solid #e2e8f0;font-size:11px;color:#64748b;display:flex;justify-content:space-between;align-items:center">';
    html += '<span><i class="fa-solid fa-circle-check" style="color:#16a34a"></i> ' + (RTL ? count + ' نموذج متاح' : count + ' models available') + '</span>';
    if (latency) html += '<span style="color:#94a3b8">' + latency + 'ms</span>';
    html += '</div>';
  }

  // Search box
  html += '<div style="padding:6px 8px;border-bottom:1px solid #e2e8f0">';
  html += '<input type="text" id="modelsSearch" placeholder="' + (RTL ? 'بحث عن نموذج...' : 'Search models...') + '" oninput="filterModels(this.value)" style="width:100%;padding:6px 10px;border:1px solid #e2e8f0;border-radius:6px;font-size:12px;font-family:\'Tajawal\';box-sizing:border-box">';
  html += '</div>';

  for (var i = 0; i < models.length; i++) {
    var m = models[i];
    var isSelected = (m.id === currentModel);
    var bg = isSelected ? '#eff6ff' : '#fff';
    var border = isSelected ? '2px solid #3b82f6' : '1px solid #f1f5f9';
    var owner = m.owned_by || '';
    html += '<div class="model-item" onclick="selectModel(\'' + m.id.replace(/'/g, "\\'") + '\')" style="padding:8px 12px;cursor:pointer;display:flex;align-items:center;gap:10px;border-bottom:1px solid #f1f5f9;background:' + bg + ';border-left:' + border + ';transition:background .15s" onmouseover="this.style.background=\'' + (isSelected ? '#eff6ff' : '#f8fafc') + '\'" onmouseout="this.style.background=\'' + bg + '\'">';
    html += '<div style="flex:1">';
    html += '<div style="font-size:12.5px;font-weight:600;color:#0f172a;font-family:monospace">' + m.id + '</div>';
    if (owner) html += '<div style="font-size:10.5px;color:#94a3b8;margin-top:2px">' + owner + '</div>';
    html += '</div>';
    if (isSelected) html += '<i class="fa-solid fa-circle-check" style="color:#3b82f6;font-size:14px"></i>';
    html += '</div>';
  }

  if (models.length === 0) {
    html += '<div style="padding:20px;text-align:center;color:#94a3b8;font-size:12px">' + (RTL ? 'لا توجد نماذج' : 'No models found') + '</div>';
  }

  list.innerHTML = html;
}

function filterModels(query) {
  var items = document.querySelectorAll('.model-item');
  var q = query.toLowerCase();
  for (var i = 0; i < items.length; i++) {
    var text = items[i].textContent.toLowerCase();
    items[i].style.display = text.indexOf(q) >= 0 ? '' : 'none';
  }
}

function selectModel(modelId) {
  document.getElementById('aiModel').value = modelId;
  var dropdown = document.getElementById('modelsDropdown');
  dropdown.style.display = 'none';
}

// Close dropdown when clicking outside
document.addEventListener('click', function(e) {
  var dd = document.getElementById('modelsDropdown');
  var btn = document.getElementById('discoverModelsBtn');
  var modelInput = document.getElementById('aiModel');
  if (dd && dd.style.display === 'block') {
    if (!dd.contains(e.target) && e.target !== btn && !btn.contains(e.target) && e.target !== modelInput) {
      dd.style.display = 'none';
    }
  }
});

function toggleShowKey() {
  var inp = document.getElementById('aiKey');
  inp.type = inp.type === 'password' ? 'text' : 'password';
}

async function saveAISettings() {
  var btn = document.getElementById('aiSaveBtn');
  var status = document.getElementById('aiStatus');
  var origText = btn.innerHTML;
  btn.disabled = true;
  btn.innerHTML = '<i class="fa-solid fa-circle-notch fa-spin"></i> ' + (RTL ? 'جاري الحفظ...' : 'Saving...');
  status.textContent = '';
  var fd = new FormData(document.getElementById('aiSettingsForm'));
  try {
    var r = await fetch('api/save_ai_settings.php', { method:'POST', body: fd });
    var d = await r.json();
    if (d.ok) {
      status.innerHTML = '<i class="fa-solid fa-check-circle"></i> ' + (RTL ? 'تم الحفظ بنجاح' : 'Saved successfully');
      status.style.color = 'var(--success)';
      setTimeout(function(){ location.reload(); }, 1200);
    } else {
      status.innerHTML = '<i class="fa-solid fa-triangle-exclamation"></i> ' + (d.msg || 'Failed') + (d.detail ? ' - ' + d.detail : '');
      status.style.color = 'var(--danger)';
      btn.disabled = false;
      btn.innerHTML = origText;
    }
  } catch (e) {
    status.innerHTML = '<i class="fa-solid fa-wifi"></i> Network Error: ' + e.message;
    status.style.color = 'var(--danger)';
    btn.disabled = false;
    btn.innerHTML = origText;
  }
}

/* --- AI Connection Testing --- */
async function testAIConnection() {
  var card = document.getElementById('aiTestCard');
  var icon = document.getElementById('aiTestIcon');
  var title = document.getElementById('aiTestTitle');
  var sub = document.getElementById('aiTestSub');
  var meta = document.getElementById('aiTestMeta');
  var preview = document.getElementById('aiTestPreview');
  var btn = document.getElementById('aiTestBtn');

  card.className = 'ai-test-card testing';
  icon.className = 'ai-test-icon testing';
  icon.innerHTML = '<i class="fa-solid fa-circle-notch fa-spin"></i>';
  title.textContent = RTL ? 'جاري اختبار الاتصال بالخوادم...' : 'Testing connection to servers...';
  sub.textContent = RTL ? 'يرجى الانتظار، قد يستغرق هذا بضع ثوانٍ...' : 'Please wait, this might take a few seconds...';
  meta.style.display = 'none';
  preview.style.display = 'none';
  btn.disabled = true;

  try {
    var fd = new FormData(document.getElementById('aiSettingsForm'));
    var r = await fetch('api/test_ai_connection.php', { method:'POST', body: fd });
    var d = await r.json();
    if (d.ok) {
      card.className = 'ai-test-card success';
      icon.className = 'ai-test-icon success';
      icon.innerHTML = '<i class="fa-solid fa-check"></i>';
      title.textContent = RTL ? 'تم الاتصال بنجاح!' : 'Connection successful!';
      sub.textContent = d.msg || '';
      meta.innerHTML = '<span class="ai-test-tag" style="background:#dbeafe;color:#1e40af"><i class="fa-solid fa-bolt"></i> ' + (d.latency_ms||'?') + 'ms</span>'
        + '<span class="ai-test-tag" style="background:#f3e8ff;color:#6b21a8"><i class="fa-solid fa-server"></i> ' + (d.provider||'') + '</span>'
        + '<span class="ai-test-tag" style="background:#d1fae5;color:#065f46"><i class="fa-solid fa-microchip"></i> ' + (d.model||'') + '</span>';
      meta.style.display = 'flex';
      if (d.response_preview) {
        preview.innerHTML = '<strong><i class="fa-solid fa-comment-dots"></i> Response:</strong><br>' + d.response_preview;
        preview.style.display = 'block';
      }
    } else {
      card.className = 'ai-test-card error';
      icon.className = 'ai-test-icon error';
      icon.innerHTML = '<i class="fa-solid fa-xmark"></i>';
      title.textContent = RTL ? 'فشل في الاتصال' : 'Connection failed';
      sub.textContent = d.error_msg || d.error_msg_en || (RTL ? 'خطأ غير معروف' : 'Unknown error');
      meta.innerHTML = '<span class="ai-test-tag" style="background:#fee2e2;color:#b91c1c"><i class="fa-solid fa-circle-info"></i> ' + (d.error_type||'') + '</span>';
      if (d.suggestion) {
        meta.innerHTML += '<span class="ai-test-tag" style="background:#fffbeb;color:#b45309; border: 1px solid #fde68a;"><i class="fa-solid fa-lightbulb"></i> ' + d.suggestion + '</span>';
      }
      meta.style.display = 'flex';
      if (d.raw_response) {
        preview.innerHTML = '<strong><i class="fa-solid fa-code"></i> Raw Response:</strong><br><code style="font-size:11px;word-break:break-all">' + d.raw_response.replace(/</g,'&lt;') + '</code>';
        preview.style.display = 'block';
      }
    }
  } catch (e) {
    card.className = 'ai-test-card error';
    icon.className = 'ai-test-icon error';
    icon.innerHTML = '<i class="fa-solid fa-wifi"></i>';
    title.textContent = RTL ? 'خطأ في الشبكة' : 'Network error';
    sub.textContent = e.message;
    meta.style.display = 'none';
  } finally {
    btn.disabled = false;
  }
}

/* --- Standing Committees Modals & Logic --- */
function openScCreate(type, label) {
  document.getElementById('scModalType').value = type;
  document.getElementById('scModalTitle').textContent = (RTL ? 'تشكيل لجنة جديدة — ' : 'Form New Committee — ') + label;
  var modal = document.getElementById('scModal');
  modal.style.display = 'flex';
  setTimeout(function(){ modal.classList.add('open'); }, 10);
}

function closeScModal() {
  var modal = document.getElementById('scModal');
  if(modal) {
    modal.classList.remove('open');
    setTimeout(function(){ modal.style.display = 'none'; }, 300);
  }
}

function addScMemberRow() {
  var c = document.getElementById('scMembersAdd');
  var f = c.querySelector('.sc-mem-row');
  if (!f) return;
  var cl = f.cloneNode(true);
  cl.querySelectorAll('select').forEach(function(s){ s.selectedIndex = 0; });
  c.appendChild(cl);
}

/* --- Lookup Tables Modals --- */
function openLtEdit(tbl, id, name, nameEn, sort, active, reqApproval) {
  document.getElementById('lmTbl').value = tbl;
  document.getElementById('lmId').value = id;
  document.getElementById('lmName').value = name;
  document.getElementById('lmNameEn').value = nameEn;
  document.getElementById('lmSort').value = sort;
  document.getElementById('lmActive').checked = !!active;
  var reqRow = document.getElementById('reqApprovalRow');
  if (tbl === 'committee_types') {
    reqRow.style.display = 'block';
    document.getElementById('lmReqApproval').checked = (reqApproval === undefined || reqApproval === null || reqApproval == 1);
  } else { reqRow.style.display = 'none'; }
  
  var modal = document.getElementById('ltModal');
  modal.style.display = 'flex';
  setTimeout(function(){ modal.classList.add('open'); }, 10);
}

function closeLtModal() { 
  var modal = document.getElementById('ltModal');
  modal.classList.remove('open');
  setTimeout(function(){ modal.style.display = 'none'; }, 300);
}

document.addEventListener('keydown', function(e) { 
  if (e.key === 'Escape') {
    closeLtModal();
    closeScModal();
  } 
});
</script>
</body>
</html>