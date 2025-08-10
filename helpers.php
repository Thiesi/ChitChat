<?php
function require_csrf(){
  $h = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
  if(!isset($_SESSION['csrf']) || !hash_equals($_SESSION['csrf'], $h)){
    http_response_code(403); echo json_encode(['error'=>'csrf']); exit;
  }
}
function ensure_active_user(){
  if(!isset($_SESSION['user_id'])){ http_response_code(401); echo json_encode(['error'=>'unauth']); exit; }
}
function settings(){
  try{
    $row = db()->query('SELECT * FROM system_settings LIMIT 1')->fetch(PDO::FETCH_ASSOC) ?: [];
  }catch(PDOException $e){ $row = []; }
  return $row + ['password_policy'=>'low','default_timezone'=>'UTC','system_closed'=>0,'shutdown_active'=>0,'shutdown_message'=>''];
}
?>