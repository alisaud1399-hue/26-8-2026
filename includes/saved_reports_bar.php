<?php
/**
 * includes/saved_reports_bar.php — شريط التقارير المحفوظة القابل لإعادة الاستخدام (نسخة مصححة)
 * ─────────────────────────────────────────────────────────────────────────────────
 * المتغيرات المطلوبة قبل الاستدعاء:
 *   $sr_module   (string)  — اسم الوحدة، مثل 'custody', 'assets'
 *   $sr_filters  (array)   — الفلاتر الحالية
 *   $sr_view     (string)  — 'executive' أو 'detailed'
 *   $sr_base_url (string)  — مثل 'http://localhost/pmsh_assets'
 *
 * الاستخدام:
 *   $sr_module = 'custody';
 *   $sr_filters = $f;
 *   $sr_view = $view_mode;
 *   $sr_base_url = BASE_URL;
 *   include BASE_PATH . '/includes/saved_reports_bar.php';
 */

if (!isset($sr_module) || !isset($sr_filters)) {
    return; // لم تُمرَّر المتغيرات المطلوبة
}

$sr_view     = $sr_view ?? 'executive';
$sr_base_url = $sr_base_url ?? BASE_URL;
$sr_user_id  = (int)(current_user()['id'] ?? 0);
$sr_list     = sr_load_for_module($pdo, $sr_module, $sr_user_id);
if (!isset($sr_share_url)) $sr_share_url = sr_build_share_url($sr_base_url, $sr_module, $sr_filters, $sr_view);

// أيقونات مقترحة حسب الوحدة
$SR_MODULE_ICONS = [
    'custody'      => ['icon' => 'fa-handshake',          'color' => '#059669', 'label' => 'العهدة'],
    'assets'       => ['icon' => 'fa-boxes-stacked',      'color' => '#0ea5e9', 'label' => 'الأصول'],
    'maintenance'  => ['icon' => 'fa-screwdriver-wrench', 'color' => '#8b5cf6', 'label' => 'الصيانة'],
    'complaints'   => ['icon' => 'fa-ticket',             'color' => '#f59e0b', 'label' => 'البلاغات'],
    'inventory'    => ['icon' => 'fa-clipboard-list',     'color' => '#0d9488', 'label' => 'الجرد'],
    'committees'   => ['icon' => 'fa-users-gear',         'color' => '#dc2626', 'label' => 'اللجان'],
    'disposals'    => ['icon' => 'fa-trash-can',          'color' => '#64748b', 'label' => 'الإتلاف'],
    'receiving'    => ['icon' => 'fa-truck-ramp-box',     'color' => '#b45309', 'label' => 'الاستلام'],
];
$sr_current_module = $SR_MODULE_ICONS[$sr_module] ?? ['icon' => 'fa-chart-line', 'color' => '#059669', 'label' => 'تقرير'];
?>

<!-- ═══════════ Saved Reports Bar ═══════════ -->
<style>
.sr-wrap{background:#fff;border:1.5px solid #e2e8f0;border-radius:14px;padding:14px 18px;margin-bottom:16px;box-shadow:0 2px 8px rgba(0,0,0,.02)}
.sr-top{display:flex;justify-content:space-between;align-items:center;margin-bottom:10px;gap:10px;flex-wrap:wrap}
.sr-title{font-size:13px;font-weight:900;color:#0f172a;display:flex;align-items:center;gap:8px}
.sr-title i{color:var(--primary,#059669)}
.sr-actions{display:flex;gap:6px;flex-wrap:wrap}
.sr-btn{background:#f8fafc;border:1.5px solid #e2e8f0;border-radius:8px;padding:6px 12px;font-size:11.5px;font-weight:800;color:#475569;cursor:pointer;display:inline-flex;align-items:center;gap:5px;transition:.2s;font-family:inherit;text-decoration:none}
.sr-btn:hover{background:var(--primary-light,#ecfdf5);border-color:var(--primary,#059669);color:var(--primary,#059669)}
.sr-btn.primary{background:var(--primary,#059669);color:#fff;border-color:var(--primary,#059669)}
.sr-btn.primary:hover{background:#047857}
.sr-chips{display:flex;gap:8px;flex-wrap:wrap}
.sr-chip{background:#f8fafc;border:1.5px solid #e2e8f0;border-radius:99px;padding:6px 12px;font-size:11.5px;font-weight:700;color:#334155;display:inline-flex;align-items:center;gap:6px;cursor:pointer;transition:.2s;text-decoration:none;position:relative}
.sr-chip:hover{background:#ecfdf5;border-color:#10b981;transform:translateY(-1px)}
.sr-chip.favorite{background:#fef3c7;border-color:#fcd34d;color:#92400e}
.sr-chip.shared{background:#e0f2fe;border-color:#7dd3fc;color:#0369a1}
.sr-chip-icon{width:16px;height:16px;border-radius:4px;display:inline-flex;align-items:center;justify-content:center;font-size:9px;color:#fff;flex-shrink:0}
.sr-chip-actions{display:inline-flex;gap:2px;margin-inline-start:4px;opacity:0;transition:.2s}
.sr-chip:hover .sr-chip-actions{opacity:1}
.sr-chip-action{width:18px;height:18px;border-radius:50%;border:none;background:rgba(0,0,0,.08);color:#475569;cursor:pointer;display:inline-flex;align-items:center;justify-content:center;font-size:9px;padding:0}
.sr-chip-action:hover{background:#ef4444;color:#fff}
.sr-chip-action.fav:hover{background:#f59e0b;color:#fff}
.sr-chip-action.share:hover{background:#0ea5e9;color:#fff}
.sr-empty{color:#94a3b8;font-size:11.5px;font-style:italic;padding:4px 0}
.sr-badge{font-size:9px;padding:1px 5px;border-radius:4px;font-weight:900;margin-inline-start:3px}
.sr-badge.fav{background:#fcd34d;color:#78350f}
.sr-badge.shared{background:#7dd3fc;color:#0c4a6e}
.sr-modal-bg{position:fixed;inset:0;background:rgba(15,23,42,.5);display:none;align-items:center;justify-content:center;z-index:9999;backdrop-filter:blur(4px)}
.sr-modal-bg.active{display:flex}
.sr-modal{background:#fff;border-radius:16px;padding:24px;width:90%;max-width:500px;box-shadow:0 20px 60px rgba(0,0,0,.2)}
.sr-modal h3{margin:0 0 16px;font-size:16px;font-weight:900}
.sr-modal-field{margin-bottom:12px}
.sr-modal-field label{display:block;font-size:11.5px;font-weight:800;color:#475569;margin-bottom:4px}
.sr-modal-field input,.sr-modal-field textarea,.sr-modal-field select{width:100%;padding:8px 12px;border:1.5px solid #e2e8f0;border-radius:8px;font-size:13px;font-family:inherit}
.sr-modal-actions{display:flex;gap:8px;justify-content:flex-end;margin-top:16px}
.sr-check-row{display:flex;align-items:center;gap:8px;font-size:12px;font-weight:600;color:#475569;margin-top:6px}
.sr-check-row input{accent-color:var(--primary,#059669)}
</style>

<div class="sr-wrap">
    <div class="sr-top">
        <div class="sr-title">
            <i class="fa-solid fa-bookmark"></i>
            <span>تقاريرك المحفوظة — <?= e($sr_current_module['label']) ?></span>
            <span style="font-size:10px;color:#94a3b8;font-weight:500">(<?= count($sr_list) ?>)</span>
        </div>
        <div class="sr-actions">
            <button type="button" class="sr-btn primary" onclick="srOpenSaveModal()">
                <i class="fa-solid fa-plus"></i> حفظ التقرير الحالي
            </button>
            <button type="button" class="sr-btn" onclick="srCopyShareUrl(this)">
                <i class="fa-solid fa-link"></i> نسخ رابط التقرير
            </button>
        </div>
    </div>

    <div class="sr-chips">
        <?php if (empty($sr_list)): ?>
            <div class="sr-empty">لا توجد تقارير محفوظة بعد. ابدأ باختيار الفلاتر ثم اضغط "حفظ التقرير الحالي".</div>
        <?php else: ?>
            <?php foreach ($sr_list as $sr):
                $is_own = (int)$sr['user_id'] === $sr_user_id;
                $filters_arr = json_decode($sr['filters_json'], true) ?: [];
                $apply_url = '?' . http_build_query(array_merge($filters_arr, ['view' => $sr['view_mode'] ?? 'executive', 'apply_saved' => $sr['id']]));
                $icon = $sr['icon'] ?: 'fa-chart-line';
                $color = $sr['color'] ?: '#059669';
            ?>
                <a href="<?= e($apply_url) ?>" class="sr-chip <?= $sr['is_favorite'] ? 'favorite' : '' ?> <?= $sr['is_shared'] ? 'shared' : '' ?>" title="<?= e($sr['description'] ?: $sr['name']) ?>">
                    <span class="sr-chip-icon" style="background:<?= e($color) ?>"><i class="fa-solid <?= e($icon) ?>"></i></span>
                    <span><?= e($sr['name']) ?></span>
                    <?php if ($sr['is_favorite']): ?><span class="sr-badge fav">⭐</span><?php endif; ?>
                    <?php if ($sr['is_shared']): ?><span class="sr-badge shared">🌐</span><?php endif; ?>
                    <?php if (!$is_own): ?><span style="font-size:9px;color:#94a3b8">(<?= e($sr['owner_name'] ?: 'مشترك') ?>)</span><?php endif; ?>
                    <?php if ($is_own): ?>
                        <span class="sr-chip-actions" onclick="event.preventDefault(); event.stopPropagation();">
                            <button type="button" class="sr-chip-action fav" onclick="srToggleFav(<?= $sr['id'] ?>)" title="تمييز">
                                <i class="fa-solid fa-star"></i>
                            </button>
                            <button type="button" class="sr-chip-action share" onclick="srToggleShare(<?= $sr['id'] ?>)" title="مشاركة">
                                <i class="fa-solid fa-share-nodes"></i>
                            </button>
                            <button type="button" class="sr-chip-action" onclick="srDelete(<?= $sr['id'] ?>)" title="حذف">
                                <i class="fa-solid fa-xmark"></i>
                            </button>
                        </span>
                    <?php endif; ?>
                </a>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</div>

<!-- Modal: حفظ تقرير -->
<div class="sr-modal-bg" id="srSaveModal">
    <div class="sr-modal">
        <h3><i class="fa-solid fa-bookmark" style="color:var(--primary,#059669)"></i> حفظ التقرير الحالي</h3>
        <form onsubmit="srSaveReport(event)">
            <div class="sr-modal-field">
                <label>اسم التقرير *</label>
                <input type="text" name="name" required placeholder="مثال: عهدة قسم الطوارئ" maxlength="150">
            </div>
            <div class="sr-modal-field">
                <label>وصف (اختياري)</label>
                <textarea name="description" rows="2" placeholder="وصف مختصر لمحتوى التقرير" maxlength="500"></textarea>
            </div>
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;">
                <div class="sr-modal-field">
                    <label>أيقونة</label>
                    <select name="icon">
                        <option value="fa-chart-line">📈 خط بياني</option>
                        <option value="fa-handshake">🤝 عهدة</option>
                        <option value="fa-boxes-stacked">📦 أصول</option>
                        <option value="fa-screwdriver-wrench">🔧 صيانة</option>
                        <option value="fa-ticket">🎫 بلاغات</option>
                        <option value="fa-triangle-exclamation">⚠️ تحذير</option>
                        <option value="fa-star">⭐ مميز</option>
                        <option value="fa-bolt">⚡ سريع</option>
                        <option value="fa-filter">🔍 فلاتر</option>
                    </select>
                </div>
                <div class="sr-modal-field">
                    <label>لون الشارة</label>
                    <input type="color" name="color" value="#059669">
                </div>
            </div>
            <div class="sr-check-row">
                <input type="checkbox" name="is_shared" id="srShared" value="1">
                <label for="srShared">🌐 مشاركة مع الجميع في نفس الوحدة</label>
            </div>
            <div class="sr-check-row">
                <input type="checkbox" name="is_favorite" id="srFav" value="1">
                <label for="srFav">⭐ تمييز كمفضلة</label>
            </div>
            <div class="sr-modal-actions">
                <button type="button" class="sr-btn" onclick="srCloseSaveModal()">إلغاء</button>
                <button type="submit" class="sr-btn primary">💾 حفظ</button>
            </div>
        </form>
    </div>
</div>

<script>
const SR_MODULE = <?= json_encode($sr_module) ?>;
const SR_FILTERS = <?= json_encode($sr_filters, JSON_UNESCAPED_UNICODE) ?>;
const SR_VIEW = <?= json_encode($sr_view) ?>;
const SR_SHARE_URL = <?= json_encode($sr_share_url) ?>;
const SR_API = <?= json_encode(BASE_URL . '/api/saved_reports.php') ?>;

function srOpenSaveModal() { document.getElementById('srSaveModal').classList.add('active'); }
function srCloseSaveModal() { document.getElementById('srSaveModal').classList.remove('active'); }

async function srApiPost(data) {
    const fd = new FormData();
    Object.entries(data).forEach(([k,v]) => fd.append(k, v));
    const res = await fetch(SR_API, { method: 'POST', body: fd });
    return res.json();
}

async function srSaveReport(e) {
    e.preventDefault();
    const f = e.target;
    const payload = {
        action: 'save',
        module: SR_MODULE,
        name: f.name.value.trim(),
        description: f.description.value.trim(),
        icon: f.icon.value,
        color: f.color.value,
        filters_json: JSON.stringify(SR_FILTERS),
        view_mode: SR_VIEW,
        is_shared: f.is_shared.checked ? 1 : 0,
        is_favorite: f.is_favorite.checked ? 1 : 0,
    };
    const r = await srApiPost(payload);
    if (r.ok) {
        alert(r.message || 'تم الحفظ بنجاح');
        srCloseSaveModal();
        location.reload();
    } else {
        alert('خطأ: ' + (r.error || 'غير معروف'));
    }
}

async function srToggleFav(id) {
    const r = await srApiPost({ action: 'toggle_favorite', id: id });
    if (r.ok) location.reload();
}
async function srToggleShare(id) {
    if (!confirm('هل تريد تبديل حالة مشاركة هذا التقرير مع الجميع؟')) return;
    const r = await srApiPost({ action: 'toggle_shared', id: id });
    if (r.ok) location.reload();
}
async function srDelete(id) {
    if (!confirm('هل أنت متأكد من حذف هذا التقرير؟ لا يمكن التراجع.')) return;
    const r = await srApiPost({ action: 'delete', id: id });
    if (r.ok) location.reload();
    else alert('فشل الحذف');
}

function srCopyShareUrl(btn) {
    navigator.clipboard.writeText(SR_SHARE_URL).then(() => {
        const original = btn.innerHTML;
        btn.innerHTML = '<i class="fa-solid fa-check"></i> تم النسخ!';
        btn.style.background = '#10b981'; btn.style.color = '#fff'; btn.style.borderColor = '#10b981';
        setTimeout(() => { btn.innerHTML = original; btn.style = ''; }, 2000);
    }).catch(() => {
        prompt('انسخ الرابط:', SR_SHARE_URL);
    });
}
</script>