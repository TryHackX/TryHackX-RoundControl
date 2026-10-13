#!/bin/bash
# =====================================================================
# Instalator integracji systemowych panelu Roundcube (uruchom jako root).
# Cel: Debian 13 (trixie) — Dovecot 2.4, Postfix 3.10, Fail2Ban 1.1.
# Idempotentny, z bramką potwierdzenia per sekcja i kopiami zapasowymi.
# Ponowne uruchomienie NIE rotuje istniejących sekretów (konta DB, które już istnieją, nie dostają nowego hasła),
# NIE duplikuje konfiguracji (jaile w /etc/fail2ban/jail.d/, sekcje wykrywane) i niczego nie nadpisuje bez pytania.
#
# 1.1.0 — zasady bezpieczeństwa tego pliku:
#  * sekrety odczytane Z PLIKÓW (hasła z map Postfixa, z mailpanel-policy.cf, z konfiguracji pluginu) są danymi, nie
#    kodem: czytane ściśle (file_kv), sprawdzane regexem ^[A-Za-z0-9._~+-]{16,128}$ i wstawiane do szablonów awk-iem
#    przez zmienne środowiskowe (bez sed, bez interpretacji znaków specjalnych). Plik .env aplikacji NIE jest czytany
#    w ogóle — w 1.0.x jego zawartość trafiała do SQL-a i do sed-a uruchamianych jako root, a plik jest zapisywalny
#    dla www-data, gdy kod należy do www-data. Nigdy `source` takiego pliku.
#  * sekcja 0 nie zmienia haseł istniejących kont DB (wcześniej ALTER USER przy każdym uruchomieniu mógł zabrać
#    Postfixowi i Dovecotowi dostęp do bazy);
#  * kod aplikacji należy do roota; dla www-data zapisywalne są wyłącznie temp/ i logs/ Roundcube.
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

# --- helpers begin (tests/install_helpers_test.sh sources exactly this block) -------------------------------------

# Sekret dozwolony w konfiguracjach: bez znaków specjalnych powłoki / SQL / sed (hex z `openssl rand -hex 24` pasuje).
valid_secret() { [[ "${1:-}" =~ ^[A-Za-z0-9._~+-]{16,128}$ ]]; }

# Wartość klucza z pliku KLUCZ=WARTOŚĆ (INI / .cnf / mapa Postfixa). Plik to DANE: nigdy nie jest wykonywany ani
# `source`-owany. Pierwsze dopasowanie, jedna para cudzysłowów zdejmowana. Dla dowiązania symbolicznego: błąd.
# $2 to stała nazwa klucza podana przez ten skrypt, nie zawartość pliku.
file_kv() {
  local file=$1 key=$2 line val
  [ -f "$file" ] && [ ! -L "$file" ] || return 1
  line=$(grep -m1 -E "^[[:space:]]*${key}[[:space:]]*=" -- "$file") || return 1
  val=${line#*=}
  val=${val%$'\r'}
  val="${val#"${val%%[![:space:]]*}"}"
  val="${val%"${val##*[![:space:]]}"}"
  case "$val" in
    \"*\") val=${val#\"}; val=${val%\"} ;;
    \'*\') val=${val#\'}; val=${val%\'} ;;
  esac
  printf '%s' "$val"
}

# Wypełnia szablon: zamienia KAŻDE wystąpienie tekstu $2 na $3 (wartości literalne — bez regexów, bez & i \).
render_template() {
  RENDER_PH="$2" RENDER_VAL="$3" awk '{
    ph = ENVIRON["RENDER_PH"]; val = ENVIRON["RENDER_VAL"]; out = ""
    while ((i = index($0, ph)) > 0) { out = out substr($0, 1, i - 1) val; $0 = substr($0, i + length(ph)) }
    print out $0
  }' "$1"
}

# Uruchamia SQL przez klienta mysql (SQL na standardowym wejściu); przy błędzie ostrzega i zwraca 1.
run_sql() {
  local out
  if ! out=$(mysql 2>&1 <<<"$1"); then
    warn "Błąd MySQL: ${out:-nieznany} — sekcja niedokończona."
    return 1
  fi
}

db_user_exists() {   # $1 = stała nazwa konta (z tego skryptu)
  [ "$(mysql -N -B -e "SELECT COUNT(*) FROM mysql.user WHERE User='$1' AND Host='localhost'" 2>/dev/null)" = "1" ]
}

gen_pw() { openssl rand -hex 24; }

# --- helpers end ----------------------------------------------------------------------------------------------------

# Helper Fail2Ban. /etc/fail2ban/jail.local należy do roota, a panel (www-data) przekazuje PROPOZYCJĘ na standardowe
# wejście root-owego helpera (przez sudo, bez argumentów) — helper sprawdza, że zmieniły się wyłącznie wartości opcji
# edytowanych z panelu, zapisuje plik W MIEJSCU (pod ProtectSystem=full w php-fpm nie da się utworzyć pliku obok ani
# zrobić rename), czyta go z powrotem, robi fail2ban-client -t i przeładowuje Fail2Ban. Idempotentne.
install_f2b_helper() {
  command -v php >/dev/null || warn "Brak php w PATH — helper Fail2Ban wymaga php-cli (apt install php-cli)."
  install -o root -g root -m 755 "$SRC/scripts/mailpanel-f2b-apply.php" /usr/local/sbin/mailpanel-f2b-apply || return 1
  if command -v php >/dev/null && ! php -l /usr/local/sbin/mailpanel-f2b-apply >/dev/null 2>&1; then
    warn "helper mailpanel-f2b-apply nie przechodzi php -l — sprawdź plik"
    return 1
  fi
  # 1.0.x zostawiało propozycję w pliku w katalogu www-data; od 1.1.0 propozycja idzie na stdin
  if [ -d /var/lib/mailpanel-f2b ]; then
    rm -f /var/lib/mailpanel-f2b/jail.local.proposed
    rmdir /var/lib/mailpanel-f2b 2>/dev/null && ok "usunięto nieużywany katalog /var/lib/mailpanel-f2b"
  fi
  ok "helper /usr/local/sbin/mailpanel-f2b-apply (755 root)"
}

[ "$(id -u)" -eq 0 ] || die "Uruchom jako root (sudo bash install.sh)."
[[ "$WEB_USER" =~ ^[a-z_][a-z0-9_-]*$ ]] || die "WEB_USER='$WEB_USER' to nie jest zwykła nazwa użytkownika."
[[ "$RC_DIR" =~ ^/[A-Za-z0-9._/-]+$ ]] || die "RC_DIR='$RC_DIR' musi być ścieżką bezwzględną bez spacji i znaków specjalnych."
[ -d "$RC_DIR" ] || die "Nie znaleziono $RC_DIR (ustaw RC_DIR=... jeśli inna ścieżka)."
command -v mysql >/dev/null || warn "Brak klienta mysql w PATH — sekcje 0/4/5/6 wymagają MariaDB/MySQL."

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

declare -A NEWPW=()   # hasła kont DB utworzonych w tym uruchomieniu (nigdzie indziej nie są zapisane)

# --- 0. Baza danych (mailserver + konta + schemat + granty) ---
if ask "0) Utworzyć bazę mailserver, konta mailuser/mailpanel/mailpostlogin, zaimportować schemat i nadać granty"; then
  if ! mysql -e 'SELECT 1' >/dev/null 2>&1; then
    warn "klient mysql nie łączy się jako root (socket) — pomijam sekcję 0."
  else
    S0=1
    run_sql "CREATE DATABASE IF NOT EXISTS mailserver CHARACTER SET utf8mb4;" || S0=0
    # Konto, które już istnieje, zostaje NIETKNIĘTE (hasło, granty mailuser). Nowe dostaje losowe hasło, wypisane
    # niżej — to jedyne miejsce, gdzie je zobaczysz. (1.0.x robiło ALTER USER przy każdym uruchomieniu.)
    for u in mailuser mailpanel mailpostlogin; do
      if db_user_exists "$u"; then
        ok "konto DB $u istnieje — hasło i (dla mailuser) granty bez zmian"
      else
        pw=$(gen_pw)
        if run_sql "CREATE USER '$u'@'localhost' IDENTIFIED BY '$pw';"; then
          NEWPW[$u]=$pw
        else
          S0=0
        fi
      fi
    done
    if [ "$S0" -eq 1 ] && [ -f "$SRC/sql/panel_schema.sql" ]; then
      ERRF=$(mktemp)
      if mysql mailserver < "$SRC/sql/panel_schema.sql" 2>"$ERRF"; then
        ok "schemat panel_schema.sql zaimportowany (IF NOT EXISTS — istniejące dane nietknięte)"
        if [ -n "${NEWPW[mailuser]:-}" ]; then
          run_sql "GRANT SELECT ON mailserver.virtual_domains TO 'mailuser'@'localhost';
GRANT SELECT ON mailserver.virtual_users   TO 'mailuser'@'localhost';
GRANT SELECT ON mailserver.virtual_aliases TO 'mailuser'@'localhost';
FLUSH PRIVILEGES;" && ok "nowe konto mailuser: SELECT na virtual_domains / virtual_users / virtual_aliases"
        fi
        # granty aplikacji: per tabela, odbierane i nadawane od nowa (idempotentnie; to również sposób na zawężenie istniejącego konta)
        if mysql < "$SRC/sql/panel_grants.sql" 2>"$ERRF"; then
          ok "granty mailpanel (sql/panel_grants.sql): per tabela, bez praw do aliasów INSERT/UPDATE"
        else
          warn "sql/panel_grants.sql nie przeszedł: $(head -1 "$ERRF")"
        fi
        if mysql < "$SRC/sql/postlogin_grants.sql" 2>"$ERRF"; then
          ok "granty mailpostlogin: tylko EXECUTE na sp_register_login"
        else
          warn "sql/postlogin_grants.sql nie przeszedł: $(head -1 "$ERRF")"
        fi
      else
        warn "import schematu nie przeszedł: $(head -1 "$ERRF")"
      fi
      rm -f "$ERRF"
    elif [ "$S0" -eq 1 ]; then
      warn "Brak $SRC/sql/panel_schema.sql — schemat zaimportuj ręcznie, potem sql/panel_grants.sql i sql/postlogin_grants.sql."
    fi
    [ -n "${NEWPW[mailuser]:-}" ]      && warn "NOWE hasło mailuser (wpisz w /etc/postfix/mysql-virtual-*.cf i dovecot.conf): ${NEWPW[mailuser]}"
    [ -n "${NEWPW[mailpanel]:-}" ]     && warn "NOWE hasło mailpanel (podasz w install.php / wpiszesz w .env jako PANEL_DB_PASS): ${NEWPW[mailpanel]}"
    [ -n "${NEWPW[mailpostlogin]:-}" ] && ok "nowe konto mailpostlogin — jego hasło zapisze sekcja 6 do /etc/dovecot/mysql-mailpanel.cnf"
    [ "${#NEWPW[@]}" -gt 0 ] && warn "Hasła wyżej są pokazane TYLKO TERAZ (poza mailpostlogin, które trafia do pliku w sekcji 6)."
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
if ask "2) Zainstalować helper Fail2Ban i regułę sudoers (/etc/sudoers.d/mailpanel), usunąć stare reguły z wildcardami"; then
  # Reguły mają DOKŁADNE argumenty (^regex$) — wymagają sudo >= 1.9.10 (Debian 13: 1.9.16).
  # Starsze sudo traktuje ^...$ dosłownie, więc nic by nie pasowało; nie instalujemy wtedy niczego.
  SV=$(sudo -V 2>/dev/null | head -1 | grep -oE '[0-9]+\.[0-9]+\.[0-9]+' | head -1 || true)
  if [ -z "$SV" ] || [ "$(printf '%s\n1.9.10\n' "$SV" | sort -V | head -1)" != "1.9.10" ]; then
    warn "sudo ${SV:-nieznane} < 1.9.10 — brak argumentów ^regex\$; sudoers NIE zainstalowany (zaktualizuj sudo)"
  elif install_f2b_helper; then   # helper najpierw — reguła sudoers wskazuje na niego
    tmp=$(mktemp)
    cp "$SRC/system/sudoers.d/mailpanel" "$tmp"
    SUDOERS_OK=0
    if visudo -cf "$tmp" >/dev/null 2>&1; then
      install -o root -g root -m 440 "$tmp" /etc/sudoers.d/mailpanel && SUDOERS_OK=1
      ok "sudoers zainstalowany i zwalidowany (visudo -cf): dokładne argumenty, bez wildcardów"
    else
      warn "Walidacja sudoers nie przeszła — pomijam (sprawdź $SRC/system/sudoers.d/mailpanel)"
    fi
    rm -f "$tmp"
    # sudo przyznaje polecenie, gdy pasuje KTÓRAKOLWIEK reguła: jedna stara reguła z wildcardem (1.0: fail2ban-client,
    # doveadm, mailpanel-scan.sh; w /etc/sudoers albo w osobnych plikach mailpanel-*) unieważnia wszystkie dokładne.
    if [ "$SUDOERS_OK" -eq 1 ]; then
      OLD=$(WEB_USER="$WEB_USER" bash "$SRC/scripts/mailpanel-sudoers-cleanup.sh" 2>&1); rc=$?
      if [ "$rc" -ne 0 ]; then
        echo -e "${c_red}✗ STARE reguły sudo dla $WEB_USER poza /etc/sudoers.d/mailpanel — wildcardy dają drogę do roota:${c_rst}"
        echo "$OLD" | sed 's/^/     /'
        if ask "   Zakomentować je teraz (kopie oryginałów w /root/sudoers-backup-*; każdy plik walidowany visudo)"; then
          WEB_USER="$WEB_USER" bash "$SRC/scripts/mailpanel-sudoers-cleanup.sh" --apply || warn "część reguł wymaga ręcznej edycji (visudo) — patrz wyżej"
        fi
      else
        ok "brak starych reguł sudo dla $WEB_USER poza /etc/sudoers.d/mailpanel"
      fi
    else
      warn "Nowy plik sudoers nie został zainstalowany, więc starych reguł nie ruszam."
    fi
  else
    warn "Instalacja helpera Fail2Ban nie powiodła się — sudoers NIE zainstalowany."
  fi
fi

# --- 3. Fail2Ban ---
hr
if ask "3) Skonfigurować Fail2Ban (filtry + jaile panelu)"; then
  install -o root -g root -m 644 "$SRC/system/fail2ban/filter.d/panel-permaban.conf" /etc/fail2ban/filter.d/ && ok "filter panel-permaban"
  install -o root -g root -m 644 "$SRC/system/fail2ban/filter.d/panel-tempban.conf"  /etc/fail2ban/filter.d/ && ok "filter panel-tempban"
  install -o root -g root -m 644 "$SRC/system/fail2ban/filter.d/mailpanel-portal.conf" /etc/fail2ban/filter.d/ && ok "filter mailpanel-portal (jail domyślnie wyłączony)"
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

  # jail.local należy do ROOTA (root:root 644) — panel go nie zapisuje (patrz install_f2b_helper).
  # Stare kopie jail.local.bak-* (robione kiedyś przez panel) też przechodzą na roota, 600.
  if [ -f /etc/fail2ban/jail.local ]; then
    install_f2b_helper
    chown root:root /etc/fail2ban/jail.local && chmod 644 /etc/fail2ban/jail.local
    for b in /etc/fail2ban/jail.local.bak-*; do [ -e "$b" ] && chown root:root "$b" && chmod 600 "$b"; done
    ok "jail.local: root:root 644 (panel tylko proponuje zmiany; zapisuje je helper mailpanel-f2b-apply)"
  else
    warn "Brak /etc/fail2ban/jail.local — utwórz wg README (jaile sshd/dovecot/postfix-sasl/roundcube-auth/recidive), jako root:root 644."
  fi

  if command -v fail2ban-client >/dev/null; then
    if fail2ban-client -t >/dev/null 2>&1; then
      fail2ban-client reload >/dev/null 2>&1 && ok "konfiguracja poprawna, fail2ban przeładowany"
    else
      warn "fail2ban-client -t zgłasza błąd konfiguracji — NIE przeładowano (sprawdź: fail2ban-client -t)"
    fi
  fi

  if [ -f /etc/fail2ban/jail.local ] && [ -x /usr/local/sbin/mailpanel-f2b-apply ]; then
    # (a) zapis W MIEJSCU pod tym samym sandboxem co php-fpm (ProtectSystem=full + ReadWritePaths=jail.local) —
    #     dokładnie to, czego 1.0.x nigdy nie sprawdzało (test z roota nie widział read-only /etc)
    if command -v systemd-run >/dev/null; then
      R=$(systemd-run --quiet --wait --pipe --collect -p ProtectSystem=full -p ReadWritePaths=/etc/fail2ban/jail.local \
            /usr/local/sbin/mailpanel-f2b-apply --selftest 2>&1)
      if [ "$R" = "selftest ok" ]; then
        ok "helper pod sandboxem php-fpm (ProtectSystem=full, ReadWritePaths=jail.local): zapis w miejscu + kopie zapasowe działają"
      else
        warn "helper pod sandboxem NIE przeszedł selftestu: ${R:-brak odpowiedzi}"
      fi
    else
      warn "brak systemd-run — pominięto test helpera pod sandboxem php-fpm"
    fi
    # (b) przez sudo jako użytkownik panelu: propozycja identyczna z jail.local => "bez zmian" (nic nie zapisuje).
    #     Sprawdza regułę sudoers, argumenty (""), czytanie ze stdin i uprawnienia.
    if grep -q 'mailpanel-f2b-apply' /etc/sudoers.d/mailpanel 2>/dev/null; then
      R=$(sudo -u "$WEB_USER" sudo -n /usr/local/sbin/mailpanel-f2b-apply < /etc/fail2ban/jail.local 2>&1)
      if [ "$R" = "bez zmian" ]; then
        ok "test helpera jako $WEB_USER przez sudo: 'bez zmian' (sudoers + helper + stdin działają)"
      else
        warn "test helpera jako $WEB_USER nie przeszedł: ${R:-brak odpowiedzi} (sprawdź krok 2 i: sudo -l -U $WEB_USER)"
      fi
    fi
  fi
fi

# --- 4. Policy service (dzienny limit wysyłki) ---
hr
if ask "4) Zainstalować policy service (dzienny limit wysyłki — liczy odbiorców)"; then
  # Idempotencja: istniejące hasło z /etc/mailpanel-policy.cf jest używane ponownie (po sprawdzeniu kształtu), konto
  # DB istniejące — nietknięte. Nowe konto/hasło tylko, gdy ich brak.
  PW=""
  if [ -f /etc/mailpanel-policy.cf ]; then
    PW=$(file_kv /etc/mailpanel-policy.cf password || true)
    if [ -n "$PW" ] && ! valid_secret "$PW"; then
      warn "hasło w /etc/mailpanel-policy.cf ma niedozwolone znaki (dozwolone: A-Za-z0-9._~+-, 16-128) — NIE używam go, trzeba nowego"
      PW=""
    fi
  fi
  POLICY_OK=1
  if db_user_exists mailpolicy; then
    if [ -z "$PW" ]; then
      if ask "   konto DB mailpolicy istnieje, ale nie znam jego hasła — ustawić nowe (jedyny użytkownik: plik, który zaraz zapiszę)"; then
        PW=$(gen_pw)
        run_sql "ALTER USER 'mailpolicy'@'localhost' IDENTIFIED BY '$PW';" || POLICY_OK=0
      else
        POLICY_OK=0
      fi
    fi
  else
    [ -n "$PW" ] || PW=$(gen_pw)
    run_sql "CREATE USER 'mailpolicy'@'localhost' IDENTIFIED BY '$PW';" || POLICY_OK=0
  fi
  if [ "$POLICY_OK" -eq 1 ] && run_sql "GRANT SELECT ON mailserver.virtual_users TO 'mailpolicy'@'localhost';
GRANT SELECT ON mailserver.app_settings  TO 'mailpolicy'@'localhost';
GRANT SELECT, INSERT, UPDATE ON mailserver.send_counters TO 'mailpolicy'@'localhost';
FLUSH PRIVILEGES;"; then
    ok "użytkownik DB mailpolicy + granty"
    if [ ! -f /etc/mailpanel-policy.cf ] || [ "$(file_kv /etc/mailpanel-policy.cf password || true)" != "$PW" ]; then
      [ -f /etc/mailpanel-policy.cf ] && cp -p /etc/mailpanel-policy.cf "/etc/mailpanel-policy.cf.bak-$(date +%s)"
      ( umask 077; render_template "$SRC/system/mailpanel-policy.cf.example" HASLO_MAILPOLICY "$PW" > /etc/mailpanel-policy.cf.new ) \
        && mv /etc/mailpanel-policy.cf.new /etc/mailpanel-policy.cf
    fi
    chown root:nogroup /etc/mailpanel-policy.cf && chmod 640 /etc/mailpanel-policy.cf
    ok "/etc/mailpanel-policy.cf (640 root:nogroup)"
    install -o root -g root -m 755 "$SRC/scripts/mailpanel-policy-daily.php" /usr/local/bin/
    echo "   Postfix: dokończ ręcznie wg system/postfix/master.cf.snippet:"
    echo "     - w submission/submissions dopisz  -o smtpd_data_restrictions=\$mua_data_restrictions  oraz  -o smtpd_recipient_limit=50"
    echo "     - main.cf: mua_data_restrictions = reject_unauth_pipelining, check_policy_service unix:private/policy-daily"
    echo "     - master.cf: usługa spawn policy-daily"
    echo "     - Roundcube musi wysyłać przez 587 z uwierzytelnieniem (smtp_host tls://localhost:587, smtp_user %u)"
    echo "     - postfix check && systemctl reload postfix"
  fi
fi

# --- 5. Plugin password (samoobsługa zmiany hasła) ---
hr
if ask "5) Skonfigurować plugin 'password' (zmiana hasła w webmailu)"; then
  PCFG="$RC_DIR/plugins/password/config.inc.php"
  if [ -d "$RC_DIR/plugins/password" ]; then
    # Idempotencja: hasło z istniejącego config.inc.php jest zachowywane (po sprawdzeniu kształtu).
    PW=""
    if [ -f "$PCFG" ] && [ ! -L "$PCFG" ]; then
      PW=$(grep -oP "mysql://roundcubepw:\K[^@]+" "$PCFG" | head -1 || true)
      if [ -n "$PW" ] && ! valid_secret "$PW"; then
        warn "hasło w $PCFG ma niedozwolone znaki — NIE używam go"
        PW=""
      fi
    fi
    PLUG_OK=1
    if db_user_exists roundcubepw; then
      if [ -z "$PW" ]; then
        if ask "   konto DB roundcubepw istnieje, ale nie znam jego hasła — ustawić nowe (jedyny użytkownik: plik pluginu)"; then
          PW=$(gen_pw)
          run_sql "ALTER USER 'roundcubepw'@'localhost' IDENTIFIED BY '$PW';" || PLUG_OK=0
        else
          PLUG_OK=0
        fi
      fi
    else
      [ -n "$PW" ] || PW=$(gen_pw)
      run_sql "CREATE USER 'roundcubepw'@'localhost' IDENTIFIED BY '$PW';" || PLUG_OK=0
    fi
    if [ "$PLUG_OK" -eq 1 ] && run_sql "GRANT SELECT, UPDATE ON mailserver.virtual_users TO 'roundcubepw'@'localhost';
FLUSH PRIVILEGES;"; then
      ok "użytkownik DB roundcubepw + granty"
      if [ ! -f "$PCFG" ] || ! grep -qF "mysql://roundcubepw:$PW@" "$PCFG"; then
        [ -f "$PCFG" ] && cp -p "$PCFG" "$PCFG.bak-$(date +%s)"
        ( umask 077; render_template "$SRC/plugins/password/config.inc.php.example" SILNE_HASLO_POLICY "$PW" > "$PCFG.new" ) \
          && mv "$PCFG.new" "$PCFG"
      fi
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
if ask "6) Skonfigurować /etc/dovecot/mysql-mailpanel.cnf (konto mailpostlogin: tylko EXECUTE na jednej procedurze)"; then
  CNF=/etc/dovecot/mysql-mailpanel.cnf
  CUR_USER=""
  [ -f "$CNF" ] && CUR_USER=$(file_kv "$CNF" user || true)
  if [ "$CUR_USER" = "mailpostlogin" ]; then
    ok "$CNF już używa konta mailpostlogin — bez zmian"
  else
    # Nowy plik albo MIGRACJA z 1.0.x, gdzie skrypt używał konta `mailpanel` (pełny dostęp do bazy panelu).
    [ -n "$CUR_USER" ] && warn "$CNF używa konta '$CUR_USER' — przechodzę na mailpostlogin (kopia zapasowa pliku powstanie)"
    PLPW="${NEWPW[mailpostlogin]:-}"
    PL_OK=1
    if [ -z "$PLPW" ]; then
      PLPW=$(gen_pw)
      if db_user_exists mailpostlogin; then
        # konto istnieje, ale jego hasło znał tylko plik, którego nie ma / który zaraz zastępujemy
        ask "   konto DB mailpostlogin istnieje, ale nie znam jego hasła — ustawić nowe (jedyny użytkownik: ten plik)" \
          && { run_sql "ALTER USER 'mailpostlogin'@'localhost' IDENTIFIED BY '$PLPW';" || PL_OK=0; } || PL_OK=0
      else
        run_sql "CREATE USER 'mailpostlogin'@'localhost' IDENTIFIED BY '$PLPW';" || PL_OK=0
      fi
    fi
    if [ "$PL_OK" -eq 1 ]; then
      mysql < "$SRC/sql/postlogin_grants.sql" 2>/dev/null || warn "sql/postlogin_grants.sql nie przeszedł (najpierw sekcja 0 — schemat i procedura)"
      [ -f "$CNF" ] && cp -p "$CNF" "$CNF.bak-$(date +%s)"
      ( umask 077; render_template "$SRC/system/dovecot/mysql-mailpanel.cnf.example" HASLO_MAILPOSTLOGIN "$PLPW" > "$CNF.new" ) \
        && mv "$CNF.new" "$CNF"
      chown dovecot:dovecot "$CNF" && chmod 400 "$CNF"
      ok "$CNF (400 dovecot:dovecot, konto mailpostlogin)"
      if mysql --defaults-extra-file="$CNF" -e 'SELECT 1' >/dev/null 2>&1; then
        ok "połączenie z bazą jako mailpostlogin działa"
      else
        warn "połączenie jako mailpostlogin NIE działa — sprawdź konto i $CNF"
      fi
    fi
  fi
  echo "   Pamiętaj o usługach imap-postlogin/pop3-postlogin w /etc/dovecot/dovecot.conf"
  echo "   (wzorzec: system/dovecot/postlogin.conf.snippet)."
fi

# --- 7. Symlinki w webroot + właściciel plików ---
hr
if ask "7) Wystawić portal/panel/assets w webroot (public_html) i ustawić właściciela (kod: root, www-data tylko do odczytu)"; then
  if [ -d "$RC_DIR/public_html" ]; then
    # Roundcube 1.7: DocumentRoot = public_html. Kod nakładki żyje POZA
    # webrootem — publikujemy tylko punkty wejścia (idempotentne ln -sfn).
    for lnk in assets portal.php panel; do
      ln -sfn "$RC_DIR/$lnk" "$RC_DIR/public_html/$lnk"
      chown -h root:root "$RC_DIR/public_html/$lnk"
    done
    [ -e "$RC_DIR/public_html/.htaccess" ] || ln -sfn "$RC_DIR/.htaccess" "$RC_DIR/public_html/.htaccess"
    ok "symlinki public_html/{assets,portal.php,panel,.htaccess}"
  else
    warn "Brak public_html (layout inny niż 1.7?) — pomiń, jeśli pliki są już w webroot."
  fi

  # Kod nakładki: root:root, katalogi 755, pliki 644. Proces PHP (www-data) potrzebuje tylko ODCZYTU: gdy kod należy
  # do www-data, KAŻDA luka w KAŻDEJ stronie z tej samej puli FPM pozwala podmienić panel.php i przechwytywać hasła.
  for p in app assets panel portal.php config/panel_config.php plugins/custom_auth .htaccess; do
    [ -e "$RC_DIR/$p" ] || continue
    chown -R root:root "$RC_DIR/$p"
    if [ -d "$RC_DIR/$p" ]; then
      find "$RC_DIR/$p" -type d -exec chmod 755 {} +
      find "$RC_DIR/$p" -type f -exec chmod 644 {} +
    else
      chmod 644 "$RC_DIR/$p"
    fi
  done
  ok "kod nakładki (app assets panel portal.php config/panel_config.php plugins/custom_auth .htaccess): root:root, 755/644"

  for ENVF in "$RC_DIR/.env" /etc/mailpanel/panel.env; do
    [ -f "$ENVF" ] && { chown root:"$WEB_USER" "$ENVF"; chmod 640 "$ENVF"; ok "$ENVF 640 root:$WEB_USER"; }
  done
  [ -d /etc/mailpanel ] && { chown root:"$WEB_USER" /etc/mailpanel; chmod 750 /etc/mailpanel; }

  if ask "   Cała reszta Roundcube (program/, vendor/, skins/, plugins/, config/...) też root:root — zapisywalne dla $WEB_USER zostają tylko temp/ i logs/"; then
    chown -R root:root "$RC_DIR"
    for d in temp logs; do
      [ -d "$RC_DIR/$d" ] && chown -R "$WEB_USER":"$WEB_USER" "$RC_DIR/$d"
    done
    # pliki z sekretami czytelne dla procesu PHP przez grupę
    for f in "$RC_DIR/config/config.inc.php" "$RC_DIR/plugins/password/config.inc.php" "$RC_DIR/.env"; do
      [ -f "$f" ] && { chown root:"$WEB_USER" "$f"; chmod 640 "$f"; }
    done
    # żaden plik poza temp/ i logs/ nie może być zapisywalny dla grupy ani innych (np. 664 po ręcznych kopiowaniach)
    find "$RC_DIR" \( -path "$RC_DIR/temp" -o -path "$RC_DIR/logs" \) -prune -o -type f \( -perm -g+w -o -perm -o+w \) -exec chmod go-w {} +
    find "$RC_DIR" \( -path "$RC_DIR/temp" -o -path "$RC_DIR/logs" \) -prune -o -type d \( -perm -g+w -o -perm -o+w \) -exec chmod go-w {} +
    LEFT=$(find "$RC_DIR" \( -path "$RC_DIR/temp" -o -path "$RC_DIR/logs" \) -prune -o ! -type l \( -user "$WEB_USER" -o -perm -o+w \) -print 2>/dev/null | head -5)
    if [ -z "$LEFT" ]; then
      ok "drzewo $RC_DIR: root:root, zapisywalne dla $WEB_USER tylko temp/ i logs/ (find -user/-perm -o+w: puste)"
    else
      warn "nadal należą do $WEB_USER albo są zapisywalne dla wszystkich: $(echo "$LEFT" | tr '\n' ' ')"
    fi
    # plik 600/640, który należał do www-data, jest teraz roota — proces PHP przestaje go czytać (np. konfiguracja pluginu)
    UNREAD=$(sudo -u "$WEB_USER" find "$RC_DIR" \( -path "$RC_DIR/temp" -o -path "$RC_DIR/logs" \) -prune -o -type f ! -readable -print 2>/dev/null | head -5)
    if [ -z "$UNREAD" ]; then
      ok "$WEB_USER czyta wszystkie pliki drzewa (poza temp/ i logs/)"
    else
      warn "$WEB_USER NIE może czytać: $(echo "$UNREAD" | tr '\n' ' ') — nadaj grupę: chown root:$WEB_USER <plik> && chmod 640 <plik>"
    fi
  else
    warn "reszta Roundcube bez zmian — sprawdź: find $RC_DIR -user $WEB_USER ; find $RC_DIR -perm -o+w"
  fi
fi

# --- 8. Przestarzały timer auto-reloadu Fail2Ban (wersja 1.0) ---
hr
# Do 1.0 timer mailpanel-f2b-reload co minutę porównywał mtime jail.local i robił ślepy reload. Od 1.0.1 jedyną
# drogą zapisu jest helper mailpanel-f2b-apply (root): sprawdza propozycję, robi fail2ban-client -t i sam przeładowuje —
# timer jest zbędny (i dublował reload po każdym zapisie). Nie instalujemy go już; stary usuwamy (za zgodą).
if [ -e /etc/systemd/system/mailpanel-f2b-reload.timer ] || [ -e /usr/local/sbin/mailpanel-f2b-reload.php ]; then
  if ask "8) Usunąć przestarzały timer mailpanel-f2b-reload (reload robi helper mailpanel-f2b-apply)"; then
    systemctl disable --now mailpanel-f2b-reload.timer >/dev/null 2>&1
    rm -f /etc/systemd/system/mailpanel-f2b-reload.timer /etc/systemd/system/mailpanel-f2b-reload.service \
          /usr/local/sbin/mailpanel-f2b-reload.php /var/lib/mailpanel/f2b_state.json
    rmdir /var/lib/mailpanel 2>/dev/null
    systemctl daemon-reload
    ok "timer mailpanel-f2b-reload usunięty"
  fi
else
  ok "8) brak przestarzałego timera mailpanel-f2b-reload — nic do zrobienia"
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

# --- 10. Drop-in php-fpm: ReadWritePaths dla helperów root-owych ---
hr
# php-fpm (Debian/sury) ma ProtectSystem=full: /usr, /etc i /boot są tylko do odczytu, a procesy uruchamiane przez
# sudo z PHP-FPM DZIEDZICZĄ ten sandbox — także root-owe helpery. Bez wyjątków zapis do /etc/fail2ban/jail.local
# (mailpanel-f2b-apply) i do /etc/postfix/main.cf (update-postfix-limits.sh) kończy się "read-only file system".
# ReadWritePaths zdejmuje tylko montowanie read-only: uprawnienia plików (root:root 644) nadal trzymają www-data z dala.
# jail.local: dokładnie jeden plik (helper pisze w miejscu); /etc/postfix: cały katalog (postconf -e tworzy plik tymczasowy
# i robi rename). Zmiana wymaga RESTARTU php-fpm (reload nie buduje namespace od nowa).
if ask "10) Zainstalować drop-in php-fpm (ReadWritePaths=/etc/fail2ban/jail.local /etc/postfix) i zrestartować php-fpm"; then
  mapfile -t FPM_SVCS < <(systemctl list-unit-files --type=service --no-legend 'php*-fpm.service' 2>/dev/null | awk '{print $1}')
  if [ "${#FPM_SVCS[@]}" -eq 0 ]; then
    warn "nie znaleziono usługi php*-fpm.service — dodaj drop-in ręcznie (README: php-fpm sandbox)"
  else
    for svc in "${FPM_SVCS[@]}"; do
      [[ "$svc" =~ ^php[0-9.]*-fpm\.service$ ]] || continue
      d="/etc/systemd/system/$svc.d"
      install -d -m 755 "$d"
      printf '%s\n' '# RoundControl 1.1.0 - root helpers started through sudo inherit the ProtectSystem=full sandbox of php-fpm.' \
                    '[Service]' \
                    'ReadWritePaths=-/etc/fail2ban/jail.local -/etc/postfix' > "$d/mailpanel.conf"
      chmod 644 "$d/mailpanel.conf"
      ok "$d/mailpanel.conf"
    done
    systemctl daemon-reload
    if ask "   Zrestartować teraz php-fpm (chwilowe przerwanie obsługi stron na tej puli)"; then
      for svc in "${FPM_SVCS[@]}"; do
        [[ "$svc" =~ ^php[0-9.]*-fpm\.service$ ]] || continue
        systemctl restart "$svc" && ok "restart $svc" || warn "restart $svc nie powiódł się"
        echo "   $(systemctl show -p ProtectSystem -p ReadWritePaths "$svc" | tr '\n' ' ')"
      done
    else
      warn "Drop-in zadziała dopiero po: systemctl restart ${FPM_SVCS[*]}"
    fi
  fi
  # test zapisu main.cf pod takim samym sandboxem (ta sama wartość -> nic się nie zmienia, ale plik jest przepisany)
  if command -v systemd-run >/dev/null && command -v postconf >/dev/null; then
    CUR=$(postconf -h smtpd_client_message_rate_limit 2>/dev/null || true)
    if [[ "$CUR" =~ ^[0-9]{1,6}$ ]]; then
      if systemd-run --quiet --wait --pipe --collect -p ProtectSystem=full -p ReadWritePaths=/etc/postfix \
           /usr/sbin/postconf -e "smtpd_client_message_rate_limit=$CUR" >/dev/null 2>&1; then
        ok "postconf -e pod sandboxem php-fpm (ReadWritePaths=/etc/postfix): zapis main.cf działa"
      else
        warn "postconf -e pod sandboxem NIE działa — zmiana limitów Postfix z panelu zgłosi błąd"
      fi
    fi
  fi
fi

# --- 11. Kwarantanna usuniętych skrzynek ---
hr
if ask "11) Utworzyć /var/mail/deleted (kwarantanna 700) i cron czyszczący wpisy starsze niż 30 dni"; then
  install -d -o root -g root -m 700 /var/mail/deleted && ok "/var/mail/deleted (700 root)"
  install -o root -g root -m 755 "$SRC/scripts/mailpanel-purge-deleted" /etc/cron.daily/mailpanel-purge-deleted \
    && ok "/etc/cron.daily/mailpanel-purge-deleted (retencja 30 dni; zmień RETENTION_DAYS w pliku)"
fi

hr
ok "Gotowe. Weryfikacja:"
echo "   - https://<host>/portal.php  → ekran logowania"
echo "   - https://<host>/.env  i  /.env.bak  i  /scripts/mailpanel-f2b-apply.php  → 403"
echo "   - panel: Fail2Ban/Bezpieczeństwo bez czerwonych ostrzeżeń; zapis w zakładce Fail2Ban kończy się komunikatem 'sprawdzona i przeładowana'"
echo "   - test limitu: nadlimitowa wysyłka SMTP → 554 (limit liczy ODBIORCÓW)"
echo "   - fail2ban-client status     → jaile panel-permaban / panel-tempban"
echo "   - stat -c '%A %U:%G' /etc/fail2ban/jail.local  → -rw-r--r-- root:root (panel go nie zapisuje)"
echo "   - sudo -l -U $WEB_USER  → tylko dokładne reguły ^regex\$, bez znaku *"
echo "   - find $RC_DIR -user $WEB_USER -o -perm -o+w  → tylko temp/ i logs/"
