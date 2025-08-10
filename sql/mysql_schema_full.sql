-- ChitChat v0.10.25 — MySQL baseline schema (fresh install)
-- Create database chitchat if needed:
--   CREATE DATABASE chitchat CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
-- Then run this file.

CREATE TABLE IF NOT EXISTS users (
  id BIGINT PRIMARY KEY AUTO_INCREMENT,
  username VARCHAR(120) NOT NULL UNIQUE,
  password_hash VARCHAR(255) NOT NULL,
  location VARCHAR(255) NULL,
  sex VARCHAR(32) NULL,
  birthday DATE NULL,
  is_super_admin TINYINT(1) DEFAULT 0,
  is_admin TINYINT(1) DEFAULT 0,
  is_chat_admin TINYINT(1) DEFAULT 0,
  is_global_mod TINYINT(1) DEFAULT 0,
  invisible_global TINYINT(1) DEFAULT 0,
  invisible_rooms TINYINT(1) DEFAULT 0,
  pub_last_active TINYINT(1) DEFAULT 0,
  pub_location TINYINT(1) DEFAULT 0,
  pub_sex TINYINT(1) DEFAULT 0,
  pub_birthday VARCHAR(16) DEFAULT 'hidden',
  theme VARCHAR(12) DEFAULT 'auto',
  timezone VARCHAR(64) NULL,
  notify_pings TINYINT(1) DEFAULT 1,
  sound_ping TINYINT(1) DEFAULT 0,
  sound_broadcast TINYINT(1) DEFAULT 0,
  sound_volume_ping INT NOT NULL DEFAULT 70,
  sound_volume_broadcast INT NOT NULL DEFAULT 70,
  force_logout_after DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS user_bans (
  id BIGINT PRIMARY KEY AUTO_INCREMENT,
  user_id BIGINT NOT NULL,
  by_admin_id BIGINT NOT NULL,
  reason VARCHAR(255),
  until DATETIME NULL
);

CREATE TABLE IF NOT EXISTS system_settings (
  id INT PRIMARY KEY,
  system_name VARCHAR(120),
  password_policy VARCHAR(16),
  default_timezone VARCHAR(64),
  system_closed TINYINT(1) DEFAULT 0,
  registrations_disabled TINYINT(1) DEFAULT 0,
  motd TEXT,
  bruteforce_max_attempts INT DEFAULT 10,
  bruteforce_lock_minutes INT DEFAULT 15,
  shutdown_active TINYINT(1) DEFAULT 0,
  shutdown_at DATETIME NULL,
  shutdown_message TEXT,
  ask_birthday TINYINT(1) DEFAULT 1,
  ask_location TINYINT(1) DEFAULT 1,
  ask_sex TINYINT(1) DEFAULT 1,
  require_birthday TINYINT(1) DEFAULT 0,
  require_location TINYINT(1) DEFAULT 0,
  require_sex TINYINT(1) DEFAULT 0,
  allow_gmod_dm_export TINYINT(1) DEFAULT 0
);

INSERT INTO system_settings (id, system_name, password_policy, default_timezone)
  VALUES (1, 'ChitChat', 'low', 'UTC')
  ON DUPLICATE KEY UPDATE system_name=VALUES(system_name);

CREATE TABLE IF NOT EXISTS direct_messages (
  id BIGINT PRIMARY KEY AUTO_INCREMENT,
  sender_id BIGINT NOT NULL,
  receiver_id BIGINT NOT NULL,
  body TEXT NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS login_attempts (
  id BIGINT PRIMARY KEY AUTO_INCREMENT,
  username VARCHAR(120),
  ip VARCHAR(64),
  reason VARCHAR(32),
  ok TINYINT(1) NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS audit_exports (
  id BIGINT PRIMARY KEY AUTO_INCREMENT,
  admin_user_id BIGINT NOT NULL,
  export_type VARCHAR(64) NOT NULL,
  params_json TEXT,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
);

-- Rooms (basic)
CREATE TABLE IF NOT EXISTS rooms (
  id BIGINT PRIMARY KEY AUTO_INCREMENT,
  room_key VARCHAR(120) UNIQUE,
  name VARCHAR(120) NOT NULL,
  info_line VARCHAR(255) NULL,
  invisible TINYINT(1) DEFAULT 0,
  min_age INT DEFAULT 0,
  created_by BIGINT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS room_members (
  room_id BIGINT NOT NULL,
  user_id BIGINT NOT NULL,
  role ENUM('member','mod','owner') NOT NULL DEFAULT 'member',
  PRIMARY KEY (room_id, user_id)
);
