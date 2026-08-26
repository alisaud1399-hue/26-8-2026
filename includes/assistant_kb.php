<?php
function kb_norm($s){ $s=mb_strtolower((string)$s);
  $s=preg_replace('/[\x{064B}-\x{0652}]/u','',$s);
  $s=str_replace(['أ','إ','آ'],'ا',$s); $s=str_replace('ة','ه',$s); $s=str_replace('ى','ي',$s);
  return $s; }
function kb_match($pdo,$q){
  $n=kb_norm($q); if($n==='') return null;
  try{ $rows=$pdo->query("SELECT keywords,answer FROM assistant_knowledge WHERE is_active=1 ORDER BY sort_order,id")->fetchAll(PDO::FETCH_ASSOC); }
  catch(Throwable $e){ return null; }
  foreach($rows as $r){
    $ks=array_filter(array_map('trim',preg_split('/[,،]/u',$r['keywords'])));
    foreach($ks as $k){ if($k!=='' && mb_strpos($n,kb_norm($k))!==false) return $r['answer']; }
  }
  return null;
}