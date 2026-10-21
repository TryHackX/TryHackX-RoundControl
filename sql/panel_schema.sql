-- =====================================================================
-- Schemat bazy `mailserver` dla panelu (tabele + procedura + domyślne
-- ustawienia). Bezpieczny dla istniejących instalacji: CREATE TABLE IF
-- NOT EXISTS + INSERT IGNORE (niczego nie nadpisuje).
--
-- Import:  mysql -u root -p mailserver < panel_schema.sql
-- =====================================================================

SET NAMES utf8mb4;

-- ---------------------------------------------------------------
-- 1.4.0: ONE collation for the whole database — the one of virtual_users.email (Postfix/Dovecot's table, often older
-- than the panel). MariaDB 11.5+ creates new tables as utf8mb4_uca1400_ai_ci, so panel tables added to a database whose
-- mail tables are utf8mb4/utf8mb3_general_ci compared across collations and failed (1267 "Illegal mix of
-- collations": the user list, log retention, the login history). First the database default (new tables and the
-- procedure parameters take it), at the end of this file the panel tables that already exist. Nothing happens when
-- virtual_users does not exist yet (a new database: everything gets the server default).
-- ---------------------------------------------------------------
SET @rc_coll := (SELECT REPLACE(COLLATION_NAME, 'utf8mb3_', 'utf8mb4_') FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'virtual_users' AND COLUMN_NAME = 'email');
SET @rc_db := (SELECT DEFAULT_COLLATION_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = DATABASE());
-- (a new database created without a character set may default to latin1: then utf8mb4 with the server's collation)
SET @rc_sql := CASE
    WHEN @rc_coll IS NOT NULL AND @rc_coll <> @rc_db THEN CONCAT('ALTER DATABASE `', DATABASE(), '` CHARACTER SET utf8mb4 COLLATE ', @rc_coll)
    WHEN @rc_coll IS NULL AND @rc_db NOT LIKE 'utf8mb4\_%' THEN CONCAT('ALTER DATABASE `', DATABASE(), '` CHARACTER SET utf8mb4')
    ELSE 'DO 0' END;
PREPARE rc_stmt FROM @rc_sql;
EXECUTE rc_stmt;
DEALLOCATE PREPARE rc_stmt;

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
  password VARCHAR(255) NOT NULL,
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

-- 1.1.0: VARCHAR(106) nie mieści hashy "$6$rounds=N$..." (120 znaków) — ustawienie rounds w pluginie password
-- obcinałoby hashe i blokowało użytkowników. Poszerzenie nie zmienia danych; bezpieczne przy ponownym imporcie.
ALTER TABLE virtual_users MODIFY COLUMN password VARCHAR(255) NOT NULL;

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

-- 1.4.0: Postfix looks aliases up by source on every delivery, the panel on every name check.
CREATE INDEX IF NOT EXISTS idx_source ON virtual_aliases (source);

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

-- 1.1.0: zdarzenia do limitowania prób (nieudane logowania admin / webmail, zapytania o dostępność nazwy).
-- Osobno od user_login_events, bo sp_register_login przycina tamtą tabelę przy każdym udanym logowaniu z nowego IP
-- (udane logowanie ofiary zerowałoby licznik atakującego). ip = klucz klienta: IPv4 albo prefiks /64 IPv6
-- (uzupełniony zerami do 16 bajtów). Wiersze starsze niż doba są sprzątane przez aplikację.
CREATE TABLE IF NOT EXISTS throttle_events (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  kind VARCHAR(10) NOT NULL,              -- admin | webmail | probe
  email VARCHAR(190) NOT NULL DEFAULT '',
  ip VARBINARY(16) NOT NULL,
  created_at DATETIME NOT NULL,
  PRIMARY KEY (id),
  KEY idx_kind_ip_created (kind, ip, created_at),
  KEY idx_kind_email_ip_created (kind, email, ip, created_at),
  KEY idx_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 1.1.0: "nagrobki" usuniętych kont — rejestracja publiczna nie odda nazwy przez app_settings.deleted_name_hold_days
-- dni (nowy właściciel nie odziedziczy poczty i resetów haseł wysyłanych na dawny adres).
CREATE TABLE IF NOT EXISTS deleted_accounts (
  email VARCHAR(100) NOT NULL,
  deleted_at DATETIME NOT NULL,
  files_left TINYINT(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (email),
  KEY idx_deleted_at (deleted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 1.4.0: files_left = 1 — the mailbox files could not be moved to the quarantine when the account was deleted (no
-- helper, or it refused). Such a name is not given out again — by the portal never, by the panel only after it moved
-- the files — and the tombstone is never pruned while the flag is set.
ALTER TABLE deleted_accounts ADD COLUMN IF NOT EXISTS files_left TINYINT(1) NOT NULL DEFAULT 0;

-- 1.4.0: the end of each timed Fail2Ban ban the panel put into the jail panel-tempban. Fail2Ban ends a manually banned
-- address only by the jail's CURRENT bantime, so the jail keeps a fixed 90 days and the panel lifts each ban itself when
-- until_ts has passed (timer mailpanel-tempban-expire, App\Panel\TempBans). ip = VARBINARY(16) as in ip_blocks.
CREATE TABLE IF NOT EXISTS f2b_tempbans (
  ip VARBINARY(16) NOT NULL,
  until_ts DATETIME NOT NULL,
  created_at DATETIME NOT NULL,
  PRIMARY KEY (ip),
  KEY idx_until_ts (until_ts)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------
-- Procedura: rejestracja logowania użytkownika (deduplikacja IP,
-- trzyma pierwsze + 2 ostatnie zdarzenia per konto)
-- 1.1.0: CREATE OR REPLACE zamiast DROP + CREATE — MariaDB zachowuje przy tym uprawnienia EXECUTE nadane
-- kontom (mailpanel, mailpostlogin); po DROP przepadały i rejestracja logowań cicho przestawała działać.
-- ---------------------------------------------------------------

DELIMITER $$
CREATE OR REPLACE PROCEDURE sp_register_login (IN p_email VARCHAR(190), IN p_ip_str VARCHAR(45))
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
-- 1.3.2: przechowywanie logów. Konto panelu NIE ma prawa DELETE na dziennikach (audyt, logowania administratorów,
-- rejestracje) — może tylko poprosić tę procedurę (SQL SECURITY DEFINER) o usunięcie wpisów STARSZYCH niż okno
-- przechowywania, a okno ma co najmniej 90 dni: nawet przejęte konto panelu nie wymaże świeżych śladów.
-- Partie po 1000 wierszy (krótkie blokady), najwyżej p_rounds partii na tabelę w jednym wywołaniu.
--   admin_audit_logs, admin_login_attempts   wpisy starsze niż okno
--   registration_events   starsze niż okno, ale udana rejestracja ISTNIEJĄCEGO konta zostaje (data utworzenia
--                         i IP rejestracji w liście kont)
--   user_login_events     starsze niż okno, ale ostatnie logowanie każdego konta zostaje (kolumna "ostatnie logowanie")
--   send_counters         dni starsze niż okno (limit dzienny potrzebuje tylko dnia dzisiejszego)
--   deleted_accounts      starsze niż MAX(okno, deleted_name_hold_days, 30 dni kwarantanny) — blokada nazw działa dalej;
--                         1.4.0: never while files_left = 1 (the old mailbox is still on disk)
-- p_days <= 0 ("bez limitu") nic nie usuwa. Wynik: jeden wiersz z liczbą usuniętych wierszy per tabela.
-- ---------------------------------------------------------------

DELIMITER $$
CREATE OR REPLACE PROCEDURE sp_prune_logs (IN p_days INT, IN p_rounds INT)
BEGIN
    DECLARE v_days INT;
    DECLARE v_keep_del INT;
    DECLARE v_rounds INT;
    DECLARE v_cut DATETIME;
    DECLARE v_n INT DEFAULT 0;
    DECLARE v_i INT DEFAULT 0;
    DECLARE c_audit INT DEFAULT 0;
    DECLARE c_admin INT DEFAULT 0;
    DECLARE c_reg INT DEFAULT 0;
    DECLARE c_user INT DEFAULT 0;
    DECLARE c_send INT DEFAULT 0;
    DECLARE c_del INT DEFAULT 0;

    IF p_days IS NOT NULL AND p_days > 0 THEN
        SET v_days   = GREATEST(p_days, 90);
        SET v_rounds = LEAST(GREATEST(COALESCE(p_rounds, 20), 1), 200);
        SET v_cut    = NOW() - INTERVAL v_days DAY;
        SET v_keep_del = GREATEST(v_days, 30,
            COALESCE((SELECT CAST(v AS SIGNED) FROM app_settings WHERE k = 'deleted_name_hold_days'), 365));

        SET v_i = 0;
        REPEAT
            DELETE FROM admin_audit_logs WHERE created_at < v_cut LIMIT 1000;
            SET v_n = ROW_COUNT(); SET c_audit = c_audit + v_n; SET v_i = v_i + 1;
        UNTIL v_n < 1000 OR v_i >= v_rounds END REPEAT;

        SET v_i = 0;
        REPEAT
            DELETE FROM admin_login_attempts WHERE created_at < v_cut LIMIT 1000;
            SET v_n = ROW_COUNT(); SET c_admin = c_admin + v_n; SET v_i = v_i + 1;
        UNTIL v_n < 1000 OR v_i >= v_rounds END REPEAT;

        SET v_i = 0;
        REPEAT
            DELETE FROM registration_events
            WHERE created_at < v_cut
              AND (status <> 'success' OR email NOT IN (SELECT email FROM virtual_users))
            LIMIT 1000;
            SET v_n = ROW_COUNT(); SET c_reg = c_reg + v_n; SET v_i = v_i + 1;
        UNTIL v_n < 1000 OR v_i >= v_rounds END REPEAT;

        SET v_i = 0;
        REPEAT
            DELETE FROM user_login_events
            WHERE created_at < v_cut
              AND (email NOT IN (SELECT email FROM virtual_users)
                   OR id NOT IN (SELECT id FROM (SELECT MAX(id) AS id FROM user_login_events GROUP BY email) AS keep_last))
            LIMIT 1000;
            SET v_n = ROW_COUNT(); SET c_user = c_user + v_n; SET v_i = v_i + 1;
        UNTIL v_n < 1000 OR v_i >= v_rounds END REPEAT;

        SET v_i = 0;
        REPEAT
            DELETE FROM send_counters WHERE day < CURDATE() - INTERVAL v_days DAY LIMIT 1000;
            SET v_n = ROW_COUNT(); SET c_send = c_send + v_n; SET v_i = v_i + 1;
        UNTIL v_n < 1000 OR v_i >= v_rounds END REPEAT;

        SET v_i = 0;
        REPEAT
            DELETE FROM deleted_accounts WHERE deleted_at < NOW() - INTERVAL v_keep_del DAY AND files_left = 0 LIMIT 1000;
            SET v_n = ROW_COUNT(); SET c_del = c_del + v_n; SET v_i = v_i + 1;
        UNTIL v_n < 1000 OR v_i >= v_rounds END REPEAT;
    END IF;

    SELECT c_audit AS audit, c_admin AS admin_logins, c_reg AS registrations, c_user AS user_logins,
           c_send AS send_counters, c_del AS deleted_accounts;
END$$
DELIMITER ;

-- ---------------------------------------------------------------
-- 1.4.0: tables created earlier with another collation get the one of virtual_users.email (see the top of the file):
-- the panel's tables, and virtual_domains / virtual_aliases when this file created them under a newer default.
-- utf8mb3_x and utf8mb4_x count as the same collation (they compare without errors), so an older mail database is not
-- converted needlessly. CONVERT rewrites a table; it runs only for one that differs, so a second import changes nothing.
-- ---------------------------------------------------------------
SET @rc_coll := (SELECT REPLACE(COLLATION_NAME, 'utf8mb3_', 'utf8mb4_') FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'virtual_users' AND COLUMN_NAME = 'email');
SET @rc_sql := (SELECT IF(@rc_coll IS NULL OR REPLACE(TABLE_COLLATION, 'utf8mb3_', 'utf8mb4_') = @rc_coll, 'DO 0', CONCAT('ALTER TABLE virtual_domains CONVERT TO CHARACTER SET utf8mb4 COLLATE ', @rc_coll)) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'virtual_domains');
PREPARE rc_stmt FROM @rc_sql;
EXECUTE rc_stmt;
DEALLOCATE PREPARE rc_stmt;
SET @rc_sql := (SELECT IF(@rc_coll IS NULL OR REPLACE(TABLE_COLLATION, 'utf8mb3_', 'utf8mb4_') = @rc_coll, 'DO 0', CONCAT('ALTER TABLE virtual_aliases CONVERT TO CHARACTER SET utf8mb4 COLLATE ', @rc_coll)) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'virtual_aliases');
PREPARE rc_stmt FROM @rc_sql;
EXECUTE rc_stmt;
DEALLOCATE PREPARE rc_stmt;
SET @rc_sql := (SELECT IF(@rc_coll IS NULL OR REPLACE(TABLE_COLLATION, 'utf8mb3_', 'utf8mb4_') = @rc_coll, 'DO 0', CONCAT('ALTER TABLE app_settings CONVERT TO CHARACTER SET utf8mb4 COLLATE ', @rc_coll)) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'app_settings');
PREPARE rc_stmt FROM @rc_sql;
EXECUTE rc_stmt;
DEALLOCATE PREPARE rc_stmt;
SET @rc_sql := (SELECT IF(@rc_coll IS NULL OR REPLACE(TABLE_COLLATION, 'utf8mb3_', 'utf8mb4_') = @rc_coll, 'DO 0', CONCAT('ALTER TABLE panel_admins CONVERT TO CHARACTER SET utf8mb4 COLLATE ', @rc_coll)) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'panel_admins');
PREPARE rc_stmt FROM @rc_sql;
EXECUTE rc_stmt;
DEALLOCATE PREPARE rc_stmt;
SET @rc_sql := (SELECT IF(@rc_coll IS NULL OR REPLACE(TABLE_COLLATION, 'utf8mb3_', 'utf8mb4_') = @rc_coll, 'DO 0', CONCAT('ALTER TABLE ip_blocks CONVERT TO CHARACTER SET utf8mb4 COLLATE ', @rc_coll)) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ip_blocks');
PREPARE rc_stmt FROM @rc_sql;
EXECUTE rc_stmt;
DEALLOCATE PREPARE rc_stmt;
SET @rc_sql := (SELECT IF(@rc_coll IS NULL OR REPLACE(TABLE_COLLATION, 'utf8mb3_', 'utf8mb4_') = @rc_coll, 'DO 0', CONCAT('ALTER TABLE registration_events CONVERT TO CHARACTER SET utf8mb4 COLLATE ', @rc_coll)) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'registration_events');
PREPARE rc_stmt FROM @rc_sql;
EXECUTE rc_stmt;
DEALLOCATE PREPARE rc_stmt;
SET @rc_sql := (SELECT IF(@rc_coll IS NULL OR REPLACE(TABLE_COLLATION, 'utf8mb3_', 'utf8mb4_') = @rc_coll, 'DO 0', CONCAT('ALTER TABLE admin_login_attempts CONVERT TO CHARACTER SET utf8mb4 COLLATE ', @rc_coll)) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'admin_login_attempts');
PREPARE rc_stmt FROM @rc_sql;
EXECUTE rc_stmt;
DEALLOCATE PREPARE rc_stmt;
SET @rc_sql := (SELECT IF(@rc_coll IS NULL OR REPLACE(TABLE_COLLATION, 'utf8mb3_', 'utf8mb4_') = @rc_coll, 'DO 0', CONCAT('ALTER TABLE admin_audit_logs CONVERT TO CHARACTER SET utf8mb4 COLLATE ', @rc_coll)) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'admin_audit_logs');
PREPARE rc_stmt FROM @rc_sql;
EXECUTE rc_stmt;
DEALLOCATE PREPARE rc_stmt;
SET @rc_sql := (SELECT IF(@rc_coll IS NULL OR REPLACE(TABLE_COLLATION, 'utf8mb3_', 'utf8mb4_') = @rc_coll, 'DO 0', CONCAT('ALTER TABLE user_login_events CONVERT TO CHARACTER SET utf8mb4 COLLATE ', @rc_coll)) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'user_login_events');
PREPARE rc_stmt FROM @rc_sql;
EXECUTE rc_stmt;
DEALLOCATE PREPARE rc_stmt;
SET @rc_sql := (SELECT IF(@rc_coll IS NULL OR REPLACE(TABLE_COLLATION, 'utf8mb3_', 'utf8mb4_') = @rc_coll, 'DO 0', CONCAT('ALTER TABLE throttle_events CONVERT TO CHARACTER SET utf8mb4 COLLATE ', @rc_coll)) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'throttle_events');
PREPARE rc_stmt FROM @rc_sql;
EXECUTE rc_stmt;
DEALLOCATE PREPARE rc_stmt;
SET @rc_sql := (SELECT IF(@rc_coll IS NULL OR REPLACE(TABLE_COLLATION, 'utf8mb3_', 'utf8mb4_') = @rc_coll, 'DO 0', CONCAT('ALTER TABLE deleted_accounts CONVERT TO CHARACTER SET utf8mb4 COLLATE ', @rc_coll)) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'deleted_accounts');
PREPARE rc_stmt FROM @rc_sql;
EXECUTE rc_stmt;
DEALLOCATE PREPARE rc_stmt;
SET @rc_sql := (SELECT IF(@rc_coll IS NULL OR REPLACE(TABLE_COLLATION, 'utf8mb3_', 'utf8mb4_') = @rc_coll, 'DO 0', CONCAT('ALTER TABLE send_counters CONVERT TO CHARACTER SET utf8mb4 COLLATE ', @rc_coll)) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'send_counters');
PREPARE rc_stmt FROM @rc_sql;
EXECUTE rc_stmt;
DEALLOCATE PREPARE rc_stmt;
SET @rc_sql := (SELECT IF(@rc_coll IS NULL OR REPLACE(TABLE_COLLATION, 'utf8mb3_', 'utf8mb4_') = @rc_coll, 'DO 0', CONCAT('ALTER TABLE f2b_tempbans CONVERT TO CHARACTER SET utf8mb4 COLLATE ', @rc_coll)) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'f2b_tempbans');
PREPARE rc_stmt FROM @rc_sql;
EXECUTE rc_stmt;
DEALLOCATE PREPARE rc_stmt;

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
('smtp_max_rcpt_per_msg', '50'),
('reserved_local_parts', 'info,support,contact,sales,marketing,billing,privacy,legal,press,jobs,help,office,mail,team,service,services,staff,owner,moderator'),
('deleted_name_hold_days', '365'),
('log_retention_days', '365');

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
