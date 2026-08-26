<?php
/**
 * inventory/locations/verify.php — توثيق الأقسام v3 (كامل)
 * يحترم الهرمية: مواقع (مبنى←طابق←غرفة) + أقسام (رئيسي←فرعي)
 * كل ربط يحفظ: dept_id + dept_parent_id + dept_root_id
 */
require_once dirname(__DIR__, 2) . '/config.php';
require_once __DIR__ . '/_helpers.php';
page_guard('inventory.locations');
if (!(is_admin() || (function_exists('can') && can('inventory.locations', 'manage')))) abort(403);
ensure_locations_schema($pdo);
$rtl = is_rtl();
$min_score = (int)($_POST['min_score'] ?? $_GET['min_score'] ?? 60);
$min_score = max(0, min(100, $min_score));

/* ═══ معالجة POST ═══ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && verify_csrf()) {
    $act  = $_POST['action'] ?? '';
    $keep = http_build_query(array_filter([
        'b'=>$_POST['keep']['b'] ?? '', 'f'=>$_POST['keep']['f'] ?? '',
        'status'=>$_POST['keep']['status'] ?? '', 'dept'=>$_POST['keep']['dept'] ?? '',
        'min_score'=>(int)($_POST['keep']['min_score'] ?? $min_score),
    ]));

    if ($act === 'bulk_link') {
        $ids = array_map('intval', $_POST['ids'] ?? []);
        $dept_id = (int)($_POST['dept_id'] ?? 0);
        if (!$ids || !$dept_id) { flash('error', $rtl?'اختر غرفاً وقسماً.':'Select rooms & dept.'); }
        else {
            try {
                $pdo->beginTransaction(); $linked=0;
                foreach ($ids as $rid) if (save_room_dept_link($pdo,$rid,$dept_id,'verified',100)) $linked++;
                $pdo->commit();
                flash('success', ($rtl?"تم ربط $linked غرفة بالقسم.":"Linked $linked rooms."));
            } catch (Throwable $e) { if($pdo->inTransaction())$pdo->rollBack(); flash('error',$e->getMessage()); }
        }
        header('Location: '.BASE_URL.'/inventory/locations/verify.php?'.$keep); exit;
    }

    if ($act === 'auto_link') {
        /* Smart Parser يطبّق على الغرف المختارة (أو كلها pending) */
        $ids = array_map('intval', $_POST['ids'] ?? []);
        $apply = !empty($_POST['do_apply']);
        try {
            $stats = run_smart_parser($pdo, $ids, $apply, $min_score);
            $msg = $rtl
                ? "التفتيك الذكي ({$min_score}%+): فحص {$stats['scanned']}، "
                  ."تطبيق {$stats['applied']}، منخفض {$stats['skipped_low']}، بدون {$stats['no_match']}"
                : "Smart parse ({$min_score}%+): scanned {$stats['scanned']}, applied {$stats['applied']}, low {$stats['skipped_low']}, none {$stats['no_match']}";
            flash($apply && $stats['applied'] ? 'success' : 'info', $msg);
        } catch (Throwable $e) { flash('error', $e->getMessage()); }
        header('Location: '.BASE_URL.'/inventory/locations/verify.php?'.$keep); exit;
    }

    if ($act === 'smart_parse_all') {
        /* تفتيك شامل لكل الغرف pending (بدون ids) */
        $apply = !empty($_POST['do_apply']);
        try {
            $stats = run_smart_parser($pdo, [], $apply, $min_score);
            $msg = $rtl
                ? "التفتيك الشامل ({$min_score}%+): فحص {$stats['scanned']}، "
                  ."تطبيق {$stats['applied']}، منخفض {$stats['skipped_low']}، بدون {$stats['no_match']}"
                : "Bulk parse ({$min_score}%+): scanned {$stats['scanned']}, applied {$stats['applied']}, low {$stats['skipped_low']}, none {$stats['no_match']}";
            flash($apply && $stats['applied'] ? 'success' : 'info', $msg);
        } catch (Throwable $e) { flash('error', $e->getMessage()); }
        header('Location: '.BASE_URL.'/inventory/locations/verify.php?'.$keep); exit;
    }

    if ($act === 'unlink') {
        $ids = array_map('intval', $_POST['ids'] ?? []);
        if ($ids) {
            try { $pdo->beginTransaction(); foreach($ids as $rid) clear_room_dept_link($pdo,$rid); $pdo->commit();
                flash('success', ($rtl?'تم فصل '.count($ids).' غرفة.':'Unlinked '.count($ids))); }
            catch (Throwable $e) { if($pdo->inTransaction())$pdo->rollBack(); flash('error',$e->getMessage()); }
        }
        header('Location: '.BASE_URL.'/inventory/locations/verify.php?'.$keep); exit;
    }
}

/* ═══ البيانات ═══ */
$buildings = $pdo->query("SELECT id,name,name_en FROM item_locations WHERE location_type='building' AND is_active=1 ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
$sel_b=(int)($_GET['b']??0); $sel_f=(int)($_GET['f']??0); $sel_status=$_GET['status']??''; $sel_dept=(int)($_GET['dept']??0);

$floors=[];
if ($sel_b) { $st=$pdo->prepare("SELECT id,name,name_en FROM item_locations WHERE location_type='floor' AND is_active=1 AND parent_id=? ORDER BY name"); $st->execute([$sel_b]); $floors=$st->fetchAll(PDO::FETCH_ASSOC); }

$depts_main=$pdo->query("SELECT id,name,name_en FROM departments WHERE level=1 AND is_active=1 ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
$depts_sub =$pdo->query("SELECT id,parent_id,name,name_en FROM departments WHERE level=2 AND is_active=1 ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);

$sql="SELECT r.id,r.name,r.name_en,r.dept_id,r.dept_parent_id,r.dept_root_id,r.parse_status,r.confidence,
      f.name f_name,b.name b_name,d.name dept_name,d.level dept_level,p.name dept_parent_name
      FROM item_locations r
      LEFT JOIN item_locations f ON f.id=r.parent_id
      LEFT JOIN item_locations b ON b.id=f.parent_id
      LEFT JOIN departments d ON d.id=r.dept_id
      LEFT JOIN departments p ON p.id=d.parent_id
      WHERE r.location_type='room' AND r.is_active=1";
$params=[];
if ($sel_b){$sql.=" AND b.id=?";$params[]=$sel_b;}
if ($sel_f){$sql.=" AND f.id=?";$params[]=$sel_f;}
if ($sel_status==='verified'){$sql.=" AND r.dept_id IS NOT NULL AND r.parse_status='verified'";}
elseif ($sel_status==='auto'){$sql.=" AND r.dept_id IS NOT NULL AND r.parse_status='auto'";}
elseif ($sel_status==='pending'){$sql.=" AND (r.dept_id IS NULL OR r.dept_id=0)";}
if ($sel_dept){$sql.=" AND (r.dept_id=? OR r.dept_parent_id=?)";$params[]=$sel_dept;$params[]=$sel_dept;}
$sql.=" ORDER BY b.name,f.name,r.name";
$st=$pdo->prepare($sql);$st->execute($params);$rooms=$st->fetchAll(PDO::FETCH_ASSOC);

/* اقتراحات Smart Parser للـ pending rooms (للعرض فقط) */
$suggestions = [];
if (!function_exists('location_suggest_for_room')) {
    require_once dirname(__DIR__, 2) . '/includes/location_parser.php';
}
foreach ($rooms as $r) {
    if ($r['dept_id']) continue;  /* عرض الاقتراح فقط للمعلّقة */
    $sug = location_suggest_for_room($r['name'], $r['name_en'] ?? '', 1);
    if ($sug && $sug[0]['score'] >= 30) {
        $suggestions[$r['id']] = $sug[0];
    }
}

$kTotal=(int)$pdo->query("SELECT COUNT(*) FROM item_locations WHERE location_type='room' AND is_active=1")->fetchColumn();
$kVer=(int)$pdo->query("SELECT COUNT(*) FROM item_locations WHERE location_type='room' AND is_active=1 AND dept_id IS NOT NULL AND parse_status='verified'")->fetchColumn();
$kAuto=(int)$pdo->query("SELECT COUNT(*) FROM item_locations WHERE location_type='room' AND is_active=1 AND parse_status='auto'")->fetchColumn();
$kPend=(int)$pdo->query("SELECT COUNT(*) FROM item_locations WHERE location_type='room' AND is_active=1 AND (dept_id IS NULL OR dept_id=0)")->fetchColumn();
$kPct=$kTotal?round(($kVer+$kAuto)/$kTotal*100):0;
?>
<!DOCTYPE html>
<html lang="<?= e(lang_attr()) ?>" dir="<?= e(lang_dir()) ?>">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1.0">
<title><?= $rtl?'توثيق الأقسام':'Dept. Verification' ?> — <?= e(get_setting('hospital_name','PMSH')) ?></title>
<link href="https://fonts.googleapis.com/css2?family=Tajawal:wght@400;500;700;800;900&family=Inter:wght@500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
<link rel="stylesheet" href="<?= BASE_URL ?>/css/app.css">
<style>
body,button,input,select{font-family:'Tajawal',sans-serif}
.vf-wrap{max-width:1400px;margin:0 auto;padding:18px}
.vf-hero{background:linear-gradient(135deg,#16a34a,#22c55e 55%,#4ade80);color:#fff;border-radius:22px;padding:24px 28px;margin-bottom:20px;display:flex;align-items:center;gap:18px;flex-wrap:wrap}
.vf-hero .ic{width:70px;height:70px;border-radius:16px;background:rgba(255,255,255,.18);display:flex;align-items:center;justify-content:center;font-size:30px;flex-shrink:0}
.vf-hero h1{margin:0;font-size:24px;font-weight:900}.vf-hero p{margin:4px 0 0;font-size:13px;opacity:.9}
.vf-stats{display:grid;grid-template-columns:repeat(5,1fr);gap:14px;margin-bottom:20px}
@media(max-width:1100px){.vf-stats{grid-template-columns:repeat(3,1fr)}}
@media(max-width:600px){.vf-stats{grid-template-columns:repeat(2,1fr)}}
.vf-stat{background:#fff;border:1.5px solid #e2e8f0;border-radius:14px;padding:16px 18px;display:flex;align-items:center;gap:14px}
.vf-stat .ic{width:46px;height:46px;border-radius:10px;display:flex;align-items:center;justify-content:center;font-size:20px;flex-shrink:0}
.vf-stat .v{font-size:22px;font-weight:900;line-height:1}.vf-stat .l{font-size:12px;color:#64748b;margin-top:4px;font-weight:700}
.vf-card{background:#fff;border:1.5px solid #e2e8f0;border-radius:18px;padding:20px;margin-bottom:16px}
.vf-card h3{margin:0 0 16px;font-size:15px;font-weight:900;display:flex;gap:9px;align-items:center}
.vf-card h3 i{color:#16a34a;background:#f0fdf4;padding:8px;border-radius:9px;font-size:13px}
.vf-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:12px}
@media(max-width:1000px){.vf-grid{grid-template-columns:repeat(2,1fr)}}
@media(max-width:560px){.vf-grid{grid-template-columns:1fr}}
.vf-grid label{font-size:11px;font-weight:800;color:#475569;margin-bottom:4px;display:block}
.vf-grid select{width:100%;border:1.5px solid #e2e8f0;border-radius:10px;padding:11px 14px;font-size:13px;background:#fff}
.vf-actions{display:flex;gap:10px;flex-wrap:wrap;margin-top:14px}
.vf-btn{border:none;border-radius:11px;padding:12px 22px;font-weight:900;font-size:13px;cursor:pointer;display:inline-flex;gap:8px;align-items:center}
.vf-btn.go{background:linear-gradient(135deg,#16a34a,#22c55e);color:#fff;box-shadow:0 4px 14px rgba(22,163,74,.3)}
.vf-btn.smart{background:#fef3c7;color:#92400e}
.vf-btn.danger{background:#fee2e2;color:#b91c1c}
.vf-btn.hub{background:#f1f5f9;color:#475569}
.vf-hint{background:#eff6ff;border:1px solid #bfdbfe;border-radius:10px;padding:10px 14px;font-size:12px;color:#1e40af;margin-top:10px;font-weight:700}
table.vf{width:100%;border-collapse:collapse;font-size:12.5px;margin-top:10px}
table.vf th{background:#f8fafc;padding:10px 12px;text-align:right;font-size:11px;font-weight:900;color:#475569;border-bottom:1.5px solid #e2e8f0}
table.vf td{padding:10px 12px;border-bottom:1px solid #f1f5f9;vertical-align:middle}
table.vf tr:hover td{background:#f0fdf4}
.vf-badge{display:inline-block;padding:3px 10px;border-radius:99px;font-size:11px;font-weight:800}
.vf-badge.verified{background:#dcfce7;color:#166534}
.vf-badge.auto{background:#fef3c7;color:#92400e}
.vf-badge.pending{background:#f1f5f9;color:#64748b}
.dept-path{font-size:11px;color:#64748b;margin-top:2px}.dept-path .arrow{color:#94a3b8;margin:0 4px}
.flash{background:#fff;border-radius:12px;padding:13px 18px;margin-bottom:14px;font-weight:800;font-size:13px;border-right:4px solid #16a34a;color:#065f46}
.flash.err{border-right-color:#dc2626;color:#991b1b}
</style>
</head>
<body class="app-layout">
<?php include BASE_PATH . '/includes/sidebar.php'; ?>
<div class="main-area">
<?php include BASE_PATH . '/includes/topbar.php'; ?>
<main class="page-content"><div class="vf-wrap">
<?php foreach (get_flash() as $fm): ?><div class="flash <?= $fm['type']==='error'?'err':'' ?>"><?= e($fm['message']) ?></div><?php endforeach; ?>

<section class="vf-hero">
<div class="ic"><i class="fa-solid fa-link"></i></div>
<div style="flex:1;min-width:220px">
<h1><?= $rtl?'توثيق الأقسام':'Dept. Verification' ?></h1>
<p><?= $rtl?'ربط الغرف بالأقسام مع الحفاظ على الهرمية الكاملة (رئيسي ← فرعي)':'Link rooms to departments preserving full hierarchy' ?></p>
</div>
<a class="vf-btn hub" href="<?= BASE_URL ?>/inventory/locations/index.php"><i class="fa-solid fa-arrow-right"></i> <?= $rtl?'الداشبورد':'Hub' ?></a>
<form method="POST" style="display:inline" id="parseAllForm"><?= csrf_input() ?><input type="hidden" name="action" value="smart_parse_all">
<?php foreach (['b','f','status','dept'] as $k): ?><input type="hidden" name="keep[<?= $k ?>]" value="<?= e($_GET[$k] ?? '') ?>"><?php endforeach; ?>
<input type="hidden" name="min_score" value="<?= $min_score ?>">
<button class="vf-btn smart" type="submit" formaction="?preview=1" onclick="event.preventDefault();document.getElementById('parseAllForm').action.value='smart_parse_all';document.getElementById('parseAllForm').querySelector('[name=do_apply]')?.remove();document.getElementById('parseAllForm').submit();" title="<?= $rtl?'معاينة فقط (بدون تطبيق)':'Preview only' ?>">
<i class="fa-solid fa-eye"></i> <?= $rtl?'معاينة التفتيك':'Preview parse' ?></button>
<button class="vf-btn go" type="submit" onclick="if(!confirm('<?= $rtl?"تطبيق التفتيك التلقائي على كل الغرف المعلّقة بثقة ≥ $min_score%؟":"Apply auto-parse to all pending rooms with ≥$min_score% confidence?" ?>'))return false;">
<i class="fa-solid fa-wand-magic-sparkles"></i> <?= $rtl?'تفتيك تلقائي شامل':'Bulk smart parse' ?></button></form>
</section>

<div class="vf-card" style="background:linear-gradient(135deg,#fef3c7,#fde68a);border-color:#f59e0b">
<form method="GET" style="display:flex;gap:14px;align-items:center;flex-wrap:wrap">
<i class="fa-solid fa-sliders" style="color:#d97706;font-size:18px"></i>
<b style="color:#92400e"><?= $rtl?'حد الثقة للتفتيك التلقائي:':'Auto-parse confidence threshold:' ?></b>
<select name="min_score" onchange="this.form.submit()" style="border:1.5px solid #f59e0b;border-radius:9px;padding:8px 14px;font-weight:800;background:#fff;color:#92400e">
<?php foreach ([40,50,60,70,80] as $t): ?>
<option value="<?= $t ?>" <?= $min_score===$t?'selected':'' ?>><?= $t ?>%</option>
<?php endforeach; ?>
</select>
<?php foreach (['b','f','status','dept'] as $k): ?><input type="hidden" name="<?= $k ?>" value="<?= e($_GET[$k] ?? '') ?>"><?php endforeach; ?>
<span style="font-size:11px;color:#78350f"><?= $rtl?'الغرف بثقة أقل تبقى معلّقة للمراجعة اليدوية.':"Rooms below this score stay pending for manual review." ?></span>
<?php if ($min_score !== 60): ?><a href="?<?= http_build_query(array_diff_key($_GET, ['min_score'=>1])) ?>" style="color:#dc2626;font-size:11px;font-weight:800"><i class="fa-solid fa-rotate-left"></i> <?= $rtl?'إعادة للافتراضي (60%)':'Reset to 60%' ?></a><?php endif; ?>
</form>
</div>

<div class="vf-stats">
<div class="vf-stat"><div class="ic" style="background:#ede9fe;color:#7c3aed"><i class="fa-solid fa-door-open"></i></div><div><div class="v"><?= number_format($kTotal) ?></div><div class="l"><?= $rtl?'إجمالي الغرف':'Total rooms' ?></div></div></div>
<div class="vf-stat"><div class="ic" style="background:#dcfce7;color:#16a34a"><i class="fa-solid fa-circle-check"></i></div><div><div class="v"><?= number_format($kVer) ?></div><div class="l"><?= $rtl?'موثّقة يدوياً':'Manual' ?></div></div></div>
<div class="vf-stat"><div class="ic" style="background:#fef3c7;color:#d97706"><i class="fa-solid fa-wand-magic-sparkles"></i></div><div><div class="v"><?= number_format($kAuto) ?></div><div class="l"><?= $rtl?'موثّقة ذكياً':'Auto' ?></div></div></div>
<div class="vf-stat"><div class="ic" style="background:#fee2e2;color:#dc2626"><i class="fa-solid fa-hourglass-half"></i></div><div><div class="v"><?= number_format($kPend) ?></div><div class="l"><?= $rtl?'غير موثّقة':'Pending' ?></div></div></div>
<div class="vf-stat"><div class="ic" style="background:#e0f2fe;color:#0284c7"><i class="fa-solid fa-percent"></i></div><div><div class="v"><?= $kPct ?>%</div><div class="l"><?= $rtl?'نسبة التوثيق':'Coverage' ?></div></div></div>
</div>

<!-- ═══ الخطوة 1: الفلترة المكانية ═══ -->
<div class="vf-card">
<h3><i class="fa-solid fa-filter"></i> <?= $rtl?'الخطوة 1: الفلترة المكانية':'Step 1: Spatial Filter' ?></h3>
<form method="GET" class="vf-grid">
<div><label><?= $rtl?'المبنى':'Building' ?></label>
<select name="b" onchange="this.form.submit()">
<option value=""><?= $rtl?'— كل المباني —':'— All —' ?></option>
<?php foreach ($buildings as $b): ?><option value="<?= $b['id'] ?>" <?= $sel_b==$b['id']?'selected':'' ?>><?= e($rtl?$b['name']:($b['name_en']?:$b['name'])) ?></option><?php endforeach; ?>
</select></div>
<div><label><?= $rtl?'الطابق (مربوط بالمبنى)':'Floor (bound)' ?></label>
<select name="f" onchange="this.form.submit()" <?= $sel_b?'':'disabled' ?>>
<option value=""><?= $rtl?'— كل الطوابق —':'— All —' ?></option>
<?php foreach ($floors as $f): ?><option value="<?= $f['id'] ?>" <?= $sel_f==$f['id']?'selected':'' ?>><?= e($rtl?$f['name']:($f['name_en']?:$f['name'])) ?></option><?php endforeach; ?>
</select></div>
<div><label><?= $rtl?'حالة التوثيق':'Status' ?></label>
<select name="status" onchange="this.form.submit()">
<option value=""><?= $rtl?'— الكل —':'— All —' ?></option>
<option value="verified" <?= $sel_status==='verified'?'selected':'' ?>><?= $rtl?'موثّقة يدوياً':'Manual' ?></option>
<option value="auto" <?= $sel_status==='auto'?'selected':'' ?>><?= $rtl?'موثّقة ذكياً':'Auto' ?></option>
<option value="pending" <?= $sel_status==='pending'?'selected':'' ?>><?= $rtl?'غير موثّقة':'Pending' ?></option>
</select></div>
<div><label><?= $rtl?'القسم الحالي':'Current dept' ?></label>
<select name="dept" onchange="this.form.submit()">
<option value=""><?= $rtl?'— الكل —':'— All —' ?></option>
<?php foreach ($depts_main as $d): ?><option value="<?= $d['id'] ?>" <?= $sel_dept==$d['id']?'selected':'' ?>><?= e($rtl?$d['name']:($d['name_en']?:$d['name'])) ?></option><?php endforeach; ?>
</select></div>
</form>
<div class="vf-hint"><i class="fa-solid fa-circle-info"></i> <?= $rtl?'قائمة الطوابق تعتمد على المبنى المختار. اترك الطابق فارغاً لرؤية كل طوابق المبنى.':'Floors depend on the selected building.' ?></div>
</div>

<?php if (!$rooms): ?>
<div style="text-align:center;padding:50px 20px;color:#94a3b8;background:#fff;border:1.5px dashed #cbd5e1;border-radius:18px">
<i class="fa-solid fa-filter-circle-xmark" style="font-size:42px;display:block;margin-bottom:10px;color:#cbd5e1"></i>
<h3><?= $rtl?'لا توجد غرف مطابقة للفلترة':'No rooms match' ?></h3></div>
<?php else: ?>

<!-- ═══ الخطوة 2: الربط الجماعي ═══ -->
<div class="vf-card">
<h3><i class="fa-solid fa-layer-group"></i> <?= $rtl?'الخطوة 2: الربط الجماعي بالقسم':'Step 2: Bulk Link' ?></h3>
<div class="vf-grid" style="grid-template-columns:1fr 1fr 2fr">
<div><label><?= $rtl?'القسم الرئيسي (يُحفظ)':'Main dept (saved)' ?> <span style="color:#dc2626">*</span></label>
<select id="deptMain"><option value=""><?= $rtl?'— اختر رئيسياً —':'— Select main —' ?></option>
<?php foreach ($depts_main as $d): ?><option value="<?= $d['id'] ?>"><?= e($rtl?$d['name']:($d['name_en']?:$d['name'])) ?></option><?php endforeach; ?>
</select></div>
<div><label><?= $rtl?'القسم الفرعي (اختياري)':'Sub dept (optional)' ?></label>
<select id="deptSub" disabled><option value=""><?= $rtl?'— اختر رئيسياً أولاً —':'— Main first —' ?></option></select></div>
<div style="display:flex;gap:8px;align-items:end;flex-wrap:wrap">
<form method="POST" style="display:inline" id="bulkForm"><?= csrf_input() ?><input type="hidden" name="action" value="bulk_link">
<?php foreach (['b','f','status','dept'] as $k): ?><input type="hidden" name="keep[<?= $k ?>]" value="<?= e($_GET[$k] ?? '') ?>"><?php endforeach; ?>
<button class="vf-btn go" type="submit" onclick="return collectIds('bulk')"><i class="fa-solid fa-link"></i> <?= $rtl?'ربط المحدد':'Link selected' ?></button></form>
<form method="POST" style="display:inline" id="autoForm"><?= csrf_input() ?><input type="hidden" name="action" value="auto_link">
<?php foreach (['b','f','status','dept'] as $k): ?><input type="hidden" name="keep[<?= $k ?>]" value="<?= e($_GET[$k] ?? '') ?>"><?php endforeach; ?>
<button class="vf-btn smart" type="submit" onclick="return collectIds('auto')"><i class="fa-solid fa-wand-magic-sparkles"></i> <?= $rtl?'ربط ذكي (غير الموثقة)':'Smart link' ?></button></form>
<form method="POST" style="display:inline" id="unlinkForm"><?= csrf_input() ?><input type="hidden" name="action" value="unlink">
<?php foreach (['b','f','status','dept'] as $k): ?><input type="hidden" name="keep[<?= $k ?>]" value="<?= e($_GET[$k] ?? '') ?>"><?php endforeach; ?>
<button class="vf-btn danger" type="submit" onclick="return collectIds('unlink')"><i class="fa-solid fa-unlink"></i> <?= $rtl?'فصل المحدد':'Unlink' ?></button></form>
</div>
</div>
<div class="vf-hint"><i class="fa-solid fa-lightbulb"></i> <?= $rtl?'الفرعي <b>للمساعدة في القرار</b> فقط — الحفظ الفعلي على <b>الرئيسي</b> (العهدة على مستوى الرئيسي).':'Sub is decision-helper only; save is on main.' ?></div>
</div>

<!-- ═══ الخطوة 3: النتائج ═══ -->
<div class="vf-card">
<h3><i class="fa-solid fa-table-list"></i> <?= $rtl?'الخطوة 3: النتائج':'Step 3: Results' ?> <span style="font-size:11px;color:#94a3b8;font-weight:600">(<?= count($rooms) ?>)</span></h3>
<div style="overflow-x:auto">
<table class="vf">
<thead><tr>
<th style="width:30px"><input type="checkbox" id="chkAll" checked></th>
<th><?= $rtl?'الغرفة':'Room' ?></th>
<th><?= $rtl?'المسار':'Path' ?></th>
<th><?= $rtl?'القسم الحالي':'Current dept' ?></th>
<th><?= $rtl?'الاقتراح الذكي':'Smart suggestion' ?></th>
<th><?= $rtl?'الحالة':'Status' ?></th>
</tr></thead>
<tbody>
<?php foreach ($rooms as $r):
$path = trim(($r['b_name']??'').' / '.($r['f_name']??''), ' /');
$status = !$r['dept_id'] ? 'pending' : ($r['parse_status'] ?: 'verified');
$stLabel = ['verified'=>($rtl?'يدوي':'Manual'),'auto'=>($rtl?'ذكي '.($r['confidence']??'').'%':'Auto '.($r['confidence']??'').'%'),'pending'=>($rtl?'غير موثّقة':'Pending')][$status];
$sug = $suggestions[$r['id']] ?? null;
?>
<tr>
<td><input type="checkbox" class="item-chk" value="<?= $r['id'] ?>" data-status="<?= e($status) ?>" checked></td>
<td><b><?= e($rtl?$r['name']:($r['name_en']?:$r['name'])) ?></b></td>
<td style="color:#64748b;font-size:11.5px"><?= e($path) ?></td>
<td><?php if ($r['dept_name']): ?>
<span style="font-weight:800"><?= e($r['dept_name']) ?></span>
<?php if ((int)$r['dept_level']===2 && $r['dept_parent_name']): ?><div class="dept-path"><?= e($r['dept_parent_name']) ?><span class="arrow">←</span><?= e($r['dept_name']) ?></div><?php endif; ?>
<?php else: ?><span style="color:#94a3b8">—</span><?php endif; ?></td>
<td><?php if ($sug): ?>
<div style="font-size:11.5px"><b><?= e($sug['name']) ?></b>
<span class="vf-badge <?= $sug['score']>=70?'auto':($sug['score']>=40?'pending':'pending') ?>" style="font-size:10px;margin-right:4px"><?= $sug['score'] ?>%</span>
</div>
<div style="font-size:10px;color:#94a3b8;margin-top:2px"><?= e($sug['matched']) ?></div>
<?php else: ?><span style="color:#cbd5e1;font-size:11px">— <?= $rtl?'بدون':'none' ?> —</span><?php endif; ?></td>
<td><span class="vf-badge <?= $status ?>"><?= e($stLabel) ?></span></td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
</div>
</div>
<?php endif; ?>

</div></main>
</div>
<script>
const DEPTS_SUB = <?= json_encode($depts_sub, JSON_UNESCAPED_UNICODE) ?>;
const RTL = <?= $rtl?'true':'false' ?>;

document.getElementById('deptMain').addEventListener('change', function(){
    const sub = document.getElementById('deptSub');
    const pid = parseInt(this.value)||0;
    sub.innerHTML = '<option value="">'+(RTL?'— بدون (رئيسي فقط) —':'— None —')+'</option>';
    if (pid) {
        sub.disabled = false;
        DEPTS_SUB.filter(s=>parseInt(s.parent_id)===pid).forEach(s=>{
            const o=document.createElement('option'); o.value=s.id; o.textContent=RTL?s.name:(s.name_en||s.name); sub.appendChild(o);
        });
    } else sub.disabled = true;
});

document.getElementById('chkAll')?.addEventListener('change', function(){
    document.querySelectorAll('.item-chk').forEach(c=>c.checked=this.checked);
});

function collectIds(mode){
    if (mode==='bulk') {
        const main = document.getElementById('deptMain').value;
        const sub  = document.getElementById('deptSub').value;
        const dept = sub || main;
        if (!dept) { alert(RTL?'اختر قسماً رئيسياً أولاً.':'Select a main dept.'); return false; }
        const ids=[...document.querySelectorAll('.item-chk:checked')].map(c=>c.value);
        if (!ids.length){ alert(RTL?'اختر غرفة واحدة على الأقل.':'Select at least one room.'); return false; }
        if (!confirm(RTL?'ربط '+ids.length+' غرفة بالقسم المحدد؟':'Link '+ids.length+' rooms?')) return false;
        const f=document.getElementById('bulkForm');
        ids.forEach(id=>{const i=document.createElement('input');i.type='hidden';i.name='ids[]';i.value=id;f.appendChild(i);});
        const d=document.createElement('input');d.type='hidden';d.name='dept_id';d.value=dept;f.appendChild(d);
        return true;
    }
    if (mode==='auto') {
        const ids=[...document.querySelectorAll('.item-chk:checked')].filter(c=>c.dataset.status==='pending').map(c=>c.value);
        if (!ids.length){ alert(RTL?'لا توجد غرف غير موثقة ضمن الاختيار.':'No pending rooms selected.'); return false; }
        if (!confirm(RTL?'الربط الذكي لـ '+ids.length+' غرفة؟':'Smart-link '+ids.length+' rooms?')) return false;
        const f=document.getElementById('autoForm');
        ids.forEach(id=>{const i=document.createElement('input');i.type='hidden';i.name='ids[]';i.value=id;f.appendChild(i);});
        return true;
    }
    if (mode==='unlink') {
        const ids=[...document.querySelectorAll('.item-chk:checked')].filter(c=>c.dataset.status!=='pending').map(c=>c.value);
        if (!ids.length){ alert(RTL?'اختر غرفاً موثقة لفصلها.':'Select verified rooms.'); return false; }
        if (!confirm(RTL?'فصل '+ids.length+' غرفة؟':'Unlink '+ids.length+' rooms?')) return false;
        const f=document.getElementById('unlinkForm');
        ids.forEach(id=>{const i=document.createElement('input');i.type='hidden';i.name='ids[]';i.value=id;f.appendChild(i);});
        return true;
    }
    return false;
}
</script>
</body>
</html>