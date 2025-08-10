<?php
require __DIR__.'/config.php'; require __DIR__.'/helpers.php'; require __DIR__.'/pw_policy.php'; require_csrf();
$in=json_decode(file_get_contents('php://input'), true) ?: [];
$username=trim((string)($in['username']??''));
$password=(string)($in['password']??'');
if($username==='' || $password===''){ http_response_code(400); echo json_encode(['error'=>'username and password required']); exit; }

// Load system settings
try{ $s = db()->query('SELECT registrations_disabled, ask_birthday, ask_location, ask_sex, require_birthday, require_location, require_sex, password_policy FROM system_settings WHERE id=1')->fetch(PDO::FETCH_ASSOC) ?: []; }
catch(PDOException $e){ $s = []; }
$policy = strtolower((string)($s['password_policy']??'low'));
$reg_disabled = (int)($s['registrations_disabled']??0)===1;
if($reg_disabled){ http_response_code(403); echo json_encode(['error'=>'registrations disabled']); exit; }

$require_b = (int)($s['require_birthday']??0)===1;
$require_l = (int)($s['require_location']??0)===1;
$require_s = (int)($s['require_sex']??0)===1;

$birthday = isset($in['birthday']) ? trim((string)$in['birthday']) : '';
$location = isset($in['location']) ? trim((string)$in['location']) : '';
$sex = isset($in['sex']) ? trim((string)$in['sex']) : '';

if($require_b && $birthday===''){ http_response_code(400); echo json_encode(['error'=>'birthday required']); exit; }
if($require_l && $location===''){ http_response_code(400); echo json_encode(['error'=>'location required']); exit; }
if($require_s && $sex===''){ http_response_code(400); echo json_encode(['error'=>'sex required']); exit; }

// Enforce password policy
$val = validate_password_policy($password, $username, $policy);
if(!$val['ok']){ http_response_code(400); echo json_encode(['error'=>$val['error']]); exit; }

// Insert user
try {
  // MySQL insert
  $stmt = db()->prepare('INSERT INTO users (username, password_hash, location, sex, birthday, created_at) VALUES (:u, :p, NULLIF(:l, \'\'), NULLIF(:s, \'\'), NULLIF(:b, \'\'), NOW())');
  $stmt->execute([':u'=>$username, ':p'=>password_hash($password, PASSWORD_DEFAULT), ':l'=>$location, ':s'=>$sex, ':b'=>$birthday]);
} catch (PDOException $e) {
  // PostgreSQL insert
  $stmt = db()->prepare(\"INSERT INTO users (username, password_hash, location, sex, birthday, created_at) VALUES (:u, :p, NULLIF(:l, ''), NULLIF(:s, ''), NULLIF(:b, ''), NOW())\");
  $stmt->execute([':u'=>$username, ':p'=>password_hash($password, PASSWORD_DEFAULT), ':l'=>$location, ':s'=>$sex, ':b'=>$birthday]);
}
echo json_encode(['ok'=>true]);
?>
