<?php
require __DIR__.'/config.php'; require __DIR__.'/helpers.php';
// Allow CSRF via header (default) or ?csrf= token for GET downloads
if(isset($_GET['action']) && $_GET['action']==='export_csv'){
  if(session_status()===PHP_SESSION_NONE){ session_start(); }
  $qcsrf = $_GET['csrf'] ?? '';
  if(!isset($_SESSION['csrf']) || !hash_equals($_SESSION['csrf'], $qcsrf)){
    http_response_code(403); echo 'csrf'; exit;
  }
  if(!isset($_SESSION['user_id'])){ http_response_code(401); echo 'unauth'; exit; }
} else { require_csrf(); ensure_active_user(); }
$uid=(int)$_SESSION['user_id'];
$st=db()->prepare('SELECT is_super_admin,is_admin FROM users WHERE id=:i'); $st->execute([':i'=>$uid]); $r=$st->fetch(PDO::FETCH_ASSOC)?:[];
if(!((int)($r['is_super_admin']??0)===1 || (int)($r['is_admin']??0)===1)){ http_response_code(403); echo json_encode(['error'=>'forbidden']); exit; }

$action=$_GET['action']??'';
$in=json_decode(file_get_contents('php://input'),true)?:[];

if($action==='list'){
  $u=trim((string)($in['username']??''));
  $ip=trim((string)($in['ip']??''));
  $from=trim((string)($in['from']??''));
  $to=trim((string)($in['to']??''));
  $ok=$in['ok']!=='' ? (int)$in['ok'] : null;
  $limit=max(1, min(2000, (int)($in['limit']??200)));

  $where=[]; $params=[];
  if($u!==''){ $where[]='username=:u'; $params[':u']=$u; }
  if($ip!==''){ $where[]='ip=:ip'; $params[':ip']=$ip; }
  if($from!==''){ $where[]='created_at >= :f'; $params[':f']=$from.' 00:00:00'; }
  if($to!==''){ $where[]='created_at <= :t'; $params[':t']=$to+' 23:59:59'; }
  if($ok!==null){ $where[]='ok=:ok'; $params[':ok']=$ok; }
  $sql='SELECT created_at, username, ip, reason, ok FROM login_attempts';
  if($where){ $sql.=' WHERE '.implode(' AND ',$where); }
  $sql.=' ORDER BY id DESC LIMIT :lim';

  try{
    $st2=db()->prepare($sql); foreach($params as $k=>$v){ $st2->bindValue($k,$v); } $st2->bindValue(':lim',(int)$limit,PDO::PARAM_INT); $st2->execute();
  }catch(PDOException $e){
    // Postgres: cast timestamp properly
    $sql=str_replace('created_at >= :f','created_at >= to_timestamp(:f_ts)','', $sql);
  }
  $rows=$st2->fetchAll(PDO::FETCH_ASSOC)?:[];
  echo json_encode($rows); exit;
}

if($action==='export_csv'){
  // Build WHERE from GET
  $u = isset($_GET['username']) ? trim((string)$_GET['username']) : '';
  $ip = isset($_GET['ip']) ? trim((string)$_GET['ip']) : '';
  $from = isset($_GET['from']) ? trim((string)$_GET['from']) : '';
  $to = isset($_GET['to']) ? trim((string)$_GET['to']) : '';
  $ok = isset($_GET['ok']) && $_GET['ok'] !== '' ? (int)$_GET['ok'] : null;

  $where=[]; $params=[];
  if($u!==''){ $where[]='username=:u'; $params[':u']=$u; }
  if($ip!==''){ $where[]='ip=:ip'; $params[':ip']=$ip; }
  if($from!==''){ $where[]='created_at >= :f'; $params[':f']=$from.' 00:00:00'; }
  if($to!==''){ $where[]='created_at <= :t'; $params[':t']=$to.' 23:59:59'; }
  if($ok!==null){ $where[]='ok=:ok'; $params[':ok']=$ok; }
  $sql='SELECT created_at, username, ip, reason, ok FROM login_attempts'; if($where){ $sql.=' WHERE '.implode(' AND ',$where); } $sql.=' ORDER BY id DESC';

  // Log export
  try{
    db()->prepare('INSERT INTO audit_exports (admin_user_id, export_type, params_json) VALUES (:a, :t, :p)')
      ->execute([':a'=>$_SESSION['user_id'], ':t'=>'login_attempts', ':p'=>json_encode(['username'=>$u,'ip'=>$ip,'from'=>$from,'to'=>$to,'ok'=>$ok])]);
  }catch(PDOException $e){}

  header('Content-Type: text/csv; charset=utf-8');
  header('Content-Disposition: attachment; filename=\"login_attempts.csv\"');
  $out = fopen('php://output', 'w');
  fputcsv($out, ['created_at','username','ip','reason','ok']);
  try{ $st2=db()->prepare($sql); foreach($params as $k=>$v){ $st2->bindValue($k,$v); } $st2->execute(); }
  catch(PDOException $e){
    $st2=db()->prepare($sql); foreach($params as $k=>$v){ $st2->bindValue($k,$v); } $st2->execute();
  }
  while($row=$st2->fetch(PDO::FETCH_ASSOC)){
    fputcsv($out, [$row['created_at'],$row['username'],$row['ip'],$row['reason'],$row['ok']]);
  }
  fclose($out);
  exit;
}

http_response_code(400); echo json_encode(['error'=>'unknown action']);
?>