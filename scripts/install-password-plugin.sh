#!/bin/bash
# Włączenie samoobsługi zmiany hasła w Roundcube (plugin `password`, sterownik SQL).
# Plugin ships z Roundcube (plugins/password/) — tu tylko konfiguracja + user DB.
set -euo pipefail

RC=/var/www/roundcube
CFG="$RC/config/config.inc.php"
PCFG="$RC/plugins/password/config.inc.php"

sudo cp -n "$CFG" "$CFG.pre-password" || true

# 1) Dedykowany użytkownik DB — tylko SELECT/UPDATE na virtual_users
PW=$(openssl rand -hex 24)
sudo mysql <<SQL
CREATE USER IF NOT EXISTS 'roundcubepw'@'localhost' IDENTIFIED BY '$PW';
ALTER USER 'roundcubepw'@'localhost' IDENTIFIED BY '$PW';
GRANT SELECT, UPDATE ON mailserver.virtual_users TO 'roundcubepw'@'localhost';
FLUSH PRIVILEGES;
SQL

# 2) Konfiguracja pluginu (SHA512-CRYPT, zgodnie z Dovecotem)
sudo tee "$PCFG" >/dev/null <<CFGEOF
<?php
\$config['password_driver'] = 'sql';
\$config['password_confirm_current'] = true;
\$config['password_minimum_length'] = 10;
\$config['password_require_nonalpha'] = true;
\$config['password_force_save'] = true;
\$config['password_algorithm'] = 'sha512-crypt';
\$config['password_algorithm_prefix'] = '';
\$config['password_db_dsn'] = 'mysql://roundcubepw:$PW@localhost/mailserver';
\$config['password_query'] = 'UPDATE virtual_users SET password=%P WHERE email=%u';
CFGEOF
# grupa = użytkownik puli PHP-FPM Roundcube (install.sh sekcja 1b: mailweb; bez osobnej puli: www-data)
GRP="${PANEL_USER:-mailweb}"; getent group "$GRP" >/dev/null || GRP=www-data
sudo chown root:"$GRP" "$PCFG" && sudo chmod 640 "$PCFG"
sudo php -l "$PCFG"

# 3) Dopisz 'password' do listy pluginów w config.inc.php (ręcznie, jeśli brak):
#    $config['plugins'] = ['archive', 'zipdownload', 'newmail_notifier', 'custom_auth', 'password'];
sudo grep -q "'password'" "$CFG" || echo "!!! Dodaj 'password' do \$config['plugins'] w $CFG"

sudo rm -rf "$RC/temp/"* 2>/dev/null || true
echo "OK — Ustawienia → Hasło dostępne w webmailu."
