<?php
/**
 * settings/merge_duplicate_departments.php
 * أداة دمج الأقسام الفرعية المكررة (الناتجة عن بيان التحويلات)
 * - تحتفظ بأصغر id لكل مجموعة (نفس الاسم + نفس الأب)
 * - تدمج أرقام التحويلات في الصف المُبقى مفصولة بفاصلة
 * - تحوّل كل المراجع (أي جدول يشير بـ dept_id / department_id)
 * - تحذف الصفوف المكررة + نسخة احتياطية تلقائية
 * الاستخدام: افتح الصفحة = معاينة، ثم زر «تنفيذ الدمج» = ?apply=1
 */
require_once dirname(__DIR__) . '/config.php';
page_guard('settings.index');
if (!is_admin()) { flash('danger', 'المديرون فقط'); header('Location: ' . BASE_URL . '/dashboard.php'); exit; }

$apply = (isset($_GET['apply']) && $_GET['apply'] === '1');

/* ═══ اكتشاف الأعمدة تلقائياً ═══ */
$cols = $pdo->query("SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'departments'")->fetchAll(PDO::FETCH_COLUMN);

$parent_col = null;
foreach (['parent_id','parent_dept_id','pid','parent'] as $c) if (in_array($c, $cols)) { $parent_col = $c; break; }

$ext_col = null;
foreach (['extension','ext','phone_extension','phone_ext','extension_number','ext_number','internal_ext','internal_phone','tahwila','tawila'] as $c)
    if (in_array($c, $cols)) { $ext_col = $c; break; }
if (!$ext_col) foreach ($cols as $c)
    if (stripos($c,'ext') !== false || stripos($c,'phone') !== false || stripos($c,'taw') !== false) { $ext_col = $c; break; }

/* ═══ الجداول التي تشير إلى departments ═══ */
$refs = $pdo->query("SELECT TABLE_NAME, COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND COLUMN_NAME IN ('dept_id','department_id')
      AND TABLE_NAME <> 'departments'")->fetchAll(PDO::FETCH_ASSOC);

$psel = $parent_col ? $parent_col : 'NULL';

/* ═══ بناء تقرير المجموعات المكررة ═══ */
function merge_build_report($pdo, $psel, $ext_col, $refs) {
    $groups = $pdo->query("SELECT name, $psel AS pid, GROUP_CONCAT(id ORDER BY id) AS ids
        FROM departments WHERE is_active = 1
        GROUP BY name, $psel
        HAVING COUNT(*) > 1
        ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
    $report = [];
    foreach ($groups as $g) {
        $ids  = array_map('intval', explode(',', $g['ids']));
        $keep = $ids[0];
        $rest = array_slice($ids, 1);
        $in   = implode(',', $ids);

        $exts = [];
        if ($ext_col) {
            foreach ($pdo->query("SELECT `$ext_col` x FROM departments WHERE id IN ($in) ORDER BY id")->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $v = trim((string)$r['x']); if ($v !== '') $exts[] = $v;
            }
            $exts = array_values(array_unique($exts));
        }
        $ref_counts = [];
        foreach ($refs as $rc) {
            $c = (int)$pdo->query("SELECT COUNT(*) FROM `{$rc['TABLE_NAME']}` WHERE `{$rc['COLUMN_NAME']}` IN ($in)")->fetchColumn();
            if ($c > 0) $ref_counts[] = "{$rc['TABLE_NAME']}.{$rc['COLUMN_NAME']} ($c)";
        }
        $report[] = ['name'=>$g['name'], 'keep'=>$keep, 'rest'=>$rest, 'exts'=>$exts, 'refs'=>$ref_counts];
    }
    return $report;
}

$report = merge_build_report($pdo, $psel, $ext_col, $refs);

/* ═══ التنفيذ ═══ */
$done = false;
if ($apply && $report) {
    $pdo->exec("CREATE TABLE IF NOT EXISTS departments_backup_" . date('Ymd_His') . " AS SELECT * FROM departments");
    $pdo->beginTransaction();
    foreach ($report as $r) {
        $rest_in = implode(',', $r['rest']);
        if ($ext_col && $r['exts'])
            $pdo->prepare("UPDATE departments SET `$ext_col` = ? WHERE id = ?")->execute([implode(', ', $r['exts']), $r['keep']]);
        foreach ($refs as $rc)
            $pdo->exec("UPDATE `{$rc['TABLE_NAME']}` SET `{$rc['COLUMN_NAME']}` = {$r['keep']} WHERE `{$rc['COLUMN_NAME']}` IN ($rest_in)");
        if ($parent_col)
            $pdo->exec("UPDATE departments SET `$parent_col` = {$r['keep']} WHERE `$parent_col` IN ($rest_in)");
        $pdo->exec("DELETE FROM departments WHERE id IN ($rest_in)");
    }
    $pdo->commit();
    $done = true;
    $report = merge_build_report($pdo, $psel, $ext_col, $refs); // إعادة القراءة بعد الدمج
}
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
<meta charset="UTF-8">
<title>دمج الأقسام المكررة</title>
<link href="https://fonts.googleapis.com/css2?family=Tajawal:wght@400;700;800&display=swap" rel="stylesheet">
<style>
body{font-family:'Tajawal',sans-serif;background:#f1f5f9;margin:0;padding:24px}
.box{max-width:1000px;margin:0 auto;background:#fff;border-radius:14px;padding:22px;box-shadow:0 1px 4px rgba(0,0,0,.08)}
h1{font-size:19px;margin:0 0 6px}
p.sub{color:#64748b;font-size:13px;margin:0 0 16px}
.det{background:#eff6ff;border:1px solid #bfdbfe;border-radius:10px;padding:10px 14px;font-size:12.5px;margin-bottom:16px}
table{width:100%;border-collapse:collapse;font-size:12.5px}
th{background:#f8fafc;padding:9px 10px;text-align:right;font-size:11.5px;color:#64748b}
td{padding:9px 10px;border-bottom:1px solid #f1f5f9;vertical-align:top}
.ok{background:#dcfce7;color:#166534;padding:10px 14px;border-radius:10px;font-weight:700;margin-bottom:14px}
.warn{background:#fef9c3;color:#854d0e;padding:10px 14px;border-radius:10px;font-weight:700;margin-bottom:14px}
.btn{display:inline-block;padding:10px 20px;border-radius:10px;font-weight:800;text-decoration:none;font-size:14px;border:none;cursor:pointer;font-family:'Tajawal'}
.btn-r{background:#dc2626;color:#fff}.btn-s{background:#e2e8f0;color:#334155}
.mono{font-family:monospace;background:#eef2ff;color:#3730a3;padding:1px 6px;border-radius:5px}
</style>
</head>
<body>
<div class="box">
<h1>🔀 دمج الأقسام الفرعية المكررة</h1>
<p class="sub">يُبقى أصغر id لكل مجموعة (نفس الاسم + نفس الأب)، تُدمج التحويلات بفاصلة، تُحوَّل المراجع، ويُحذف المكرر.</p>

<?php if ($done): ?>
<div class="ok">✅ تم تنفيذ الدمج بنجاح — وأُخذت نسخة احتياطية تلقائية (departments_backup_*)</div>
<?php endif; ?>

<div class="det">
عمود الأب المكتشف: <span class="mono"><?= $parent_col ? e($parent_col) : 'غير موجود' ?></span>
&nbsp;|&nbsp; عمود التحويلة المكتشف: <span class="mono"><?= $ext_col ? e($ext_col) : 'غير موجود ⚠' ?></span>
&nbsp;|&nbsp; جداول تشير للأقسام: <span class="mono"><?= count($refs) ? e(implode('، ', array_map(function($r){ return $r['TABLE_NAME'].'.'.$r['COLUMN_NAME']; }, $refs))) : 'لا يوجد' ?></span>
</div>

<?php if (!$ext_col): ?>
<div class="warn">⚠ لم يُتعرف على عمود التحويلة — سيُنفَّذ الدمج والحذف لكن بدون دمج الأرقام. أخبرني باسم العمود لأضبطه.</div>
<?php endif; ?>

<?php if (empty($report)): ?>
<div class="ok">🎉 لا توجد أقسام مكررة — الجدول نظيف!</div>
<?php else: ?>
<?php if (!$done): ?>
<div class="warn">⚠ وُجدت <?= count($report) ?> مجموعة تكرار — راجعها بالأسفل ثم اضغط «تنفيذ الدمج».</div>
<?php endif; ?>
<table>
<tr><th>القسم</th><th>يُبقى id</th><th>يُحذف ids</th><th>التحويلات بعد الدمج</th><th>مراجع مرتبطة</th></tr>
<?php foreach ($report as $r): ?>
<tr>
<td><b><?= e($r['name']) ?></b></td>
<td><span class="mono"><?= $r['keep'] ?></span></td>
<td><span class="mono"><?= e(implode(', ', $r['rest'])) ?></span></td>
<td><?= $r['exts'] ? e(implode('، ', $r['exts'])) : '—' ?></td>
<td><?= $r['refs'] ? e(implode('، ', $r['refs'])) : '—' ?></td>
</tr>
<?php endforeach; ?>
</table>
<?php endif; ?>

<div style="margin-top:18px;display:flex;gap:10px">
<?php if (!empty($report) && !$done): ?>
<a class="btn btn-r" href="?apply=1" onclick="return confirm('سيتم دمج <?= count($report) ?> مجموعة وحذف المكرر مع نسخة احتياطية تلقائية. متابعة؟')">⚡ تنفيذ الدمج الآن</a>
<?php endif; ?>
<a class="btn btn-s" href="<?= BASE_URL ?>/settings/locations.php">العودة لإدارة المواقع</a>
</div>
</div>
</body>
</html>