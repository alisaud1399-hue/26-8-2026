<?php
/**
 * inventory/translate_assets.php — صفحة ترجمة أسماء الأجهزة الطبية
 * ترجمة batch من الإنجليزي للعربي عبر Google Translate
 * يتطلب صلاحية admin
 */
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/_utils.php';
page_guard('inventory');
if (!is_admin()) abort(403);

$rtl = is_rtl();
global $pdo;

$total_all = $pdo->query("SELECT COUNT(*) FROM assets WHERE id <= 2858 AND description IS NOT NULL AND description != ''")->fetchColumn();
$total_done = $pdo->query("SELECT COUNT(*) FROM assets WHERE id <= 2858 AND description_ar IS NOT NULL AND description_ar != ''")->fetchColumn();
?>
<!DOCTYPE html>
<html lang="<?= $rtl ? 'ar' : 'en' ?>" dir="<?= $rtl ? 'rtl' : 'ltr' ?>">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1.0">
<title><?= $rtl ? 'ترجمة أسماء الأجهزة' : 'Translate Asset Names' ?> — <?= e(get_setting('hospital_name','PMSH')) ?></title>
<link href="https://fonts.googleapis.com/css2?family=Tajawal:wght@400;500;700;800;900&family=Inter:wght@500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
<style>
*{margin:0;padding:0;box-sizing:border-box}
body{font-family:'Tajawal',sans-serif;background:#f1f5f9;color:#0f172a;min-height:100vh}
.wrap{max-width:1200px;margin:0 auto;padding:20px}

.hero{background:linear-gradient(135deg,#0f172a,#1e293b 55%,#334155);color:#fff;border-radius:22px;padding:28px;margin-bottom:24px;display:flex;align-items:center;gap:20px;flex-wrap:wrap}
.hero .ic{width:64px;height:64px;border-radius:16px;background:rgba(255,255,255,.18);display:grid;place-items:center;font-size:28px;flex-shrink:0}
.hero h1{font-size:22px;font-weight:900;margin:0}
.hero p{font-size:13px;opacity:.85;margin-top:4px}
.hero .back{margin-inline-start:auto;background:rgba(255,255,255,.15);border:none;color:#fff;width:42px;height:42px;border-radius:12px;font-size:18px;cursor:pointer;transition:.2s;text-decoration:none;display:grid;place-items:center}
.hero .back:hover{background:rgba(255,255,255,.25)}

.stats{display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:14px;margin-bottom:24px}
.stat{background:#fff;border:1.5px solid #e2e8f0;border-radius:16px;padding:18px;text-align:center;transition:.2s}
.stat:hover{transform:translateY(-2px);box-shadow:0 6px 16px rgba(0,0,0,.06)}
.stat .num{font-size:28px;font-weight:900;line-height:1}
.stat .lbl{font-size:12px;color:#64748b;margin-top:6px;font-weight:700}
.stat.total .num{color:#0f172a}
.stat.done .num{color:#16a34a}
.stat.remaining .num{color:#dc2626}
.stat.pct .num{color:#7c3aed}

.progress-bar{background:#e2e8f0;border-radius:99px;height:10px;margin-bottom:24px;overflow:hidden}
.progress-bar .fill{height:100%;border-radius:99px;background:linear-gradient(90deg,#16a34a,#22c55e);transition:width .5s ease}

.actions{display:flex;gap:10px;flex-wrap:wrap;margin-bottom:24px}
.btn{padding:12px 22px;border-radius:12px;border:none;font-family:inherit;font-weight:800;font-size:13px;cursor:pointer;display:inline-flex;align-items:center;gap:8px;transition:.2s}
.btn:hover{transform:translateY(-1px);box-shadow:0 4px 12px rgba(0,0,0,.12)}
.btn:disabled{opacity:.5;cursor:not-allowed;transform:none;box-shadow:none}
.btn-primary{background:linear-gradient(135deg,#2563eb,#1d4ed8);color:#fff}
.btn-success{background:linear-gradient(135deg,#16a34a,#15803d);color:#fff}
.btn-danger{background:linear-gradient(135deg,#dc2626,#b91c1c);color:#fff}
.btn-outline{background:#fff;border:1.5px solid #e2e8f0;color:#475569}
.btn-outline:hover{border-color:#cbd5e1;background:#f8fafc}
.btn-purple{background:linear-gradient(135deg,#7c3aed,#6d28d9);color:#fff}

.table-wrap{background:#fff;border:1.5px solid #e2e8f0;border-radius:18px;overflow:hidden;margin-bottom:24px}
.table-head{display:flex;align-items:center;gap:12px;padding:16px 20px;border-bottom:1.5px solid #e2e8f0;background:#f8fafc}
.table-head h3{font-size:15px;font-weight:900;flex:1}
.table-head .count{background:#e2e8f0;padding:4px 12px;border-radius:99px;font-size:12px;font-weight:800}
table{width:100%;border-collapse:collapse}
th{background:#f8fafc;padding:12px 16px;font-size:12px;font-weight:800;color:#64748b;text-align:left;border-bottom:1.5px solid #e2e8f0}
td{padding:12px 16px;border-bottom:1px solid #f1f5f9;font-size:13px;vertical-align:middle}
tr:hover td{background:#f8fafc}
.en{text-family:Inter,monospace;color:#475569;font-size:12.5px}
.ar{color:#0f172a;font-weight:700;font-size:13.5px}
.id-col{font-family:Inter,monospace;color:#94a3b8;font-size:11px}
.tag-col{font-family:Inter,monospace;color:#2563eb;font-size:11.5px;font-weight:700}
.status-badge{display:inline-flex;align-items:center;gap:4px;padding:3px 10px;border-radius:99px;font-size:11px;font-weight:800}
.status-badge.ok{background:#dcfce7;color:#166534}
.status-badge.fail{background:#fee2e2;color:#991b1b}
.status-badge.pending{background:#fef3c7;color:#92400e}
.checkbox{width:18px;height:18px;cursor:pointer;accent-color:#2563eb}

.toast{position:fixed;bottom:24px;left:50%;transform:translateX(-50%);background:#0f172a;color:#fff;padding:14px 24px;border-radius:14px;font-weight:800;font-size:13px;z-index:9999;opacity:0;transition:.3s;pointer-events:none;box-shadow:0 8px 24px rgba(0,0,0,.3)}
.toast.show{opacity:1}
.toast.ok{background:#16a34a}
.toast.err{background:#dc2626}

.spinner{display:inline-block;width:16px;height:16px;border:2.5px solid rgba(255,255,255,.3);border-top-color:#fff;border-radius:50%;animation:spin .6s linear infinite}
@keyframes spin{to{transform:rotate(360deg)}}

.empty{text-align:center;padding:60px 20px;color:#94a3b8}
.empty i{font-size:48px;margin-bottom:16px;display:block;opacity:.5}
.empty p{font-size:14px;font-weight:700}

@media(max-width:768px){
    .hero{padding:20px}.hero h1{font-size:18px}
    .stats{grid-template-columns:repeat(2,1fr)}
    table{font-size:12px}th,td{padding:8px 10px}
    .btn{padding:10px 16px;font-size:12px}
}
</style>
</head>
<body>
<div class="wrap">

<!-- Hero -->
<div class="hero">
    <div class="ic"><i class="fa-solid fa-language"></i></div>
    <div>
        <h1><?= $rtl ? 'ترجمة أسماء الأجهزة الطبية' : 'Translate Medical Device Names' ?></h1>
        <p><?= $rtl ? 'ترجمة وصف الأجهزة من الإنجليزي إلى العربي عبر Google Translate' : 'Translate device descriptions from English to Arabic via Google Translate' ?></p>
    </div>
    <a class="back" href="<?= BASE_URL ?>/inventory/index.php" title="<?= $rtl ? 'العودة' : 'Back' ?>"><i class="fa-solid fa-arrow-right"></i></a>
</div>

<!-- Stats -->
<div class="stats">
    <div class="stat total"><div class="num" id="sTotal"><?= number_format($total_all) ?></div><div class="lbl"><?= $rtl ? 'إجمالي الأجهزة' : 'Total Assets' ?></div></div>
    <div class="stat done"><div class="num" id="sDone"><?= number_format($total_done) ?></div><div class="lbl"><?= $rtl ? 'مترجم' : 'Translated' ?></div></div>
    <div class="stat remaining"><div class="num" id="sRemaining"><?= number_format($total_all - $total_done) ?></div><div class="lbl"><?= $rtl ? 'متبقي' : 'Remaining' ?></div></div>
    <div class="stat pct"><div class="num" id="sPct"><?= $total_all > 0 ? round(($total_done / $total_all) * 100) : 0 ?>%</div><div class="lbl"><?= $rtl ? 'النسبة' : 'Progress' ?></div></div>
</div>

<!-- Progress -->
<div class="progress-bar"><div class="fill" id="pBar" style="width:<?= $total_all > 0 ? round(($total_done / $total_all) * 100) : 0 ?>%"></div></div>

<!-- Actions -->
<div class="actions">
    <button class="btn btn-primary" id="btnTranslate" onclick="translateBatch()"><i class="fa-solid fa-wand-magic-sparkles"></i> <?= $rtl ? 'ترجمة الدفعة القادمة (50)' : 'Translate Next Batch (50)' ?></button>
    <button class="btn btn-success" id="btnSave" onclick="saveApproved()" disabled><i class="fa-solid fa-floppy-disk"></i> <?= $rtl ? 'حفظ المختارة' : 'Save Selected' ?></button>
    <button class="btn btn-danger" id="btnReject" onclick="rejectSelected()" disabled><i class="fa-solid fa-trash"></i> <?= $rtl ? 'حذف المختارة' : 'Delete Selected' ?></button>
    <button class="btn btn-outline" onclick="selectAll()"><i class="fa-solid fa-check-double"></i> <?= $rtl ? 'اختيار الكل' : 'Select All' ?></button>
    <button class="btn btn-purple" id="btnAutoAll" onclick="autoTranslateAll()"><i class="fa-solid fa-bolt"></i> <?= $rtl ? 'ترجمة الكل تلقائياً' : 'Auto-Translate All' ?></button>
</div>

<!-- Table -->
<div class="table-wrap">
    <div class="table-head">
        <h3><i class="fa-solid fa-table"></i> <?= $rtl ? 'نتائج الترجمة' : 'Translation Results' ?></h3>
        <span class="count" id="rowCount">0</span>
    </div>
    <div id="tableContainer">
        <div class="empty">
            <i class="fa-solid fa-language"></i>
            <p><?= $rtl ? 'اضغط "ترجمة الدفعة القادمة" للبدء' : 'Click "Translate Next Batch" to start' ?></p>
        </div>
    </div>
</div>

</div>

<div class="toast" id="toast"></div>

<script>
const IS_AR = <?= $rtl ? 'true' : 'false' ?>;
let rows = [];
let offset = 0;

function toast(t, ok) {
    const el = document.getElementById('toast');
    el.textContent = t;
    el.className = 'toast show' + (ok === true ? ' ok' : ok === false ? ' err' : '');
    clearTimeout(el._t);
    el._t = setTimeout(() => el.className = 'toast', 3000);
}

function updateStats(translatedCount, remaining) {
    const total = parseInt(document.getElementById('sTotal').textContent.replace(/,/g,''));
    document.getElementById('sDone').textContent = translatedCount.toLocaleString();
    document.getElementById('sRemaining').textContent = remaining.toLocaleString();
    const pct = total > 0 ? Math.round((translatedCount / total) * 100) : 0;
    document.getElementById('sPct').textContent = pct + '%';
    document.getElementById('pBar').style.width = pct + '%';
}

async function translateBatch() {
    const btn = document.getElementById('btnTranslate');
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner"></span> ' + (IS_AR ? 'جارٍ الترجمة...' : 'Translating...');
    try {
        const res = await fetch('api/translate_batch.php', {
            method: 'POST',
            headers: {'Content-Type':'application/json'},
            body: JSON.stringify({offset: offset, limit: 50})
        });
        const data = await res.json();
        if (!data.ok) { toast(data.error || 'Error', false); return; }
        if (data.translated.length === 0) {
            toast(IS_AR ? 'تم ترجمة كل الأجهزة!' : 'All assets translated!', true);
            btn.innerHTML = '<i class="fa-solid fa-check"></i> ' + (IS_AR ? 'مكتمل!' : 'Done!');
            return;
        }
        rows = rows.concat(data.translated);
        offset += data.translated.length;
        updateStats(data.translated_count, data.remaining);
        renderTable();
        const src = data.translated[0];
        toast(IS_AR ? `تم ترجمة ${data.translated.length} جهاز` : `${data.translated.length} devices translated`, true);
    } catch(e) {
        toast('Error: ' + e.message, false);
    } finally {
        btn.disabled = false;
        btn.innerHTML = '<i class="fa-solid fa-wand-magic-sparkles"></i> ' + (IS_AR ? 'ترجمة الدفعة القادمة (50)' : 'Translate Next Batch (50)');
    }
}

async function autoTranslateAll() {
    const btn = document.getElementById('btnAutoAll');
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner"></span> ' + (IS_AR ? 'جارٍ الترجمة...' : 'Translating...');
    let batch = 0;
    while (true) {
        try {
            const res = await fetch('api/translate_batch.php', {
                method: 'POST',
                headers: {'Content-Type':'application/json'},
                body: JSON.stringify({offset: offset, limit: 50})
            });
            const data = await res.json();
            if (!data.ok || data.translated.length === 0) break;
            rows = rows.concat(data.translated);
            offset += data.translated.length;
            batch++;
            updateStats(data.translated_count, data.remaining);
            renderTable();
            toast(IS_AR ? `الدفعة ${batch}: ${data.translated.length} جهاز (${data.remaining} متبقي)` : `Batch ${batch}: ${data.translated.length} devices (${data.remaining} remaining)`);
            await new Promise(r => setTimeout(r, 500));
        } catch(e) {
            toast('Error: ' + e.message, false);
            break;
        }
    }
    btn.innerHTML = '<i class="fa-solid fa-check"></i> ' + (IS_AR ? 'اكتمل!' : 'Complete!');
    toast(IS_AR ? 'تم ترجمة كل الأجهزة!' : 'All assets translated!', true);
}

function renderTable() {
    if (rows.length === 0) {
        document.getElementById('tableContainer').innerHTML = '<div class="empty"><i class="fa-solid fa-language"></i><p>' + (IS_AR ? 'اضغط "ترجمة الدفعة القادمة" للبدء' : 'Click "Translate Next Batch" to start') + '</p></div>';
        document.getElementById('rowCount').textContent = '0';
        return;
    }
    let h = '<table><thead><tr>';
    h += '<th><input type="checkbox" class="checkbox" id="chkAll" onchange="toggleAll(this)"></th>';
    h += '<th>ID</th><th>' + (IS_AR ? 'التاج' : 'Tag') + '</th><th>' + (IS_AR ? 'الإنجليزي' : 'English') + '</th><th>' + (IS_AR ? 'العربي' : 'Arabic') + '</th><th>' + (IS_AR ? 'الحالة' : 'Status') + '</th>';
    h += '</tr></thead><tbody>';
    rows.forEach((r, i) => {
        const ok = r.ar && r.ar !== r.en;
        h += '<tr>';
        h += '<td><input type="checkbox" class="checkbox row-chk" data-idx="' + i + '" onchange="updateButtons()"></td>';
        h += '<td class="id-col">' + r.id + '</td>';
        h += '<td class="tag-col">' + esc(r.tag_number) + '</td>';
        h += '<td class="en">' + esc(r.en) + '</td>';
        h += '<td class="ar" contenteditable="true" data-idx="' + i + '" onblur="editAr(this)">' + esc(r.ar) + '</td>';
        h += '<td><span class="status-badge ' + (ok ? 'ok' : 'fail') + '"><i class="fa-solid fa-' + (ok ? 'check' : 'xmark') + '"></i> ' + (ok ? (IS_AR ? 'جيد' : 'OK') : (IS_AR ? 'خاطئ' : 'Fail')) + '</span></td>';
        h += '</tr>';
    });
    h += '</tbody></table>';
    document.getElementById('tableContainer').innerHTML = h;
    document.getElementById('rowCount').textContent = rows.length;
    updateButtons();
}

function esc(s) { const d = document.createElement('div'); d.textContent = s; return d.innerHTML; }

function editAr(el) {
    const idx = parseInt(el.dataset.idx);
    rows[idx].ar = el.textContent.trim();
    const ok = rows[idx].ar && rows[idx].ar !== rows[idx].en;
    const badge = el.closest('tr').querySelector('.status-badge');
    badge.className = 'status-badge ' + (ok ? 'ok' : 'fail');
    badge.innerHTML = '<i class="fa-solid fa-' + (ok ? 'check' : 'xmark') + '"></i> ' + (ok ? (IS_AR ? 'جيد' : 'OK') : (IS_AR ? 'خاطئ' : 'Fail'));
}

function toggleAll(el) {
    document.querySelectorAll('.row-chk').forEach(c => c.checked = el.checked);
    updateButtons();
}

function selectAll() {
    document.querySelectorAll('.row-chk').forEach(c => c.checked = true);
    updateButtons();
}

function updateButtons() {
    const checked = document.querySelectorAll('.row-chk:checked').length;
    document.getElementById('btnSave').disabled = checked === 0;
    document.getElementById('btnReject').disabled = checked === 0;
}

function getSelected() {
    const selected = [];
    document.querySelectorAll('.row-chk:checked').forEach(c => {
        selected.push(rows[parseInt(c.dataset.idx)]);
    });
    return selected;
}

async function saveApproved() {
    const selected = getSelected().filter(r => r.ar && r.ar !== r.en);
    if (selected.length === 0) { toast(IS_AR ? 'لا توجد ترجمات جيدة للحفظ' : 'No good translations to save', false); return; }
    const btn = document.getElementById('btnSave');
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner"></span> ' + (IS_AR ? 'جارٍ الحفظ...' : 'Saving...');
    try {
        const res = await fetch('api/save_translations.php', {
            method: 'POST',
            headers: {'Content-Type':'application/json'},
            body: JSON.stringify({translations: selected.map(r => ({id: r.id, ar: r.ar}))})
        });
        const data = await res.json();
        if (data.ok) {
            toast(IS_AR ? `تم حفظ ${data.saved_count} ترجمة` : `${data.saved_count} translations saved`, true);
            rows = rows.filter(r => !selected.find(s => s.id === r.id));
            renderTable();
        } else {
            toast(data.error || 'Error', false);
        }
    } catch(e) {
        toast('Error: ' + e.message, false);
    } finally {
        btn.disabled = false;
        btn.innerHTML = '<i class="fa-solid fa-floppy-disk"></i> ' + (IS_AR ? 'حفظ المختارة' : 'Save Selected');
    }
}

async function rejectSelected() {
    const selected = getSelected();
    if (selected.length === 0) return;
    rows = rows.filter(r => !selected.find(s => s.id === r.id));
    renderTable();
    toast(IS_AR ? `تم حذف ${selected.length} من القائمة` : `${selected.length} removed from list`, true);
}
</script>
</body>
</html>
