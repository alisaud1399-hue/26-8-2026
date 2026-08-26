<?php
/**
 * inventory/scan_offline.php
 * ---------------------------
 * Progressive Web App — الجرد الميداني بدون إنترنت
 *
 * - يحفظ المسحات في IndexedDB (Dexie.js)
 * - عند الاتصال بالإنترنت: sync تلقائي إلى /inventory/api/sync_batch.php
 * - يعمل في وضع PWA standalone (بعد التثبيت)
 * - يستخدم نفس /inventory/api/lookup.php للجرد الفوري (online only)
 *
 * التثبيت:
 *   1. افتح الرابط في Chrome/Edge على الجوال
 *   2. شريط العنوان → "Install" / "إضافة للشاشة الرئيسية"
 *   3. التطبيق يعمل standalone + offline
 *
 * @version 1.0.0 (2026-07-30)
 */
require_once dirname(__DIR__) . '/config.php';
page_guard('inventory.scan_offline');

$rtl = is_rtl();
$page_title = $rtl ? 'الجرد الميداني (PWA)' : 'Field Inventory (PWA)';
$active_nav = 'inventory.scan_offline';

global $pdo;
$user_id = user_id();
$user_name = user_name();

// Generate a stable device_id (per-browser, per-install)
$device_id = substr(md5($_SERVER['HTTP_USER_AGENT'] ?? 'unknown') . session_id(), 0, 24);

// Recent field scans (last 10 — for quick reference)
$recent = $pdo->prepare("
    SELECT ifs.id, ifs.tag_number, ifs.scan_status, ifs.found_location,
           ifs.scanned_at_local, ifs.synced_at, ifs.notes,
           a.description AS asset_description
    FROM inventory_field_scans ifs
    LEFT JOIN assets a ON a.id = ifs.asset_id
    WHERE ifs.scanned_by_user_id = ?
    ORDER BY ifs.synced_at DESC
    LIMIT 10
");
$recent->execute([$user_id]);
$recent_scans = $recent->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="<?= e(lang_attr()) ?>" dir="<?= e(lang_dir()) ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <meta name="theme-color" content="#0f3460">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="apple-mobile-web-app-title" content="PMSH جرد">
    <title><?= e($page_title) ?></title>
    <link rel="manifest" href="<?= BASE_URL ?>/inventory/manifest.json">
    <link rel="icon" type="image/png" sizes="192x192" href="<?= BASE_URL ?>/inventory/icon-192.png">
    <link rel="apple-touch-icon" href="<?= BASE_URL ?>/inventory/icon-192.png">
    <link href="https://fonts.googleapis.com/css2?family=Tajawal:wght@400;500;700;800;900&family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="<?= BASE_URL ?>/css/app.css">
    <style>
        :root {
            --primary: #0f3460;
            --primary-light: #1a5276;
            --success: #16a34a;
            --warning: #f59e0b;
            --danger: #dc2626;
            --bg: #f1f5f9;
        }
        * { box-sizing: border-box; }
        body {
            font-family: 'Tajawal', sans-serif;
            background: var(--bg);
            margin: 0;
            padding: 0;
            padding-bottom: env(safe-area-inset-bottom, 0);
            -webkit-tap-highlight-color: transparent;
        }
        /* Header — fixed at top */
        .hdr {
            background: linear-gradient(135deg, var(--primary) 0%, var(--primary-light) 100%);
            color: #fff;
            padding: 16px;
            padding-top: max(16px, env(safe-area-inset-top, 16px));
            position: sticky;
            top: 0;
            z-index: 100;
            box-shadow: 0 2px 12px rgba(15, 52, 96, 0.18);
        }
        .hdr-row { display: flex; align-items: center; gap: 10px; }
        .hdr h1 { font-size: 17px; font-weight: 800; margin: 0; flex: 1; }
        .hdr-ico {
            width: 36px; height: 36px;
            background: rgba(255,255,255,0.15);
            border-radius: 8px;
            display: flex; align-items: center; justify-content: center;
            font-size: 18px;
        }
        .net-badge {
            display: inline-flex; align-items: center; gap: 5px;
            padding: 5px 10px;
            border-radius: 99px;
            font-size: 12px;
            font-weight: 700;
            background: rgba(255,255,255,0.18);
        }
        .net-badge .dot {
            width: 8px; height: 8px;
            border-radius: 50%;
            background: #16a34a;
            animation: pulse 2s infinite;
        }
        .net-badge.offline { background: rgba(220,38,38,0.25); }
        .net-badge.offline .dot { background: #dc2626; }
        @keyframes pulse { 0%,100% { opacity: 1; } 50% { opacity: 0.4; } }

        .container { max-width: 720px; margin: 0 auto; padding: 14px; }

        /* Pending count card */
        .pending-card {
            background: linear-gradient(135deg, #fef3c7, #fde68a);
            border: 2px solid #f59e0b;
            border-radius: 14px;
            padding: 12px 16px;
            margin-bottom: 14px;
            display: flex;
            align-items: center;
            gap: 12px;
        }
        .pending-card.empty { background: #d1fae5; border-color: #16a34a; }
        .pending-card .ico { font-size: 22px; }
        .pending-card .txt { flex: 1; font-size: 13px; line-height: 1.5; color: #78350f; font-weight: 700; }
        .pending-card.empty .txt { color: #065f46; }
        .pending-card .val {
            font-size: 24px; font-weight: 900; color: #b45309;
            min-width: 32px; text-align: center;
        }
        .pending-card.empty .val { color: #15803d; }
        .pending-card .sync-btn {
            background: #b45309; color: #fff;
            border: 0; padding: 8px 14px;
            border-radius: 8px; font-size: 12px; font-weight: 700;
            cursor: pointer; display: flex; align-items: center; gap: 5px;
        }
        .pending-card .sync-btn:disabled { opacity: 0.5; cursor: not-allowed; }
        .pending-card .sync-btn:hover:not(:disabled) { background: #92400e; }
        .pending-card.empty .sync-btn { display: none; }

        /* Scan form */
        .card {
            background: #fff;
            border-radius: 14px;
            padding: 16px;
            margin-bottom: 14px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.06);
        }
        .card h2 {
            font-size: 15px; font-weight: 800; color: var(--primary);
            margin: 0 0 12px;
            display: flex; align-items: center; gap: 8px;
        }
        .card h2 .ic { color: var(--success); }

        .field { display: flex; flex-direction: column; gap: 4px; margin-bottom: 10px; }
        .field label { font-size: 12px; font-weight: 700; color: #475569; }
        .field label .req { color: var(--danger); }
        .field input, .field select, .field textarea {
            padding: 10px 12px;
            border: 1.5px solid #e2e8f0;
            border-radius: 8px;
            font-family: inherit;
            font-size: 15px;
            transition: border-color 0.2s;
        }
        .field input:focus, .field select:focus, .field textarea:focus {
            outline: none; border-color: var(--primary);
        }
        .field .hint { font-size: 11px; color: #94a3b8; }
        .row-2 { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; }

        /* Status pills */
        .status-pills {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 8px;
        }
        .status-pill {
            display: flex; align-items: center; gap: 8px;
            padding: 12px;
            border: 2px solid #e2e8f0;
            border-radius: 10px;
            cursor: pointer;
            background: #fff;
            font-size: 13px; font-weight: 700;
            color: #475569;
            transition: all 0.2s;
        }
        .status-pill input[type=radio] { display: none; }
        .status-pill .pill-ico { font-size: 18px; }
        .status-pill:has(input:checked) { border-color: var(--primary); background: #eff6ff; color: var(--primary); }
        .status-pill[data-status="verified"]:has(input:checked) { border-color: #16a34a; background: #d1fae5; color: #14532d; }
        .status-pill[data-status="not_found"]:has(input:checked) { border-color: #dc2626; background: #fee2e2; color: #7f1d1d; }
        .status-pill[data-status="moved"]:has(input:checked) { border-color: #f59e0b; background: #fef3c7; color: #78350f; }
        .status-pill[data-status="damaged"]:has(input:checked) { border-color: #ea580c; background: #ffedd5; color: #7c2d12; }

        /* Save button (sticky bottom on mobile) */
        .save-bar {
            position: sticky;
            bottom: 0;
            background: linear-gradient(0deg, #fff 60%, rgba(255,255,255,0) 100%);
            padding: 12px 0 0;
            margin-top: 14px;
        }
        .save-btn {
            width: 100%;
            padding: 14px;
            background: linear-gradient(135deg, var(--success), #15803d);
            color: #fff;
            border: 0;
            border-radius: 12px;
            font-size: 16px;
            font-weight: 800;
            cursor: pointer;
            display: flex; align-items: center; justify-content: center; gap: 8px;
            box-shadow: 0 4px 16px rgba(22, 163, 74, 0.3);
        }
        .save-btn:disabled { background: #94a3b8; box-shadow: none; cursor: not-allowed; }

        /* Recent scans list */
        .recent-list { padding: 0; margin: 0; list-style: none; }
        .recent-item {
            display: flex; align-items: center; gap: 10px;
            padding: 10px 0;
            border-bottom: 1px solid #f1f5f9;
        }
        .recent-item:last-child { border-bottom: 0; }
        .recent-item .tag {
            font-family: 'IBM Plex Mono', monospace;
            font-weight: 800; font-size: 12px;
            color: var(--primary);
        }
        .recent-item .stat {
            padding: 2px 8px;
            border-radius: 99px;
            font-size: 10.5px;
            font-weight: 700;
        }
        .stat.verified { background: #16a34a22; color: #15803d; }
        .stat.not_found { background: #dc262622; color: #7f1d1d; }
        .stat.moved { background: #f59e0b22; color: #78350f; }
        .stat.damaged { background: #ea580c22; color: #7c2d12; }
        .recent-item .time { font-size: 11px; color: #94a3b8; margin-inline-start: auto; }

        /* Recent scans — local queue (offline) */
        .queue-list { padding: 0; margin: 0; list-style: none; max-height: 240px; overflow-y: auto; }
        .queue-item {
            display: flex; align-items: center; gap: 8px;
            padding: 8px 10px;
            background: #fef3c7;
            border: 1px solid #fde68a;
            border-radius: 8px;
            margin-bottom: 6px;
            font-size: 12.5px;
        }
        .queue-item .tag {
            font-family: 'IBM Plex Mono', monospace;
            font-weight: 800; color: #b45309;
        }

        /* Toast */
        .toast {
            position: fixed;
            bottom: 80px;
            left: 50%;
            transform: translateX(-50%);
            background: var(--primary);
            color: #fff;
            padding: 10px 20px;
            border-radius: 99px;
            font-size: 13px;
            font-weight: 700;
            box-shadow: 0 4px 12px rgba(0,0,0,0.2);
            opacity: 0;
            transition: opacity 0.3s;
            pointer-events: none;
            z-index: 200;
        }
        .toast.show { opacity: 1; }
        .toast.success { background: var(--success); }
        .toast.warning { background: var(--warning); }
        .toast.danger { background: var(--danger); }

        @media (max-width: 480px) {
            .row-2 { grid-template-columns: 1fr; }
            .status-pills { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body>
<header class="hdr">
    <div class="hdr-row">
        <div class="hdr-ico"><i class="fa-solid fa-wifi"></i></div>
        <h1><?= $rtl?'الجرد الميداني':'Field Inventory' ?></h1>
        <div class="net-badge" id="netBadge">
            <span class="dot"></span>
            <span id="netText"><?= $rtl?'متصل':'Online' ?></span>
        </div>
    </div>
</header>

<div class="container">

    <!-- Pending Sync Card -->
    <div class="pending-card empty" id="pendingCard">
        <div class="ico" id="pendingIco">✅</div>
        <div class="txt" id="pendingTxt"><?= $rtl?'لا توجد مسحات معلقة':'No pending scans' ?></div>
        <div class="val" id="pendingVal">0</div>
        <button class="sync-btn" id="syncBtn" disabled>
            <i class="fa-solid fa-cloud-arrow-up"></i>
            <?= $rtl?'مزامنة':'Sync' ?>
        </button>
    </div>

    <!-- Scan Form -->
    <form id="scanForm" autocomplete="off">
        <div class="card">
            <h2><i class="fa-solid fa-barcode ic"></i> <?= $rtl?'بيانات المسح':'Scan Data' ?></h2>

            <div class="field">
                <label for="tagInput"><?= $rtl?'رقم التاج (Tag Number)':'Tag Number' ?> <span class="req">*</span></label>
                <input type="text" id="tagInput" name="tag" required
                       placeholder="<?= $rtl?'مثل: BHC002000001':'e.g. BHC002000001' ?>"
                       autocapitalize="characters" autocorrect="off" spellcheck="false">
                <div class="hint" id="tagHint"><?= $rtl?'امسح الباركود أو اكتب التاج يدوياً':'Scan barcode or type the tag' ?></div>
            </div>

            <div class="field">
                <label><?= $rtl?'حالة المسح':'Scan Status' ?> <span class="req">*</span></label>
                <div class="status-pills">
                    <label class="status-pill" data-status="verified">
                        <input type="radio" name="status" value="verified" required checked>
                        <span class="pill-ico">✅</span>
                        <span><?= $rtl?'تم التحقق':'Verified' ?></span>
                    </label>
                    <label class="status-pill" data-status="not_found">
                        <input type="radio" name="status" value="not_found">
                        <span class="pill-ico">❌</span>
                        <span><?= $rtl?'غير موجود':'Not Found' ?></span>
                    </label>
                    <label class="status-pill" data-status="moved">
                        <input type="radio" name="status" value="moved">
                        <span class="pill-ico">📍</span>
                        <span><?= $rtl?'منقول':'Moved' ?></span>
                    </label>
                    <label class="status-pill" data-status="damaged">
                        <input type="radio" name="status" value="damaged">
                        <span class="pill-ico">⚠️</span>
                        <span><?= $rtl?'تالف':'Damaged' ?></span>
                    </label>
                </div>
            </div>

            <div class="row-2">
                <div class="field">
                    <label for="locInput"><?= $rtl?'الموقع الفعلي (اختياري)':'Actual Location' ?></label>
                    <input type="text" id="locInput" name="location"
                           placeholder="<?= $rtl?'مثل: غرفة 203 - الدور الثاني':'e.g. Room 203 - 2nd floor' ?>">
                </div>
                <div class="field">
                    <label for="condInput"><?= $rtl?'الحالة (اختياري)':'Condition' ?></label>
                    <select id="condInput" name="condition">
                        <option value="">—</option>
                        <option value="excellent"><?= $rtl?'ممتاز':'Excellent' ?></option>
                        <option value="good"><?= $rtl?'جيد':'Good' ?></option>
                        <option value="fair"><?= $rtl?'مقبول':'Fair' ?></option>
                        <option value="poor"><?= $rtl?'ضعيف':'Poor' ?></option>
                    </select>
                </div>
            </div>

            <div class="field">
                <label for="notesInput"><?= $rtl?'ملاحظات (اختياري)':'Notes' ?></label>
                <textarea id="notesInput" name="notes" rows="2"
                          placeholder="<?= $rtl?'أي ملاحظات...':'Any notes...' ?>"></textarea>
            </div>
        </div>

        <div class="save-bar">
            <button type="submit" class="save-btn" id="saveBtn">
                <i class="fa-solid fa-floppy-disk"></i>
                <?= $rtl?'حفظ المسح':'Save Scan' ?>
            </button>
        </div>
    </form>

    <!-- Local Queue (pending sync) -->
    <div class="card" id="queueCard" style="display:none">
        <h2><i class="fa-solid fa-cloud-arrow-up ic" style="color: #f59e0b"></i>
            <?= $rtl?'المسحات في الانتظار':'Pending Queue' ?>
            <span id="queueCount" style="margin-inline-start:auto; background:#f59e0b; color:#fff; padding:2px 10px; border-radius:99px; font-size:12px; font-weight:800">0</span>
        </h2>
        <ul class="queue-list" id="queueList"></ul>
    </div>

    <!-- Recent scans (synced) -->
    <?php if ($recent_scans): ?>
    <div class="card">
        <h2><i class="fa-solid fa-clock-rotate-left ic"></i>
            <?= $rtl?'آخر مسحاتك':'Recent Scans' ?>
        </h2>
        <ul class="recent-list">
            <?php foreach ($recent_scans as $r): ?>
                <li class="recent-item">
                    <span class="tag"><?= e($r['tag_number']) ?></span>
                    <span class="stat <?= e($r['scan_status']) ?>"><?= e($r['scan_status']) ?></span>
                    <span class="time"><?= e(substr($r['synced_at'], 11, 5)) ?></span>
                </li>
            <?php endforeach; ?>
        </ul>
    </div>
    <?php endif; ?>

</div>

<div class="toast" id="toast"></div>

<script src="https://unpkg.com/dexie@3.2.7/dist/dexie.min.js"></script>
<script>
// ============ CONFIG (server-injected) ============
const DEVICE_ID = <?= json_encode($device_id) ?>;
const USER_ID = <?= (int)$user_id ?>;
const USER_NAME = <?= json_encode($user_name) ?>;
const API_SYNC = '<?= BASE_URL ?>/inventory/api/sync_batch.php';
const IS_RTL = <?= $rtl ? 'true' : 'false' ?>;

// ============ IndexedDB via Dexie ============
const db = new Dexie('PMSH_Inventory');
db.version(1).stores({
    scan_queue: '++id, client_id, tag, status, location, condition, notes, scanned_at, synced'  // ++id = auto-increment PK
});

// ============ UI helpers ============
const $ = sel => document.querySelector(sel);
const netBadge = $('#netBadge');
const netText = $('#netText');
const pendingCard = $('#pendingCard');
const pendingVal = $('#pendingVal');
const pendingTxt = $('#pendingTxt');
const pendingIco = $('#pendingIco');
const syncBtn = $('#syncBtn');
const queueCard = $('#queueCard');
const queueList = $('#queueList');
const queueCount = $('#queueCount');
const toast = $('#toast');

function showToast(msg, type='info', dur=2500) {
    toast.textContent = msg;
    toast.className = 'toast show ' + (type || '');
    setTimeout(() => { toast.className = 'toast'; }, dur);
}

function updateNetBadge() {
    if (navigator.onLine) {
        netBadge.classList.remove('offline');
        netText.textContent = IS_RTL ? 'متصل' : 'Online';
    } else {
        netBadge.classList.add('offline');
        netText.textContent = IS_RTL ? 'غير متصل' : 'Offline';
    }
}

async function refreshQueue() {
    const items = await db.scan_queue.toArray();
    const n = items.length;
    pendingVal.textContent = n;
    if (n === 0) {
        pendingCard.classList.add('empty');
        pendingIco.textContent = '✅';
        pendingTxt.textContent = IS_RTL ? 'لا توجد مسحات معلقة' : 'No pending scans';
        syncBtn.disabled = true;
        queueCard.style.display = 'none';
    } else {
        pendingCard.classList.remove('empty');
        pendingIco.textContent = '📥';
        pendingTxt.textContent = IS_RTL ? 'مسحات في الانتظار' : 'Pending sync';
        syncBtn.disabled = !navigator.onLine;
        queueCard.style.display = '';
        queueCount.textContent = n;
        queueList.innerHTML = items.map(it => `
            <li class="queue-item">
                <span class="tag">${escapeHtml(it.tag)}</span>
                <span class="stat ${escapeHtml(it.status)}">${escapeHtml(it.status)}</span>
                <span style="color:#94a3b8; font-size:11px; margin-inline-start:auto">${new Date(it.scanned_at).toLocaleTimeString()}</span>
            </li>
        `).join('');
    }
}

function escapeHtml(s) {
    return String(s).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
}

function uuid() {
    // RFC4122-like v4 UUID (no crypto.randomUUID dependency for old browsers)
    return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, c => {
        const r = (Math.random() * 16) | 0;
        const v = c === 'x' ? r : (r & 0x3) | 0x8;
        return v.toString(16);
    });
}

// ============ Save scan (always to local queue, then try sync) ============
$('#scanForm').addEventListener('submit', async (e) => {
    e.preventDefault();
    const form = e.target;
    const tag = form.tag.value.trim();
    if (!tag) {
        showToast(IS_RTL ? 'الرجاء إدخال رقم التاج' : 'Please enter a tag', 'warning');
        return;
    }
    const status = form.status.value;
    const location = form.location.value.trim();
    const condition = form.condition.value;
    const notes = form.notes.value.trim();

    const item = {
        client_id: uuid(),
        tag: tag.toUpperCase(),
        status: status,
        location: location,
        condition: condition,
        notes: notes,
        scanned_at: new Date().toISOString().slice(0, 19).replace('T', ' '),
        device_id: DEVICE_ID,
        user_id: USER_ID,
        user_name: USER_NAME,
        synced: 0
    };

    try {
        const id = await db.scan_queue.add(item);
        item.id = id;
        showToast(IS_RTL ? `✅ تم الحفظ (#${id})` : `✅ Saved (#${id})`, 'success');
        form.reset();
        form.status.value = 'verified';  // reset to default
        await refreshQueue();
        form.tag.focus();
        // Try to sync if online
        if (navigator.onLine) {
            syncNow();
        }
    } catch (err) {
        console.error('Save failed:', err);
        showToast(IS_RTL ? '❌ فشل الحفظ' : '❌ Save failed', 'danger');
    }
});

// ============ Sync (POST batch to server) ============
async function syncNow() {
    const items = await db.scan_queue.where('synced').equals(0).toArray();
    if (items.length === 0) {
        showToast(IS_RTL ? 'لا شيء للمزامنة' : 'Nothing to sync', 'info');
        return;
    }

    syncBtn.disabled = true;
    const oldText = syncBtn.innerHTML;
    syncBtn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> ' + (IS_RTL ? 'جارٍ...' : 'Syncing...');

    try {
        const res = await fetch(API_SYNC, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ items: items })
        });
        const data = await res.json();

        if (data.ok) {
            // Mark successfully synced items
            const successIds = (data.results || []).filter(r => r.ok).map(r => r.id);
            if (successIds.length > 0) {
                await db.scan_queue.bulkDelete(successIds);
            }
            const failed = items.length - successIds.length;
            if (failed > 0) {
                showToast(IS_RTL ? `⚠️ تمت مزامنة ${successIds.length}، فشل ${failed}` : `⚠️ ${successIds.length} synced, ${failed} failed`, 'warning', 4000);
            } else {
                showToast(IS_RTL ? `✅ تمت مزامنة ${successIds.length}` : `✅ ${successIds.length} synced`, 'success');
            }
            await refreshQueue();
            // Broadcast to server-side
            try { navigator.serviceWorker.controller.postMessage({ type: 'SYNC_DONE' }); } catch (e) {}
        } else {
            showToast(IS_RTL ? '❌ ' + (data.error || 'فشل') : '❌ ' + (data.error || 'Failed'), 'danger');
        }
    } catch (err) {
        console.error('Sync failed:', err);
        showToast(IS_RTL ? '❌ لا اتصال' : '❌ No connection', 'danger');
    } finally {
        syncBtn.innerHTML = oldText;
        syncBtn.disabled = !navigator.onLine;
    }
}

syncBtn.addEventListener('click', syncNow);

// ============ Network status listeners ============
window.addEventListener('online', () => { updateNetBadge(); refreshQueue(); syncNow(); });
window.addEventListener('offline', () => { updateNetBadge(); refreshQueue(); });
window.addEventListener('load', () => { updateNetBadge(); refreshQueue(); });

// ============ Service Worker registration ============
if ('serviceWorker' in navigator) {
    window.addEventListener('load', () => {
        navigator.serviceWorker.register('/inventory/sw.js')
            .then(reg => console.log('[PWA] SW registered, scope:', reg.scope))
            .catch(err => console.warn('[PWA] SW registration failed:', err));
    });
}

// ============ Initial render ============
updateNetBadge();
refreshQueue();

// ============ Back Button Guard (PWA Field Safety) ============
// Prevents the user from accidentally navigating back to a page that requires
// internet (e.g. session.php). When offline, the back button shows a
// confirmation. When online, normal back behavior is preserved.
(function() {
    // Only enforce guard when PWA is installed (standalone mode) or when offline.
    const isStandalone = window.matchMedia('(display-mode: standalone)').matches ||
                         window.navigator.standalone === true;
    if (!isStandalone && navigator.onLine) {
        // Browser tab + online: let normal back work (user might be exploring)
        return;
    }

    // Push a state so we can intercept the back button
    history.pushState({ pwa_scan: true }, document.title, location.href);

    // Track if user explicitly chose to leave (so we don't re-prompt)
    let userChoseToLeave = false;

    window.addEventListener('popstate', (e) => {
        if (userChoseToLeave) {
            // Already confirmed — allow navigation
            return;
        }
        if (!navigator.onLine) {
            const stay = confirm(
                '⚠️ أنت غير متصل بالإنترنت.\n\n' +
                'الرجوع قد يحمّل صفحة لا تعمل بدون إنترنت.\n\n' +
                '• اضغط "موافق" للبقاء في المسح الميداني\n' +
                '• اضغط "إلغاء" للخروج'
            );
            if (stay) {
                // Re-push state to keep us here
                history.pushState({ pwa_scan: true }, document.title, location.href);
            } else {
                // Allow navigation
                userChoseToLeave = true;
                history.back();
            }
        } else {
            // Online: allow normal back navigation
            userChoseToLeave = true;
            history.back();
        }
    });

    // Warn before tab close / refresh if there are unsaved scans
    window.addEventListener('beforeunload', (e) => {
        const n = pendingVal ? parseInt(pendingVal.textContent, 10) || 0 : 0;
        if (n > 0 && !userChoseToLeave) {
            e.preventDefault();
            e.returnValue = `لديك ${n} مسحات في الانتظار. هل تريد المغادرة؟`;
            return e.returnValue;
        }
    });

    console.log('[PWA] Back button guard installed (standalone=' + isStandalone + ', online=' + navigator.onLine + ')');
})();
</script>

</body>
</html>
