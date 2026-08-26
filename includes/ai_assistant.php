<?php if (!defined('AI_ASSIST_LOADED')): define('AI_ASSIST_LOADED', 1);
/* تضمين آمن: لا يقتل الموقع إن غاب الملف أو الجدول */
if (file_exists(__DIR__.'/assistant_kb.php')) require_once __DIR__.'/assistant_kb.php';
if (!function_exists('kb_match')) { function kb_match($pdo,$q){ return null; } }

$__cu=current_user(); $__name=trim($__cu['full_name']??'');
$__chips=['كم أصل حرج؟','كيف أرفع بلاغاً؟','فئات الحساسية؟','دورة دخول الأصول؟'];
try{
  $kw=$pdo->query("SELECT keywords FROM assistant_knowledge WHERE is_active=1 ORDER BY sort_order,id LIMIT 8")->fetchAll(PDO::FETCH_COLUMN);
  if($kw){ $m=[]; foreach($kw as $k){ $f=trim(explode(',',$k)[0]??''); if($f!=='')$m[]=$f.'؟'; } if($m)$__chips=$m; }
}catch(Throwable $e){ /* تجاهل — الجدول قد لا يكون موجوداً بعد */ }
?>
<style>
#aa-fab{position:fixed;bottom:20px;left:20px;z-index:1200;width:54px;height:54px;border-radius:50%;
  border:none;background:linear-gradient(135deg,#6366f1,#8b5cf6);color:#fff;font-size:21px;cursor:pointer;
  box-shadow:0 8px 24px rgba(99,102,241,.4);transition:.25s}
#aa-fab:hover{transform:scale(1.06)}
#aa-panel{position:fixed;top:70px;bottom:90px;left:16px;width:370px;max-width:calc(100vw - 32px);z-index:1201;
  display:flex;flex-direction:column;background:#fff;color:#0f172a;
  border:1px solid #e2e8f0;border-radius:18px;box-shadow:0 20px 60px rgba(2,8,20,.2);overflow:hidden;
  transform:translateX(-120%);transition:transform .35s cubic-bezier(.2,.9,.3,1)}
#aa-head{border-radius:18px 18px 0 0}
#aa-inrow{border-radius:0 0 18px 18px}
#aa-panel.open{transform:translateX(0)}
#aa-head{padding:16px;display:flex;gap:12px;align-items:center;flex-shrink:0;
  background:linear-gradient(135deg,#6366f1,#8b5cf6);color:#fff}
#aa-head .av{width:42px;height:42px;border-radius:12px;background:rgba(255,255,255,.2);
  display:flex;align-items:center;justify-content:center;font-size:18px;flex-shrink:0}
#aa-head b{font-size:15px}
#aa-head small{display:block;font-size:11px;opacity:.9}
#aa-close{margin-inline-start:auto;width:34px;height:34px;border-radius:9px;border:1px solid rgba(255,255,255,.3);
  background:rgba(255,255,255,.1);color:#fff;cursor:pointer}
#aa-chips{display:flex;flex-wrap:wrap;gap:6px;padding:10px 12px;border-bottom:1px solid #eef2f7;flex-shrink:0}
#aa-chips button{background:#eef2ff;border:1px solid #c7d2fe;color:#4338ca;
  font-size:11px;font-weight:700;padding:6px 12px;border-radius:99px;cursor:pointer;font-family:'Tajawal'}
#aa-chat{flex:1;overflow-y:auto;padding:16px;display:flex;flex-direction:column;gap:10px}
#aa-chat::-webkit-scrollbar{width:5px}#aa-chat::-webkit-scrollbar-thumb{background:#cbd5e1;border-radius:99px}
.aa-msg{max-width:88%;padding:11px 14px;border-radius:12px;font-size:13px;line-height:1.8;white-space:pre-line}
.aa-msg.bot{background:#f1f5f9;color:#0f172a;align-self:flex-start}
.aa-msg.user{background:linear-gradient(135deg,#6366f1,#8b5cf6);color:#fff;align-self:flex-end}
.aa-msg a{display:inline-block;margin-top:8px;padding:6px 12px;border-radius:8px;background:#e0e7ff;
  color:#4338ca;font-weight:800;font-size:11.5px;text-decoration:none}
#aa-inrow{display:flex;gap:8px;padding:12px;border-top:1px solid #e2e8f0;background:#f8fafc;flex-shrink:0}
#aa-in{flex:1;background:#fff;border:1px solid #cbd5e1;border-radius:10px;padding:11px 12px;
  color:#0f172a;font-family:'Tajawal';font-size:13px;outline:none}
#aa-send{width:44px;border:none;border-radius:10px;background:linear-gradient(135deg,#6366f1,#8b5cf6);color:#fff;cursor:pointer}
</style>

<button id="aa-fab" title="المساعد الذكي"><i class="fa-solid fa-robot"></i></button>
<div id="aa-panel">
  <div id="aa-head">
    <div class="av"><i class="fa-solid fa-robot"></i></div>
    <div><b>المساعد الذكي</b><small>● متصل — جاهز للمساعدة</small></div>
    <button id="aa-close"><i class="fa-solid fa-xmark"></i></button>
  </div>
  <div id="aa-chips"></div>
  <div id="aa-chat"></div>
  <div id="aa-inrow">
    <input id="aa-in" placeholder="اكتب سؤالك هنا...">
    <button id="aa-send"><i class="fa-solid fa-paper-plane"></i></button>
  </div>
</div>

<script>
(function(){
  const BASE=<?= json_encode(BASE_URL) ?>;
  const NAME=<?= json_encode($__name, JSON_UNESCAPED_UNICODE) ?>;
  const CHIPS=<?= json_encode($__chips, JSON_UNESCAPED_UNICODE) ?>;
  const hr=new Date().getHours();
  const sal=(hr>=5&&hr<12)?'صباح الخير':'مساء الخير';
  const GREET=sal+(NAME?'، '+NAME:'')+'. أنا المساعد الآلي لإدارة الأصول؛ كيف يمكنني مساعدتك اليوم؟';
  const panel=document.getElementById('aa-panel'), fab=document.getElementById('aa-fab'),
        cls=document.getElementById('aa-close'), chat=document.getElementById('aa-chat'),
        inp=document.getElementById('aa-in'), send=document.getElementById('aa-send'),
        chips=document.getElementById('aa-chips');
  function add(txt,who,link){
    const d=document.createElement('div'); d.className='aa-msg '+who; d.textContent=txt;
    if(link){ const a=document.createElement('a'); a.href=link.url; a.target='_blank'; a.textContent='📊 '+link.label; d.appendChild(a); }
    chat.appendChild(d); chat.scrollTop=chat.scrollHeight;
  }
  function ask(){
    const q=inp.value.trim(); if(!q) return;
    add(q,'user'); inp.value='';
    add('...','bot'); const think=chat.lastChild;
    fetch(BASE+'/api/assistant.php?q='+encodeURIComponent(q))
      .then(r=>r.json()).then(o=>{ think.remove(); add(o.text||'...','bot',o.link); })
      .catch(()=>{ think.remove(); add('تعذر الاتصال.','bot'); });
  }
  CHIPS.forEach(t=>{ const b=document.createElement('button'); b.textContent=t; b.onclick=()=>{ inp.value=t; ask(); }; chips.appendChild(b); });
  fab.onclick=()=>{ const open=panel.classList.toggle('open'); if(open&&!chat.children.length) add(GREET,'bot'); };
  cls.onclick=()=>panel.classList.remove('open');
  send.onclick=ask;
  inp.addEventListener('keydown',e=>{ if(e.key==='Enter') ask(); });
})();
</script>
<?php endif; ?>