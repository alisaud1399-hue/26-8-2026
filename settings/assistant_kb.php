<?php
require_once dirname(__DIR__).'/config.php';
require_once dirname(__DIR__).'/includes/assistant_kb.php';
page_guard('settings.index');
$__u = current_user();
if (empty($__u['is_admin']) && !can('roles.permissions','manage')) {
    http_response_code(403);
    exit('⛈ هذه الصفحة متاحة لمدير النظام فقط.');
}
$rtl=is_rtl();

/* ═══ معالجة الإجراءات ═══ */
if($_SERVER['REQUEST_METHOD']==='POST'){
  $id=(int)($_POST['id']??0);
  $kw=trim($_POST['keywords']??''); $ans=trim($_POST['answer']??'');
  $cat=trim($_POST['category']??''); $act=isset($_POST['is_active'])?1:0;
  $so=(int)($_POST['sort_order']??0);
  if($kw!==''&&$ans!==''){
    if($id>0){ $st=$pdo->prepare("UPDATE assistant_knowledge SET keywords=?,answer=?,category=?,is_active=?,sort_order=? WHERE id=?");
      $st->execute([$kw,$ans,$cat,$act,$so,$id]); }
    else{ $st=$pdo->prepare("INSERT INTO assistant_knowledge(keywords,answer,category,is_active,sort_order)VALUES(?,?,?,?,?)");
      $st->execute([$kw,$ans,$cat,$act,$so]); }
  }
  header('Location: '.BASE_URL.'/settings/assistant_kb.php'); exit;
}
if(isset($_GET['del'])){ $pdo->prepare("DELETE FROM assistant_knowledge WHERE id=?")->execute([(int)$_GET['del']]); header('Location: '.BASE_URL.'/settings/assistant_kb.php'); exit; }
if(isset($_GET['toggle'])){ $pdo->prepare("UPDATE assistant_knowledge SET is_active=1-is_active WHERE id=?")->execute([(int)$_GET['toggle']]); header('Location: '.BASE_URL.'/settings/assistant_kb.php'); exit; }

$edit=null;
if(isset($_GET['edit'])){ $st=$pdo->prepare("SELECT * FROM assistant_knowledge WHERE id=?"); $st->execute([(int)$_GET['edit']]); $edit=$st->fetch(PDO::FETCH_ASSOC); }

$test=null;
if(isset($_GET['test'])&&trim($_GET['test'])!=='') $test=kb_match($pdo,trim($_GET['test']));

$rows=$pdo->query("SELECT * FROM assistant_knowledge ORDER BY sort_order,id")->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html><html lang="<?= e(lang_attr()) ?>" dir="<?= e(lang_dir()) ?>"><head>
<meta charset="UTF-8"><title>إدارة معرفة المساعد</title>
<link href="https://fonts.googleapis.com/css2?family=Tajawal:wght@400;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
<link rel="stylesheet" href="<?= BASE_URL ?>/css/app.css">
<style>
.kb-wrap{max-width:1100px;margin:0 auto;padding:20px}
.kb-card{background:#fff;border:1px solid #e2e8f0;border-radius:16px;padding:20px;margin-bottom:20px}
.kb-card h2{font-size:17px;font-weight:800;margin-bottom:14px;display:flex;gap:10px;align-items:center}
.kb-card h2 i{color:#4f46e5}
.kb-grid{display:grid;grid-template-columns:1fr 1fr;gap:12px}
.kb-grid .full{grid-column:1/-1}
.kb-lbl{font-size:12px;font-weight:800;color:#64748b;display:block;margin-bottom:4px}
.kb-in,.kb-ta{width:100%;border:1.5px solid #cbd5e1;border-radius:10px;padding:10px 12px;font-family:'Tajawal';font-size:13px}
.kb-ta{min-height:90px;resize:vertical}
.kb-btn{background:#4f46e5;color:#fff;border:none;border-radius:10px;padding:10px 22px;font-weight:800;cursor:pointer;font-family:'Tajawal'}
.kb-btn.sec{background:#e2e8f0;color:#0f172a}
table.kb{width:100%;border-collapse:collapse;font-size:12.5px}
table.kb th{background:#f8fafc;padding:9px;text-align:right;font-size:11px;font-weight:800;color:#475569;border-bottom:2px solid #e2e8f0}
table.kb td{padding:9px;border-bottom:1px solid #f1f5f9;vertical-align:top;text-align:right}
.kb-ans{max-width:340px;white-space:pre-line;color:#334155}
.kb-off{opacity:.5}
.kb-a{color:#4f46e5;font-weight:700;text-decoration:none;margin-left:8px}
.kb-a.del{color:#dc2626}
.kb-test{background:#eef2ff;border:1px solid #c7d2fe;border-radius:12px;padding:14px;margin-top:12px;white-space:pre-line;font-size:13px}
</style></head>
<body class="app-layout">
<?php include BASE_PATH.'/includes/sidebar.php'; ?>
<div class="main-area"><?php include BASE_PATH.'/includes/topbar.php'; ?>
<main class="page-content"><div class="kb-wrap">

<div class="kb-card">
  <h2><i class="fa-solid fa-plus"></i><?= $edit?'تعديل سؤال':'إضافة سؤال / جواب جديد' ?></h2>
  <form method="post">
    <input type="hidden" name="id" value="<?= (int)($edit['id']??0) ?>">
    <div class="kb-grid">
      <div><span class="kb-lbl">كلمات الاستدلال (افصل بفاصلة)</span>
        <input class="kb-in" name="keywords" required value="<?= e($edit['keywords']??'') ?>" placeholder="مثال: بلاغ, شكوى, عطل"></div>
      <div><span class="kb-lbl">التصنيف</span>
        <input class="kb-in" name="category" value="<?= e($edit['category']??'') ?>" placeholder="إرشاد / تعاريف / ..."></div>
      <div class="full"><span class="kb-lbl">الإجابة</span>
        <textarea class="kb-ta" name="answer" required placeholder="اكتب الإجابة... (يدعم أسطر جديدة)"><?= e($edit['answer']??'') ?></textarea></div>
      <div><span class="kb-lbl">الترتيب</span><input class="kb-in" type="number" name="sort_order" value="<?= (int)($edit['sort_order']??0) ?>"></div>
      <div style="align-self:end"><label><input type="checkbox" name="is_active" <?= ($edit['is_active']??1)?'checked':'' ?>> مفعّل</label></div>
    </div>
    <div style="margin-top:14px;display:flex;gap:10px">
      <button class="kb-btn" type="submit"><i class="fa-solid fa-floppy-disk"></i> حفظ</button>
      <?php if($edit): ?><a class="kb-btn sec" style="text-decoration:none" href="?">إلغاء التعديل</a><?php endif; ?>
    </div>
  </form>
  <form method="get" style="margin-top:16px;display:flex;gap:10px;align-items:center">
    <input class="kb-in" style="max-width:320px" name="test" placeholder="جرّب سؤالاً لاختبار المطابقة..." value="<?= e($_GET['test']??'') ?>">
    <button class="kb-btn sec" type="submit"><i class="fa-solid fa-flask"></i> اختبار</button>
  </form>
  <?php if(isset($_GET['test'])): ?>
    <div class="kb-test"><?= $test? e($test) : '⚠️ لا توجد إجابة مطابقة — أضف كلمات استدلال أوسع.' ?></div>
  <?php endif; ?>
</div>

<div class="kb-card">
  <h2><i class="fa-solid fa-database"></i> قاعدة المعرفة (<?= count($rows) ?>)</h2>
  <table class="kb"><thead><tr><th>كلمات الاستدلال</th><th>الإجابة</th><th>التصنيف</th><th>الحالة</th><th>إجراءات</th></tr></thead><tbody>
  <?php foreach($rows as $r): ?>
    <tr class="<?= $r['is_active']?'':'kb-off' ?>">
      <td><?= e($r['keywords']) ?></td>
      <td class="kb-ans"><?= e(mb_strimwidth($r['answer'],0,120,'...')) ?></td>
      <td><?= e($r['category']) ?></td>
      <td><?= $r['is_active']?'🟢':'⚪' ?></td>
      <td>
        <a class="kb-a" href="?edit=<?= $r['id'] ?>">تعديل</a>
        <a class="kb-a" href="?toggle=<?= $r['id'] ?>"><?= $r['is_active']?'تعطيل':'تفعيل' ?></a>
        <a class="kb-a del" href="?del=<?= $r['id'] ?>" onclick="return confirm('حذف؟')">حذف</a>
      </td>
    </tr>
  <?php endforeach; ?>
  </tbody></table>
</div>

</div></main></div></body></html>