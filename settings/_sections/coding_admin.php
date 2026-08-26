<?php
/**
 * settings/_sections/coding_admin.php — تبويب «الترميز»
 * معالجات توليد + تحكم يدوي لكل مستوى (مبنى/طابق/غرفة)
 * يُضمَّن من settings/locations.php
 */
if (!defined('PMSH_CODING_SECTION')) define('PMSH_CODING_SECTION', true);

/* ═══ مساعدات مكتفية ذاتياً (إن لم توجد) ═══ */
$icols = $pdo->query("SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'item_locations'")->fetchAll(PDO::FETCH_COLUMN);
if (!in_array('node_code', $icols)) {
    try { $pdo->exec("ALTER TABLE item_locations ADD COLUMN node_code VARCHAR(20) NULL AFTER name_en"); } catch (Throwable $e) {}
}
if (!function_exists('loc_node_path')) {
    function loc_node_path($id) {
        global $pdo;
        $st = $pdo->prepare("SELECT id, parent_id, location_type, room_code, node_code FROM item_locations WHERE id = ?");
        $path = []; $cur = $id; $g = 0;
        while ($cur && $g < 10) {
            $st->execute([$cur]); $n = $st->fetch(PDO::FETCH_ASSOC);
            if (!$n) break;
            array_unshift($path, $n);
            $cur = $n['parent_id'] ? (int)$n['parent_id'] : 0; $g++;
        }
        return $path;
    }
}
if (!function_exists('loc_node_fallback')) {
    function loc_node_fallback($n) {
        if ($n['location_type'] === 'building') return 'B' . $n['id'];
        if ($n['location_type'] === 'floor')   return 'F' . $n['id'];
        return !empty($n['room_code']) ? $n['room_code'] : 'R' . $n['id'];
    }
}
if (!function_exists('loc_build_code')) {
    function loc_build_code($id) {
        $parts = [];
        foreach (loc_node_path($id) as $n) {
            $c = trim((string)($n['node_code'] ?? ''));
            if ($c === '') $c = loc_node_fallback($n);
            $parts[] = $c;
        }
        return implode('-', $parts);
    }
}
if (!function_exists('loc_rebuild_all')) {
    function loc_rebuild_all() {
        global $pdo;
        $ids = $pdo->query("SELECT id FROM item_locations WHERE is_active=1")->fetchAll(PDO::FETCH_COLUMN);
        $u = $pdo->prepare("UPDATE item_locations SET location_code = ? WHERE id = ?");
        foreach ($ids as $id) $u->execute([loc_build_code((int)$id), (int)$id]);
        return count($ids);
    }
}

/* ═══ معالجة POST ═══ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && verify_csrf()) {
    $action = isset($_POST['action']) ? $_POST['action'] : '';

    if ($action === 'code_apply') {
        $level  = isset($_POST['level']) ? $_POST['level'] : 'buildings';
        $mode   = isset($_POST['mode']) ? $_POST['mode'] : 'alpha';
        $prefix = trim(isset($_POST['prefix']) ? $_POST['prefix'] : '');
        $changed = 0;

        if ($level === 'buildings') {
            $rows = $pdo->query("SELECT id FROM item_locations WHERE location_type='building' AND is_active=1 ORDER BY name")->fetchAll(PDO::FETCH_COLUMN);
            $i = 0;
            foreach ($rows as $rid) {
                if ($mode === 'clear') $pdo->prepare("UPDATE item_locations SET node_code=NULL WHERE id=?")->execute([$rid]);
                else {
                    $code = ($mode === 'alpha') ? (chr(65 + ($i % 26)) . ($i >= 26 ? intdiv($i,26) : ''))
                          : (($mode === 'prefix_seq') ? $prefix . ($i + 1) : (string)($i + 1));
                    $pdo->prepare("UPDATE item_locations SET node_code=? WHERE id=?")->execute([$code, $rid]);
                }
                $changed++; $i++;
            }
        }
        elseif ($level === 'floors') {
            $blds = $pdo->query("SELECT id FROM item_locations WHERE location_type='building' AND is_active=1 ORDER BY name")->fetchAll(PDO::FETCH_COLUMN);
            foreach ($blds as $b) {
                $st = $pdo->prepare("SELECT id FROM item_locations WHERE parent_id=? AND is_active=1 ORDER BY name"); $st->execute([$b]);
                $n = 1;
                foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $fid) {
                    if ($mode === 'clear') $pdo->prepare("UPDATE item_locations SET node_code=NULL WHERE id=?")->execute([$fid]);
                    else {
                        $code = ($mode === 'alpha') ? (chr(64 + min($n,26)))
                              : (($mode === 'prefix_seq') ? $prefix . $n : (string)$n);
                        $pdo->prepare("UPDATE item_locations SET node_code=? WHERE id=?")->execute([$code, $fid]);
                    }
                    $changed++; $n++;
                }
            }
        }
        else { /* rooms */
            if ($mode === 'from_room') {
                $pdo->exec("UPDATE item_locations SET node_code = room_code WHERE location_type='room' AND is_active=1 AND room_code IS NOT NULL AND room_code != ''");
                $changed = 1;
            } else {
                $fls = $pdo->query("SELECT id FROM item_locations WHERE location_type='floor' AND is_active=1 ORDER BY name")->fetchAll(PDO::FETCH_COLUMN);
                foreach ($fls as $f) {
                    $st = $pdo->prepare("SELECT id FROM item_locations WHERE parent_id=? AND is_active=1 ORDER BY name"); $st->execute([$f]);
                    $n = 1;
                    foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $rid) {
                        if ($mode === 'clear') $pdo->prepare("UPDATE item_locations SET node_code=NULL WHERE id=?")->execute([$rid]);
                        else {
                            $code = ($mode === 'prefix_seq') ? $prefix . $n : (string)$n;
                            $pdo->prepare("UPDATE item_locations SET node_code=? WHERE id=?")->execute([$code, $rid]);
                        }
                        $changed++; $n++;
                    }
                }
            }
        }
        $cnt = loc_rebuild_all();
        flash('success', "✅ طُبّق المعالج على $changed عنصراً وأعيد بناء $cnt كوداً");
        header('Location: ' . BASE_URL . '/settings/locations.php?tab=scheme'); exit;
    }

    if ($action === 'code_manual') {
        $mc = isset($_POST['mc']) ? $_POST['mc'] : [];
        $changed = 0;
        $u = $pdo->prepare("UPDATE item_locations SET node_code=? WHERE id=?");
        foreach ($mc as $id => $val) {
            $val = trim((string)$val);
            $u->execute([$val !== '' ? $val : null, (int)$id]);
            $changed++;
        }
        $cnt = loc_rebuild_all();
        flash('success', "✅ حُفظ $changed رمزاً يدوياً وأعيد بناء $cnt كوداً (الفارغ = مسح الرمز)");
        header('Location: ' . BASE_URL . '/settings/locations.php?tab=scheme'); exit;
    }
}

/* ═══ بيانات التبويب ═══ */
$buildings = $pdo->query("SELECT id,name,name_en,node_code,location_code FROM item_locations WHERE location_type='building' AND is_active=1 ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
$floors = $pdo->query("SELECT l.id,l.name,l.name_en,l.node_code,l.location_code,b.name AS bname
    FROM item_locations l LEFT JOIN item_locations b ON b.id=l.parent_id
    WHERE l.location_type='floor' AND l.is_active=1 ORDER BY b.name,l.name")->fetchAll(PDO::FETCH_ASSOC);
$rooms = $pdo->query("SELECT l.id,l.name,l.name_en,l.node_code,l.location_code,l.room_code,f.name AS fname,b.name AS bname
    FROM item_locations l LEFT JOIN item_locations f ON f.id=l.parent_id LEFT JOIN item_locations b ON b.id=f.parent_id
    WHERE l.location_type='room' AND l.is_active=1 ORDER BY b.name,f.name,l.name")->fetchAll(PDO::FETCH_ASSOC);

function code_gen_form($level, $extra_modes = []) {
    $modes = ['alpha'=>'أبجدي A,B,C...','prefix_seq'=>'بادئة + تسلسل (A1,A2...)','seq'=>'رقمي 1,2,3...','clear'=>'مسح الرموز'];
    foreach ($extra_modes as $k=>$v) $modes[$k]=$v;
    ob_start(); ?>
    <form method="POST" style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;margin-bottom:12px">
    <?= csrf_input() ?>
    <input type="hidden" name="action" value="code_apply"><input type="hidden" name="level" value="<?= $level ?>">
    <select name="mode" style="padding:7px;border-radius:8px;border:1px solid #e2e8f0">
    <?php foreach ($modes as $k=>$v): ?><option value="<?= $k ?>"><?= $v ?></option><?php endforeach; ?>
    </select>
    <input type="text" name="prefix" placeholder="البادئة (A)" style="width:80px;padding:7px;border-radius:8px;border:1px solid #e2e8f0">
    <button class="btn btn-p">⚡ توليد</button>
    </form>
    <?php return ob_get_clean();
}
function code_manual_table($rows, $show_path = false) {
    ob_start(); ?>
    <table style="width:100%;border-collapse:collapse;font-size:12px">
    <tr style="background:#f8fafc"><th style="padding:7px;text-align:right">الموقع</th><?php if($show_path): ?><th style="padding:7px;text-align:right">المسار</th><?php endif; ?><th style="padding:7px;width:110px">الرمز الحالي</th><th style="padding:7px;width:130px">الرمز الجديد</th></tr>
    <?php foreach ($rows as $r): ?>
    <tr data-search="<?= e(mb_strtolower($r['name'].' '.($r['bname']??'').' '.($r['fname']??''))) ?>" style="border-bottom:1px solid #f1f5f9">
    <td style="padding:6px"><?= e($r['name']) ?></td>
    <?php if($show_path): ?><td style="padding:6px;color:#64748b"><?= e(trim(($r['bname']??'').' '.($r['fname']??''))) ?></td><?php endif; ?>
    <td style="padding:6px"><span class="mono"><?= e($r['node_code'] ?? '') ?></span> <small style="color:#94a3b8"><?= e($r['location_code'] ?? '') ?></small></td>
    <td style="padding:6px"><input name="mc[<?= $r['id'] ?>]" value="<?= e($r['node_code'] ?? '') ?>" style="width:100%;padding:5px;border:1px solid #e2e8f0;border-radius:6px"></td>
    </tr>
    <?php endforeach; ?>
    </table>
    <?php return ob_get_clean();
}
?>
<style>.cod-search{margin:8px 0;padding:7px;border:1px solid #e2e8f0;border-radius:8px;width:100%}</style>

<div class="card">
<h3 style="margin:0 0 10px">🏢 المباني (<?= count($buildings) ?>)</h3>
<?= code_gen_form('buildings') ?>
<form method="POST"><?= csrf_input() ?><input type="hidden" name="action" value="code_manual">
<?= code_manual_table($buildings) ?>
<button class="btn btn-g" style="margin-top:10px">💾 حفظ رموز المباني</button>
</form>
</div>

<div class="card">
<h3 style="margin:0 0 10px">🏗️ الطوابق (<?= count($floors) ?>)</h3>
<?= code_gen_form('floors') ?>
<input class="cod-search" placeholder="بحث..." oninput="codFilter(this,'fl')">
<form method="POST"><?= csrf_input() ?><input type="hidden" name="action" value="code_manual">
<div id="cod-fl"><?= code_manual_table($floors, true) ?></div>
<button class="btn btn-g" style="margin-top:10px">💾 حفظ رموز الطوابق</button>
</form>
</div>

<div class="card">
<h3 style="margin:0 0 10px">🚪 الغرف (<?= count($rooms) ?>)</h3>
<?= code_gen_form('rooms', ['from_room'=>'نسخ من room_code']) ?>
<input class="cod-search" placeholder="بحث..." oninput="codFilter(this,'rm')">
<form method="POST"><?= csrf_input() ?><input type="hidden" name="action" value="code_manual">
<div id="cod-rm"><?= code_manual_table($rooms, true) ?></div>
<button class="btn btn-g" style="margin-top:10px">💾 حفظ رموز الغرف</button>
</form>
</div>

<script>
function codFilter(inp, key){
  var q = inp.value.toLowerCase();
  document.getElementById('cod-'+key).querySelectorAll('tr[data-search]').forEach(function(tr){
    tr.style.display = (!q || tr.dataset.search.includes(q)) ? '' : 'none';
  });
}
</script>