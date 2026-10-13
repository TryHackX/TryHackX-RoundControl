-- =====================================================================
-- 1.3.1 -> 1.3.2: log retention (run as root, AFTER the code or before it — the order does not matter):
--
--     mysql mailserver < sql/upgrade_1.3.2.sql
--
-- 1) the procedure sp_prune_logs (the same text as in sql/panel_schema.sql — a fresh install gets it from there),
-- 2) EXECUTE on it for the panel account (the panel still has NO DELETE on the audit / login / registration logs:
--    it can only ask the procedure to remove entries older than the retention window, which is at least 90 days),
-- 3) the retention stored explicitly (365 days) unless one is already set — a future change of the code default
--    never changes a running site.
-- Idempotent: CREATE OR REPLACE keeps grants, GRANT and INSERT IGNORE can be repeated.
-- =====================================================================

-- ---------------------------------------------------------------
-- przechowywanie logów: patrz opis w sql/panel_schema.sql
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
            DELETE FROM deleted_accounts WHERE deleted_at < NOW() - INTERVAL v_keep_del DAY LIMIT 1000;
            SET v_n = ROW_COUNT(); SET c_del = c_del + v_n; SET v_i = v_i + 1;
        UNTIL v_n < 1000 OR v_i >= v_rounds END REPEAT;
    END IF;

    SELECT c_audit AS audit, c_admin AS admin_logins, c_reg AS registrations, c_user AS user_logins,
           c_send AS send_counters, c_del AS deleted_accounts;
END$$
DELIMITER ;

GRANT EXECUTE ON PROCEDURE mailserver.sp_prune_logs TO 'mailpanel'@'localhost';

INSERT IGNORE INTO app_settings (k, v) VALUES ('log_retention_days', '365');

