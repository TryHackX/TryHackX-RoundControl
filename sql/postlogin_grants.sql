-- =====================================================================
-- Konto `mailpostlogin` — jedyne, z którego korzysta /usr/local/bin/dovecot-postlogin.sh
-- (dane w /etc/dovecot/mysql-mailpanel.cnf, 400 dovecot:dovecot). Ma wyłącznie EXECUTE na procedurze
-- sp_register_login: nie czyta i nie zmienia żadnej tabeli.
--
-- Konto musi już istnieć:
--     CREATE USER 'mailpostlogin'@'localhost' IDENTIFIED BY '<losowe hasło>';
-- (install.sh sekcja 6 robi to sam i zapisuje hasło do /etc/dovecot/mysql-mailpanel.cnf).
-- Uruchom PO imporcie sql/panel_schema.sql (import usuwa i tworzy na nowo procedurę wraz z jej uprawnieniami):
--     mysql < sql/postlogin_grants.sql
-- =====================================================================

REVOKE ALL PRIVILEGES, GRANT OPTION FROM 'mailpostlogin'@'localhost';
GRANT EXECUTE ON PROCEDURE mailserver.sp_register_login TO 'mailpostlogin'@'localhost';
FLUSH PRIVILEGES;
