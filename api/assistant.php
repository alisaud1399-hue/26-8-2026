<?php
/**
 * api/assistant.php — عقل المساعد الذكي
 * الأولوية: قاعدة المعرفة (تُدار من شاشة الإعدادات) ← الإرشاد ← ردود حية مقيّدة بالصلاحيات
 */
require_once dirname(__DIR__) . '/config.php';
if (file_exists(dirname(__DIR__).'/includes/assistant_kb.php')) require_once dirname(__DIR__).'/includes/assistant_kb.php';
if (!function_exists('kb_match')) { function kb_match($pdo,$q){ return null; } }
header('Content-Type: application/json; charset=utf-8');
if (!current_user()) { echo json_encode(['text'=>'غير مصرح']); exit; }

$q = trim($_GET['q'] ?? '');
$norm = function($s){ $s=mb_strtolower($s);
  $s=preg_replace('/[\x{064B}-\x{0652}]/u','',$s);
  $s=str_replace(['أ','إ','آ'],'ا',$s); $s=str_replace('ة','ه',$s); $s=str_replace('ى','ي',$s);
  return $s; };
$nq=$norm($q);
$has=function($arr) use($nq){ foreach($arr as $w) if(mb_strpos($nq,$w)!==false) return true; return false; };
$c=function($sql) use($pdo){ try{ return $pdo->query($sql)->fetchColumn(); }catch(Throwable $e){ return 0; } };
$R=BASE_URL; $text=''; $link=null;

/* ═══ بوابة الصلاحيات ═══ */
$need=function($codes){ foreach((array)$codes as $cd){ if(can($cd,'view')) return true; } return false; };
$DENY="عذراً، لا تملك الصلاحية اللازمة لعرض هذا المؤشر. يمكنني مع ذلك إرشادك تشغيلياً لأي إجراء.";

/* ═══ 1) قاعدة المعرفة (الأولوية — تُدار بدون كود) ═══ */
$db = kb_match($pdo, $q);
if ($db !== null) { echo json_encode(['text'=>$db,'link'=>null]); exit; }

/* ═══ 2) الدليل الإرشادي (احتياطي، متاح للجميع) ═══ */
$isGuide=$has(['كيف','طريقه','خطوات','علمني','وجهني','اعمل','اسوي','وش اسوي']);
if ($isGuide) {
  if ($has(['بلاغ','شكوي'])) { $text="لرفع بلاغ:\n1) «البلاغات» ← «رفع بلاغ».\n2) اربط الأصل بالتاج/السيريال.\n3) حدد الأولوية والوصف.\n4) أرسل."; $link=['label'=>'رفع بلاغ','url'=>$R.'/complaints/form.php']; }
  elseif ($has(['اصل','اصول'])) { $text="لإضافة أصل:\n1) «الأصول» ← «إضافة أصل».\n2) أدخل التاج والسيريال والفئة والقسم.\n3) حدد الحساسية والقيمة.\n4) حفظ."; $link=['label'=>'إضافة أصل','url'=>$R.'/assets/form.php']; }
  elseif ($has(['عهده'])) { $text="لنقل عهدة:\n1) «توزيع العهدة».\n2) اختر الأصل والموظف.\n3) تأكيد التوقيع.\n4) يُحدَّث السجل."; $link=['label'=>'توزيع عهدة','url'=>$R.'/installation/custody.php']; }
  elseif ($has(['جرد'])) { $text="لبدء جرد:\n1) «الجرد» ← «جلسة جديدة».\n2) الأعضاء والأقسام.\n3) المسح بالباركود.\n4) اعتماد الفروقات."; $link=['label'=>'الجرد','url'=>$R.'/inventory/index.php']; }
  elseif ($has(['استلام'])) { $text="لمحضر استلام:\n1) «الاستلام» ← «محضر استلام».\n2) ربط أمر الشراء والبنود.\n3) إرفاق واعتماد."; $link=['label'=>'محضر استلام','url'=>$R.'/receiving/form.php']; }
  elseif ($has(['تقرير','تصدير','حفظ'])) { $text="لإصدار تقرير:\n1) افتح تقريراً.\n2) فلاتر.\n3) حفظ/تصدير.\n4) مشاركة بالرابط."; $link=['label'=>'التقارير','url'=>$R.'/reports/index.php']; }
  else { $text="أرشدك لأي عملية: رفع بلاغ، إضافة أصل، نقل عهدة، جرد، استلام، أو تقرير."; }
  echo json_encode(['text'=>$text,'link'=>$link]); exit;
}

/* ═══ 3) كيان: قسم معين ═══ */
$dept=null;
try{ foreach($pdo->query("SELECT id,name FROM departments") as $d){ if(mb_strpos($nq,$norm($d['name']))!==false){ $dept=$d; break; } } }catch(Throwable $e){}

/* ═══ 4) الردود الرقمية (مقيّدة بالصلاحية) ═══ */
if ($has(['مساعده','ماذا تستطيع','قائمه'])) { $text="أحلل المؤشرات (حسب صلاحياتك): الأصول، البلاغات، الصيانة، الجرد، العهدة، التمويل، التخلص، الاستلام. وأرشدك خطوة-بخطوة لأي عملية."; }
elseif ($dept && $has(['حرج'])) { if(!$need(['reports.risk','reports.assets'])){$text=$DENY;}else{ $n=(int)$c("SELECT COUNT(*) FROM assets WHERE department_id={$dept['id']} AND risk_band='critical'"); $text="قسم {$dept['name']} لديه $n أصلاً حرجاً."; $link=['label'=>'المخاطر','url'=>$R.'/reports/risk/distribution.php']; } }
elseif ($dept && $has(['بلاغ','شكوي'])) { if(!$need('reports.complaints')){$text=$DENY;}else{ $n=(int)$c("SELECT COUNT(*) FROM complaints c JOIN assets a ON a.id=c.asset_id WHERE a.department_id={$dept['id']} AND c.status NOT IN ('closed','resolved','cancelled')"); $text="قسم {$dept['name']} لديه $n بلاغاً مفتوحاً."; } }
elseif ($has(['فجوه','تمويل'])) { if(!$need(['reports.risk','reports.assets'])){$text=$DENY;}else{ $v=(float)$c("SELECT SUM(funding_gap) FROM assets WHERE funding_gap>0"); $text="فجوة التمويل المقدّرة ".number_format($v,0)." ريال."; $link=['label'=>'المخاطر','url'=>$R.'/reports/risk/distribution.php']; } }
elseif ($has(['صيانة']) && $has(['متاخر','تاخر'])) { if(!$need('reports.maintenance')){$text=$DENY;}else{ $n=(int)$c("SELECT COUNT(*) FROM pm_schedules WHERE is_active=1 AND next_due<CURDATE()"); $text="توجد $n خطة صيانة وقائية متأخرة."; $link=['label'=>'الصيانة','url'=>$R.'/reports/maintenance/overview.php']; } }
elseif ($has(['حرج']) && $has(['اصل','اصول','خطر'])) { if(!$need(['reports.risk','reports.assets'])){$text=$DENY;}else{ $n=(int)$c("SELECT COUNT(*) FROM assets WHERE risk_band='critical'"); $text="على مستوى المستشفى: $n أصلاً ضمن النطاق الحرج."; $link=['label'=>'الخريطة الرقمية','url'=>$R.'/reports/risk/twin.php']; } }
elseif ($has(['بلاغ','شكوي'])) { if(!$need('reports.complaints')){$text=$DENY;}else{ $o=(int)$c("SELECT COUNT(*) FROM complaints WHERE status NOT IN ('closed','resolved','cancelled')"); $cr=(int)$c("SELECT COUNT(*) FROM complaints WHERE priority='critical' AND status NOT IN ('closed','resolved','cancelled')"); $text="توجد $o بلاغات مفتوحة منها $cr حرجة."; $link=['label'=>'البلاغات','url'=>$R.'/reports/complaints/overview.php']; } }
elseif ($has(['جرد'])) { if(!$need('reports.inventory')){$text=$DENY;}else{ $op=(int)$c("SELECT COUNT(*) FROM inventory_sessions WHERE status IN ('planning','active','review')"); $acc=(int)$c("SELECT 100*SUM(action='confirmed')/COUNT(*) FROM inventory_audits"); $text="$op جلسة جرد مفتوحة، ودقة المطابقة $acc%."; $link=['label'=>'الجرد','url'=>$R.'/reports/inventory/overview.php']; } }
elseif ($has(['عهده']) && $has(['بدون','لم'])) { if(!$need('reports.custody')){$text=$DENY;}else{ $n=(int)$c("SELECT COUNT(*) FROM assets WHERE (custodian_name IS NULL OR custodian_name='') AND status NOT IN ('disposed')"); $text="$n أصلاً بدون عهدة موظف بعد."; } }
elseif ($has(['عهده'])) { if(!$need('reports.custody')){$text=$DENY;}else{ $n=(int)$c("SELECT COUNT(*) FROM assets WHERE custodian_dept_id IS NOT NULL"); $text="تم إسناد $n أصلاً كعهدة على الأقسام."; $link=['label'=>'العهدة','url'=>$R.'/reports/custody/overview.php']; } }
elseif ($has(['تخلص','تكهين'])) { if(!$need('reports.disposal')){$text=$DENY;}else{ $v=(float)$c("SELECT SUM(disposal_value) FROM asset_disposals"); $text="القيمة المستردة من التخلص ".number_format($v,0)." ريال."; $link=['label'=>'التخلص','url'=>$R.'/reports/disposal/overview.php']; } }
elseif ($has(['استلام','تشغيل'])) { if(!$need('reports.receiving')){$text=$DENY;}else{ $n=(int)$c("SELECT COUNT(*) FROM commissioning_certificates WHERE asset_id IS NOT NULL"); $text="$n أصلاً مستلماً ومُشغّلاً عبر النظام."; $link=['label'=>'الاستلام','url'=>$R.'/reports/receiving/index.php']; } }
elseif ($has(['صحه'])) { if(!$need(['reports.assets','reports.risk'])){$text=$DENY;}else{ $h=(int)$c("SELECT AVG(health_score) FROM assets"); $text="متوسط صحة الأسطول $h%."; } }
elseif ($has(['اصل','اصول','كم'])) { if(!$need(['reports.assets','reports.risk'])){$text=$DENY;}else{ $n=(int)$c("SELECT COUNT(*) FROM assets WHERE status NOT IN ('disposed','returned_to_supplier')"); $text="السجل يضم $n أصلاً نشطاً."; $link=['label'=>'الأصول','url'=>$R.'/reports/assets/overview.php']; } }
else { $text="لم أفهم تماماً 🤔 جرّب رقماً («كم أصل حرج؟») أو دليلاً («كيف أرفع بلاغاً؟»)."; }

echo json_encode(['text'=>$text,'link'=>$link]);