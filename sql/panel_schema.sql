-- =====================================================================
-- Schemat bazy `mailserver` dla panelu (tabele + procedura + domyślne
-- ustawienia). Bezpieczny dla istniejących instalacji: CREATE TABLE IF
-- NOT EXISTS + INSERT IGNORE (niczego nie nadpisuje).
--
-- Import:  mysql -u root -p mailserver < panel_schema.sql
-- =====================================================================

SET NAMES utf8mb4;

-- ---------------------------------------------------------------
-- Tabele serwera pocztowego (Postfix/Dovecot virtual users).
-- Jeśli masz już działający mailserver — te CREATE zostaną pominięte.
-- ---------------------------------------------------------------

CREATE TABLE IF NOT EXISTS virtual_domains (
  id INT NOT NULL AUTO_INCREMENT,
  name VARCHAR(50) NOT NULL,
  PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS virtual_users (
  id INT NOT NULL AUTO_INCREMENT,
  domain_id INT NOT NULL,
  password VARCHAR(106) NOT NULL,
  email VARCHAR(100) NOT NULL,
  quota_mb INT NOT NULL DEFAULT 0,
  -- indywidualny dzienny limit wysyłki: NULL = globalny (app_settings
  -- daily_send_limit), 0 = bez limitu; egzekwuje mailpanel-policy-daily.php
  daily_send_limit INT NULL DEFAULT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY email (email),
  KEY domain_id (domain_id),
  CONSTRAINT virtual_users_ibfk_1 FOREIGN KEY (domain_id)
    REFERENCES virtual_domains (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Migracja istniejących instalacji (MariaDB: bezpieczne przy ponownym imporcie)
ALTER TABLE virtual_users ADD COLUMN IF NOT EXISTS daily_send_limit INT NULL DEFAULT NULL AFTER quota_mb;

-- Liczniki dziennej wysyłki (zapisuje policy service Postfiksa,
-- czyta panel w modalu "Zmiana Quota"); notified = czy wysłano już dziś
-- e-mail informujący nadawcę o wyczerpaniu limitu (raz dziennie)
CREATE TABLE IF NOT EXISTS send_counters (
  email VARCHAR(100) NOT NULL,
  day DATE NOT NULL,
  cnt INT UNSIGNED NOT NULL DEFAULT 0,
  notified TINYINT(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (email, day),
  KEY idx_day (day)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

ALTER TABLE send_counters ADD COLUMN IF NOT EXISTS notified TINYINT(1) NOT NULL DEFAULT 0;

CREATE TABLE IF NOT EXISTS virtual_aliases (
  id INT NOT NULL AUTO_INCREMENT,
  domain_id INT NOT NULL,
  source VARCHAR(100) NOT NULL,
  destination VARCHAR(100) NOT NULL,
  PRIMARY KEY (id),
  KEY domain_id (domain_id),
  CONSTRAINT virtual_aliases_ibfk_1 FOREIGN KEY (domain_id)
    REFERENCES virtual_domains (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------
-- Tabele panelu
-- ---------------------------------------------------------------

CREATE TABLE IF NOT EXISTS panel_admins (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  email VARCHAR(190) NOT NULL,
  pwd_hash VARCHAR(255) NOT NULL,
  created_at DATETIME NOT NULL,
  last_login DATETIME DEFAULT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS app_settings (
  k VARCHAR(100) NOT NULL,
  v TEXT NOT NULL,
  PRIMARY KEY (k)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS ip_blocks (
  ip VARBINARY(16) NOT NULL,
  until_ts DATETIME NOT NULL,
  reason VARCHAR(255) DEFAULT NULL,
  PRIMARY KEY (ip),
  KEY idx_until_ts (until_ts)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS registration_events (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  ip VARBINARY(16) NOT NULL,
  email VARCHAR(190) NOT NULL,
  status ENUM('success','failed','blocked') NOT NULL DEFAULT 'success',
  reason VARCHAR(255) DEFAULT NULL,
  created_at DATETIME NOT NULL,
  PRIMARY KEY (id),
  KEY idx_created_at (created_at),
  KEY idx_email (email),
  KEY idx_ip (ip),
  KEY idx_status_created (status, created_at),
  KEY idx_ip_status_created (ip, status, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS admin_login_attempts (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  ip VARBINARY(16) NOT NULL,
  username VARCHAR(190) NOT NULL,
  success TINYINT(1) NOT NULL,
  details VARCHAR(255) DEFAULT NULL,
  created_at DATETIME NOT NULL,
  PRIMARY KEY (id),
  KEY idx_ip (ip),
  KEY idx_created_at (created_at),
  KEY idx_username (username),
  KEY idx_ip_success_created (ip, success, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS admin_audit_logs (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  admin_email VARCHAR(190) NOT NULL,
  ip VARBINARY(16) NOT NULL,
  action_type VARCHAR(50) NOT NULL,
  details TEXT DEFAULT NULL,
  created_at DATETIME NOT NULL,
  PRIMARY KEY (id),
  KEY idx_created (created_at),
  KEY idx_admin (admin_email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS user_login_events (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  email VARCHAR(190) NOT NULL,
  ip VARBINARY(16) NOT NULL,
  created_at DATETIME NOT NULL,
  PRIMARY KEY (id),
  KEY idx_email (email),
  KEY idx_ip (ip),
  KEY idx_created_at (created_at),
  KEY idx_email_created (email, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------
-- Procedura: rejestracja logowania użytkownika (deduplikacja IP,
-- trzyma pierwsze + 2 ostatnie zdarzenia per konto)
-- ---------------------------------------------------------------

DROP PROCEDURE IF EXISTS sp_register_login;

DELIMITER $$
CREATE PROCEDURE sp_register_login (IN p_email VARCHAR(190), IN p_ip_str VARCHAR(45))
BEGIN
    DECLARE v_ip VARBINARY(16);
    DECLARE v_last_id BIGINT DEFAULT NULL;
    DECLARE v_last_ip VARBINARY(16) DEFAULT NULL;

    SET v_ip = INET6_ATON(p_ip_str);

    SELECT id, ip INTO v_last_id, v_last_ip
    FROM user_login_events
    WHERE email = p_email
    ORDER BY created_at DESC LIMIT 1;

    IF v_last_id IS NOT NULL AND v_last_ip = v_ip THEN
        UPDATE user_login_events SET created_at = NOW() WHERE id = v_last_id;
    ELSE
        INSERT INTO user_login_events (email, ip, created_at) VALUES (p_email, v_ip, NOW());

        DELETE FROM user_login_events
        WHERE email = p_email
        AND id NOT IN (
            SELECT id FROM (
                (SELECT id FROM user_login_events WHERE email = p_email ORDER BY created_at ASC LIMIT 1)
                UNION
                (SELECT id FROM user_login_events WHERE email = p_email ORDER BY created_at DESC LIMIT 2)
            ) AS keep_ids
        );
    END IF;
END$$
DELIMITER ;

-- ---------------------------------------------------------------
-- Domyślne ustawienia (INSERT IGNORE — istniejące wartości zostają).
-- Wszystko edytowalne później z poziomu panelu.
-- ---------------------------------------------------------------

INSERT IGNORE INTO app_settings (k, v) VALUES
('allow_registration', '1'),
('registration_enabled', '1'),
('accounts_per_ip', '3'),
('ip_max_accounts', '3'),
('ip_window_hours', '24'),
('ip_total_limit', '15'),
('register_min_interval_seconds', '20'),
('mailbox_default_quota_mb', '1024'),
('daily_send_limit', '50'),
('admin_lock_threshold', '5'),
('admin_lock_minutes', '15'),
('max_login_attempts', '5'),
('ban_duration_minutes', '15'),
('pwd_min_length', '10'),
('pwd_require_upper', '1'),
('pwd_require_digit', '1'),
('pwd_require_special', '1'),
('recaptcha_enabled', '0'),
('recaptcha_site_key', ''),
('recaptcha_secret', ''),
('rc_max_recipients', '10'),
('rc_max_disclosed_recipients', '5'),
('rc_max_group_members', '5'),
('rc_sendmail_delay', '15'),
('rc_login_rate_limit', '3'),
('pf_message_rate_limit', '15'),
('pf_connection_rate_limit', '10'),
('pf_connection_count_limit', '10'),
('smtp_max_per_hour', '200'),
('smtp_max_rcpt_per_msg', '50');

-- ---------------------------------------------------------------
-- PO IMPORCIE zrób ręcznie (patrz README.md):
--
-- 1) Dodaj swoją domenę (jeśli tabela była pusta):
--    INSERT INTO virtual_domains (name) VALUES ('twojadomena.pl');
--
-- 2) Utwórz pierwszego administratora panelu (hash Argon2id):
--    php -r 'echo password_hash("TwojeHaslo", PASSWORD_ARGON2ID), PHP_EOL;'
--    INSERT INTO panel_admins (email, pwd_hash, created_at)
--    VALUES ('admin@twojadomena.pl', '<WYNIK_POWYŻSZEJ_KOMENDY>', NOW());
-- ---------------------------------------------------------------
