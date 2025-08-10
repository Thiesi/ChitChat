<?php
require __DIR__.'/config.php'; require __DIR__.'/helpers.php';
$user=null; $settings=settings();
if(isset($_SESSION['user_id'])){
  $st=db()->prepare('SELECT id, username, COALESCE(is_super_admin,0) as is_super_admin, COALESCE(is_admin,0) as is_admin, COALESCE(is_chat_admin,0) as is_chat_admin, COALESCE(is_global_mod,0) as is_global_mod, COALESCE(invisible_global,0) as invisible_global, COALESCE(invisible_rooms,0) as invisible_rooms, COALESCE(theme,\\'auto\\') as theme, timezone, COALESCE(notify_pings,1) as notify_pings, COALESCE(sound_ping,0) as sound_ping, COALESCE(sound_broadcast,0) as sound_broadcast FROM users WHERE id=:i');
  $st->execute([':i'=>$_SESSION['user_id']]); $user=$st->fetch(PDO::FETCH_ASSOC)?:null;
  if($user){ foreach(['is_super_admin','is_admin','is_chat_admin','is_global_mod','invisible_global','invisible_rooms','notify_pings','sound_ping','sound_broadcast'] as $k){ $user[$k]=(int)$user[$k]===1; } }
}
echo json_encode(['csrf'=>$_SESSION['csrf'],'user'=>$user,'settings'=>$settings]);
?>
