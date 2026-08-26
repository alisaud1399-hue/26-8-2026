<?php
/**
 * inventory/settings.php — مركز إعدادات الجرد
 * 6 فئات × 20 إعداد — كل تغيير يحفظ مباشرة عبر AJAX
 * يتطلب صلاحية admin
 */
require_once dirname(__DIR__) . '/config.php';
require_once __DIR__ . '/settings_lib.php';
page_guard('inventory');
if (!is_admin()) abort(403);

$rtl = is_rtl();
$defs = inv_settings_definitions();
$cats = inv_settings_categories();
$active_cat = $_GET['cat'] ?? 'access';
if (!isset($cats[$active_cat])) $active_cat = 'access';
$csrf = csrf_token();
?>
<!DOCTYPE html>
<html lang="<?= e(lang_attr()) ?>" dir="<?= e(lang_dir()) ?>">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1.0">
<title><?= $rtl?'إعدادات الجرد':'Inventory Settings' ?> — <?= e(get_setting('hospital_name','PMSH')) ?></title>
<link href="https://fonts.googleapis.com/css2?family=Tajawal:wght@400;500;700;800;900&family=Inter:wght@500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
<link rel="stylesheet" href="<?= BASE_URL ?>/css/app.css">
<style>
body,button,input,select,textarea{font-family:'Tajawal',sans-serif}
.is-wrap{max-width:1400px;margin:0 auto;padding:18px}
.is-hero{background:linear-gradient(135deg,#0f172a,#1e293b 55%,#334155);color:#fff;border-radius:22px;padding:24px 28px;margin-bottom:20px;display:flex;align-items:center;gap:18px;flex-wrap:wrap}
.is-hero .ic{width:70px;height:70px;border-radius:16px;background:rgba(255,255,255,.18);display:flex;align-items:center;justify-content:center;font-size:30px;flex-shrink:0}
.is-hero h1{margin:0;font-size:24px;font-weight:900}.is-hero p{margin:4px 0 0;font-size:13px;opacity:.9}
.is-tabs{display:flex;gap:8px;flex-wrap:wrap;margin-bottom:20px}
.is-tab{padding:12px 18px;border-radius:12px;background:#fff;border:1.5px solid #e2e8f0;cursor:pointer;font-weight:800;font-size:12.5px;display:inline-flex;gap:8px;align-items:center;transition:.2s}
.is-tab:hover{transform:translateY(-1px);box-shadow:0 4px 10px rgba(0,0,0,.06)}
.is-tab.active{background:linear-gradient(135deg,#0f172a,#1e293b);color:#fff;border-color:#0f172a}
.is-tab i{color:inherit;opacity:.85}
.is-cat-card{background:#fff;border:1.5px solid #e2e8f0;border-radius:18px;padding:20px;margin-bottom:16px}
.is-cat-card h3{margin:0 0 4px;font-size:17px;font-weight:900;display:flex;gap:9px;align-items:center}
.is-cat-card .desc{color:#64748b;font-size:12.5px;margin-bottom:18px;font-weight:600}
.is-row{display:grid;grid-template-columns:1fr 2fr;gap:18px;padding:14px 0;border-bottom:1px solid #f1f5f9;align-items:center}
.is-row:last-child{border-bottom:none}
.is-row .lbl{font-size:13px;font-weight:800;color:#0f172a}
.is-row .desc-l{font-size:11.5px;color:#64748b;margin-top:3px;line-height:1.5}
.is-toggle{position:relative;width:54px;height:28px;background:#cbd5e1;border-radius:99px;cursor:pointer;transition:.25s;display:inline-block;flex-shrink:0}
.is-toggle::after{content:"";position:absolute;width:22px;height:22px;background:#fff;border-radius:50%;top:3px;right:3px;transition:.25s;box-shadow:0 2px 4px rgba(0,0,0,.2)}
.is-toggle.on{background:linear-gradient(135deg,#16a34a,#22c55e)}
.is-toggle.on::after{right:29px}
.is-toggle input{display:none}
.is-sel,.is-inp{border:1.5px solid #e2e8f0;border-radius:10px;padding:9px 14px;font-size:13px;background:#fff;width:100%;max-width:280px;font-family:inherit}
.is-inp{width:120px}
.is-range-row{display:flex;gap:10px;align-items:center;flex-wrap:wrap}
.is-range-row .val{font-weight:900;color:#0f766e;min-width:50px;text-align:center;background:#f0fdf4;padding:6px 12px;border-radius:8px}
.is-actions{display:flex;gap:10px;flex-wrap:wrap;margin-top:20px;padding-top:18px;border-top:1.5px solid #f1f5f9}
.is-btn{border:none;border-radius:11px;padding:11px 20px;font-weight:900;font-size:13px;cursor:pointer;display:inline-flex;gap:8px;align-items:center;transition:.2s}
.is-btn.save{background:linear-gradient(135deg,#16a34a,#22c55e);color:#fff;box-shadow:0 4px 14px rgba(22,163,74,.3)}
.is-btn.save:hover{transform:translateY(-1px);box-shadow:0 6px 18px rgba(22,163,74,.4)}
.is-btn.save:disabled{background:#cbd5e1;box-shadow:none;cursor:not-allowed;transform:none}
.is-btn.reset{background:#f1f5f9;color:#475569}
.is-btn.reset:hover{background:#e2e8f0}
.is-btn.busy{background:#f59e0b;cursor:wait}
.is-flash{position:fixed;top:20px;left:50%;transform:translateX(-50%);background:#0f172a;color:#fff;padding:14px 22px;border-radius:12px;font-weight:800;font-size:13px;z-index:10000;box-shadow:0 8px 24px rgba(0,0,0,.3);display:none;animation:slideDown .3s}
.is-flash.show{display:flex;align-items:center;gap:10px}
.is-flash.ok{background:linear-gradient(135deg,#16a34a,#22c55e)}
.is-flash.err{background:linear-gradient(135deg,#dc2626,#ef4444)}
@keyframes slideDown{from{transform:translate(-50%,-100%);opacity:0}to{transform:translate(-50%,0);opacity:1}}
.is-dep{font-size:10.5px;background:#fef3c7;color:#92400e;padding:3px 8px;border-radius:6px;font-weight:800;margin-right:6px;display:inline-block}
.is-dep.all{background:#dbeafe;color:#1e40af}
.is-dep.api{background:#f3e8ff;color:#6b21a8}
.is-dep.lock_ui{background:#fce7f3;color:#9d174d}
.is-dep.scan{background:#dcfce7;color:#166534}
.is-summary{display:grid;grid-template-columns:repeat(3,1fr);gap:12px;margin-bottom:20px}
@media(max-width:900px){.is-summary,.is-row{grid-template-columns:1fr}}
.is-sum{background:#fff;border:1.5px solid #e2e8f0;border-radius:14px;padding:14px 18px;display:flex;align-items:center;gap:12px}
.is-sum .ic{width:42px;height:42px;border-radius:10px;display:flex;align-items:center;justify-content:center;font-size:18px;flex-shrink:0}
.is-sum .v{font-size:20px;font-weight:900;line-height:1}.is-sum .l{font-size:11.5px;color:#64748b;margin-top:4px;font-weight:700}
.is-cat-panel{display:none}.is-cat-panel.active{display:block}
</style>
</head>
<body class="app-layout">
<?php include BASE_PATH . '/includes/sidebar.php'; ?>
<div class="main-area">
<?php include BASE_PATH . '/includes/topbar.php'; ?>
<main class="page-content"><div class="is-wrap">

<section class="is-hero">
<div class="ic"><i class="fa-solid fa-sliders"></i></div>
<div style="flex:1;min-width:240px">
<h1><?= $rtl?'إعدادات الجرد':'Inventory Settings' ?></h1>
<p><?= $rtl?'المرجع الرسمي لجميع إعدادات الجرد — التغييرات تسري مباشرة على scan.php و room_lock.php':'Canonical reference for all audit settings — changes apply live' ?></p>
</div>
<a class="is-btn reset" href="<?= BASE_URL ?>/inventory/index.php"><i class="fa-solid fa-arrow-right"></i> <?= $rtl?'الجرد':'Audit' ?></a>
</section>

<div class="is-summary">
<?php
$cnt_active = 0; $cnt_total = 0;
foreach ($defs as $k => $d) { $cnt_total++; if (inv_get($k) === '1' || inv_get_typed($k) === true) $cnt_active++; }
$cnt_cats = count($cats);
?>
<div class="is-sum"><div class="ic" style="background:#dbeafe;color:#1e40af"><i class="fa-solid fa-list-check"></i></div>
<div><div class="v"><?= $cnt_total ?></div><div class="l"><?= $rtl?'إعداد':'Settings' ?></div></div></div>
<div class="is-sum"><div class="ic" style="background:#dcfce7;color:#16a34a"><i class="fa-solid fa-circle-check"></i></div>
<div><div class="v"><?= $cnt_active ?></div><div class="l"><?= $rtl?'مفعّل':'Enabled' ?></div></div></div>
<div class="is-sum"><div class="ic" style="background:#f3e8ff;color:#7c3aed"><i class="fa-solid fa-layer-group"></i></div>
<div><div class="v"><?= $cnt_cats ?></div><div class="l"><?= $rtl?'فئة':'Categories' ?></div></div></div>
</div>

<div class="is-tabs">
<?php foreach ($cats as $key => $cat): ?>
<button class="is-tab <?= $active_cat===$key?'active':'' ?>" data-cat="<?= e($key) ?>">
<i class="fa-solid <?= e($cat['icon']) ?>"></i>
<?= $cat[$rtl?'ar':'en'] ?>
</button>
<?php endforeach; ?>
</div>

<?php foreach ($cats as $key => $cat): ?>
<div class="is-cat-panel <?= $active_cat===$key?'active':'' ?>" data-panel="<?= e($key) ?>">
<div class="is-cat-card" style="border-color:<?= e($cat['color']) ?>40">
<h3 style="color:<?= e($cat['color']) ?>"><i class="fa-solid <?= e($cat['icon']) ?>"></i> <?= $cat[$rtl?'ar':'en'] ?></h3>
<div class="desc"><?= $key==='access' ? ($rtl?'تحكم في كيفية وصول الموظفين وبدء الجرد':'How staff access rooms and start audits') :
    ($key==='lifecycle' ? ($rtl?'قواعد دورة حياة القفل (تعليق/استلام/إقفال)':'Lock lifecycle rules (suspend/takeover/complete)') :
    ($key==='data' ? ($rtl?'جودة البيانات ومتطلبات الأجهزة':'Data quality and device requirements') :
    ($key==='alerts' ? ($rtl?'التنبيهات والمؤثرات':'Alerts and feedback') :
    ($key==='limits' ? ($rtl?'الحدود والقيود':'Limits and caps') :
    ($rtl?'قواعد العمل الصارمة — توثيق + ربط + تكويد':'Strict workflow rules — doc + link + code'))))) ?></div>

<?php foreach ($defs as $k => $d):
if ($d['category'] !== $key) continue;
$cur = inv_get($k);
$is_bool = $d['type'] === 'bool';
$on = ($cur === '1' || $cur === 'true');
?>
<div class="is-row">
<div>
<div class="lbl"><?= inv_label($d, $rtl) ?>
<span class="is-dep <?= e($d['scope']) ?>"><?= e($d['scope']) ?></span>
</div>
<div class="desc-l"><?= inv_desc($d, $rtl) ?></div>
</div>
<div>
<?php if ($is_bool): ?>
<span class="is-toggle <?= $on?'on':'' ?>" data-key="<?= e($k) ?>" onclick="toggleIs(this)">
<input type="checkbox" <?= $on?'checked':'' ?>>
</span>
<?php elseif ($d['type'] === 'select'): ?>
<select class="is-sel" data-key="<?= e($k) ?>">
<?php foreach ($d['options'] as $val => $lbl): ?>
<option value="<?= e($val) ?>" <?= $cur===$val?'selected':'' ?>><?= $lbl[$rtl?'ar':'en'] ?></option>
<?php endforeach; ?>
</select>
<?php elseif ($d['type'] === 'int'): ?>
<div class="is-range-row">
<input type="range" class="is-range" data-key="<?= e($k) ?>" data-default="<?= e($d['default']) ?>"
    min="<?= (int)($d['min'] ?? 0) ?>" max="<?= (int)($d['max'] ?? 100) ?>" step="<?= (int)($d['step'] ?? 1) ?>" value="<?= e($cur) ?>">
<input type="number" class="is-inp" data-key="<?= e($k) ?>" min="<?= (int)($d['min'] ?? 0) ?>" max="<?= (int)($d['max'] ?? 9999) ?>" value="<?= e($cur) ?>">
<span class="val" data-display="<?= e($k) ?>"><?= e($cur) ?></span>
</div>
<?php elseif ($d['type'] === 'json'): ?>
<input type="text" class="is-inp" data-key="<?= e($k) ?>" value="<?= e($cur) ?>" style="width:100%;max-width:420px" placeholder="[1, 2, 3]">
<?php elseif ($d['type'] === 'reasons_list'): ?>
<div class="reasons-list-editor" data-key="<?= e($k) ?>">
<?php
    $_reasons = json_decode($cur, true) ?: [];
    if (empty($_reasons) && $cur !== '') {
        // تحويل من الصيغة القديمة عربي|إنجليزي
        foreach (explode("\n", $cur) as $_line) {
            $_line = trim($_line);
            if ($_line === '') continue;
            $_parts = explode('|', $_line, 2);
            $_reasons[] = ['ar' => trim($_parts[0] ?? ''), 'en' => trim($_parts[1] ?? $_parts[0] ?? '')];
        }
    }
?>
<div class="rl-items">
<?php foreach ($_reasons as $_ri => $_rr): ?>
<div class="rl-row" style="display:flex;gap:8px;margin-bottom:8px;align-items:center;">
<input type="text" class="rl-ar is-inp" value="<?= e($_rr['ar'] ?? '') ?>" style="flex:1" placeholder="عربي">
<input type="text" class="rl-en is-inp" value="<?= e($_rr['en'] ?? '') ?>" style="flex:1;direction:ltr" placeholder="English">
<button type="button" class="rl-del" onclick="this.closest('.rl-row').remove()" style="background:#fef2f2;border:1px solid #fecaca;color:#dc2626;width:32px;height:32px;border-radius:8px;cursor:pointer;font-size:14px;flex-shrink:0"><i class="fa-solid fa-trash"></i></button>
</div>
<?php endforeach; ?>
</div>
<button type="button" class="rl-add" onclick="addReasonRow(this)" style="background:#f0fdf4;border:1.5px dashed #86efac;color:#16a34a;padding:8px 14px;border-radius:8px;font-weight:800;font-size:12px;cursor:pointer;font-family:inherit;margin-top:4px"><i class="fa-solid fa-plus"></i> <?= $rtl ? 'إضافة سبب' : 'Add reason' ?></button>
</div>
<?php else: ?>
<input type="text" class="is-inp" data-key="<?= e($k) ?>" value="<?= e($cur) ?>" style="width:100%;max-width:420px">
<?php endif; ?>
</div>
</div>
<?php endforeach; ?>

<div class="is-actions">
<button class="is-btn save" data-save="<?= e($key) ?>"><i class="fa-solid fa-floppy-disk"></i> <?= $rtl?'حفظ هذه الفئة':'Save category' ?></button>
<button class="is-btn reset" data-reset="<?= e($key) ?>"><i class="fa-solid fa-rotate-left"></i> <?= $rtl?'إعادة الافتراضي':'Reset defaults' ?></button>
</div>
</div>
</div>
<?php endforeach; ?>

<div class="is-flash" id="isFlash"></div>

</div></main>
</div>
<script>
const CSRF = <?= json_encode($csrf) ?>;
const BASE = <?= json_encode(BASE_URL) ?>;
const RTL = <?= $rtl?'true':'false' ?>;

document.querySelectorAll('.is-tab').forEach(b => b.addEventListener('click', () => {
    const c = b.dataset.cat;
    history.replaceState(null, '', '?cat=' + c);
    document.querySelectorAll('.is-tab').forEach(x => x.classList.toggle('active', x === b));
    document.querySelectorAll('.is-cat-panel').forEach(p => p.classList.toggle('active', p.dataset.panel === c));
}));

/* Toggle — يعمل مباشرة بلا سلوك label افتراضي */
function toggleIs(el){
    const on = !el.classList.contains('on');
    el.classList.toggle('on', on);
    const i = el.querySelector('input');
    i.checked = on;
}

/* Range <-> Number sync */
document.querySelectorAll('.is-range').forEach(r => r.addEventListener('input', () => {
    const k = r.dataset.key;
    r.parentElement.querySelector('.is-inp').value = r.value;
    r.parentElement.querySelector('.val').textContent = r.value;
}));
document.querySelectorAll('.is-inp').forEach(i => {
    if (i.type !== 'number') return;
    i.addEventListener('input', () => {
        const r = i.parentElement.querySelector('.is-range');
        if (r) { r.value = i.value; i.parentElement.querySelector('.val').textContent = i.value; }
    });
});

function flash(msg, ok) {
    const f = document.getElementById('isFlash');
    f.className = 'is-flash show ' + (ok ? 'ok' : 'err');
    f.innerHTML = '<i class="fa-solid ' + (ok ? 'fa-circle-check' : 'fa-triangle-exclamation') + '"></i> ' + msg;
    setTimeout(() => f.classList.remove('show'), 3000);
}

function addReasonRow(btn) {
    const container = btn.closest('.reasons-list-editor').querySelector('.rl-items');
    const row = document.createElement('div');
    row.className = 'rl-row';
    row.style.cssText = 'display:flex;gap:8px;margin-bottom:8px;align-items:center;';
    row.innerHTML = '<input type="text" class="rl-ar is-inp" value="" style="flex:1" placeholder="عربي"><input type="text" class="rl-en is-inp" value="" style="flex:1;direction:ltr" placeholder="English"><button type="button" class="rl-del" onclick="this.closest(\'.rl-row\').remove()" style="background:#fef2f2;border:1px solid #fecaca;color:#dc2626;width:32px;height:32px;border-radius:8px;cursor:pointer;font-size:14px;flex-shrink:0"><i class="fa-solid fa-trash"></i></button>';
    container.appendChild(row);
    row.querySelector('.rl-ar').focus();
}

async function saveCat(cat) {
    const panel = document.querySelector(`.is-cat-panel[data-panel="${cat}"]`);
    const btn = panel.querySelector(`[data-save="${cat}"]`);
    const values = {};
    panel.querySelectorAll('[data-key]').forEach(el => {
        const k = el.dataset.key;
        if (el.classList.contains('is-toggle')) values[k] = el.classList.contains('on') ? '1' : '0';
        else values[k] = el.value;
    });
    // حفظ القوائم الديناميكية (reasons_list)
    panel.querySelectorAll('.reasons-list-editor').forEach(editor => {
        const k = editor.dataset.key;
        const items = [];
        editor.querySelectorAll('.rl-row').forEach(row => {
            const ar = row.querySelector('.rl-ar')?.value.trim() || '';
            const en = row.querySelector('.rl-en')?.value.trim() || '';
            if (ar || en) items.push({ar, en: en || ar});
        });
        values[k] = JSON.stringify(items);
    });

    btn.classList.add('busy'); btn.disabled = true;
    const oldHtml = btn.innerHTML;
    btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> ' + (RTL?'جاري الحفظ...':'Saving...');

    try {
        const fd = new FormData();
        fd.append('csrf', CSRF);
        for (const [k, v] of Object.entries(values)) fd.append('values[' + k + ']', v);
        const r = await fetch(BASE + '/inventory/api/settings_save.php', { method: 'POST', body: fd });
        const j = await r.json();
        if (j.ok) {
            flash((RTL?'تم حفظ ':'Saved ') + j.saved + (RTL?' إعداد':' settings'), true);
        } else {
            flash((RTL?'خطأ: ':'Error: ') + (j.error || 'unknown') + (j.debug ? ' | ' + JSON.stringify(j.debug) : ''), false);
        }
    } catch (e) {
        flash((RTL?'فشل الاتصال':'Connection failed'), false);
    }
    btn.classList.remove('busy'); btn.disabled = false;
    btn.innerHTML = oldHtml;
}

document.querySelectorAll('[data-save]').forEach(b => b.addEventListener('click', () => saveCat(b.dataset.save)));
document.querySelectorAll('[data-reset]').forEach(b => b.addEventListener('click', () => {
    if (!confirm(RTL?'إعادة كل إعدادات هذه الفئة للافتراضي؟':'Reset all settings in this category to defaults?')) return;
    const panel = document.querySelector(`.is-cat-panel[data-panel="${b.dataset.reset}"]`);
    panel.querySelectorAll('[data-key]').forEach(el => {
        const k = el.dataset.key;
        if (el.classList.contains('is-toggle')) {
            const def = el.dataset.default || '0';
            el.classList.toggle('on', def === '1');
            el.querySelector('input').checked = def === '1';
        } else {
            const def = el.dataset.default || '';
            el.value = def;
            if (el.type === 'range') {
                const r = panel.querySelector('.is-range[data-key="' + k + '"]');
                if (r) { r.value = def; panel.querySelector('.val[data-display="' + k + '"]').textContent = def; }
            }
        }
    });
    saveCat(b.dataset.reset);
}));
</script>
</body>
</html>
