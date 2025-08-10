<?php
// backend/config.php — DSN via env (MYSQL/Postgres), sessions+CSRF
if(session_status()===PHP_SESSION_NONE){ session_start(); }
if(empty($_SESSION['csrf'])){ $_SESSION['csrf']=bin2hex(random_bytes(16)); }
$dsn = getenv('DB_DSN'); $dbu = getenv('DB_USER'); $dbp = getenv('DB_PASS');
if(!$dsn){
  // Example: mysql:host=localhost;dbname=chitchat;charset=utf8mb4
  // or: pgsql:host=localhost;port=5432;dbname=chitchat
  $driver = getenv('DB_DRIVER') ?: 'mysql';
  $host = getenv('DB_HOST') ?: 'localhost';
  $port = getenv('DB_PORT') ?: ($driver==='pgsql' ? '5432' : '3306');
  $name = getenv('DB_NAME') ?: 'chitchat';
  $charset = $driver==='mysql' ? ';charset=utf8mb4' : '';
  $dsn = "{$driver}:host={$host};port={$port};dbname={$name}{$charset}";
  $dbu = $dbu ?: getenv('DB_USERNAME') ?: 'root';
  $dbp = $dbp ?: getenv('DB_PASSWORD') ?: '';
}
$GLOBALS['_pdo']=null;
function db(){
  if($GLOBALS['_pdo']) return $GLOBALS['_pdo'];
  $opt=[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC];
  $GLOBALS['_pdo']=new PDO(getenv('DB_DSN')?:$GLOBALS['dsn'],$GLOBALS['dbu']??getenv('DB_USER'),$GLOBALS['dbp']??getenv('DB_PASS'),$opt);
  return $GLOBALS['_pdo'];
}
?>
