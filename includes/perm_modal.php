<?php /* includes/perm_modal.php — Modal الصلاحية المشترك (يُضمَّن قبل </body>) */ ?>
<style>
.pm-ov{position:fixed;inset:0;background:rgba(0,0,0,.52);backdrop-filter:blur(5px);-webkit-backdrop-filter:blur(5px);z-index:9000;display:flex;align-items:center;justify-content:center;padding:20px;opacity:0;transition:opacity .2s ease}
.pm-ov:not([hidden]){opacity:1}
.pm-ov[hidden]{display:none!important}
.pm-card{background:#fff;border-radius:22px;padding:40px 36px;max-width:420px;width:100%;text-align:center;transform:scale(.88) translateY(20px);transition:transform .28s cubic-bezier(.34,1.56,.64,1);box-shadow:0 28px 70px rgba(0,0,0,.16),0 8px 24px rgba(0,0,0,.08)}
.pm-ov:not([hidden]) .pm-card{transform:scale(1) translateY(0)}
.pm-ico-wrap{width:80px;height:80px;background:linear-gradient(135deg,#fef2f2,#fee2e2);border:2px solid #fecaca;border-radius:20px;display:flex;align-items:center;justify-content:center;margin:0 auto 20px}
.pm-ico{font-size:38px;color:#dc2626}
.pm-badge{font-size:11px;font-weight:700;color:#dc2626;letter-spacing:.07em;text-transform:uppercase;margin-bottom:10px}
.pm-title{font-size:21px;font-weight:800;color:#0f172a;margin-bottom:8px}
.pm-desc{font-size:13.5px;color:#64748b;line-height:1.7;margin-bottom:20px}
.pm-action-pill{display:inline-flex;align-items:center;gap:6px;font-size:12px;font-weight:600;background:#f1f5f9;color:#334155;border:1.5px solid #e2e8f0;border-radius:50px;padding:5px 14px;margin-bottom:24px}
.pm-action-pill i{color:#94a3b8;font-size:12px}
.pm-footer{display:flex;justify-content:center;gap:8px}
.pm-btn{height:44px;padding:0 24px;border-radius:12px;border:none;cursor:pointer;font-size:14px;font-weight:500;transition:all .15s}
.pm-btn-ok{background:linear-gradient(135deg,#1565C0,#003c8f);color:#fff;box-shadow:0 4px 12px rgba(21,101,192,.3)}
.pm-btn-ok:hover{transform:translateY(-1px);box-shadow:0 8px 20px rgba(21,101,192,.4)}
</style>

<div id="permModal" class="pm-ov" role="dialog" aria-modal="true"
     aria-labelledby="pmTitle" hidden
     onclick="if(event.target===this)PMSH.perm.close()">
  <div class="pm-card" style="font-family:'Tajawal',sans-serif">
    <div class="pm-ico-wrap" aria-hidden="true">
      <i class="fa-solid fa-shield-exclamation pm-ico"></i>
    </div>
    <p class="pm-badge">403 &mdash; Forbidden</p>
    <h2 class="pm-title"  id="pmTitle"></h2>
    <p class="pm-desc"    id="pmDesc"></p>
    <div class="pm-action-pill" id="pmAction" aria-hidden="true">
      <i class="fa-solid fa-lock"></i>
      <span id="pmActionTxt"></span>
    </div>
    <div class="pm-footer">
      <button class="pm-btn pm-btn-ok" id="pmOkBtn" onclick="PMSH.perm.close()"></button>
    </div>
  </div>
</div>

<script>
window.PMSH = window.PMSH || {};
PMSH.perm = (function(){
  const rtl = () => document.documentElement.dir === 'rtl';

  function show(actionAr, actionEn) {
    const r   = rtl();
    const act = r ? actionAr : (actionEn || actionAr);
    document.getElementById('pmTitle').textContent     = r ? 'ليس لديك صلاحية' : 'Access Denied';
    document.getElementById('pmDesc').textContent      = r
      ? 'ليس لديك الصلاحية اللازمة لتنفيذ هذا الإجراء. تواصل مع مدير النظام لطلب الصلاحيات المطلوبة.'
      : "You don't have permission to perform this action. Contact your system administrator to request access.";
    document.getElementById('pmActionTxt').textContent = act;
    document.getElementById('pmOkBtn').textContent     = r ? 'حسناً، فهمت' : 'Got it';
    document.getElementById('permModal').removeAttribute('hidden');
    document.getElementById('pmOkBtn').focus();
    document.body.style.overflow = 'hidden';
  }

  function close() {
    document.getElementById('permModal').setAttribute('hidden','');
    document.body.style.overflow = '';
  }

  document.addEventListener('keydown', e => { if (e.key === 'Escape') close(); });
  return { show, close };
})();
// Global shorthand
function showPermModal(ar, en) { PMSH.perm.show(ar, en); }
</script>