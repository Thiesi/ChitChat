<?php
require __DIR__.'/config.php'; require __DIR__.'/helpers.php';
// Allow CSRF via header for POST, or ?csrf= for GET CSV
if(isset($_GET['action']) && $_GET['action']==='export_csv'){
  if(session_status()===PHP_SESSION_NONE){ session_start(); }
  $qcsrf = $_GET['csrf'] ?? '';
  if(!isset($_SESSION['csrf']) || !hash_equals($_SESSION['csrf'], $qcsrf)){ http_response_code(403); echo 'csrf'; exit; }
  if(!isset($_SESSION['user_id'])){ http_response_code(401); echo 'unauth'; exit; }
} else { require_csrf(); ensure_active_user(); }

$uid=(int)$_SESSION['user_id'];
$st=db()->prepare('SELECT is_super_admin,is_admin FROM users WHERE id=:i'); $st->execute([':i'=>$uid]); $r=$st->fetch(PDO::FETCH_ASSOC)?:[];
if(!((int)($r['is_super_admin']??0)===1 || (int)($r['is_admin']??0)===1)){ http_response_code(403); echo json_encode(['error'=>'forbidden']); exit; }

$action=$_GET['action']??'';
function build_query($in,&$params){
  $admin = trim((string)($in['admin']??''));
  $type = trim((string)($in['type']??''));
  $from = trim((string)($in['from']??''));
  $to   = trim((string)($in['to']??''));
  $where=[];
  if($admin!==''){
    // join to users by username
    $where[] = 'ae.admin_user_id = (SELECT id FROM users WHERE username=:u LIMIT 1)';
    $params[':u'] = $admin;
  }
  if($type!==''){ $where[]='ae.export_type=:t'; $params[':t']=$type; }
  if($from!==''){ $where[]='ae.created_at >= :f'; $params[':f']=$from.' 00:00:00'; }
  if($to!==''){ $where[]='ae.created_at <= :to'; $params[':to']=$to.' 23:59:59'; }
  $sql='SELECT ae.created_at, ae.admin_user_id, u.username AS admin_username, ae.export_type, ae.params_json FROM audit_exports ae LEFT JOIN users u ON u.id=ae.admin_user_id';
  if($where){ $sql.=' WHERE '.implode(' AND ',$where); }
  $sql.=' ORDER BY ae.id DESC';
  return $sql;
}

if($action==='list'){
  $in=json_decode(file_get_contents('php://input'),true)?:[];
  $limit=max(1, min(2000, (int)($in['limit']??200)));
  $params=[]; $sql=build_query($in,$params); $sql.=' LIMIT :lim';
  $st2=db()->prepare($sql); foreach($params as $k=>$v){ $st2->bindValue($k,$v); } $st2->bindValue(':lim',(int)$limit,PDO::PARAM_INT); $st2->execute();
  $rows=$st2->fetchAll(PDO::FETCH_ASSOC)?:[]; echo json_encode($rows); exit;
}

if($action==='export_csv'){
  $params=[]; $sql=build_query($_GET,$params);
  header('Content-Type: text/csv; charset=utf-8');
  header('Content-Disposition: attachment; filename="export_logs.csv"');
  $out=fopen('php://output','w');
  fputcsv($out,['created_at','admin_username','export_type','params_json']);
  $st2=db()->prepare($sql); foreach($params as $k=>$v){ $st2->bindValue($k,$v); } $st2->execute();
  while($row=$st2->fetch(PDO::FETCH_ASSOC)){
    fputcsv($out, [$row['created_at'], $row['admin_username'], $row['export_type'], $row['params_json']]);
  }
  fclose($out); exit;
}

http_response_code(400); echo json_encode(['error'=>'unknown action']);
?>