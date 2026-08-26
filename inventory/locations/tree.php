<?php
/**
 * inventory/locations/tree.php — لوحة إدارة المواقع (كاملة)
 * استعراض هرمي + تعديل + ترجمة AI (مدمجة) + إضافة + حذف محمي
 * تعتمد includes/_utils.php للـ AI والترجمة الثنائية
 */
require_once dirname(__DIR__, 2) . '/config.php';
require_once BASE_PATH . '/includes/_utils.php';
page_guard('inventory.index');
if (!(is_admin() || (function_exists('can') && can('inventory.locations','manage')))) abort(403);
$rtl = is_rtl();
$ai_ready = function_exists('ai_is_ready') && ai_is_ready();

/* ── Cascade: عند تعديل اسم موقع، زامن الحقول النصية في الأصول ── */
function cascade_location_name_update(PDO $pdo, int $loc_id, string $type): int {
    $st = $pdo->prepare("SELECT r.name, f.name f_n, b.name b_n FROM item_locations r
        LEFT JOIN item_locations f ON f.id=r.parent_id LEFT JOIN item_locations b ON b.id=f.parent_id WHERE r.id=?");
    $st->execute([$loc_id]); $row = $st->fetch(PDO::FETCH_ASSOC); if (!$row) return 0;
    $b=$row['b_n']??''; $f=$row['f_n']??''; $r=$row['name']??'';
    if ($type==='building') {
        $st=$pdo->prepare("UPDATE assets SET loc_building=? WHERE location_id IN (SELECT id FROM item_locations WHERE id=? OR parent_id=? OR parent_id IN (SELECT id FROM item_locations WHERE parent_id=?))");
        $st->execute([$b,$loc_id,$loc_id,$loc_id]);
    } elseif ($type==='floor') {
        $st=$pdo->prepare("UPDATE assets SET loc_building=?, loc_floor=? WHERE location_id IN (SELECT id FROM item_locations WHERE id=? OR parent_id=?)");
        $st->execute([$b,$f,$loc_id,$loc_id]);
    } else {
        $st=$pdo->prepare("UPDATE assets SET loc_building=?, loc_floor=?, loc_room=? WHERE location_id=?");
        $st->execute([$b,$f,$r,$loc_id]);
    }
    return $st->rowCount();
}

/* ═══ POST ═══ */
if ($_SERVER['REQUEST_METHOD']==='POST') {
    $act = $_POST['action'] ?? '';

    // ── ترجمة AI (JSON) — مع fallback ──
    if ($act === 'translate') {
        header('Content-Type: application/json; charset=utf-8');
        $text = trim($_POST['text'] ?? '');
        $to   = ($_POST['to'] ?? 'ar') === 'en' ? 'en' : 'ar';
        if ($text==='') { echo json_encode(['ok'=>false,'error'=>'empty']); exit; }
        $result = translate_with_fallback($text, $to === 'ar' ? 'en' : 'ar', $to, 'location');
        echo json_encode($result['ok'] ? ['ok'=>true,'translated'=>$result['text'],'via'=>$result['source']] : ['ok'=>false,'error'=>'all_failed']);
        exit;
    }

    if (!verify_csrf()) { flash('error','CSRF error'); header('Location: '.BASE_URL.'/inventory/locations/tree.php'); exit; }
    $keep = http_build_query(array_filter(['b'=>$_POST['keep']['b']??'','f'=>$_POST['keep']['f']??'','r'=>$_POST['keep']['r']??'','tab'=>$_POST['keep']['tab']??'manage']));
    $redir = BASE_URL.'/inventory/locations/tree.php?'.($keep?:'tab=manage');

    if ($act==='update') {
        $id=(int)($_POST['id']??0); $ar=trim($_POST['name_ar']??''); $en=trim($_POST['name_en']??'');
        if ($id && ($ar!==''||$en!=='')) {
            $t=$pdo->prepare("SELECT location_type FROM item_locations WHERE id=?"); $t->execute([$id]); $type=$t->fetchColumn();
            $pdo->prepare("UPDATE item_locations SET name=?, name_en=? WHERE id=?")->execute([$ar?:null,$en?:null,$id]);
            $n=cascade_location_name_update($pdo,$id,$type);
            flash('success', ($rtl?"تم التحديث".($n?" + مزامنة $n أصل":""):"Updated".($n?" + synced $n assets":"")));
        }
        header('Location: '.$redir); exit;
    }

    if ($act==='delete') {
        $id=(int)($_POST['id']??0);
        if ($id) {
            $t=$pdo->prepare("SELECT location_type FROM item_locations WHERE id=?"); $t->execute([$id]); $type=$t->fetchColumn();
            $c=$pdo->prepare("SELECT COUNT(*) FROM item_locations WHERE parent_id=?"); $c->execute([$id]); $children=(int)$c->fetchColumn();
            $as=0; if($type==='room'){ $a=$pdo->prepare("SELECT COUNT(*) FROM assets WHERE location_id=?"); $a->execute([$id]); $as=(int)$a->fetchColumn(); }
            if ($children>0) flash('error', $rtl?"لا يمكن الحذف: يوجد $children عنصر تابع.":"Cannot delete: $children children.");
            elseif ($as>0) flash('error', $rtl?"لا يمكن الحذف: يوجد $as أصل داخل الغرفة.":"Cannot delete: $as assets inside.");
            else { $pdo->prepare("DELETE FROM item_locations WHERE id=?")->execute([$id]); flash('success',$rtl?'تم الحذف.':'Deleted.'); }
        }
        header('Location: '.$redir); exit;
    }

    if ($act==='add') {
        $type=$_POST['loc_type']??''; $ar=trim($_POST['name_ar']??''); $en=trim($_POST['name_en']??''); $parent=(int)($_POST['parent_id']??0);
        $err=[];
        if (!in_array($type,['building','floor','room'])) $err[]=$rtl?'نوع غير صحيح.':'Invalid type.';
        if ($ar===''&&$en==='') $err[]=$rtl?'اكتب اسماً واحداً على الأقل.':'Need a name.';
        if ($type==='floor'&&!$parent) $err[]=$rtl?'اختر المبنى.':'Select building.';
        if ($type==='room'&&!$parent) $err[]=$rtl?'اختر الطابق.':'Select floor.';
        if ($type==='floor'&&$parent){$p=$pdo->prepare("SELECT location_type FROM item_locations WHERE id=?");$p->execute([$parent]);if($p->fetchColumn()!=='building')$err[]=$rtl?'المبنى غير صحيح.':'Bad building.';}
        if ($type==='room'&&$parent){$p=$pdo->prepare("SELECT location_type FROM item_locations WHERE id=?");$p->execute([$parent]);if($p->fetchColumn()!=='floor')$err[]=$rtl?'الطابق غير صحيح.':'Bad floor.';}
        if ($type==='building') $parent=null;
        if ($err) flash('error', implode(' ',$err));
        else { $pdo->prepare("INSERT INTO item_locations (location_type,parent_id,name,name_en,is_active,created_at) VALUES (?,?,?,?,1,NOW())")->execute([$type,$parent,$ar?:null,$en?:null]); flash('success',$rtl?'تمت الإضافة بنجاح.':'Added successfully.'); }
        header('Location: '.$redir); exit;
    }
}

/* ═══ بيانات ═══ */
$tab=$_GET['tab']??'manage';
$sel_b=(int)($_GET['b']??0); $sel_f=(int)($_GET['f']??0); $sel_r=(int)($_GET['r']??0);
$buildings=$pdo->query("SELECT id,name,name_en FROM item_locations WHERE location_type='building' AND is_active=1 ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
$floors=[]; if($sel_b){$s=$pdo->prepare("SELECT id,name,name_en FROM item_locations WHERE location_type='floor' AND is_active=1 AND parent_id=? ORDER BY name");$s->execute([$sel_b]);$floors=$s->fetchAll(PDO::FETCH_ASSOC);}
$rooms=[]; if($sel_f){$s=$pdo->prepare("SELECT id,name,name_en FROM item_locations WHERE location_type='room' AND is_active=1 AND parent_id=? ORDER BY name");$s->execute([$sel_f]);$rooms=$s->fetchAll(PDO::FETCH_ASSOC);}

$results=[]; $level='buildings';
if ($sel_r) {
    $s=$pdo->prepare("SELECT r.*, f.name f_name, b.name b_name, d.name dept_name, (SELECT COUNT(*) FROM assets WHERE location_id=r.id) assets_cnt
        FROM item_locations r LEFT JOIN item_locations f ON f.id=r.parent_id LEFT JOIN item_locations b ON b.id=f.parent_id LEFT JOIN departments d ON d.id=r.dept_id WHERE r.id=?");
    $s->execute([$sel_r]); $results=$s->fetchAll(PDO::FETCH_ASSOC); $level='room';
} elseif ($sel_f) {
    $s=$pdo->prepare("SELECT r.*, f.name f_name, b.name b_name, d.name dept_name, (SELECT COUNT(*) FROM assets WHERE location_id=r.id) assets_cnt
        FROM item_locations r LEFT JOIN item_locations f ON f.id=r.parent_id LEFT JOIN item_locations b ON b.id=f.parent_id LEFT JOIN departments d ON d.id=r.dept_id
        WHERE r.location_type='room' AND r.is_active=1 AND r.parent_id=? ORDER BY r.name");
    $s->execute([$sel_f]); $results=$s->fetchAll(PDO::FETCH_ASSOC); $level='rooms';
} elseif ($sel_b) {
    $s=$pdo->prepare("SELECT f.*, b.name b_name, (SELECT COUNT(*) FROM item_locations WHERE parent_id=f.id AND location_type='room' AND is_active=1) rooms_cnt
        FROM item_locations f LEFT JOIN item_locations b ON b.id=f.parent_id WHERE f.location_type='floor' AND f.is_active=1 AND f.parent_id=? ORDER BY f.name");
    $s->execute([$sel_b]); $results=$s->fetchAll(PDO::FETCH_ASSOC); $level='floors';
} else {
    $s=$pdo->query("SELECT b.*, (SELECT COUNT(*) FROM item_locations WHERE parent_id=b.id AND location_type='floor' AND is_active=1) floors_cnt,
        (SELECT COUNT(*) FROM item_locations r JOIN item_locations f ON f.id=r.parent_id WHERE f.parent_id=b.id AND r.location_type='room' AND r.is_active=1) rooms_cnt
        FROM item_locations b WHERE b.location_type='building' AND b.is_active=1 ORDER BY b.name");
    $results=$s->fetchAll(PDO::FETCH_ASSOC); $level='buildings';
}
$all_floors=$pdo->query("SELECT f.id,f.name,f.name_en,f.parent_id,b.name b_name FROM item_locations f JOIN item_locations b ON b.id=f.parent_id WHERE f.location_type='floor' AND f.is_active=1 ORDER BY b.name,f.name")->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="<?= e(lang_attr()) ?>" dir="<?= e(lang_dir()) ?>">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1.0">
<title><?= $rtl?'إدارة المواقع':'Locations Manager' ?></title>
<link href="https://fonts.googleapis.com/css2?family=Tajawal:wght@400;500;700;800;900&family=Inter:wght@500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
<link rel="stylesheet" href="<?= BASE_URL ?>/css/app.css">
<style>
body,button,input,select{font-family:'Tajawal',sans-serif}
.tw{max-width:1400px;margin:0 auto;padding:18px}
.th{background:linear-gradient(135deg,#0f766e,#0891b2 55%,#06b6d4);color:#fff;border-radius:22px;padding:24px 28px;margin-bottom:20px;display:flex;align-items:center;gap:18px;flex-wrap:wrap}
.th .ic{width:70px;height:70px;border-radius:16px;background:rgba(255,255,255,.18);display:flex;align-items:center;justify-content:center;font-size:30px}
.th h1{margin:0;font-size:24px;font-weight:900}
.tabs{display:flex;gap:4px;background:#f1f5f9;padding:5px;border-radius:14px;width:fit-content;margin-bottom:16px}
.tab{padding:11px 22px;border-radius:10px;font-weight:800;font-size:13px;cursor:pointer;border:none;background:transparent;color:#64748b;display:inline-flex;gap:8px;align-items:center}
.tab.active{background:#fff;color:#0f766e;box-shadow:0 2px 8px rgba(15,23,42,.08)}
.panel{display:none}.panel.active{display:block}
.card{background:#fff;border:1.5px solid #e2e8f0;border-radius:18px;padding:20px;margin-bottom:16px}
.card h3{margin:0 0 16px;font-size:15px;font-weight:900;display:flex;gap:9px;align-items:center}
.card h3 i{color:#0891b2;background:#ecfeff;padding:8px;border-radius:9px;font-size:13px}
.fgrid{display:grid;grid-template-columns:repeat(3,1fr);gap:12px}
@media(max-width:900px){.fgrid{grid-template-columns:1fr}}
.fgrid label{font-size:11px;font-weight:800;color:#475569;display:block;margin-bottom:4px}
.fgrid select{width:100%;border:1.5px solid #e2e8f0;border-radius:10px;padding:11px 14px;font-size:13px;background:#fff}
table.t{width:100%;border-collapse:collapse;font-size:12.5px}
table.t th{background:#f8fafc;padding:10px 12px;text-align:right;font-size:11px;font-weight:900;color:#475569;border-bottom:1.5px solid #e2e8f0}
table.t td{padding:10px 12px;border-bottom:1px solid #f1f5f9;vertical-align:middle}
table.t tr:hover td{background:#f0fdfa}
.inp{border:1.5px solid #e2e8f0;border-radius:8px;padding:7px 10px;font-size:12.5px;width:100%}
.btn{border:none;border-radius:9px;padding:7px 13px;font-weight:800;font-size:12px;cursor:pointer;display:inline-flex;gap:6px;align-items:center}
.btn.save{background:#16a34a;color:#fff}
.btn.ai{background:linear-gradient(135deg,#7c3aed,#a855f7);color:#fff}
.btn.del{background:#fee2e2;color:#b91c1c}
.btn.eye{background:#ecfeff;color:#0e7490}
.btn.go{background:linear-gradient(135deg,#0891b2,#06b6d4);color:#fff;padding:12px 22px;font-size:13px}
.badge{display:inline-flex;gap:4px;align-items:center;padding:3px 10px;border-radius:99px;font-size:11px;font-weight:800}
.badge.bld{background:#dbeafe;color:#1e40af}.badge.flr{background:#fef3c7;color:#92400e}.badge.rmx{background:#dcfce7;color:#166534}.badge.dept{background:#ede9fe;color:#6d28d9}.badge.cnt{background:#f1f5f9;color:#475569}
.flash{background:#fff;border-radius:12px;padding:13px 18px;margin-bottom:14px;font-weight:800;font-size:13px;border-right:4px solid #16a34a;color:#065f46}
.flash.err{border-right-color:#dc2626;color:#991b1b}
.addgrid{display:grid;grid-template-columns:repeat(3,1fr);gap:12px}
@media(max-width:900px){.addgrid{grid-template-columns:1fr}}
.addgrid label{font-size:11px;font-weight:800;color:#475569;display:block;margin-bottom:4px}
.addgrid select,.addgrid input{width:100%;border:1.5px solid #e2e8f0;border-radius:10px;padding:11px 14px;font-size:13px}
.airow{display:flex;gap:6px;margin-top:6px}
</style>
</head>
<body class="app-layout">
<?php include BASE_PATH.'/includes/sidebar.php'; ?>
<div class="main-area">
<?php include BASE_PATH.'/includes/topbar.php'; ?>
<main class="page-content"><div class="tw">
<?php foreach (get_flash() as $fm): ?><div class="flash <?= $fm['type']==='error'?'err':'' ?>"><?= e($fm['message']) ?></div><?php endforeach; ?>

<section class="th">
<div class="ic"><i class="fa-solid fa-sitemap"></i></div>
<div style="flex:1;min-width:220px"><h1><?= $rtl?'إدارة المواقع':'Locations Manager' ?></h1>
<p style="margin:4px 0 0;font-size:13px;opacity:.9"><?= $rtl?'استعراض هرمي + تحرير + ترجمة AI + إضافة — التغييرات تسري على النظام بالكامل':'Hierarchical browse + edit + AI translate + add' ?></p></div>
<a class="btn" style="background:rgba(255,255,255,.18);color:#fff;padding:10px 18px" href="<?= BASE_URL ?>/inventory/locations/index.php"><i class="fa-solid fa-arrow-right"></i> <?= $rtl?'الداشبورد':'Hub' ?></a>
</section>

<div class="tabs">
<button class="tab <?= $tab==='manage'?'active':'' ?>" onclick="switchTab('manage')"><i class="fa-solid fa-sitemap"></i> <?= $rtl?'إدارة المواقع':'Manage' ?></button>
<button class="tab <?= $tab==='add'?'active':'' ?>" onclick="switchTab('add')"><i class="fa-solid fa-plus-circle"></i> <?= $rtl?'إضافة موقع':'Add' ?></button>
</div>

<!-- ═══ إدارة ═══ -->
<div class="panel <?= $tab==='manage'?'active':'' ?>" id="tab-manage">
<div class="card">
<h3><i class="fa-solid fa-filter"></i> <?= $rtl?'التصفية الهرمية':'Hierarchical Filter' ?></h3>
<form method="GET" class="fgrid">
<input type="hidden" name="tab" value="manage">

<!-- 1) المبنى -->
<div>
    <label><?= $rtl?'1) المبنى':'1) Building' ?></label>
    <select name="b" onchange="
        // 1. تفريغ الطابق (إذا كان اسمه f، عدله لاسم الحقل لديك)
        if(this.form.elements['f']) this.form.elements['f'].value=''; 
        // 2. تفريغ الغرفة (إذا كان اسمها r، عدله لاسم الحقل لديك)
        if(this.form.elements['r']) this.form.elements['r'].value=''; 
        // 3. إرسال النموذج بعد التفريغ
        this.form.submit();
    ">
        <option value=""><?= $rtl?'— كل المباني —':'— All —' ?></option>
        <?php foreach($buildings as $b): ?>
            <option value="<?= $b['id'] ?>" <?= $sel_b==$b['id']?'selected':'' ?>><?= e($b['name']?:$b['name_en']) ?></option>
        <?php endforeach; ?>
    </select>
</div>

<!-- 2) الطابق -->
<div>
    <label><?= $rtl?'2) الطابق':'2) Floor' ?></label>
    <select name="f" onchange="this.form.elements['r'].value=''; this.form.submit();" <?= $sel_b?'':'disabled' ?>>
        <option value=""><?= $rtl?'— كل الطوابق —':'— All —' ?></option>
        <?php foreach($floors as $f): ?>
            <option value="<?= $f['id'] ?>" <?= $sel_f==$f['id']?'selected':'' ?>><?= e($f['name']?:$f['name_en']) ?></option>
        <?php endforeach; ?>
    </select>
</div>

<!-- 3) الغرفة مع زر الإلغاء بجانبها -->
<div>
    <label><?= $rtl?'3) الغرفة':'3) Room' ?></label>
    <div style="display: flex; gap: 8px; align-items: center;">
        <select name="r" onchange="this.form.submit()" <?= $sel_f?'':'disabled' ?> style="flex: 1;">
            <option value=""><?= $rtl?'— كل الغرف —':'— All —' ?></option>
            <?php foreach($rooms as $r): ?>
                <option value="<?= $r['id'] ?>" <?= $sel_r==$r['id']?'selected':'' ?>><?= e($r['name']?:$r['name_en']) ?></option>
            <?php endforeach; ?>
        </select>
        <button type="button" class="btn" onclick="const f=this.form; f.querySelectorAll('select').forEach(s=>s.value=''); f.submit();" style="background-color: #64748b; color: white; padding: 10px 14px; border-radius: 8px; white-space: nowrap; font-size: 12px; cursor: pointer;" title="<?= $rtl ? 'إلغاء التخصيص' : 'Reset Selection' ?>">
            <i class="fa-solid fa-rotate-right"></i> <?= $rtl ? 'إلغاء' : 'Reset' ?>
        </button>
    </div>
</div>

</form>
</div>
</div>

<div class="card">
<h3><i class="fa-solid fa-table-list"></i> <?= $rtl?'النتائج':'Results' ?> <span style="font-size:11px;color:#94a3b8">(<?= count($results) ?>)</span></h3>
<div style="overflow-x:auto"><table class="t">
<thead><tr><th style="width:60px"><?= $rtl?'النوع':'Type' ?></th><th><?= $rtl?'الاسم العربي':'Arabic' ?></th><th><?= $rtl?'الاسم الإنجليزي':'English' ?></th><th><?= $rtl?'المسار/القسم':'Path/Dept' ?></th><th style="width:240px"><?= $rtl?'إجراءات':'Actions' ?></th></tr></thead>
<tbody>
<?php foreach($results as $row):
$id=(int)$row['id']; $type=$row['location_type']??($level==='buildings'?'building':($level==='floors'?'floor':'room'));
$badge=['building'=>'<span class="badge bld"><i class="fa-solid fa-building"></i> '.($rtl?'مبنى':'Bld').'</span>','floor'=>'<span class="badge flr"><i class="fa-solid fa-layer-group"></i> '.($rtl?'طابق':'Flr').'</span>','room'=>'<span class="badge rmx"><i class="fa-solid fa-door-open"></i> '.($rtl?'غرفة':'Room').'</span>'][$type];
$path='';
if($type==='room') $path=($row['b_name']??'').' / '.($row['f_name']??'').($row['dept_name']?' · <span class="badge dept">'.e($row['dept_name']).'</span>':'').' <span class="badge cnt"><i class="fa-solid fa-box"></i> '.(int)($row['assets_cnt']??0).'</span>';
elseif($type==='floor') $path=($row['b_name']??'').' · <span class="badge cnt">'.(int)($row['rooms_cnt']??0).' '.($rtl?'غرفة':'rm').'</span>';
else $path='<span class="badge cnt">'.(int)($row['floors_cnt']??0).' '.($rtl?'طابق':'fl').'</span> <span class="badge cnt">'.(int)($row['rooms_cnt']??0).' '.($rtl?'غرفة':'rm').'</span>';
$drill = $type==='building' ? '?tab=manage&b='.$id : ($type==='floor' ? '?tab=manage&b='.$sel_b.'&f='.$id : '?tab=manage&b='.$sel_b.'&f='.$sel_f.'&r='.$id);
?>
<tr>
<td><?= $badge ?></td>
<form method="POST" id="frm-<?= $id ?>">
<?= csrf_input() ?><input type="hidden" name="action" value="update"><input type="hidden" name="id" value="<?= $id ?>">
<?php foreach(['b','f','r'] as $k): ?><input type="hidden" name="keep[<?= $k ?>]" value="<?= e($_GET[$k]??'') ?>"><?php endforeach; ?><input type="hidden" name="keep[tab]" value="manage">
<!-- تم عكس الـ name ليتوافق مع قاعدة البيانات المقلوبة مع إبقاء العرض البصري صحيحاً -->
<!-- تم إضافة form= لضمان ارتباط الحقل بالنموذج حتى داخل الجداول -->
<td><input class="inp" form="frm-<?= $id ?>" name="name_en" value="<?= e($row['name_en']??'') ?>" placeholder="<?= $rtl?'عربي…':'AR…' ?>"></td>
<td><input class="inp" form="frm-<?= $id ?>" name="name_ar" value="<?= e($row['name']??'') ?>" placeholder="EN…"></td>
</form>
<td><?= $path ?></td>
<td style="white-space:nowrap">
<button class="btn save" form="frm-<?= $id ?>" type="submit"><i class="fa-solid fa-floppy-disk"></i> <?= $rtl?'حفظ':'Save' ?></button>
<button class="btn ai" type="button" onclick="aiTr(<?= $id ?>,'ar')" <?= $ai_ready?'':'disabled' ?> title="EN→AR">ع</button>
<button class="btn ai" type="button" onclick="aiTr(<?= $id ?>,'en')" <?= $ai_ready?'':'disabled' ?> title="AR→EN">EN</button>
<a class="btn eye" href="<?= $drill ?>"><i class="fa-solid fa-eye"></i></a>
<form method="POST" style="display:inline" onsubmit="return confirm('<?= $rtl?'حذف نهائي؟':'Delete?' ?>')"><?= csrf_input() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= $id ?>"><?php foreach(['b','f','r'] as $k): ?><input type="hidden" name="keep[<?= $k ?>]" value="<?= e($_GET[$k]??'') ?>"><?php endforeach; ?><input type="hidden" name="keep[tab]" value="manage"><button class="btn del" type="submit"><i class="fa-solid fa-trash"></i></button></form>
</td>
</tr>
<?php endforeach; ?>
</tbody></table></div>
</div>
</div>

<!-- ═══ إضافة ═══ -->
<div class="panel <?= $tab==='add'?'active':'' ?>" id="tab-add">
<div class="card">
<h3><i class="fa-solid fa-plus-circle"></i> <?= $rtl?'إضافة موقع جديد (بربط سليم)':'Add Location' ?></h3>
<form method="POST">
<?= csrf_input() ?><input type="hidden" name="action" value="add"><input type="hidden" name="keep[tab]" value="add">
<div class="addgrid">
<div><label><?= $rtl?'النوع':'Type' ?> *</label>
<select name="loc_type" id="addType" required onchange="updParent()"><option value="building"><?= $rtl?'مبنى':'Building' ?></option><option value="floor"><?= $rtl?'طابق':'Floor' ?></option><option value="room"><?= $rtl?'غرفة':'Room' ?></option></select></div>
<div id="addParentBox" style="display:none"><label id="addParentLbl"><?= $rtl?'الأب':'Parent' ?> *</label><select name="parent_id" id="addParent"><option value="">—</option></select></div>
<div></div>
<!-- تم عكس الـ name فقط لضمان توافق الإضافة الجديدة مع قاعدة البيانات -->
<div><label><?= $rtl?'الاسم العربي':'Arabic' ?></label><input type="text" name="name_en" id="addAr"></div>
<div><label><?= $rtl?'الاسم الإنجليزي':'English' ?></label><input type="text" name="name_ar" id="addEn"></div>
<div><label><?= $rtl?'ترجمة AI':'AI Translate' ?></label>
<div class="airow">
<button class="btn ai" type="button" onclick="aiTrAdd('ar')" <?= $ai_ready?'':'disabled' ?>>→ عربي</button>
<button class="btn ai" type="button" onclick="aiTrAdd('en')" <?= $ai_ready?'':'disabled' ?>>→ EN</button>
</div></div>
</div>
<div style="margin-top:18px;display:flex;gap:10px">
<button class="btn go" type="submit"><i class="fa-solid fa-plus-circle"></i> <?= $rtl?'إضافة':'Add' ?></button>
<button class="btn" type="button" style="background:#f1f5f9;color:#475569;padding:12px 22px" onclick="switchTab('manage')"><?= $rtl?'العودة':'Back' ?></button>
</div>
</form>
</div>
</div>

</div></main></div>
<script>
const AI=<?= $ai_ready?'true':'false' ?>;
const B=<?= json_encode(array_map(fn($x)=>['id'=>(int)$x['id'],'n'=>$x['name']?:$x['name_en']],$buildings),JSON_UNESCAPED_UNICODE) ?>;
const F=<?= json_encode(array_map(fn($x)=>['id'=>(int)$x['id'],'p'=>(int)$x['parent_id'],'n'=>($x['b_name']?$x['b_name'].' / ':'').($x['name']?:$x['name_en'])],$all_floors),JSON_UNESCAPED_UNICODE) ?>;
function switchTab(t){document.querySelectorAll('.tab').forEach(e=>e.classList.remove('active'));document.querySelectorAll('.panel').forEach(e=>e.classList.remove('active'));document.querySelector('.tab[onclick*="\''+t+'\'"]').classList.add('active');document.getElementById('tab-'+t).classList.add('active');}
function updParent(){const t=document.getElementById('addType').value;const box=document.getElementById('addParentBox');const sel=document.getElementById('addParent');const lbl=document.getElementById('addParentLbl');sel.innerHTML='<option value="">—</option>';
if(t==='building'){box.style.display='none';sel.required=false;return;}
box.style.display='';sel.required=true;
if(t==='floor'){lbl.textContent='<?= $rtl?'المبنى الأب':'Parent building' ?>';B.forEach(b=>{const o=document.createElement('option');o.value=b.id;o.textContent=b.n;sel.appendChild(o);});}
else{lbl.textContent='<?= $rtl?'الطابق الأب':'Parent floor' ?>';F.forEach(f=>{const o=document.createElement('option');o.value=f.id;o.textContent=f.n;sel.appendChild(o);});}}
async function callAI(text,to){if(!AI)return null;const fd=new FormData();fd.append('action','translate');fd.append('text',text);fd.append('to',to);
const r=await fetch('<?= BASE_URL ?>/inventory/locations/tree.php',{method:'POST',body:fd});const d=await r.json();return d.ok?d.translated:null;}
async function aiTr(id,to){
    // استهداف الصف الذي يحتوي على الزر مباشرة لتجاوز مشاكل الجداول
    const btn = event.currentTarget;
    const tr = btn.closest('tr');
    
    // البحث عن الحقول المقلوبة داخل هذا الصف تحديداً
    const src = tr.querySelector(to==='ar'?'input[name=name_ar]':'input[name=name_en]');
    const dst = tr.querySelector(to==='ar'?'input[name=name_en]':'input[name=name_ar]');
    
    const t=src.value.trim();
    if(!t){alert('<?= $rtl?'الحقل المصدر فارغ':'Source empty' ?>');return;}
    
    const o=btn.innerHTML;
    btn.innerHTML='…';btn.disabled=true;
    const trText=await callAI(t,to);
    btn.innerHTML=o;btn.disabled=false;
    if(trText)dst.value=trText;
}
async function aiTrAdd(to){const src=document.getElementById(to==='ar'?'addEn':'addAr');const dst=document.getElementById(to==='ar'?'addAr':'addEn');const t=src.value.trim();if(!t){alert('<?= $rtl?'الحقل المصدر فارغ':'Source empty' ?>');return;}const tr=await callAI(t,to);if(tr)dst.value=tr;}
updParent();
function resetLocationDropdowns(btn) {
    // تحديد الحاوية أو البطاقة التي يتواجد فيها الزر والقوائم
    const card = btn.closest('.card') || btn.closest('div'); // قم بتعديل .card إلى اسم الكلاس (class) الخاص بالبطاقة لديك إن وجد
    
    // البحث عن كل القوائم المنسدلة داخل هذه البطاقة
    const selects = card.querySelectorAll('select');
    
    selects.forEach(select => {
        select.value = ''; // تصفير القيمة
        // هذا السطر مهم جداً لتحديث الفلاتر المرتبطة ببعضها وإرجاعها للصفر
        select.dispatchEvent(new Event('change')); 
    });
}
</script>
</body>
</html>