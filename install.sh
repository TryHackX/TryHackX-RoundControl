#!/bin/bash
# =====================================================================
# Instalator integracji systemowych panelu Roundcube (uruchom jako root).
# Cel: Debian 13 (trixie) — Dovecot 2.4, Postfix 3.10, Fail2Ban 1.1.
# Idempotentny, z bramką potwierdzenia per sekcja i kopiami zapasowymi.
# Ponowne uruchomienie NIE rotuje istniejących sekretów i NIE duplikuje
# konfiguracji (jaile w /etc/fail2ban/jail.d/, sekcje wykrywane).
# Warstwę aplikacji (.env, schemat, admin) instaluje osobno: php install.php
# =====================================================================
set -uo pipefail

SRC="$(cd "$(dirname "$0")" && pwd)"
RC_DIR="${RC_DIR:-/var/www/roundcube}"
WEB_USER="${WEB_USER:-www-data}"

c_grn='\033[32m'; c_red='\033[31m'; c_yel='\033[33m'; c_rst='\033[0m'; c_b='\033[1m'
ok()   { echo -e "${c_grn}✓${c_rst} $*"; }
warn() { echo -e "${c_yel}!${c_rst} $*"; }
die()  { echo -e "${c_red}✗ $*${c_rst}"; exit 1; }
hr()   { printf '%.0s─' {1..60}; echo; }
ask()  { local a; read -rp "$1 (t/N): " a; [[ "$a" =~ ^[tTyY] ]]; }

# Uruchamia SQL przez mysql CLI; zwraca 1 (i warn) przy błędzie zamiast
# cichego przejścia dalej.
run_sql() {
  local out
  if ! out=$(mysql 2>&1 <<<"$1"); then
    warn "Błąd MySQL: ${out:-nieznany} — sekcja niedokończona."
    return 1
  fi
}

[ "$(id -u)" -eq 0 ] || die "Uruchom jako root (sudo bash install.sh)."
[ -d "$RC_DIR" ] || die "Nie znaleziono $RC_DIR (ustaw RC_DIR=... jeśli inna ścieżka)."
command -v mysql >/dev/null || warn "Brak klienta mysql w PATH — sekcje 4/5 wymagają MariaDB/MySQL."

# Skrypt celuje w Debiana 13 (składnia Dovecot 2.4 itd.) — na innym systemie
# tylko ostrzegamy, decyzja należy do operatora.
if [ -r /etc/os-release ]; then
  . /etc/os-release
  if [ "${ID:-}" != "debian" ] || [ "${VERSION_ID:-}" != "13" ]; then
    warn "Wykryto: ${PRETTY_NAME:-nieznany system}. Instalator jest testowany na Debianie 13."
    ask "Kontynuować mimo to" || die "Przerwano."
  fi
fi

echo -e "${c_b}  Panel Roundcube — integracje systemowe${c_rst}"
hr
echo "Roundcube: $RC_DIR   |   web user: $WEB_USER"
hr

# --- 0. Baza danych (mailserver + mailuser + mailpanel + schemat) ---
if ask "0) Utworzyć bazę mailserver, userów mailuser/mailpanel i zaimportować schemat"; then
  # Idempotencja: hasła istniejących userów NIE są rotowane — mailuser czytamy
  # z map Postfixa, mailpanel z .env; nowe generujemy tylko przy ich braku
  # (wtedy hasło pojawia się na konsoli — ZAPISZ je i wpisz do configów).
  MU_PW=$(grep -oP '^\s*password\s*=\s*\K\S+' /etc/postfix/mysql-virtual-mailbox-domains.cf 2>/dev/null | head -1 || true)
  MP_PW=$(grep -oP '^PANEL_DB_PASS=\K.*' "$RC_DIR/.env" 2>/dev/null | tr -d '"' || true)
  MU_NEW=0; MP_NEW=0
  [ -n "$MU_PW" ] || { MU_PW=$(openssl rand -hex 24); MU_NEW=1; }
  [ -n "$MP_PW" ] || { MP_PW=$(openssl rand -hex 24); MP_NEW=1; }
  if run_sql "CREATE DATABASE IF NOT EXISTS mailserver CHARACTER SET utf8mb4;
CREATE USER IF NOT EXISTS 'mailuser'@'localhost' IDENTIFIED BY '$MU_PW';
ALTER USER 'mailuser'@'localhost' IDENTIFIED BY '$MU_PW';
GRANT SELECT ON mailserver.* TO 'mailuser'@'localhost';
CREATE USER IF NOT EXISTS 'mailpanel'@'localhost' IDENTIFIED BY '$MP_PW';
ALTER USER 'mailpanel'@'localhost' IDENTIFIED BY '$MP_PW';
GRANT SELECT, INSERT, UPDATE, DELETE ON mailserver.virtual_users   TO 'mailpanel'@'localhost';
GRANT SELECT                         ON mailserver.virtual_domains TO 'mailpanel'@'localhost';
GRANT SELECT, INSERT, UPDATE, DELETE ON mailserver.app_settings    TO 'mailpanel'@'localhost';
GRANT SELECT, INSERT, UPDATE, DELETE ON mailserver.ip_blocks       TO 'mailpanel'@'localhost';
GRANT SELECT, INSERT, UPDATE         ON mailserver.panel_admins    TO 'mailpanel'@'localhost';
GRANT SELECT, INSERT, DELETE         ON mailserver.admin_login_attempts TO 'mailpanel'@'localhost';
GRANT SELECT, INSERT                 ON mailserver.user_login_events    TO 'mailpanel'@'localhost';
GRANT SELECT, INSERT                 ON mailserver.admin_audit_logs     TO 'mailpanel'@'localhost';
GRANT SELECT, INSERT                 ON mailserver.registration_events  TO 'mailpanel'@'localhost';
GRANT SELECT                         ON mailserver.send_counters        TO 'mailpanel'@'localhost';
FLUSH PRIVILEGES;"; then
    ok "baza mailserver + granty mailuser (SELECT) i mailpanel (per tabela)"
    [ "$MU_NEW" -eq 1 ] && warn "NOWE hasło mailuser (wpisz w mapy mysql-virtual-*.cf i dovecot.conf): $MU_PW"
    [ "$MU_NEW" -eq 0 ] && ok "hasło mailuser: istniejące (z map Postfixa)"
    [ "$MP_NEW" -eq 1 ] && warn "NOWE hasło mailpanel (podasz w install.php / wpisz do .env): $MP_PW"
    [ "$MP_NEW" -eq 0 ] && ok "hasło mailpanel: istniejące (z .env)"
    if [ -f "$SRC/sql/panel_schema.sql" ]; then
      ERRF=$(mktemp)
      if mysql mailserver < "$SRC/sql/panel_schema.sql" 2>"$ERRF"; then
        ok "schemat panel_schema.sql zaimportowany (IF NOT EXISTS — istniejące dane nietknięte)"
        run_sql "GRANT EXECUTE ON PROCEDURE mailserver.sp_register_login TO 'mailpanel'@'localhost';
FLUSH PRIVILEGES;" && ok "GRANT EXECUTE ON PROCEDURE sp_register_login -> mailpanel"
      else
        warn "import schematu nie przeszedł: $(head -1 "$ERRF")"
      fi
      rm -f "$ERRF"
    else
      warn "Brak $SRC/sql/panel_schema.sql — schemat zaimportuj ręcznie."
    fi
  fi
fi
hr

# --- 1. Skrypty /usr/local/bin ---
if ask "1) Zainstalować skrypty do /usr/local/bin"; then
  install -o root -g root -m 755 "$SRC/scripts/mailpanel-scan.sh"         /usr/local/bin/ && ok "mailpanel-scan.sh (755)"
  install -o root -g root -m 700 "$SRC/scripts/mailpanel-delete-user.sh"  /usr/local/bin/ && ok "mailpanel-delete-user.sh (700)"
  install -o root -g root -m 755 "$SRC/scripts/update-postfix-limits.sh"  /usr/local/bin/ && ok "update-postfix-limits.sh (755)"
  install -o root -g root -m 755 "$SRC/scripts/mailpanel-policy-daily.php" /usr/local/bin/ && ok "mailpanel-policy-daily.php (755)"
  if getent group dovecot >/dev/null; then
    install -o root -g dovecot -m 750 "$SRC/scripts/dovecot-postlogin.sh" /usr/local/bin/ && ok "dovecot-postlogin.sh (750 root:dovecot)"
  else
    install -o root -g root -m 755 "$SRC/scripts/dovecot-postlogin.sh"    /usr/local/bin/ && warn "dovecot-postlogin.sh (grupa dovecot nie istnieje)"
  fi
fi

# --- 2. sudoers ---
hr
if ask "2) Zainstalować regułę sudoers (/etc/sudoers.d/mailpanel)"; then
  tmp=$(mktemp)
  cp "$SRC/system/sudoers.d/mailpanel" "$tmp"
  if visudo -cf "$tmp" >/dev/null 2>&1; then
    install -o root -g root -m 440 "$tmp" /etc/sudoers.d/mailpanel
    ok "sudoers zainstalowany i zwalidowany"
  else
    warn "Walidacja sudoers nie przeszła — pomijam (sprawdź $SRC/system/sudoers.d/mailpanel)"
  fi
  rm -f "$tmp"
fi

# --- 3. Fail2Ban ---
hr
if ask "3) Skonfigurować Fail2Ban (filtry + jaile panelu)"; then
  install -o root -g root -m 644 "$SRC/system/fail2ban/filter.d/panel-permaban.conf" /etc/fail2ban/filter.d/ && ok "filter panel-permaban"
  install -o root -g root -m 644 "$SRC/system/fail2ban/filter.d/panel-tempban.conf"  /etc/fail2ban/filter.d/ && ok "filter panel-tempban"
  [ -f /etc/fail2ban/filter.d/roundcube-auth.conf ] || install -o root -g root -m 644 "$SRC/system/fail2ban/filter.d/roundcube-auth.conf" /etc/fail2ban/filter.d/
  touch /var/log/mailpanel-permaban.log /var/log/mailpanel-tempban.log
  ok "logi permaban/tempban gotowe"

  # Jaile panelu żyją w OSOBNYM pliku jail.d/ — nadpisanie = idempotencja,
  # jail.local zostaje czysty dla jaili standardowych (edytowanych z panelu).
  if [ -f /etc/fail2ban/jail.local ] && grep -q '\[panel-permaban\]' /etc/fail2ban/jail.local; then
    warn "jaile panelu są (legacy) w jail.local — zostawiam; rozważ przeniesienie do jail.d/mailpanel.local"
  else
    install -o root -g root -m 644 "$SRC/system/fail2ban/jail.d/mailpanel.local" /etc/fail2ban/jail.d/mailpanel.local
    ok "jaile panelu: /etc/fail2ban/jail.d/mailpanel.local"
  fi

  if [ -f /etc/fail2ban/jail.local ]; then
    chgrp "$WEB_USER" /etc/fail2ban/jail.local && chmod 664 /etc/fail2ban/jail.local
    ok "jail.local zapisywalny przez panel (664 :$WEB_USER)"
  else
    warn "Brak /etc/fail2ban/jail.local — utwórz wg README (jaile sshd/dovecot/postfix-sasl/roundcube-auth/recidive)."
  fi

  if command -v fail2ban-client >/dev/null; then
    if fail2ban-client -t >/dev/null 2>&1; then
      fail2ban-client reload >/dev/null 2>&1 && ok "konfiguracja poprawna, fail2ban przeładowany"
    else
      warn "fail2ban-client -t zgłasza błąd konfiguracji — NIE przeładowano (sprawdź: fail2ban-client -t)"
    fi
  fi
fi

# --- 4. Policy service (dzienny limit wysyłki) ---
hr
if ask "4) Zainstalować policy service (dzienny limit wysyłki)"; then
  # Idempotencja: istniejące hasło z /etc/mailpanel-policy.cf jest używane
  # ponownie zamiast rotacji (nie unieważnia działającej konfiguracji).
  if [ -f /etc/mailpanel-policy.cf ]; then
    PW=$(grep -oP '^\s*password\s*=\s*"?\K[^"]+' /etc/mailpanel-policy.cf || true)
    [ -n "$PW" ] && ok "użyto istniejącego hasła z /etc/mailpanel-policy.cf" || PW=$(openssl rand -hex 24)
  else
    PW=$(openssl rand -hex 24)
  fi
  if run_sql "CREATE USER IF NOT EXISTS 'mailpolicy'@'localhost' IDENTIFIED BY '$PW';
ALTER USER 'mailpolicy'@'localhost' IDENTIFIED BY '$PW';
GRANT SELECT ON mailserver.virtual_users TO 'mailpolicy'@'localhost';
GRANT SELECT ON mailserver.app_settings  TO 'mailpolicy'@'localhost';
GRANT SELECT, INSERT, UPDATE ON mailserver.send_counters TO 'mailpolicy'@'localhost';
FLUSH PRIVILEGES;"; then
    ok "użytkownik DB mailpolicy + granty"
    sed "s/HASLO_MAILPOLICY/$PW/" "$SRC/system/mailpanel-policy.cf.example" > /etc/mailpanel-policy.cf
    chown root:nogroup /etc/mailpanel-policy.cf && chmod 640 /etc/mailpanel-policy.cf
    ok "/etc/mailpanel-policy.cf (640 root:nogroup)"
    echo "   Postfix: dokończ ręcznie wg system/postfix/master.cf.snippet:"
    echo "     - dopisz  -o smtpd_data_restrictions=\$mua_data_restrictions  w submission/submissions"
    echo "     - main.cf: mua_data_restrictions = reject_unauth_pipelining, check_policy_service unix:private/policy-daily"
    echo "     - master.cf: usługa spawn policy-daily"
    echo "     - postfix check && systemctl reload postfix"
  fi
fi

# --- 5. Plugin password (samoobsługa zmiany hasła) ---
hr
if ask "5) Skonfigurować plugin 'password' (zmiana hasła w webmailu)"; then
  PCFG="$RC_DIR/plugins/password/config.inc.php"
  if [ -d "$RC_DIR/plugins/password" ]; then
    # Idempotencja: hasło z istniejącego config.inc.php jest zachowywane.
    if [ -f "$PCFG" ]; then
      PW=$(grep -oP "mysql://roundcubepw:\K[^@]+" "$PCFG" || true)
      [ -n "$PW" ] && ok "użyto istniejącego hasła z config.inc.php pluginu" || PW=$(openssl rand -hex 24)
    else
      PW=$(openssl rand -hex 24)
    fi
    if run_sql "CREATE USER IF NOT EXISTS 'roundcubepw'@'localhost' IDENTIFIED BY '$PW';
ALTER USER 'roundcubepw'@'localhost' IDENTIFIED BY '$PW';
GRANT SELECT, UPDATE ON mailserver.virtual_users TO 'roundcubepw'@'localhost';
FLUSH PRIVILEGES;"; then
      ok "użytkownik DB roundcubepw + granty"
      sed "s#mysql://roundcubepw:SILNE_HASLO_POLICY@localhost/mailserver#mysql://roundcubepw:$PW@localhost/mailserver#" \
          "$SRC/plugins/password/config.inc.php.example" > "$PCFG"
      chown root:"$WEB_USER" "$PCFG" && chmod 640 "$PCFG"
      ok "$PCFG (640 root:$WEB_USER)"
      grep -q "'password'" "$RC_DIR/config/config.inc.php" \
        && ok "'password' już w \$config['plugins']" \
        || warn "'password' nie jest w \$config['plugins'] — doda go sekcja 9"
      rm -rf "$RC_DIR/temp/"* 2>/dev/null
    fi
  else
    warn "Brak $RC_DIR/plugins/password — plugin jest w komplecie Roundcube; zainstaluj Roundcube z pluginami."
  fi
fi

# --- 6. Rejestracja logowań IMAP/POP3 (dovecot postlogin -> sp_register_login) ---
hr
if ask "6) Skonfigurować /etc/dovecot/mysql-mailpanel.cnf (logowanie logowań do panelu)"; then
  if [ -f /etc/dovecot/mysql-mailpanel.cnf ]; then
    ok "plik już istnieje — zostawiam bez zmian"
  else
    PANEL_PW=""
    [ -f "$RC_DIR/.env" ] && PANEL_PW=$(grep -oP '^PANEL_DB_PASS=\K.*' "$RC_DIR/.env" | tr -d '"' || true)
    if [ -z "$PANEL_PW" ]; then
      read -rsp "   Hasło użytkownika DB 'mailpanel' (jak w .env): " PANEL_PW; echo
    else
      ok "hasło mailpanel odczytane z $RC_DIR/.env"
    fi
    umask 077
    sed "s/HASLO_MAILPANEL/$PANEL_PW/" "$SRC/system/dovecot/mysql-mailpanel.cnf.example" > /etc/dovecot/mysql-mailpanel.cnf
    umask 022
    chown dovecot:dovecot /etc/dovecot/mysql-mailpanel.cnf && chmod 400 /etc/dovecot/mysql-mailpanel.cnf
    ok "/etc/dovecot/mysql-mailpanel.cnf (400 dovecot:dovecot)"
  fi
  echo "   Pamiętaj o usługach imap-postlogin/pop3-postlogin w /etc/dovecot/dovecot.conf"
  echo "   (wzorzec: system/dovecot/postlogin.conf.snippet) oraz o grantach EXECUTE"
  echo "   na procedurę sp_register_login dla 'mailpanel' (sql/panel_schema.sql)."
fi

# --- 7. Symlinki w webroot + właściciel ---
hr
if ask "7) Wystawić portal/panel/assets w webroot (public_html) i ustawić właściciela"; then
  if [ -d "$RC_DIR/public_html" ]; then
    # Roundcube 1.7: DocumentRoot = public_html. Kod nakładki żyje POZA
    # webrootem — publikujemy tylko punkty wejścia (idempotentne ln -sfn).
    for lnk in assets portal.php panel; do
      ln -sfn "$RC_DIR/$lnk" "$RC_DIR/public_html/$lnk"
      chown -h "$WEB_USER":"$WEB_USER" "$RC_DIR/public_html/$lnk"
    done
    [ -e "$RC_DIR/public_html/.htaccess" ] || ln -sfn "$RC_DIR/.htaccess" "$RC_DIR/public_html/.htaccess"
    ok "symlinki public_html/{assets,portal.php,panel,.htaccess}"
  else
    warn "Brak public_html (layout inny niż 1.7?) — pomiń, jeśli pliki są już w webroot."
  fi
  chown -R "$WEB_USER":"$WEB_USER" "$RC_DIR/app" "$RC_DIR/assets" 2>/dev/null
  [ -f "$RC_DIR/.env" ] && { chown root:"$WEB_USER" "$RC_DIR/.env"; chmod 640 "$RC_DIR/.env"; ok ".env 640 root:$WEB_USER"; }
  ok "właściciel app/ assets/ = $WEB_USER"
fi

# --- 8. Timer auto-reloadu Fail2Ban (fallback po edycji z panelu) ---
hr
if ask "8) Zainstalować timer mailpanel-f2b-reload (reload F2B po zmianie jail.local)"; then
  # Panel przeładowuje Fail2Ban przez sudo od razu po zapisie; timer to siatka
  # bezpieczeństwa — co minutę porównuje mtime jail.local i robi reload, gdyby
  # bezpośrednie przeładowanie nie doszło do skutku (np. brak wpisu sudoers).
  install -o root -g root -m 755 "$SRC/scripts/mailpanel-f2b-reload.php" /usr/local/sbin/
  install -o root -g root -m 644 "$SRC/system/systemd/mailpanel-f2b-reload.service" /etc/systemd/system/
  install -o root -g root -m 644 "$SRC/system/systemd/mailpanel-f2b-reload.timer"   /etc/systemd/system/
  systemctl daemon-reload
  if systemctl enable --now mailpanel-f2b-reload.timer >/dev/null 2>&1; then
    ok "mailpanel-f2b-reload.timer aktywny (interwał 1 min)"
  else
    warn "Nie udało się włączyć timera — sprawdź: systemctl status mailpanel-f2b-reload.timer"
  fi
fi

# --- 9. Wpięcie panelu w config.inc.php Roundcube ---
hr
if ask "9) Wpiąć panel w config.inc.php (require panel_config.php + plugin 'password')"; then
  CFG="$RC_DIR/config/config.inc.php"
  if [ ! -f "$CFG" ]; then
    warn "Brak $CFG — najpierw skonfiguruj Roundcube; potem uruchom tę sekcję ponownie."
  elif ! command -v php >/dev/null; then
    warn "Brak php w PATH — wepnij ręcznie wg README (sekcja 5)."
  else
    # Bezpieczna edycja: kopia zapasowa -> zmiana -> walidacja php -l ->
    # przy błędzie automatyczny powrót do kopii. Ręczne przywrócenie:
    #   cp -a config.inc.php.bak-<czas> config.inc.php
    BAK="$CFG.bak-$(date +%s)"
    cp -a "$CFG" "$BAK"
    CHANGED=0
    if grep -q "panel_config.php" "$CFG"; then
      ok "require panel_config.php — już wpięty"
    else
      printf "\nrequire_once __DIR__ . '/panel_config.php';\n" >> "$CFG"
      CHANGED=1; ok "dopisano require_once panel_config.php (na końcu pliku)"
    fi
    if grep -q "'password'" "$CFG"; then
      ok "'password' — już na liście pluginów"
    else
      if RC_CFG="$CFG" php <<'PHP'
<?php
$f = getenv('RC_CFG');
$s = (string) file_get_contents($f);
$n = preg_replace(
    '/(\$config\[[\'"]plugins[\'"]\]\s*=\s*(?:\[|array\())/',
    '$1' . "'password', ",
    $s, 1, $cnt
);
if ($cnt !== 1) { exit(1); }
file_put_contents($f, $n);
PHP
      then
        CHANGED=1; ok "dopisano 'password' do \$config['plugins']"
      else
        warn "nie znalazłem \$config['plugins'] w $CFG — dopisz 'password' ręcznie"
      fi
    fi
    if php -l "$CFG" >/dev/null 2>&1; then
      if [ "$CHANGED" -eq 1 ]; then
        ok "składnia OK (php -l); backup: $BAK"
        echo "   Przywrócenie ręczne w razie potrzeby: cp -a $BAK $CFG"
        rm -rf "$RC_DIR/temp/"* 2>/dev/null
      else
        rm -f "$BAK"; ok "nic do zmiany (backup usunięty)"
      fi
    else
      cp -a "$BAK" "$CFG"
      warn "php -l zgłosił błąd składni — PRZYWRÓCONO oryginał z $BAK."
      warn "Wepnij ręcznie: require_once + 'password' w plugins (README sekcja 5)."
    fi
  fi
fi

hr
ok "Gotowe. Weryfikacja:"
echo "   - https://<host>/portal.php  → ekran logowania"
echo "   - https://<host>/.env        → 403"
echo "   - panel: Fail2Ban/Bezpieczeństwo bez czerwonych ostrzeżeń"
echo "   - test limitu: nadlimitowa wysyłka SMTP → 554"
echo "   - fail2ban-client status     → jaile panel-permaban / panel-tempban"
