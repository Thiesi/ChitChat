-- ChitChat v0.10.25 — PostgreSQL baseline schema (fresh install)
-- Create database chitchat if needed, then run this file.

CREATE TABLE IF NOT EXISTS users (
  id BIGSERIAL PRIMARY KEY,
  username VARCHAR(120) NOT NULL UNIQUE,
  password_hash VARCHAR(255) NOT NULL,
  location VARCHAR(255),
  sex VARCHAR(32),
  birthday DATE,
  is_super_admin INT DEFAULT 0,
  is_admin INT DEFAULT 0,
  is_chat_admin INT DEFAULT 0,
  is_global_mod INT DEFAULT 0,
  invisible_global INT DEFAULT 0,
  invisible_rooms INT DEFAULT 0,
  pub_last_active INT DEFAULT 0,
  pub_location INT DEFAULT 0,
  pub_sex INT DEFAULT 0,
  pub_birthday VARCHAR(16) DEFAULT 'hidden',
  theme VARCHAR(12) DEFAULT 'auto',
  timezone VARCHAR(64),
  notify_pings INT DEFAULT 1,
  sound_ping INT DEFAULT 0,
  sound_broadcast INT DEFAULT 0,
  sound_volume_ping INT NOT NULL DEFAULT 70,
  sound_volume_broadcast INT NOT NULL DEFAULT 70,
  force_logout_after TIMESTAMP NULL,
  created_at TIMESTAMP NOT NULL DEFAULT NOW()
);

CREATE TABLE IF NOT EXISTS user_bans (
  id BIGSERIAL PRIMARY KEY,
  user_id BIGINT NOT NULL,
  by_admin_id BIGINT NOT NULL,
  reason VARCHAR(255),
  until TIMESTAMP NULL
);

CREATE TABLE IF NOT EXISTS system_settings (
  id INT PRIMARY KEY,
  system_name VARCHAR(120),
  password_policy VARCHAR(16),
  default_timezone VARCHAR(64),
  system_closed INT DEFAULT 0,
  registrations_disabled INT DEFAULT 0,
  motd TEXT,
  bruteforce_max_attempts INT DEFAULT 10,
  bruteforce_lock_minutes INT DEFAULT 15,
  shutdown_active INT DEFAULT 0,
  shutdown_at TIMESTAMP NULL,
  shutdown_message TEXT,
  ask_birthday INT DEFAULT 1,
  ask_location INT DEFAULT 1,
  ask_sex INT DEFAULT 1,
  require_birthday INT DEFAULT 0,
  require_location INT DEFAULT 0,
  require_sex INT DEFAULT 0,
  allow_gmod_dm_export INT DEFAULT 0
);

INSERT INTO system_settings (id, system_name, password_policy, default_timezone)
  VALUES (1, 'ChitChat', 'low', 'UTC')
  ON CONFLICT (id) DO UPDATE SET system_name=EXCLUDED.system_name;

CREATE TABLE IF NOT EXISTS direct_messages (
  id BIGSERIAL PRIMARY KEY,
  sender_id BIGINT NOT NULL,
  receiver_id BIGINT NOT NULL,
  body TEXT NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT NOW()
);

CREATE TABLE IF NOT EXISTS login_attempts (
  id BIGSERIAL PRIMARY KEY,
  username VARCHAR(120),
  ip VARCHAR(64),
  reason VARCHAR(32),
  ok INT NOT NULL DEFAULT 0,
  created_at TIMESTAMP NOT NULL DEFAULT NOW()
);

CREATE TABLE IF NOT EXISTS audit_exports (
  id BIGSERIAL PRIMARY KEY,
  admin_user_id BIGINT NOT NULL,
  export_type VARCHAR(64) NOT NULL,
  params_json TEXT,
  created_at TIMESTAMP NOT NULL DEFAULT NOW()
);

CREATE TABLE IF NOT EXISTS rooms (
  id BIGSERIAL PRIMARY KEY,
  room_key VARCHAR(120) UNIQUE,
  name VARCHAR(120) NOT NULL,
  info_line VARCHAR(255),
  invisible INT DEFAULT 0,
  min_age INT DEFAULT 0,
  created_by BIGINT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT NOW()
);

CREATE TABLE IF NOT EXISTS room_members (
  room_id BIGINT NOT NULL,
  user_id BIGINT NOT NULL,
  role VARCHAR(16) NOT NULL DEFAULT 'member',
  PRIMARY KEY (room_id, user_id)
);
