<?php
// backend/change_password.php — self-service password change
require __DIR__.'/config.php'; require __DIR__.'/helpers.php'; require __DIR__.'/pw_policy.php'; require_csrf(); ensure_active_user();
$uid=(int)$_SESSION['user_id'];
$in=json_decode(file_get_contents('php://input'),true)?:[];
$old=(string)($in['old_password']??''); $new=(string)($in['new_password']??'');
if($old==='' || $new===''){ http_response_code(400); echo json_encode(['error'=>'old_password and new_password required']); exit; }
$st=db()->prepare('SELECT username,password_hash FROM users WHERE id=:i'); $st->execute([':i'=>$uid]); $row=$st->fetch(PDO::FETCH_ASSOC)?:null;
if(!$row){ http_response_code(404); echo json_encode(['error'=>'user not found']); exit; }
if(!password_verify($old, $row['password_hash'])){ http_response_code(403); echo json_encode(['error'=>'old password incorrect']); exit; }
// Policy
try{ $s=db()->query('SELECT password_policy FROM system_settings WHERE id=1')->fetch(PDO::FETCH_ASSOC)?:[]; }catch(PDOException $e){ $s=[]; }
$pol=strtolower((string)($s['password_policy']??'low'));
$val=validate_password_policy($new,$row['username'],$pol);
if(!$val['ok']){ http_response_code(400); echo json_encode(['error'=>$val['error']]); exit; }
// Save
db()->prepare('UPDATE users SET password_hash=:p WHERE id=:i')->execute([':p'=>password_hash($new,PASSWORD_DEFAULT),':i'=>$uid]);
echo json_encode(['ok'=>true]);
?>