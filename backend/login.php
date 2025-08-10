<?php
// backend/login.php — with brute-force throttling and attempt logs
require __DIR__.'/config.php'; if(session_status()===PHP_SESSION_NONE){ session_start(); }
$in=json_decode(file_get_contents('php://input'),true)?:[];
$username=trim((string)($in['username']??'')); $password=(string)($in['password']??'');
header('Content-Type: application/json');

function log_attempt($username,$reason,$ok){
  try{
    $ip=$_SERVER['REMOTE_ADDR']??'';
    db()->prepare('INSERT INTO login_attempts (username, ip, reason, ok) VALUES (:u,:ip,:r,:ok)')->execute([':u'=>$username,':ip'=>$ip,':r'=>$reason,':ok'=>$ok?1:0]);
  }catch(PDOException $e){}
}

try{ $s = db()->query('SELECT bruteforce_max_attempts, bruteforce_lock_minutes FROM system_settings WHERE id=1')->fetch(PDO::FETCH_ASSOC) ?: []; }
catch(PDOException $e){ $s = []; }
$maxA=max(1,(int)($s['bruteforce_max_attempts']??10));
$lockM=max(1,(int)($s['bruteforce_lock_minutes']??15));

// Check lock status by IP+username in window
try{
  $st=db()->prepare('SELECT COUNT(*) FROM login_attempts WHERE username=:u AND ip=:ip AND created_at>DATE_SUB(NOW(), INTERVAL :m MINUTE) AND ok=0');
  $st->execute([':u'=>$username,':ip'=>($_SERVER['REMOTE_ADDR']??''),':m'=>$lockM]);
}catch(PDOException $e){
  $st=db()->prepare(\"SELECT COUNT(*) FROM login_attempts WHERE username=:u AND ip=:ip AND created_at> NOW() - (:m || ' minutes')::interval AND ok=0\");
  $st->execute([':u'=>$username,':ip'=>($_SERVER['REMOTE_ADDR']??''),':m'=>$lockM]);
}
$fails=(int)$st->fetchColumn();
if($fails >= $maxA){ log_attempt($username,'locked',false); http_response_code(429); echo json_encode(['error'=>'too many attempts, try later']); exit; }

// Find user
$st2=db()->prepare('SELECT id,username,password_hash FROM users WHERE username=:u'); $st2->execute([':u'=>$username]); $u=$st2->fetch(PDO::FETCH_ASSOC)?:null;
if(!$u){ log_attempt($username,'no_user',false); http_response_code(401); echo json_encode(['error'=>'invalid credentials']); exit; }
// Verify
if(!password_verify($password,$u['password_hash'])){ log_attempt($username,'bad_password',false); http_response_code(401); echo json_encode(['error'=>'invalid credentials']); exit; }

// Success
$_SESSION['user_id']=(int)$u['id']; log_attempt($username,'ok',true);
echo json_encode(['ok'=>true]);
?>
