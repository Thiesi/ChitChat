<?php
require __DIR__.'/config.php'; require __DIR__.'/helpers.php';
if (!isset($_SESSION['user_id'])) { http_response_code(401); echo json_encode(['error'=>'unauth']); exit; }
$uid=(int)$_SESSION['user_id']; $action=$_GET['action']??'get_self';
if($action==='get_self'){
  $st=db()->prepare('SELECT username, location, sex, to_char(birthday,\\'YYYY-MM-DD\\') as birthday, pub_last_active, pub_location, pub_sex, pub_birthday, COALESCE(theme,\\'auto\\') as theme, timezone, COALESCE(notify_pings,1) as notify_pings, COALESCE(sound_ping,0) as sound_ping, COALESCE(sound_broadcast,0) as sound_broadcast FROM users WHERE id=:i');
  try{$st->execute([':i'=>$uid]);}
  catch(PDOException $e){ $st=db()->prepare('SELECT username, location, sex, DATE_FORMAT(birthday,\\'%Y-%m-%d\\') as birthday, pub_last_active, pub_location, pub_sex, pub_birthday, COALESCE(theme,\\'auto\\') as theme, timezone, COALESCE(notify_pings,1) as notify_pings, COALESCE(sound_ping,0) as sound_ping, COALESCE(sound_broadcast,0) as sound_broadcast FROM users WHERE id=:i'); $st->execute([':i'=>$uid]); }
  $row=$st->fetch(PDO::FETCH_ASSOC)?:[]; echo json_encode($row); exit;
}
if($action==='update'){
  require_csrf();
  $d=json_decode(file_get_contents('php://input'),true)?:[];
  $b = trim((string)($d['birthday']??''));
  if($b!==''){ if(!preg_match('/^\\d{4}-\\d{2}-\\d{2}$/',$b)){ http_response_code(400); echo json_encode(['error'=>'Birthday must be YYYY-MM-DD']); exit; } }
  $theme = in_array(($d['theme']??'auto'), ['auto','light','dark'], true) ? $d['theme'] : 'auto';
  $tz = (string)($d['timezone'] ?? NULL); if($tz==='') $tz=NULL;
  $np = !empty($d['notify_pings']) ? 1 : 0;
  $sp = !empty($d['sound_ping']) ? 1 : 0;
  $sb = !empty($d['sound_broadcast']) ? 1 : 0;
  db()->prepare('UPDATE users SET location= NULLIF(:l,\\'\\'), sex= NULLIF(:s,\\'\\'), birthday= NULLIF(:b,\\'\\'), pub_last_active=:pla, pub_location=:pl, pub_sex=:ps, pub_birthday=:pb, theme=:t, timezone=:tz, notify_pings=:np, sound_ping=:sp, sound_broadcast=:sb WHERE id=:i')
    ->execute([':l'=>$d['location']??'',':s'=>$d['sex']??'',':b'=>$b,':pla'=>!empty($d['pub_last_active'])?1:0, ':pl'=>!empty($d['pub_location'])?1:0, ':ps'=>!empty($d['pub_sex'])?1:0, ':pb'=>$d['pub_birthday']??'hidden', ':t'=>$theme, ':tz'=>$tz, ':np'=>$np, ':sp'=>$sp, ':sb'=>$sb, ':i'=>$uid]);
  echo json_encode(['ok'=>true]); exit;
}
http_response_code(400); echo json_encode(['error'=>'unknown action']);
?>