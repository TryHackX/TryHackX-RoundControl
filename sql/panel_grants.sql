-- =====================================================================
-- Uprawnienia bazy `mailserver` dla kont aplikacji (1.1.0, zasada najmniejszych uprawnień).
--
-- Uruchom jako administrator bazy PO imporcie sql/panel_schema.sql (import usuwa i tworzy na nowo procedurę
-- sp_register_login, a razem z nią jej uprawnienie EXECUTE):
--
--     mysql < sql/panel_grants.sql
--
-- Konta muszą już istnieć (install.sh, sekcja 0, zakłada brakujące z losowym hasłem); plik NIE zawiera haseł
-- i niczego nie tworzy — gdy konta brakuje, kończy się błędem na pierwszej instrukcji. Jest idempotentny:
-- najpierw odbiera wszystko, potem nadaje dokładnie poniższą listę (są to sekundy, w których konto nie ma praw).
--
--  * mailpanel     — konto aplikacji WWW (portal + panel; .env: PANEL_DB_USER). Uprawnienia per tabela, bez praw
--                    do tworzenia aliasów, bez DELETE na dziennikach (admin_login_attempts, admin_audit_logs,
--                    registration_events) i bez globalnego `mailserver.*`.
--  * mailpostlogin — konto skryptu Dovecota dovecot-postlogin.sh: sql/postlogin_grants.sql (osobny plik, bo to konto
--                    może jeszcze nie istnieć). Wyłącznie EXECUTE na jednej procedurze. Dawniej ten skrypt używał konta
--                    `mailpanel`, więc przejęcie użytkownika dovecot dawało pełną kontrolę nad bazą panelu.
--
-- Konta `mailuser` (Postfix/Dovecot, odczyt tabel virtual_*) i `mailpolicy` / `roundcubepw` mają własne granty
-- (install.sh sekcje 0, 4, 5). Jeśli `mailuser` ma dziś `SELECT ON mailserver.*`, możesz go zawęzić:
--     REVOKE ALL PRIVILEGES, GRANT OPTION FROM 'mailuser'@'localhost';
--     GRANT SELECT ON mailserver.virtual_domains TO 'mailuser'@'localhost';
--     GRANT SELECT ON mailserver.virtual_users   TO 'mailuser'@'localhost';
--     GRANT SELECT ON mailserver.virtual_aliases TO 'mailuser'@'localhost';
-- (sprawdź najpierw, że zapytania w /etc/postfix/mysql-*.cf i dovecot nie sięgają po inne tabele).
-- =====================================================================

REVOKE ALL PRIVILEGES, GRANT OPTION FROM 'mailpanel'@'localhost';

GRANT SELECT, INSERT, UPDATE, DELETE ON mailserver.virtual_users        TO 'mailpanel'@'localhost';
GRANT SELECT                         ON mailserver.virtual_domains      TO 'mailpanel'@'localhost';
-- aliasy: odczyt (rejestracja nie odda adresu wskazanego przez alias) i kasowanie aliasów kierujących na usuwane konto;
-- celowo BEZ INSERT/UPDATE — kto przejmie konto bazy panelu, nie przekieruje cudzej poczty
GRANT SELECT, DELETE                 ON mailserver.virtual_aliases      TO 'mailpanel'@'localhost';
GRANT SELECT, INSERT, UPDATE         ON mailserver.app_settings         TO 'mailpanel'@'localhost';
GRANT SELECT, INSERT, UPDATE, DELETE ON mailserver.ip_blocks            TO 'mailpanel'@'localhost';
GRANT SELECT, INSERT, UPDATE         ON mailserver.panel_admins         TO 'mailpanel'@'localhost';
GRANT SELECT, INSERT                 ON mailserver.admin_login_attempts TO 'mailpanel'@'localhost';
GRANT SELECT, INSERT                 ON mailserver.admin_audit_logs     TO 'mailpanel'@'localhost';
GRANT SELECT, INSERT                 ON mailserver.registration_events  TO 'mailpanel'@'localhost';
-- historia logowań i licznik wysyłki: odczyt + kasowanie przy usuwaniu konta (zapis robi procedura / policy service)
GRANT SELECT, DELETE                 ON mailserver.user_login_events    TO 'mailpanel'@'localhost';
GRANT SELECT, DELETE                 ON mailserver.send_counters        TO 'mailpanel'@'localhost';
GRANT SELECT, INSERT, DELETE         ON mailserver.throttle_events      TO 'mailpanel'@'localhost';
GRANT SELECT, INSERT, UPDATE         ON mailserver.deleted_accounts     TO 'mailpanel'@'localhost';
GRANT EXECUTE ON PROCEDURE mailserver.sp_register_login                 TO 'mailpanel'@'localhost';
-- 1.3.2: przechowywanie logów — usuwanie wyłącznie przez procedurę (wpisy starsze niż okno, min. 90 dni), nigdy DELETE
GRANT EXECUTE ON PROCEDURE mailserver.sp_prune_logs                     TO 'mailpanel'@'localhost';

FLUSH PRIVILEGES;
