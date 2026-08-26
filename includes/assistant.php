<?php if (!defined('ASST_LOADED')): define('ASST_LOADED', 1);
/* ═══ ترحيب حسب الوقت واسم المستخدم ═══ */
$__cu   = current_user();
$__name = trim($__cu['full_name'] ?? '');
$__hr   = (int)date('G');
$__sal  = ($__hr >= 5 && $__hr < 12) ? 'صباح الخير' : 'مساء الخير';
$__greet = $__sal . ($__name ? '، ' . $__name : '') . '. أنا المساعد الآلي لإدارة الأصول؛ أزوّدك بالمؤشرات اللحظية وأرشدك خطوةً بخطوة لأي إجراء. كيف أخدمك؟';
?>
<style>
#as-fab{position:fixed;bottom:86px;inset-inline-start:22px;z-index:999;width:52px;height:52px;border-radius:50%;
  border:none;background:linear-gradient(135deg,#4f46e5,#7c3aed);color:#fff;font-size:20px;cursor:pointer;
  box-shadow:0 8px 24px rgba(79,70,229,.35);transition:.25s}
#as-fab:hover{transform:scale(1.06)}
#as-box{position:fixed;bottom:150px;inset-inline-start:22px;z-index:999;width:350px;max-height:480px;
  display:none;flex-direction:column;background:#ffffff;color:#0f172a;
  border:1px solid #e2e8f0;border-radius:16px;overflow:hidden;box-shadow:0 20px 50px rgba(2,8,20,.18)}
#as-box.open{display:flex}
#as-head{padding:14px 16px;display:flex;gap:10px;align-items:center;background:#f8fafc;border-bottom:1px solid #e2e8f0}
#as-head .av{width:38px;height:38px;border-radius:10px;background:linear-gradient(135deg,#4f46e5,#7c3aed);
  display:flex;align-items:center;justify-content:center;font-size:16px;color:#fff}
#as-head b{font-size:14px;color:#0f172a}
#as-head small{display:block;font-size:10.5px;color:#16a34a;font-weight:700}
#as-chips{display:flex;gap:6px;overflow-x:auto;padding:10px 12px;border-bottom:1px solid #eef2f7;background:#fff}
#as-chips::-webkit-scrollbar{height:4px}#as-chips::-webkit-scrollbar-thumb{background:#cbd5e1;border-radius:99px}
#as-chips button{flex-shrink:0;background:#eef2ff;border:1px solid #c7d2fe;color:#4338ca;
  font-size:11px;font-weight:700;padding:6px 12px;border-radius:99px;cursor:pointer;font-family:'Tajawal'}
#as-chips button:hover{background:#e0e7ff}
#as-chat{flex:1;overflow-y:auto;padding:14px;display:flex;flex-direction:column;gap:10px;background:#fff}
#as-chat::-webkit-scrollbar{width:5px}#as-chat::-webkit-scrollbar-thumb{background:#cbd5e1;border-radius:99px}
.as-msg{max-width:85%;padding:10px 14px;border-radius:12px;font-size:13px;line-height:1.8;white-space:pre-line}
.as-msg.bot{background:#f1f5f9;color:#0f172a;align-self:flex-start;border-start-start-radius:4px}
.as-msg.user{background:linear-gradient(135deg,#4f46e5,#6366f1);color:#fff;align-self:flex-end;border-start-end-radius:4px}
.as-msg a{display:inline-block;margin-top:8px;padding:6px 12px;border-radius:8px;background:#e0e7ff;
  color:#4338ca;font-weight:800;font-size:11.5px;text-decoration:none}
#as-inrow{display:flex;gap:8px;padding:12px;border-top:1px solid #e2e8f0;background:#f8fafc}
#as-in{flex:1;background:#fff;border:1px solid #cbd5e1;border-radius:10px;padding:10px 12px;
  color:#0f172a;font-family:'Tajawal';font-size:13px;outline:none}
#as-in:focus{border-color:#6366f1}
#as-send{width:42px;border:none;border-radius:10px;background:linear-gradient(135deg,#4f46e5,#7c3aed);color:#fff;cursor:pointer}
</style>

<button id="as-fab" title="المساعد الذكي"><i class="fa-solid fa-robot"></i></button>
<div id="as-box">
  <div id="as-head">
    <div class="av"><i class="fa-solid fa-robot"></i></div>
    <div><b>المساعد الذكي</b><small>● متصل — بيانات لحظية</small></div>
  </div>
  <div id="as-chips"></div>
  <div id="as-chat"></div>
  <div id="as-inrow">
    <input id="as-in" placeholder="اكتب سؤالك هنا...">
    <button id="as-send"><i class="fa-solid fa-paper-plane"></i></button>
  </div>
</div>

<script>
(function(){
  const BASE=<?= json_encode(BASE_URL) ?>;
  const GREET=<?= json_encode($__greet, JSON_UNESCAPED_UNICODE) ?>;
  const box=document.getElementById('as-box'), fab=document.getElementById('as-fab'),
        chat=document.getElementById('as-chat'), inp=document.getElementById('as-in'),
        send=document.getElementById('as-send'), chips=document.getElementById('as-chips');

  const CHIPS=['كم أصل حرج؟','كيف أرفع بلاغاً؟','فجوة التمويل؟','الصيانة المتأخرة؟',
               'كيف أنقل عهدة؟','البلاغات المفتوحة؟','كيف أضيف أصلاً؟','أصول بدون عهدة؟','ماذا تستطيع؟'];

  function add(txt,who,link){
    const d=document.createElement('div'); d.className='as-msg '+who; d.textContent=txt;
    if(link){ const a=document.createElement('a'); a.href=link.url; a.target='_blank'; a.textContent='📊 '+link.label; d.appendChild(a); }
    chat.appendChild(d); chat.scrollTop=chat.scrollHeight;
  }
  function ask(){
    const q=inp.value.trim(); if(!q) return;
    add(q,'user'); inp.value='';
    add('...','bot'); const think=chat.lastChild;
    fetch(BASE+'/api/assistant.php?q='+encodeURIComponent(q))
      .then(r=>r.json())
      .then(o=>{ think.remove(); add(o.text||'...', 'bot', o.link); })
      .catch(()=>{ think.remove(); add('تعذر الاتصال بالخادم.','bot'); });
  }

  CHIPS.forEach(t=>{ const b=document.createElement('button'); b.textContent=t; b.onclick=()=>{ inp.value=t; ask(); }; chips.appendChild(b); });
  fab.onclick=()=>{ box.classList.toggle('open'); if(box.classList.contains('open') && !chat.children.length) add(GREET,'bot'); };
  send.onclick=ask;
  inp.addEventListener('keydown',e=>{ if(e.key==='Enter') ask(); });
})();
</script>
<?php endif; ?>