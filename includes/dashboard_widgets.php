<?php
/**
 * includes/dashboard_widgets.php — 5 widgets للوحة التحكم
 * ───────────────────────────────────────────────────────
 *  يُدرج في أي صفحة (dashboard أو غيره) عبر:
 *      <?php include BASE_PATH . '/includes/dashboard_widgets.php'; ?>
 *
 *  يفترض أن:
 *   - session + user_id() + is_rtl() متاحين
 *   - BASE_URL معرّف
 *   - $pdo (DB) متاح
 */
$dw_rtl  = is_rtl();
$dw_uid  = user_id();
$dw_user = current_user();

// صلاحية إنشاء أحداث مؤسسية (broadcast)
$dw_can_inst = can('institutional_events', 'create');

// CSRF token للمهام (POST)
$dw_csrf = csrf_token();
?>
<style>
/* ═══ Dashboard Widgets ═══ */
.dw-grid {
  display: grid;
  grid-template-columns: 1fr 1fr 1fr;
  gap: 14px;
  margin: 14px 0;
}
.dw-grid-2 { grid-template-columns: 1fr 1fr; }
.dw-col-1 { grid-column: span 1; }
.dw-col-2 { grid-column: span 2; }
.dw-col-3 { grid-column: span 3; }

@media (max-width: 1100px) { .dw-grid { grid-template-columns: 1fr 1fr; } .dw-col-3 { grid-column: span 2; } }
@media (max-width: 720px)  { .dw-grid { grid-template-columns: 1fr; } .dw-col-2, .dw-col-3 { grid-column: span 1; } }

.dw-card {
  background: #fff;
  border: 1.5px solid #e2e8f0;
  border-radius: 16px;
  overflow: hidden;
  display: flex; flex-direction: column;
  transition: all 0.2s;
  position: relative;
}
.dw-card.dw-premium::before {
  content: '';
  position: absolute;
  top: 0; inset-inline-start: 0; right: 0;
  height: 3px;
  background: linear-gradient(90deg, #7c3aed 0%, #4f46e5 50%, #06b6d4 100%);
}
.dw-card:hover { box-shadow: 0 8px 24px rgba(0,0,0,.06); transform: translateY(-1px); }
.dw-card-h {
  padding: 14px 18px;
  border-bottom: 1.5px solid #f1f5f9;
  display: flex; align-items: center; gap: 8px;
  font-size: 14px; font-weight: 800; color: #0f172a;
}
.dw-card-h i { font-size: 15px; color: #7c3aed; }
.dw-card-h .dw-badge {
  background: #fef2f2; color: #dc2626;
  padding: 1px 7px; border-radius: 9px;
  font-size: 10.5px; font-weight: 800;
  margin-inline-start: 0;
}
.dw-add-btn {
  margin-inline-start: auto;
  background: linear-gradient(135deg, #7c3aed, #4f46e5);
  color: #fff;
  border: none;
  width: 26px; height: 26px;
  border-radius: 8px;
  cursor: pointer;
  display: flex; align-items: center; justify-content: center;
  font-size: 11px;
  box-shadow: 0 2px 8px rgba(124,58,237,.30);
  transition: all 0.15s;
}
.dw-add-btn:hover { transform: scale(1.08); box-shadow: 0 4px 12px rgba(124,58,237,.40); }
.dw-card-b { padding: 14px 18px; flex: 1; }
.dw-card-loading { text-align: center; padding: 30px 16px; color: #94a3b8; font-size: 12px; }
.dw-card-loading i { animation: dwspin 1s linear infinite; }

/* ─── Weather ─── */
.dw-weather { display: grid; grid-template-columns: 1fr 1fr; gap: 0; }
.dw-weather-city { padding: 12px 14px; border-left: 1px solid #f1f5f9; }
.dw-weather-city:last-child { border-left: none; }
.dw-w-name { font-size: 12px; font-weight: 700; color: #64748b; margin-bottom: 6px; }
.dw-w-main { display: flex; align-items: center; gap: 8px; }
.dw-w-temp { font-size: 28px; font-weight: 800; color: #0f172a; line-height: 1; }
.dw-w-ico { font-size: 26px; color: #f59e0b; }
.dw-w-desc { font-size: 11.5px; color: #475569; margin-top: 4px; }
.dw-w-meta { display: flex; gap: 10px; margin-top: 8px; font-size: 10.5px; color: #94a3b8; }
.dw-w-meta span { display: inline-flex; align-items: center; gap: 3px; }
.dw-w-alert { margin-top: 8px; padding: 6px 8px; border-radius: 7px; font-size: 10.5px; font-weight: 700; display: flex; gap: 6px; align-items: center; }
.dw-w-alert.danger  { background: #fef2f2; color: #991b1b; border: 1px solid #fca5a5; }
.dw-w-alert.warning { background: #fffbeb; color: #92400e; border: 1px solid #fcd34d; }
.dw-w-alert.info    { background: #eff6ff; color: #1e40af; border: 1px solid #93c5fd; }

/* ─── Tasks ─── */
.dw-task-form {
  background: linear-gradient(135deg, #faf5ff 0%, #eff6ff 100%);
  border: 1.5px solid #e0e7ff;
  border-radius: 12px;
  padding: 12px;
  margin-bottom: 12px;
  display: flex; flex-direction: column; gap: 8px;
}
.dw-task-form input[type=text],
.dw-task-form input[type=date],
.dw-task-form input[type=time],
.dw-task-form select {
  width: 100%;
  padding: 7px 10px;
  border: 1.5px solid #e0e7ff;
  border-radius: 7px;
  font-size: 12.5px;
  font-family: inherit;
  background: #fff;
}
.dw-task-form input:focus, .dw-task-form select:focus { outline: none; border-color: #7c3aed; box-shadow: 0 0 0 3px rgba(124,58,237,.10); }
.dw-task-form-row { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 6px; }
.dw-task-form-actions { display: flex; gap: 6px; align-items: center; margin-top: 4px; }
.dw-bell-toggle { display: inline-flex; align-items: center; gap: 5px; padding: 5px 10px; background: #fff; border: 1.5px solid #e0e7ff; border-radius: 7px; cursor: pointer; font-size: 11.5px; color: #7c3aed; font-weight: 700; }
.dw-bell-toggle input { display: none; }
.dw-bell-toggle i { font-size: 11px; }
.dw-bell-toggle input:checked + i { color: #f59e0b; }
.dw-btn-primary {
  padding: 7px 14px; background: linear-gradient(135deg, #7c3aed, #4f46e5);
  color: #fff; border: none; border-radius: 7px; cursor: pointer; font-size: 12px; font-weight: 700;
  display: inline-flex; align-items: center; gap: 5px;
}
.dw-btn-secondary {
  padding: 7px 12px; background: transparent; color: #64748b;
  border: 1.5px solid #e2e8f0; border-radius: 7px; cursor: pointer; font-size: 12px; font-weight: 600;
}
.dw-task-quick-add {
  width: 100%; padding: 10px; margin-bottom: 10px;
  background: #f8fafc; border: 1.5px dashed #cbd5e1; border-radius: 9px;
  color: #64748b; font-size: 12.5px; font-weight: 600; cursor: pointer;
  display: flex; align-items: center; justify-content: center; gap: 6px;
  transition: all 0.15s;
}
.dw-task-quick-add:hover { background: #faf5ff; border-color: #7c3aed; color: #7c3aed; }

.dw-task-list { list-style: none; padding: 0; margin: 0; max-height: 340px; overflow-y: auto; }
.dw-task-list li { display: flex; align-items: center; gap: 8px; padding: 9px 0; border-bottom: 1px dashed #f1f5f9; }
.dw-task-list li:last-child { border-bottom: none; }
.dw-task-list .dw-task-cb { width: 18px; height: 18px; flex-shrink: 0; cursor: pointer; accent-color: #7c3aed; }
.dw-task-list .dw-task-title { flex: 1; font-size: 12.5px; color: #0f172a; font-weight: 600; }
.dw-task-list .dw-task-title.done { text-decoration: line-through; color: #94a3b8; font-weight: 400; }
.dw-task-list .dw-task-meta { font-size: 10px; color: #94a3b8; display: flex; gap: 6px; align-items: center; margin-top: 2px; flex-wrap: wrap; }
.dw-task-list .dw-task-meta .dw-task-bell { color: #f59e0b; font-size: 9.5px; }
.dw-task-list .dw-task-prio { padding: 1px 6px; border-radius: 4px; font-size: 9.5px; font-weight: 800; }
.dw-task-list .dw-task-prio.urgent { background: #fef2f2; color: #dc2626; }
.dw-task-list .dw-task-prio.high   { background: #fffbeb; color: #d97706; }
.dw-task-list .dw-task-prio.normal { background: #f1f5f9; color: #475569; }
.dw-task-list .dw-task-prio.low    { background: #ecfdf5; color: #059669; }
.dw-task-list .dw-task-del { background: none; border: none; color: #94a3b8; cursor: pointer; padding: 4px 7px; border-radius: 5px; font-size: 11px; }
.dw-task-list .dw-task-del:hover { color: #dc2626; background: #fef2f2; }
.dw-task-empty { text-align: center; padding: 30px 10px; color: #94a3b8; font-size: 12.5px; }
.dw-task-list .dw-task-overdue { color: #dc2626; font-weight: 700; }
.dw-task-list .dw-task-due { color: #d97706; }

/* ─── Scope toggle (شخصي / مؤسسي) ─── */
.dw-scope {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 4px;
  background: #f1f5f9;
  border-radius: 9px;
  padding: 3px;
  margin-bottom: 4px;
}
.dw-scope-opt {
  padding: 7px 10px;
  border-radius: 7px;
  cursor: pointer;
  font-size: 12px;
  font-weight: 700;
  text-align: center;
  color: #64748b;
  transition: all 0.15s;
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 5px;
}
.dw-scope-opt.active.personal { background: #fff; color: #7c3aed; box-shadow: 0 1px 4px rgba(124,58,237,.20); }
.dw-scope-opt.active.inst     { background: #fff; color: #16a34a; box-shadow: 0 1px 4px rgba(22,163,74,.20); }
.dw-scope-opt.disabled { opacity: 0.4; cursor: not-allowed; }
.dw-scope-opt .dot { width: 7px; height: 7px; border-radius: 50%; display: inline-block; }
.dw-scope-opt .dot.personal { background: #7c3aed; }
.dw-scope-opt .dot.inst     { background: #16a34a; }

/* ─── Audience selector (الكل / مستخدمين محددين) ─── */
.dw-audience { background: #f0fdf4; border: 1.5px solid #bbf7d0; border-radius: 9px; padding: 8px 10px; margin-bottom: 4px; }
.dw-audience-row { display: flex; gap: 10px; align-items: center; font-size: 12px; color: #166534; font-weight: 700; }
.dw-audience-row label { display: inline-flex; align-items: center; gap: 4px; cursor: pointer; }
.dw-audience-pick { margin-top: 6px; max-height: 140px; overflow-y: auto; background: #fff; border: 1px solid #d1fae5; border-radius: 7px; padding: 6px 8px; }
.dw-audience-pick label { display: flex; align-items: center; gap: 6px; padding: 4px 0; font-size: 11.5px; color: #334155; cursor: pointer; }
.dw-audience-pick label:hover { background: #f0fdf4; border-radius: 4px; }
.dw-audience-pick input { accent-color: #16a34a; }
.dw-audience-pick .badge { background: #dcfce7; color: #166534; font-size: 9.5px; padding: 1px 5px; border-radius: 4px; font-weight: 700; }
.dw-audience-pick .badge.admin { background: #fef3c7; color: #92400e; }
.dw-audience-count { font-size: 10.5px; color: #64748b; margin-top: 4px; }

/* ─── Activity ─── */
.dw-feed { list-style: none; padding: 0; margin: 0; max-height: 280px; overflow-y: auto; }
.dw-feed li { display: flex; gap: 9px; padding: 7px 0; border-bottom: 1px dashed #f1f5f9; }
.dw-feed li:last-child { border-bottom: none; }
.dw-feed-dot { width: 26px; height: 26px; border-radius: 50%; background: #f3e8ff; color: #7c3aed; display: flex; align-items: center; justify-content: center; font-size: 10px; flex-shrink: 0; }
.dw-feed-action { font-size: 12px; font-weight: 700; color: #0f172a; }
.dw-feed-meta { font-size: 10.5px; color: #94a3b8; margin-top: 1px; }

/* ─── Calendar (Mini) ─── */
.dw-cal-grid { display: grid; grid-template-columns: repeat(7, 1fr); gap: 2px; margin-top: 6px; }
.dw-cal-day { aspect-ratio: 1; padding: 4px 2px; background: #f8fafc; border-radius: 5px; text-align: center; font-size: 11px; position: relative; cursor: default; }
.dw-cal-day.has-event { background: #fef3c7; cursor: pointer; }
.dw-cal-day.has-event:hover { background: #fcd34d; }
/* ألوان الأحداث حسب النوع — مؤسسية (أخضر) vs شخصية (بنفسجي) vs تذكير (برتقالي) */
.dw-cal-day .dw-cal-dot { display: block; width: 5px; height: 5px; border-radius: 50%; margin: 2px auto 0; }
.dw-cal-day .dw-cal-dot.institutional { background: #16a34a; }  /* أخضر: مؤسسي */
.dw-cal-day .dw-cal-dot.personal { background: #7c3aed; }        /* بنفسجي: شخصي */
.dw-cal-day .dw-cal-dot.reminder { background: #f59e0b; width: 7px; height: 7px; }  /* برتقالي أكبر: تذكير */
.dw-cal-hdr { text-align: center; font-size: 10px; font-weight: 700; color: #94a3b8; padding: 4px 0; }
.dw-cal-month { text-align: center; font-size: 13px; font-weight: 800; color: #0f172a; margin-bottom: 6px; }

/* ─── Alerts ─── */
.dw-alert-list { list-style: none; padding: 0; margin: 0; max-height: 280px; overflow-y: auto; }
.dw-alert-list li { display: flex; align-items: flex-start; gap: 9px; padding: 9px 0; border-bottom: 1px dashed #f1f5f9; }
.dw-alert-list li:last-child { border-bottom: none; }
.dw-alert-list .dw-alert-ico { width: 30px; height: 30px; border-radius: 8px; display: flex; align-items: center; justify-content: center; font-size: 12px; flex-shrink: 0; }
.dw-alert-list .dw-alert-ico.danger  { background: #fef2f2; color: #dc2626; }
.dw-alert-list .dw-alert-ico.warning { background: #fffbeb; color: #d97706; }
.dw-alert-list .dw-alert-ico.info    { background: #eff6ff; color: #2563eb; }
.dw-alert-list .dw-alert-title { font-size: 12.5px; font-weight: 700; color: #0f172a; line-height: 1.4; }
.dw-alert-list .dw-alert-meta { font-size: 10.5px; color: #94a3b8; margin-top: 2px; }

@keyframes dwspin { from { transform: rotate(0); } to { transform: rotate(360deg); } }
</style>

<div class="dw-grid dw-grid-2" id="dwGrid">

  <!-- ═══ 1) المهام السريعة (محسّنة — مع تاريخ + جرس) ═══ -->
  <div class="dw-card dw-col-1 dw-premium" data-widget="tasks">
    <div class="dw-card-h dw-card-h-tasks">
      <i class="fa-solid fa-list-check"></i>
      <?= $dw_rtl?'مهامي':'My Tasks' ?>
      <span class="dw-badge" id="dwTasksBadge" style="display:none">0</span>
      <button onclick="dwTaskFormOpen()" class="dw-add-btn" title="<?= $dw_rtl?'مهمة جديدة':'New Task' ?>">
        <i class="fa-solid fa-plus"></i>
      </button>
    </div>
    <div class="dw-card-b">
      <!-- نموذج إضافة مهمة/حدث مع تاريخ + جرس + scope toggle -->
      <div class="dw-task-form" id="dwTaskForm" style="display:none">
        <!-- Scope toggle: شخصي (افتراضي) / مؤسسي (لمن عنده صلاحية) -->
        <div class="dw-scope" id="dwScope">
          <div class="dw-scope-opt active personal" data-scope="personal" onclick="dwScopeSet('personal')">
            <span class="dot personal"></span>
            <?= $dw_rtl?'شخصي (لي فقط)':'Personal' ?>
          </div>
          <div class="dw-scope-opt inst <?= $dw_can_inst?'':'disabled' ?>" data-scope="inst" onclick="dwScopeSet('inst')">
            <span class="dot inst"></span>
            <?= $dw_rtl?'مؤسسي (broadcast)':'Institutional' ?>
          </div>
        </div>
        <input type="text" id="dwTaskInput" placeholder="<?= $dw_rtl?'عنوان المهمة/الحدث...':'Title...' ?>" maxlength="200">
        <div class="dw-task-form-row">
          <input type="date" id="dwTaskDate" min="<?= date('Y-m-d') ?>">
          <input type="time" id="dwTaskTime">
          <select id="dwTaskPriority">
            <option value="low"><?= $dw_rtl?'منخفضة':'Low' ?></option>
            <option value="normal" selected><?= $dw_rtl?'عادية':'Normal' ?></option>
            <option value="high"><?= $dw_rtl?'عالية':'High' ?></option>
            <option value="urgent"><?= $dw_rtl?'عاجلة':'Urgent' ?></option>
          </select>
          <select id="dwEventType" style="display:none">
            <option value="meeting"><?= $dw_rtl?'اجتماع':'Meeting' ?></option>
            <option value="inventory"><?= $dw_rtl?'جرد':'Inventory' ?></option>
            <option value="maintenance"><?= $dw_rtl?'صيانة':'Maintenance' ?></option>
            <option value="delivery"><?= $dw_rtl?'توريد':'Delivery' ?></option>
            <option value="backup"><?= $dw_rtl?'نسخ احتياطي':'Backup' ?></option>
            <option value="deadline"><?= $dw_rtl?'موعد نهائي':'Deadline' ?></option>
            <option value="other"><?= $dw_rtl?'آخر':'Other' ?></option>
          </select>
        </div>
        <!-- Audience selector — يظهر فقط للمؤسسي -->
        <div class="dw-audience" id="dwAudience" style="display:none">
          <div class="dw-audience-row">
            <label><input type="radio" name="dw_audience_mode" value="all" checked onchange="dwAudienceToggle()"> <?= $dw_rtl?'الكل (broadcast)':'All users' ?></label>
            <label><input type="radio" name="dw_audience_mode" value="users" onchange="dwAudienceToggle()"> <?= $dw_rtl?'مستخدمين محددين':'Specific users' ?></label>
          </div>
          <div class="dw-audience-pick" id="dwAudiencePick" style="display:none">
            <div style="color:#94a3b8;font-size:11px;text-align:center;padding:10px"><?= $dw_rtl?'جاري التحميل...':'Loading...' ?></div>
          </div>
          <div class="dw-audience-count" id="dwAudienceCount"></div>
        </div>
        <div class="dw-task-form-actions">
          <label class="dw-bell-toggle" id="dwBellLabel" title="<?= $dw_rtl?'تنبيه بالجرس في الموعد':'Bell reminder' ?>">
            <input type="checkbox" id="dwTaskBell" checked>
            <i class="fa-solid fa-bell"></i>
            <span><?= $dw_rtl?'تنبيه':'Remind' ?></span>
          </label>
          <button onclick="dwTaskAdd()" class="dw-btn-primary"><i class="fa-solid fa-plus"></i> <?= $dw_rtl?'إضافة':'Add' ?></button>
          <button onclick="dwTaskFormClose()" class="dw-btn-secondary"><?= $dw_rtl?'إلغاء':'Cancel' ?></button>
        </div>
      </div>
      <button onclick="dwTaskFormOpen()" class="dw-task-quick-add" id="dwTaskQuickAdd">
        <i class="fa-solid fa-plus"></i> <?= $dw_rtl?'مهمة جديدة...':'Add a new task...' ?>
      </button>
      <ul class="dw-task-list" id="dwTaskList">
        <li class="dw-card-loading"><i class="fa-solid fa-spinner"></i></li>
      </ul>
    </div>
  </div>

  <!-- ═══ 2) تقويم مصغر ═══ -->
  <div class="dw-card dw-col-1 dw-premium" data-widget="calendar">
    <div class="dw-card-h">
      <i class="fa-regular fa-calendar"></i>
      <?= $dw_rtl?'تقويم':'Calendar' ?>
      <span style="margin-inline-start:auto;font-size:11px;color:#94a3b8" id="dwEventsCount"></span>
    </div>
    <div class="dw-card-b">
      <div id="dwCalendarBody">
        <div class="dw-card-loading"><i class="fa-solid fa-spinner"></i></div>
      </div>
    </div>
  </div>

</div>

<script>
const DW_API = <?= json_encode(BASE_URL . '/api/dashboard_widgets.php') ?>;
const DW_CSRF = <?= json_encode($dw_csrf) ?>;
const DW_RTL = <?= json_encode($dw_rtl) ?>;
const DW_LABELS = DW_RTL ? {
    loading: 'جاري التحميل...',
    empty: 'لا يوجد',
    add: 'إضافة',
    share: 'مشاركة',
    confirm_delete: 'حذف؟',
    online: 'متصل',
} : {
    loading: 'Loading...',
    empty: 'None',
    add: 'Add',
    share: 'Share',
    confirm_delete: 'Delete?',
    online: 'online',
};

async function dwFetch(action, opts = {}) {
    const url = DW_API + '?action=' + action + (opts.id ? '&id=' + opts.id : '');
    const init = { credentials: 'same-origin', headers: {} };
    if (opts.method === 'POST') {
        init.method = 'POST';
        init.headers['Content-Type'] = 'application/x-www-form-urlencoded';
        init.body = 'csrf_token=' + encodeURIComponent(DW_CSRF) + (opts.body || '');
    }
    try {
        const r = await fetch(url, init);
        return await r.json();
    } catch (e) {
        console.error('widget fetch error', action, e);
        return { error: 'network' };
    }
}

function dwIcon(icon) { return '<i class="fa-solid ' + (icon || 'fa-circle') + '"></i>'; }

function dwRelTime(iso) {
    if (!iso) return '';
    const d = new Date(iso.replace(' ', 'T'));
    const s = Math.floor((Date.now() - d.getTime()) / 1000);
    if (s < 60) return DW_RTL ? 'الآن' : 'now';
    if (s < 3600) return DW_RTL ? `قبل ${Math.floor(s/60)} د` : `${Math.floor(s/60)}m ago`;
    if (s < 86400) return DW_RTL ? `قبل ${Math.floor(s/3600)} س` : `${Math.floor(s/3600)}h ago`;
    return DW_RTL ? `قبل ${Math.floor(s/86400)} ي` : `${Math.floor(s/86400)}d ago`;
}

// ═══ 1) Weather ═══
async function dwLoadWeather() {
    const j = await dwFetch('weather');
    if (j.error) { document.getElementById('dwWeatherBody').innerHTML = '<div class="dw-card-loading">❌ ' + j.error + '</div>'; return; }
    const cities = j.cities || [];
    let alerts_count = 0;
    let html = '<div class="dw-weather">';
    for (const c of cities) {
        const ico = c.icon || 'fa-cloud';
        const temp = c.temp != null ? c.temp + '°C' : '—';
        const desc = (c.description && c.description[0]) ? c.description[0].value : (c.description || '—');
        const feels = c.feels_like != null ? DW_RTL ? `كأنه ${c.feels_like}°` : `feels ${c.feels_like}°` : '';
        const hum = c.humidity != null ? c.humidity + '% 💧' : '';
        const wind = c.wind_kmh != null ? c.wind_kmh + ' km/h 💨' : '';
        let alert_html = '';
        if (c.alerts && c.alerts.length) {
            for (const a of c.alerts) {
                alerts_count++;
                alert_html += `<div class="dw-w-alert ${a.level}">${dwIcon(a.icon)} ${a.msg}</div>`;
            }
        }
        html += `
        <div class="dw-weather-city">
          <div class="dw-w-name"><i class="fa-solid fa-location-dot" style="color:#94a3b8"></i> ${c.city}</div>
          <div class="dw-w-main">
            <div class="dw-w-temp">${temp}</div>
            <div class="dw-w-ico">${dwIcon(ico)}</div>
          </div>
          <div class="dw-w-desc">${desc} ${feels?'· '+feels:''}</div>
          <div class="dw-w-meta"><span>${hum}</span><span>${wind}</span></div>
          ${alert_html}
        </div>`;
    }
    html += '</div>';
    document.getElementById('dwWeatherBody').innerHTML = html;
    const badge = document.getElementById('dwWeatherBadge');
    if (alerts_count > 0) { badge.textContent = alerts_count; badge.style.display = 'inline-block'; badge.style.background = '#fef2f2'; badge.style.color = '#dc2626'; }
    else { badge.style.display = 'none'; }
}

// ═══ 2) Tasks ═══
async function dwLoadTasks() {
    const j = await dwFetch('tasks');
    if (j.error) { document.getElementById('dwTaskList').innerHTML = '<li class="dw-task-empty">❌ ' + j.error + '</li>'; return; }
    const list = (j.active || []).concat((j.completed || []).slice(0, 3));
    const inst = j.institutional || [];
    if (!list.length && !inst.length) {
        document.getElementById('dwTaskList').innerHTML = `<li class="dw-task-empty">${DW_RTL?'لا توجد مهام بعد — أضف واحدة!':'No tasks yet — add one!'}</li>`;
        return;
    }
    let html = '';
    // 1) المهام الشخصية (user_tasks)
    for (const t of list) {
        const done = t.completed ? 'done' : '';
        const cb = t.completed ? 'checked' : '';
        const prio = t.priority || 'normal';
        const prio_label = {urgent: '!', high: '↑', normal: '·', low: '↓'}[prio] || '·';
        const shared = t.is_shared_with_me ? (DW_RTL ? ' (مُشاركة)' : ' (shared)') : '';

        let meta_html = '';
        if (t.due_date) {
            const today = new Date(); today.setHours(0,0,0,0);
            const due = new Date(t.due_date);
            const overdue = !t.completed && due < today;
            const dueClass = overdue ? 'dw-task-overdue' : 'dw-task-due';
            const dateLabel = '📅 ' + t.due_date + (t.due_time ? ' ' + t.due_time : '');
            meta_html += `<span class="${dueClass}">${dateLabel}</span>`;
        }
        if (t.notify_bell) meta_html += `<span class="dw-task-bell" title="${DW_RTL?'تنبيه بالجرس':'Bell reminder'}"><i class="fa-solid fa-bell"></i></span>`;
        if (t.is_shared_with_me) meta_html += `<span style="color:#7c3aed">@${escapeHtml(t.owner_name)}${shared}</span>`;

        html += `<li>
            <input type="checkbox" class="dw-task-cb" ${cb} onchange="dwTaskToggle(${t.id}, this.checked)">
            <div style="flex:1; min-width:0">
                <div class="dw-task-title ${done}">${escapeHtml(t.title)}</div>
                ${meta_html ? `<div class="dw-task-meta">${meta_html}</div>` : ''}
            </div>
            <span class="dw-task-prio ${prio}" title="priority: ${prio}">${prio_label}</span>
            <button class="dw-task-del" onclick="dwTaskDelete(${t.id})" title="Delete"><i class="fa-solid fa-xmark"></i></button>
        </li>`;
    }
    // 2) تذكيراتي المؤسسية (events.related_type IS NULL AND created_by=me)
    if (inst.length) {
        html += `<li class="dw-task-section" style="background:#f0fdf4;padding:6px 10px;margin:8px 0 4px;border-radius:7px;display:flex;align-items:center;gap:6px;border:1px dashed #86efac">
            <span style="color:#16a34a;font-weight:800;font-size:11.5px"><i class="fa-solid fa-bullhorn"></i> ${DW_RTL?'تذكيراتي المؤسسية':'My Broadcasts'}</span>
            <span style="background:#dcfce7;color:#166534;padding:1px 7px;border-radius:9px;font-size:10px;font-weight:800">${inst.length}</span>
        </li>`;
        for (const e of inst) {
            const isAll = e.audience === 'all';
            const audIcon = isAll ? 'fa-globe' : 'fa-user-group';
            const audColor = isAll ? '#16a34a' : '#0891b2';
            const dateLabel = '📅 ' + e.due_date + (e.due_time ? ' ' + e.due_time : '');
            const dateClass = e.is_overdue ? 'dw-task-overdue' : 'dw-task-due';
            const typeIcon = {
                meeting: 'fa-handshake', inventory: 'fa-boxes-stacked', maintenance: 'fa-screwdriver-wrench',
                delivery: 'fa-truck', backup: 'fa-database', deadline: 'fa-flag-checkered', other: 'fa-circle-dot'
            }[e.event_type] || 'fa-circle';
            html += `<li style="background:#fafff9">
                <span style="width:22px;height:22px;border-radius:6px;background:${e.color || '#16a34a'};color:#fff;display:flex;align-items:center;justify-content:center;font-size:10px;flex-shrink:0">
                    <i class="fa-solid ${typeIcon}"></i>
                </span>
                <div style="flex:1; min-width:0">
                    <div class="dw-task-title">${escapeHtml(e.title)}</div>
                    <div class="dw-task-meta">
                        <span class="${dateClass}">${dateLabel}</span>
                        <span style="color:${audColor};font-weight:700" title="${escapeHtml(e.audience)}">
                            <i class="fa-solid ${audIcon}"></i> ${escapeHtml(e.audience_label)}
                        </span>
                    </div>
                </div>
                <button class="dw-task-del" onclick="dwEventDelete(${e.id})" title="${DW_RTL?'حذف':'Delete'}"><i class="fa-solid fa-xmark"></i></button>
            </li>`;
        }
    }
    document.getElementById('dwTaskList').innerHTML = html;
    // Badge — يشمل المهام الشخصية + التذكيرات المؤسسية
    const total = (j.count_active || 0) + (j.count_institutional || 0);
    const badge = document.getElementById('dwTasksBadge');
    if (total > 0) { badge.textContent = total; badge.style.display = 'inline-block'; }
    else { badge.style.display = 'none'; }
}

async function dwEventDelete(id) {
    if (!confirm(DW_LABELS.confirm_delete)) return;
    const r = await dwFetch('delete_event', { method: 'POST', body: '&event_id=' + id });
    if (r.ok) { dwLoadTasks(); dwLoadEvents(); }
    else alert(r.error || 'Error');
}

// ═══ Scope toggle (شخصي / مؤسسي) ═══
let dwCurrentScope = 'personal';
let dwUsersCache = null;

function dwScopeSet(scope) {
    if (scope === 'inst' && !<?= $dw_can_inst ? 'true' : 'false' ?>) {
        alert(DW_RTL ? 'لا تملك صلاحية إنشاء أحداث مؤسسية' : 'No permission for institutional events');
        return;
    }
    dwCurrentScope = scope;
    document.querySelectorAll('.dw-scope-opt').forEach(el => {
        el.classList.toggle('active', el.dataset.scope === scope);
    });
    // تبديل الحقول
    const isInst = scope === 'inst';
    document.getElementById('dwTaskPriority').style.display = isInst ? 'none' : '';
    document.getElementById('dwEventType').style.display = isInst ? '' : 'none';
    document.getElementById('dwAudience').style.display = isInst ? 'block' : 'none';
    document.getElementById('dwBellLabel').style.display = isInst ? 'none' : '';  // المؤسسي = الإشعار هو الهدف
    if (isInst && !dwUsersCache) dwLoadUsersList();
}

function dwAudienceToggle() {
    const mode = document.querySelector('input[name=dw_audience_mode]:checked').value;
    document.getElementById('dwAudiencePick').style.display = mode === 'users' ? 'block' : 'none';
    dwUpdateAudienceCount();
}

function dwUpdateAudienceCount() {
    const mode = document.querySelector('input[name=dw_audience_mode]:checked').value;
    const cnt = document.getElementById('dwAudienceCount');
    if (mode === 'all') {
        cnt.textContent = DW_RTL ? 'سيتم إرسال إشعار لكل المستخدمين النشطين' : 'Will notify all active users';
    } else {
        const picked = document.querySelectorAll('#dwAudiencePick input[type=checkbox]:checked').length;
        cnt.textContent = DW_RTL ? `سيتم إرسال إشعار لـ ${picked} مستخدم` : `Will notify ${picked} user(s)`;
    }
}

async function dwLoadUsersList() {
    const j = await dwFetch('users_list');
    if (j.error) { document.getElementById('dwAudiencePick').innerHTML = '<div style="color:#dc2626;padding:8px;font-size:11px">❌ ' + j.error + '</div>'; return; }
    dwUsersCache = j.users || [];
    let html = '';
    for (const u of dwUsersCache) {
        const roleBadges = u.roles.map(r => {
            const cls = u.is_admin ? 'badge admin' : 'badge';
            const label = r === 'admin' ? '👑' : (r === 'executive' ? '⭐' : (r === 'dept_manager' ? '🏢' : (r === 'section_manager' ? '🔹' : (r === 'site_manager' ? '🔧' : ''))));
            return `<span class="${cls}">${label} ${r}</span>`;
        }).join(' ');
        html += `<label><input type="checkbox" value="${u.id}" onchange="dwUpdateAudienceCount()">
            <span>${escapeHtml(u.full_name || u.username)}</span> ${roleBadges}
        </label>`;
    }
    document.getElementById('dwAudiencePick').innerHTML = html;
    dwUpdateAudienceCount();
}

function dwTaskFormOpen() {
    document.getElementById('dwTaskForm').style.display = 'flex';
    document.getElementById('dwTaskQuickAdd').style.display = 'none';
    setTimeout(() => document.getElementById('dwTaskInput').focus(), 100);
}
function dwTaskFormClose() {
    document.getElementById('dwTaskForm').style.display = 'none';
    document.getElementById('dwTaskQuickAdd').style.display = 'flex';
    document.getElementById('dwTaskInput').value = '';
    document.getElementById('dwTaskDate').value = '';
    document.getElementById('dwTaskTime').value = '';
    document.getElementById('dwTaskPriority').value = 'normal';
    document.getElementById('dwTaskBell').checked = true;
    dwScopeSet('personal');  // reset to personal
    document.querySelector('input[name=dw_audience_mode][value=all]').checked = true;
    dwAudienceToggle();
}

async function dwTaskAdd() {
    const title = document.getElementById('dwTaskInput').value.trim();
    if (!title) { document.getElementById('dwTaskInput').focus(); return; }
    const date = document.getElementById('dwTaskDate').value;
    const time = document.getElementById('dwTaskTime').value;

    let body = '&title=' + encodeURIComponent(title);
    let endpoint, okReload;
    if (dwCurrentScope === 'inst') {
        // مؤسسي → api_add_event
        // ⚠️ السيرفر في api_add_event يقرأ event_date و event_time (ليس due_date)
        if (!date) { alert(DW_RTL ? 'التاريخ مطلوب للحدث المؤسسي' : 'Date required for institutional event'); return; }
        if (date) body += '&event_date=' + date;
        if (time) body += '&event_time=' + time;
        const event_type = document.getElementById('dwEventType').value;
        const mode = document.querySelector('input[name=dw_audience_mode]:checked').value;
        let audience = 'all';
        if (mode === 'users') {
            const ids = Array.from(document.querySelectorAll('#dwAudiencePick input[type=checkbox]:checked')).map(c => c.value);
            if (ids.length === 0) { alert(DW_RTL ? 'حدد مستخدم واحد على الأقل' : 'Select at least one user'); return; }
            audience = 'users:' + ids.join(',');
        }
        body += '&event_type=' + event_type + '&audience=' + audience;
        endpoint = 'add_event';
        okReload = 'events';
    } else {
        // شخصي → api_add_task
        if (date) body += '&due_date=' + date;
        if (time) body += '&due_time=' + time;
        const prio = document.getElementById('dwTaskPriority').value;
        const bell = document.getElementById('dwTaskBell').checked ? 1 : 0;
        body += '&priority=' + prio + '&notify_bell=' + bell;
        endpoint = 'add_task';
        okReload = 'tasks';
    }

    const r = await dwFetch(endpoint, { method: 'POST', body: body });
    if (r.ok) {
        dwTaskFormClose();
        if (okReload === 'tasks') dwLoadTasks();
        else if (okReload === 'events') { dwLoadEvents(); dwLoadTasks(); }
    } else { alert(r.error || 'Error'); }
}

async function dwTaskToggle(id, completed) {
    const r = await dwFetch('complete_task', { method: 'POST', body: '&task_id=' + id + '&completed=' + (completed?1:0) });
    if (!r.ok) alert(r.error || 'Error');
    dwLoadTasks();
}

async function dwTaskDelete(id) {
    if (!confirm(DW_LABELS.confirm_delete)) return;
    const r = await dwFetch('delete_task', { method: 'POST', body: '&task_id=' + id });
    if (r.ok) dwLoadTasks();
}

// ═══ 3) Activity ═══
async function dwLoadActivity() {
    const j = await dwFetch('activity');
    const items = (j.items || []);
    if (!items.length) {
        document.getElementById('dwActivityList').innerHTML = `<li class="dw-task-empty">${DW_LABELS.empty}</li>`;
        return;
    }
    let html = '';
    for (const a of items.slice(0, 12)) {
        const icon = a.action.includes('login') ? 'fa-right-to-bracket' :
                     a.action.includes('logout') ? 'fa-right-from-bracket' :
                     a.action.includes('custody') ? 'fa-handshake' :
                     a.action.includes('task') ? 'fa-list-check' :
                     a.action.includes('password') ? 'fa-lock' :
                     a.action.includes('unauthorized') ? 'fa-ban' : 'fa-circle';
        const cls = a.action.includes('unauthorized') ? 'style="background:#fee2e2;color:#dc2626"' : '';
        html += `<li>
            <div class="dw-feed-dot" ${cls}>${dwIcon(icon)}</div>
            <div style="flex:1; min-width:0">
                <div class="dw-feed-action">${escapeHtml(a.action)}${a.target?' <span style="color:#7c3aed">→ '+escapeHtml(a.target)+'</span>':''}</div>
                <div class="dw-feed-meta">${dwRelTime(a.created_at)}</div>
            </div>
        </li>`;
    }
    document.getElementById('dwActivityList').innerHTML = html;
}

// ═══ 4) Calendar (Mini) ═══
async function dwLoadEvents() {
    const j = await dwFetch('events');
    const events = (j.events || []);
    const byDate = j.by_date || {};

    // بناء تقويم الشهر الحالي
    const now = new Date();
    const year = now.getFullYear();
    const month = now.getMonth();
    const firstDay = new Date(year, month, 1);
    const lastDay = new Date(year, month + 1, 0);
    const monthName = firstDay.toLocaleDateString(DW_RTL ? 'ar-SA' : 'en-US', { month: 'long', year: 'numeric' });
    const daysInMonth = lastDay.getDate();
    const startDayOfWeek = firstDay.getDay();  // 0=Sun
    const today = now.getDate();

    const dayNames = DW_RTL ? ['أحد','اثن','ثلا','أرب','خمي','جمع','سبت'] : ['Sun','Mon','Tue','Wed','Thu','Fri','Sat'];
    let html = `<div class="dw-cal-month">${monthName}</div>`;
    html += '<div class="dw-cal-grid">';
    for (const d of dayNames) html += `<div class="dw-cal-hdr">${d}</div>`;
    for (let i = 0; i < startDayOfWeek; i++) html += '<div></div>';
    for (let d = 1; d <= daysInMonth; d++) {
        const dateStr = `${year}-${String(month+1).padStart(2,'0')}-${String(d).padStart(2,'0')}`;
        const evs = byDate[dateStr] || [];
        const has = evs.length > 0;
        const cls = `dw-cal-day ${has?'has-event':''} ${d===today?'today':''}`;
        // tooltip مع badge للنوع
        let titleLines = [];
        let dotCls = 'institutional';  // default أخضر (مؤسسي)
        let hasReminder = false;
        for (const e of evs) {
            const isPersonal = e.related_type === 'task';
            const isReminder = e.event_type === 'task_reminder';
            let badge = '';
            if (isReminder) { badge = '🔔 تذكير شخصي'; hasReminder = true; dotCls = 'reminder'; }
            else if (isPersonal) { badge = '🟣 شخصي'; if (dotCls === 'institutional') dotCls = 'personal'; }
            else { badge = '🟢 مؤسسي'; }
            titleLines.push(badge + ' — ' + e.title);
        }
        const title = titleLines.join('\n');
        html += `<div class="${cls}" title="${escapeHtml(title)}">
            <div class="dw-cal-num">${d}</div>
            ${has?`<div class="dw-cal-dot ${dotCls}"></div>`:''}
        </div>`;
    }
    html += '</div>';
    document.getElementById('dwCalendarBody').innerHTML = html;
    document.getElementById('dwEventsCount').textContent = events.length + (DW_RTL?' حدث':' events');
}

// ═══ 5) Alerts ═══
async function dwLoadAlerts() {
    const j = await dwFetch('alerts');
    const items = (j.items || []);
    if (!items.length) {
        document.getElementById('dwAlertsList').innerHTML = `<li class="dw-task-empty">${DW_LABELS.empty} 🎉</li>`;
        return;
    }
    let html = '';
    for (const a of items) {
        const url = a.url ? `onclick="window.open('${escapeHtml(a.url)}','_blank')" style="cursor:pointer"` : '';
        html += `<li ${url}>
            <div class="dw-alert-ico ${a.level}">${dwIcon(a.icon)}</div>
            <div style="flex:1; min-width:0">
                <div class="dw-alert-title">${escapeHtml(a.title)}</div>
                <div class="dw-alert-meta">${escapeHtml(a.meta || '')}</div>
            </div>
        </li>`;
    }
    document.getElementById('dwAlertsList').innerHTML = html;
    const badge = document.getElementById('dwAlertsBadge');
    if (items.length > 0) { badge.textContent = items.length; badge.style.display = 'inline-block'; }
    else { badge.style.display = 'none'; }
}

function escapeHtml(s) {
    if (s == null) return '';
    return String(s).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
}

function dwReload(widget) {
    if (widget === 'weather') dwLoadWeather();
}

// ═══ Initial load + live refresh (60s) ═══
dwLoadTasks();
dwLoadEvents();

setInterval(() => {
    dwLoadTasks();
    dwLoadEvents();
}, 60000);

// Enter key في input المهمة
document.addEventListener('DOMContentLoaded', () => {
    const inp = document.getElementById('dwTaskInput');
    if (inp) inp.addEventListener('keydown', e => { if (e.key === 'Enter') dwTaskAdd(); });
});
</script>
