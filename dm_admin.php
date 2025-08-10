<?php
// backend/dm_admin.php — DM viewer + export with audit logging
// Permissions: Chat Admin+ (Chat Admin, Admin, Super-Admin). Global Moderators can view but not export if desired — adjust as needed.
require __DIR__.'/config.php'; require __DIR__.'/helpers.php'; require_csrf(); ensure_active_user();
header('Content-Type: application/json');

$uid=(int)$_SESSION['user_id'];
$st=db()->prepare('SELECT is_super_admin,is_admin,is_chat_admin,is_global_mod FROM users WHERE id=:i'); $st->execute([':i'=>$uid]);
$role=$st->fetch(PDO::FETCH_ASSOC)?:[];
$can_view = ((int)($role['is_super_admin']??0)===1) || ((int)($role['is_admin']??0)===1) || ((int)($role['is_chat_admin']??0)===1) || ((int)($role['is_global_mod']??0)===1);
$can_export = ((int)($role['is_super_admin']??0)===1) || ((int)($role['is_admin']??0)===1) || ((int)($role['is_chat_admin']??0)===1);
// Check SA setting for GMods
$allow_gmod = 0; try{ $s=db()->query('SELECT allow_gmod_dm_export FROM system_settings WHERE id=1')->fetch(PDO::FETCH_ASSOC)?:[]; $allow_gmod=(int)($s['allow_gmod_dm_export']??0); }catch(PDOException $e){}
if(!$can_export && ((int)($role['is_global_mod']??0)===1) && $allow_gmod===1){ $can_export = true; }
// Adjust above if you want GMods to export too.

if(!$can_view){ http_response_code(403); echo json_encode(['error'=>'forbidden']); exit; }

$action=$_GET['action']??'';

// Helper to build query from filters
function build_dm_query($in,&$params){
  $from = trim((string)($in['from']??''));
  $to   = trim((string)($in['to']??''));
  $u_from = trim((string)($in['from_user']??''));
  $u_to   = trim((string)($in['to_user']??''));
  $limit  = max(1, min(5000, (int)($in['limit']??100)));
  $where=[];
  if($u_from!==''){ $where[]='m.sender_id = (SELECT id FROM users WHERE username=:uf LIMIT 1)'; $params[':uf']=$u_from; }
  if($u_to!==''){   $where[]='m.receiver_id = (SELECT id FROM users WHERE username=:ut LIMIT 1)'; $params[':ut']=$u_to; }
  if($from!==''){   $where[]='m.created_at >= :f'; $params[':f']=$from.' 00:00:00'; }
  if($to!==''){     $where[]='m.created_at <= :t'; $params[':t']=$to.' 23:59:59'; }

  $sql='SELECT m.id, m.created_at, su.username AS sender, ru.username AS receiver, m.body FROM direct_messages m JOIN users su ON su.id=m.sender_id JOIN users ru ON ru.id=m.receiver_id';
  if($where){ $sql.=' WHERE '.implode(' AND ',$where); }
  $sql.=' ORDER BY m.id DESC LIMIT :lim';
  return [$sql,$limit];
}

// Simple redaction example (you can extend patterns)
function redact_row($row){
  // Example: redact email addresses
  $row['body'] = preg_replace('/[\\w.+-]+@[\\w-]+\\.[\\w.-]+/u', '[redacted-email]', $row['body']);
  return $row;
}

// List endpoint (JSON)
if($action==='list'){
  $in=json_decode(file_get_contents('php://input'),true)?:[];
  $params=[]; list($sql,$limit) = build_dm_query([
    'from'=>$in['from']??'','to'=>$in['to']??'','from_user'=>$in['from_user']??$in['from_username']??'','to_user'=>$in['to_user']??$in['to_username']??'','limit'=>$in['limit']??100
  ], $params);
  $st2=db()->prepare($sql); foreach($params as $k=>$v){ $st2->bindValue($k,$v); } $st2->bindValue(':lim',(int)$limit,PDO::PARAM_INT); $st2->execute();
  $rows=$st2->fetchAll(PDO::FETCH_ASSOC)?:[];
  // Optional inline redaction for UI preview
  $red = !empty($in['redact']) ? true : false;
  if($red){ $rows = array_map('redact_row', $rows); }
  echo json_encode($rows); exit;
}

// Common: log export
function log_dm_export($admin_user_id, $params, $format){
  try{
    db()->prepare('INSERT INTO audit_exports (admin_user_id, export_type, params_json) VALUES (:a,:t,:p)')
      ->execute([':a'=>$admin_user_id, ':t'=>'dm_export', ':p'=>json_encode(['format'=>$format] + $params)]);
  }catch(PDOException $e){}
}

// Export JSON
if($action==='export_json'){
  if(!$can_export){ http_response_code(403); echo json_encode(['error'=>'forbidden']); exit; }
  $in=json_decode(file_get_contents('php://input'),true)?:[];
  $params=[]; list($sql,$limit) = build_dm_query([
    'from'=>$in['from']??'','to'=>$in['to']??'','from_user'=>$in['from_user']??$in['from_username']??'','to_user'=>$in['to_user']??$in['to_username']??'','limit'=>$in['limit']??5000
  ], $params);
  $st2=db()->prepare($sql); foreach($params as $k=>$v){ $st2->bindValue($k,$v); } $st2->bindValue(':lim',(int)$limit,PDO::PARAM_INT); $st2->execute();
  $rows=$st2->fetchAll(PDO::FETCH_ASSOC)?:[];
  // Optional redaction flag
  if(!empty($in['redact'])){ $rows=array_map('redact_row', $rows); }
  // Log export
  log_dm_export($uid, ['from'=>$in['from']??'','to'=>$in['to']??'','from_user'=>$in['from_user']??'','to_user'=>$in['to_user']??'','limit'=>$in['limit']??5000,'redact'=>!empty($in['redact'])], 'json');
  header('Content-Type: application/json'); echo json_encode(['ok'=>true,'rows'=>$rows]); exit;
}

// Export CSV (streamed)
if($action==='export_csv'){
  if(!$can_export){ http_response_code(403); echo json_encode(['error'=>'forbidden']); exit; }
  $in=json_decode(file_get_contents('php://input'),true)?:[];
  $params=[]; list($sql,$limit) = build_dm_query([
    'from'=>$in['from']??'','to'=>$in['to']??'','from_user'=>$in['from_user']??$in['from_username']??'','to_user'=>$in['to_user']??$in['to_username']??'','limit'=>$in['limit']??5000
  ], $params);
  // Log export before streaming
  log_dm_export($uid, ['from'=>$in['from']??'','to'=>$in['to']??'','from_user'=>$in['from_user']??'','to_user'=>$in['to_user']??'','limit'=>$in['limit']??5000,'redact'=>!empty($in['redact'])], 'csv');
  // Stream CSV
  header('Content-Type: text/csv; charset=utf-8');
  header('Content-Disposition: attachment; filename=\"dm_export.csv\"');
  $out = fopen('php://output','w');
  fputcsv($out, ['id','created_at','sender','receiver','body']);
  $st2=db()->prepare($sql); foreach($params as $k=>$v){ $st2->bindValue($k,$v); } $st2->bindValue(':lim',(int)$limit,PDO::PARAM_INT); $st2->execute();
  while($row=$st2->fetch(PDO::FETCH_ASSOC)){
    if(!empty($in['redact'])){ $row=redact_row($row); }
    fputcsv($out, [$row['id'],$row['created_at'],$row['sender'],$row['receiver'],$row['body']]);
  }
  fclose($out); exit;
}

http_response_code(400); echo json_encode(['error'=>'unknown action']);
?>