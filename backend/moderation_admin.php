<?php
require __DIR__.'/config.php'; require __DIR__.'/helpers.php'; require_csrf(); ensure_active_user();
$uid=(int)$_SESSION['user_id'];
$st=db()->prepare('SELECT is_super_admin,is_admin,is_chat_admin,is_global_mod FROM users WHERE id=:i'); $st->execute([':i'=>$uid]); $r=$st->fetch(PDO::FETCH_ASSOC);
$is_sa=(int)$r['is_super_admin']===1; $is_admin=(int)$r['is_admin']===1; $is_cadmin=(int)$r['is_chat_admin']===1; $is_gmod=(int)$r['is_global_mod']===1;
if(!($is_sa||$is_admin||$is_cadmin||$is_gmod)){ http_response_code(403); echo json_encode(['error'=>'forbidden']); exit; }
$payload=file_get_contents('php://input'); $in=json_decode($payload?:'{}', true)?:[];
$action=$in['action']??''; $target=trim((string)($in['username']??''));
if($target===''){ http_response_code(400); echo json_encode(['error'=>'username required']); exit; }
if($target[0]==='@'){ $target=substr($target,1); }
$st2=db()->prepare('SELECT id FROM users WHERE username=:u'); $st2->execute([':u'=>$target]); $tid=(int)($st2->fetchColumn()?:0);
if(!$tid){ http_response_code(404); echo json_encode(['error'=>'user not found']); exit; }
if($action==='kick'){
  db()->prepare('UPDATE users SET force_logout_after=NOW() WHERE id=:i')->execute([':i'=>$tid]);
  echo json_encode(['ok'=>true]); exit;
}
if($action==='ban'){
  $days = isset($in['days']) ? (int)$in['days'] : null; $reason = trim((string)($in['reason']??''));
  try{ db()->prepare("INSERT INTO user_bans (user_id,by_admin_id,reason,until) VALUES (:u,:a,:r, ".($days?"DATE_ADD(NOW(), INTERVAL $days DAY)":"NULL").")")->execute([':u'=>$tid,':a'=>$uid,':r'=>$reason]); }
  catch(PDOException $e){ db()->prepare("INSERT INTO user_bans (user_id,by_admin_id,reason,until) VALUES (:u,:a,:r, (CASE WHEN :d>0 THEN NOW()+(:d||' days')::interval ELSE NULL END))")->execute([':u'=>$tid,':a'=>$uid,':r'=>$reason,':d'=>$days?:0]); }
  echo json_encode(['ok'=>true]); exit;
}
if($action==='unban'){
  db()->prepare('DELETE FROM user_bans WHERE user_id=:u')->execute([':u'=>$tid]);
  echo json_encode(['ok'=>true]); exit;
}
http_response_code(400); echo json_encode(['error'=>'unknown action']);
?>
