-- =====================================================================
-- 1.3.2 -> 1.4.0 (run as root; the order against the code does not matter):
--
--     mysql mailserver < sql/upgrade_1.4.0.sql
--
-- 1) one collation for the whole database: the one of virtual_users.email. Panel tables created on MariaDB 11.5+
--    (utf8mb4_uca1400_ai_ci) next to older mail tables (utf8mb4/utf8mb3_general_ci) are converted; tables that
--    already match are not touched. Same blocks as in sql/panel_schema.sql.
-- 2) deleted_accounts.files_left: the mailbox files of a deleted account could not be moved to the quarantine;
--    such a name is not given out again until they are.
-- 3) an index on virtual_aliases.source (Postfix and the panel look aliases up by source).
-- 4) both procedures again (sp_prune_logs keeps tombstones with files_left = 1; sp_register_login only so its
--    parameters take the database collation). CREATE OR REPLACE keeps the EXECUTE grants.
-- No grant changes. Idempotent: a second run changes nothing.
-- =====================================================================

SET NAMES utf8mb4;

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

ALTER TABLE deleted_accounts ADD COLUMN IF NOT EXISTS files_left TINYINT(1) NOT NULL DEFAULT 0;

CREATE INDEX IF NOT EXISTS idx_source ON virtual_aliases (source);

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
