<?php
// backend/sa_settings.php — Super‑Admin settings API
require __DIR__.'/config.php'; require __DIR__.'/helpers.php'; require_csrf(); ensure_active_user();
$uid=(int)$_SESSION['user_id'];
$st=db()->prepare('SELECT is_super_admin FROM users WHERE id=:i'); $st->execute([':i'=>$uid]); $is_sa=(int)$st->fetchColumn()===1;
if(!$is_sa){ http_response_code(403); echo json_encode(['error'=>'forbidden']); exit; }

$action=$_GET['action']??'';
if($action==='save_system'){
  $in=json_decode(file_get_contents('php://input'),true)?:[];
  $name=trim((string)($in['system_name']??'ChitChat'));
  $policy=(string)($in['password_policy']??'low');
  if(!in_array($policy,['none','low','medium','high'],true)){ $policy='low'; }
  $tz=(string)($in['default_timezone']??'UTC');
  $closed=!empty($in['system_closed'])?1:0;
  $no_reg=!empty($in['registrations_disabled'])?1:0;
  $bf_max=max(1,(int)($in['bruteforce_max_attempts']??10));
  $bf_min=max(1,(int)($in['bruteforce_lock_minutes']??15));
  $allow_gmod = !empty($in['allow_gmod_dm_export'])?1:0;
  // upsert
  try{
    db()->prepare('INSERT INTO system_settings (id, system_name, password_policy, default_timezone, system_closed, registrations_disabled, bruteforce_max_attempts, bruteforce_lock_minutes, allow_gmod_dm_export)
      VALUES (1,:n,:p,:tz,:c,:r,:bm,:bl,:ag)
      ON DUPLICATE KEY UPDATE system_name=VALUES(system_name), password_policy=VALUES(password_policy), default_timezone=VALUES(default_timezone), system_closed=VALUES(system_closed), registrations_disabled=VALUES(registrations_disabled), bruteforce_max_attempts=VALUES(bruteforce_max_attempts), bruteforce_lock_minutes=VALUES(bruteforce_lock_minutes), allow_gmod_dm_export=VALUES(allow_gmod_dm_export)')
      ->execute([':n'=>$name,':p'=>$policy,':tz'=>$tz,':c'=>$closed,':r'=>$no_reg,':bm'=>$bf_max,':bl'=>$bf_min,':ag'=>$allow_gmod]);
  }catch(PDOException $e){
    // PostgreSQL upsert
    db()->prepare('INSERT INTO system_settings (id, system_name, password_policy, default_timezone, system_closed, registrations_disabled, bruteforce_max_attempts, bruteforce_lock_minutes, allow_gmod_dm_export)
      VALUES (1,:n,:p,:tz,:c,:r,:bm,:bl,:ag)
      ON CONFLICT (id) DO UPDATE SET system_name=EXCLUDED.system_name, password_policy=EXCLUDED.password_policy, default_timezone=EXCLUDED.default_timezone, system_closed=EXCLUDED.system_closed, registrations_disabled=EXCLUDED.registrations_disabled, bruteforce_max_attempts=EXCLUDED.bruteforce_max_attempts, bruteforce_lock_minutes=EXCLUDED.bruteforce_lock_minutes, allow_gmod_dm_export=EXCLUDED.allow_gmod_dm_export')
      ->execute([':n'=>$name,':p'=>$policy,':tz'=>$tz,':c'=>$closed,':r'=>$no_reg,':bm'=>$bf_max,':bl'=>$bf_min,':ag'=>$allow_gmod]);
  }
  echo json_encode(['ok'=>true]); exit;
}

if($action==='save_reg'){
  $in=json_decode(file_get_contents('php://input'),true)?:[];
  $ask_b = !empty($in['ask_birthday'])?1:0;
  $ask_l = !empty($in['ask_location'])?1:0;
  $ask_s = !empty($in['ask_sex'])?1:0;
  $req_b = !empty($in['require_birthday'])?1:0;
  $req_l = !empty($in['require_location'])?1:0;
  $req_s = !empty($in['require_sex'])?1:0;
  try{
    db()->prepare('INSERT INTO system_settings (id, ask_birthday, ask_location, ask_sex, require_birthday, require_location, require_sex) VALUES (1,:ab,:al,:as,:rb,:rl,:rs)
      ON DUPLICATE KEY UPDATE ask_birthday=VALUES(ask_birthday), ask_location=VALUES(ask_location), ask_sex=VALUES(ask_sex), require_birthday=VALUES(require_birthday), require_location=VALUES(require_location), require_sex=VALUES(require_sex)')
      ->execute([':ab'=>$ask_b,':al'=>$ask_l,':as'=>$ask_s,':rb'=>$req_b,':rl'=>$req_l,':rs'=>$req_s]);
  }catch(PDOException $e){
    db()->prepare('INSERT INTO system_settings (id, ask_birthday, ask_location, ask_sex, require_birthday, require_location, require_sex) VALUES (1,:ab,:al,:as,:rb,:rl,:rs)
      ON CONFLICT (id) DO UPDATE SET ask_birthday=EXCLUDED.ask_birthday, ask_location=EXCLUDED.ask_location, ask_sex=EXCLUDED.ask_sex, require_birthday=EXCLUDED.require_birthday, require_location=EXCLUDED.require_location, require_sex=EXCLUDED.require_sex')
      ->execute([':ab'=>$ask_b,':al'=>$ask_l,':as'=>$ask_s,':rb'=>$req_b,':rl'=>$req_l,':rs'=>$req_s]);
  }
  echo json_encode(['ok'=>true]); exit;
}
if($action==='save_motd'){
  $in=json_decode(file_get_contents('php://input'),true)?:[];
  $motd=(string)($in['motd']??'');
  try{
    db()->prepare('INSERT INTO system_settings (id, motd) VALUES (1,:m) ON DUPLICATE KEY UPDATE motd=VALUES(motd)')->execute([':m'=>$motd]);
  }catch(PDOException $e){
    db()->prepare('INSERT INTO system_settings (id, motd) VALUES (1,:m) ON CONFLICT (id) DO UPDATE SET motd=EXCLUDED.motd')->execute([':m'=>$motd]);
  }
  echo json_encode(['ok'=>true]); exit;
}

http_response_code(400); echo json_encode(['error'=>'unknown action']);
?>