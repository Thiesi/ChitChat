<?php
// backend/user_admin.php — admin & chat-admin user management (subset for patch)
require __DIR__.'/config.php'; require __DIR__.'/helpers.php'; require __DIR__.'/pw_policy.php'; require_csrf(); ensure_active_user();
$me=(int)$_SESSION['user_id'];
$ru = db()->prepare('SELECT is_super_admin,is_admin,is_chat_admin FROM users WHERE id=:i'); $ru->execute([':i'=>$me]); $pr=$ru->fetch(PDO::FETCH_ASSOC)?:[];
$can_edit = ((int)($pr['is_admin']??0)===1) || ((int)($pr['is_super_admin']??0)===1);
$can_read = $can_edit || ((int)($pr['is_chat_admin']??0)===1);
$action=$_GET['action']??'';

if($action==='set_password'){
  if(!$can_edit){ http_response_code(403); echo json_encode(['error'=>'forbidden']); exit; }
  $in=json_decode(file_get_contents('php://input'),true)?:[];
  $uid=(int)($in['user_id']??0); $password=(string)($in['password']??'');
  if($uid<=0 || $password===''){ http_response_code(400); echo json_encode(['error'=>'user_id and password required']); exit; }
  // Load policy
  try{ $s=db()->query('SELECT password_policy FROM system_settings WHERE id=1')->fetch(PDO::FETCH_ASSOC)?:[]; }catch(PDOException $e){ $s=[]; }
  $st=db()->prepare('SELECT username FROM users WHERE id=:i'); $st->execute([':i'=>$uid]); $uname=(string)($st->fetchColumn()?:'');
  if($uname===''){ http_response_code(404); echo json_encode(['error'=>'user not found']); exit; }
  $pol=strtolower((string)($s['password_policy']??'low'));
  $val=validate_password_policy($password,$uname,$pol);
  if(!$val['ok']){ http_response_code(400); echo json_encode(['error'=>$val['error']]); exit; }
  // Update
  db()->prepare('UPDATE users SET password_hash=:p WHERE id=:i')->execute([':p'=>password_hash($password, PASSWORD_DEFAULT),':i'=>$uid]);
  echo json_encode(['ok'=>true]); exit;
}

if($action==='get'){
  if(!$can_read){ http_response_code(403); echo json_encode(['error'=>'forbidden']); exit; }
  $in=json_decode(file_get_contents('php://input'),true)?:[];
  $u=trim((string)($in['username']??'')); if($u===''){ http_response_code(400); echo json_encode(['error'=>'username required']); exit; }
  $st=db()->prepare('SELECT id,username,is_super_admin,is_admin,is_chat_admin,is_global_mod,location,sex,DATE_FORMAT(birthday,"%Y-%m-%d") AS birthday, pub_location,pub_sex,pub_birthday, NOW() as last_active_human FROM users WHERE username=:u'); 
  try{$st->execute([':u'=>$u]);}catch(PDOException $e){ $st=db()->prepare("SELECT id,username,is_super_admin,is_admin,is_chat_admin,is_global_mod,location,sex,TO_CHAR(birthday,'YYYY-MM-DD') AS birthday, pub_location,pub_sex,pub_birthday, NOW() as last_active_human FROM users WHERE username=:u"); $st->execute([':u'=>$u]); }
  $row=$st->fetch(PDO::FETCH_ASSOC)?:[]; echo json_encode($row); exit;
}

http_response_code(400); echo json_encode(['error'=>'unknown action']);
?>
