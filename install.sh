#!/bin/bash
# =====================================================================
# Installer for the system integrations of the Roundcube panel (run as root).
# Target: Debian 13 (trixie) — Dovecot 2.4, Postfix 3.10, Fail2Ban 1.1.
# Idempotent, with a confirmation gate per section and backups.
# Running it again does NOT rotate existing secrets (DB accounts that already exist do not get a new password),
# does NOT duplicate configuration (jails live in /etc/fail2ban/jail.d/, sections are detected) and overwrites nothing without asking.
#
# 1.1.0 — security rules of this file:
#  * secrets read FROM FILES (passwords from Postfix maps, from mailpanel-policy.cf, from the plugin configuration) are data,
#    not code: read strictly (file_kv), checked with the regex ^[A-Za-z0-9._~+-]{16,128}$ and inserted into templates by awk
#    through environment variables (no sed, no interpretation of special characters). The application's .env file is NOT read
#    at all — in 1.0.x its contents ended up in SQL and in sed run as root, and that file is writable by www-data when the
#    code belongs to www-data. Never `source` such a file.
#  * section 0 does not change the passwords of existing DB accounts (previously ALTER USER on every run could cut Postfix
#    and Dovecot off from the database);
#  * the application code belongs to root; the panel's PHP user can write only Roundcube's temp/ and logs/
#    (and its log_dir, if that lies outside the tree).
#  * Roundcube and the panel get THEIR OWN PHP-FPM pool and their own user (section 1b, PANEL_USER, default mailweb):
#    other sites on the server (the [www] pool, www-data) cannot read the panel's secrets and do not have its sudo rules.
#
# Variables (optional):  RC_DIR=/var/www/roundcube   PANEL_USER=mailweb   APACHE_USER=www-data   PHP_VER=8.5
#   PANEL_USER=www-data (or the old WEB_USER=www-data) = no separate pool: Roundcube stays in the shared [www] pool.
# The application layer (.env, schema, admin) is installed separately: php install.php
# =====================================================================
set -uo pipefail

SRC="$(cd "$(dirname "$0")" && pwd)"
RC_DIR="${RC_DIR:-/var/www/roundcube}"
APACHE_USER="${APACHE_USER:-www-data}"              # użytkownik Apache: łączy się z gniazdem puli, czyta pliki statyczne
PANEL_USER="${PANEL_USER:-${WEB_USER:-mailweb}}"    # użytkownik puli PHP-FPM Roundcube + panelu (WEB_USER: nazwa z 1.0.x)
PHP_VER="${PHP_VER:-}"                              # wersja PHP-FPM (puste = wykryj z /etc/php/*/fpm)
RUN_USER="$APACHE_USER"                             # kto NAPRAWDĘ wykonuje PHP Roundcube (ustala sekcja 1b)

c_grn='\033[32m'; c_red='\033[31m'; c_yel='\033[33m'; c_rst='\033[0m'; c_b='\033[1m'
ok()   { echo -e "${c_grn}✓${c_rst} $*"; }
warn() { echo -e "${c_yel}!${c_rst} $*"; }
die()  { echo -e "${c_red}✗ $*${c_rst}"; exit 1; }
hr()   { printf '%.0s─' {1..60}; echo; }
# y/Y/yes confirm; t/T/tak (the old Polish habit) are still accepted. Anything else = no.
ask()  { local a; read -rp "$1 [y/N]: " a; [[ "$a" =~ ^[tTyY] ]]; }

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
    warn "MySQL error: ${out:-unknown} — section not completed."
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
  command -v php >/dev/null || warn "php not found in PATH — the Fail2Ban helper needs php-cli (apt install php-cli)."
  install -o root -g root -m 755 "$SRC/scripts/mailpanel-f2b-apply.php" /usr/local/sbin/mailpanel-f2b-apply || return 1
  if command -v php >/dev/null && ! php -l /usr/local/sbin/mailpanel-f2b-apply >/dev/null 2>&1; then
    warn "helper mailpanel-f2b-apply fails php -l — check the file"
    return 1
  fi
  # 1.0.x zostawiało propozycję w pliku w katalogu www-data; od 1.1.0 propozycja idzie na stdin
  if [ -d /var/lib/mailpanel-f2b ]; then
    rm -f /var/lib/mailpanel-f2b/jail.local.proposed
    rmdir /var/lib/mailpanel-f2b 2>/dev/null && ok "removed the unused directory /var/lib/mailpanel-f2b"
  fi
  ok "helper /usr/local/sbin/mailpanel-f2b-apply (755 root)"
}

# Kopie plików z sekretami zostawione ręcznie albo przez starsze instalatory (config.inc.php.bak-*, config.old.php,
# .env.bak ...): tylko dla roota (600). Proces PHP ich nie potrzebuje, a czytelne dla wszystkich (755 po kopiowaniu)
# zdradzają hasło bazy i des_key Roundcube każdemu lokalnemu kontu.
SECRET_COPY_NAMES=(-name 'config.inc.php.bak*' -o -name 'config.inc.php.save' -o -name 'config.inc.php.orig'
                   -o -name 'config.old.php' -o -name '.env.*' ! -name '.env.example')
secure_secret_copies() {
  local d f n=0
  for d in "$RC_DIR" "$RC_DIR/config" "$RC_DIR/plugins/password"; do
    [ -d "$d" ] || continue
    while IFS= read -r -d '' f; do
      chown root:root "$f" && chmod 600 "$f" && n=$((n + 1))
    done < <(find "$d" -maxdepth 1 -type f \( "${SECRET_COPY_NAMES[@]}" \) -print0 2>/dev/null)
  done
  ok "copies of files with secrets (config.inc.php.bak*, config.old.php, .env.*): $n file(s) — root:root 600"
}

# --- pool helpers begin (tests/scripts_test.php sources exactly this block) ----------------------------------------
# osobna pula PHP-FPM (sekcja 1b) i własność plików. Ścieżki systemowe są tu stałymi (testy podmieniają je po wczytaniu).
APACHE_SITES=/etc/apache2/sites-enabled
LOGROTATE_D=/etc/logrotate.d
RC_BACKUP_ROOT=/root

# Wersja PHP-FPM: jedyna zainstalowana albo ta, której usługa działa.
detect_php_ver() {
  local d v cand=()
  for d in /etc/php/*/fpm/pool.d; do
    [ -d "$d" ] || continue
    v=${d#/etc/php/}; v=${v%%/*}
    [[ "$v" =~ ^[0-9]+\.[0-9]+$ ]] && cand+=("$v")
  done
  [ "${#cand[@]}" -eq 1 ] && { printf '%s' "${cand[0]}"; return 0; }
  for v in "${cand[@]}"; do
    systemctl is-active --quiet "php$v-fpm" && { printf '%s' "$v"; return 0; }
  done
  return 1
}

pool_sock()  { printf '/run/php/php%s-fpm-%s.sock' "$PHP_VER" "$PANEL_USER"; }
pool_conf()  { printf '/etc/php/%s/fpm/pool.d/%s.conf' "$PHP_VER" "$PANEL_USER"; }

# Pliki vhostów Apache (sites-enabled, po rozwinięciu dowiązań), których DocumentRoot to RC_DIR albo RC_DIR/public_html.
rc_vhost_files() {
  local f real seen=" "
  for f in "$APACHE_SITES"/*; do
    [ -e "$f" ] || continue
    real=$(readlink -f -- "$f") || continue
    case "$seen" in *" $real "*) continue ;; esac
    seen="$seen$real "
    grep -qE "^[[:space:]]*DocumentRoot[[:space:]]+\"?${RC_DIR}(/public_html)?/?\"?[[:space:]]*$" "$real" && printf '%s\n' "$real"
  done
}

# Czy Roundcube NAPRAWDĘ działa w osobnej puli: użytkownik i plik puli istnieją, a vhost kieruje PHP na jej gniazdo.
pool_ready() {
  local f found=0
  [ "$PANEL_USER" != "$APACHE_USER" ] && [ -n "$PHP_VER" ] && [ -f "$(pool_conf)" ] && id -u "$PANEL_USER" >/dev/null 2>&1 || return 1
  while IFS= read -r f; do
    grep -qF "$(pool_sock)" "$f" && found=1
  done < <(rc_vhost_files)
  [ "$found" -eq 1 ]
}

set_run_user() { if pool_ready; then RUN_USER=$PANEL_USER; else RUN_USER=$APACHE_USER; fi; }

# log_dir Roundcube, jeśli leży POZA drzewem (np. /var/log/roundcube) i jest wyraźnie jego własnym katalogiem; inaczej
# pusto. Czytane z config.inc.php jako tekst (nigdy nie wykonywane). Katalog o obcej nazwie (np. /var/log) — nie ruszamy.
rc_log_dir() {
  local cfg="$RC_DIR/config/config.inc.php" v
  [ -f "$cfg" ] || return 0
  v=$(grep -E "^[[:space:]]*\\\$config\[['\"]log_dir['\"]\][[:space:]]*=" "$cfg" | tail -1 \
      | sed -nE "s/.*=[[:space:]]*['\"]([^'\"]+)['\"][[:space:]]*;.*/\1/p")
  v=${v%/}
  [[ "$v" =~ ^/[A-Za-z0-9._/-]+$ ]] && [[ "$v" != *..* ]] || return 0
  case "$v" in "$RC_DIR" | "$RC_DIR"/*) return 0 ;; esac      # w drzewie: logs/ obsługuje runtime_ownership
  if [[ ! "${v##*/}" =~ ^(roundcube|rcube|webmail) ]]; then
    warn "Roundcube's log_dir ($v) does not look like its own directory — give it to the PHP user manually: chown -R <user>: $v" >&2
    return 0
  fi
  printf '%s' "$v"
}

# logrotate dla katalogu $1 ma odtwarzać pliki dla użytkownika $2 (linia `create MODE USER GROUP`); kopia + logrotate -d.
logrotate_user() {
  local dir=$1 u=$2 f tmp bdir
  [ -n "$dir" ] && [ -d "$LOGROTATE_D" ] || return 0
  for f in "$LOGROTATE_D"/*; do
    [ -f "$f" ] && grep -qF -- "$dir" "$f" || continue
    tmp=$(mktemp)
    sed -E "s/^([[:space:]]*create[[:space:]]+[0-7]+)[[:space:]]+[A-Za-z0-9_-]+[[:space:]]+[A-Za-z0-9_-]+[[:space:]]*$/\1 $u $u/" "$f" > "$tmp"
    if cmp -s "$tmp" "$f"; then rm -f "$tmp"; continue; fi
    bdir="$RC_BACKUP_ROOT/roundcontrol-logrotate-backup-$(date +%Y%m%d%H%M%S)"
    mkdir -p "$bdir" && cp -p "$f" "$bdir/" && cat "$tmp" > "$f"
    rm -f "$tmp"
    if command -v logrotate >/dev/null && ! logrotate -d "$f" >/dev/null 2>&1; then
      cp -p "$bdir/$(basename "$f")" "$f"
      warn "logrotate -d rejects the modified $f — restored (change the create line manually to: create 0640 $u $u)"
    else
      ok "$f: logs are recreated for $u (create … $u $u; backup in $bdir)"
    fi
  done
}

# Wszystko, co proces PHP Roundcube/panelu ZAPISUJE (temp/, logs/, log_dir poza drzewem), należy do $1; sekrety, które
# CZYTA, są root:$1 640. Wywoływane przy przełączaniu puli (1b) i po porządkowaniu własności (7).
runtime_ownership() {
  local u=$1 d f ld
  for d in "$RC_DIR/temp" "$RC_DIR/logs"; do
    [ -d "$d" ] && chown -R "$u:$u" "$d"
  done
  ld=$(rc_log_dir)
  if [ -n "$ld" ] && [ -d "$ld" ]; then
    chown -R "$u:$u" "$ld"
    logrotate_user "$ld" "$u"
  fi
  for f in "$RC_DIR/config/config.inc.php" "$RC_DIR/plugins/password/config.inc.php" "$RC_DIR/.env" /etc/mailpanel/panel.env; do
    [ -f "$f" ] && [ ! -L "$f" ] && { chown root:"$u" "$f"; chmod 640 "$f"; }
  done
  [ -d /etc/mailpanel ] && { chown root:"$u" /etc/mailpanel; chmod 750 /etc/mailpanel; }
  ok "writable by $u: temp/ logs/${ld:+ $ld}; secrets (config.inc.php, password/config.inc.php, .env) root:$u 640"
}

# Wstawia blok SetHandler (gniazdo puli) do każdego <VirtualHost> z DocumentRoot = RC_DIR(/public_html), sprawdza
# apache2ctl configtest i przeładowuje Apache. Przy błędzie przywraca pliki. Idempotentne (gniazdo już jest = bez zmian).
switch_vhosts() {
  local sock=$1 f tmp block bdir rc changed=() files=()
  mapfile -t files < <(rc_vhost_files)
  if [ "${#files[@]}" -eq 0 ]; then
    warn "no Apache vhost with DocumentRoot $RC_DIR(/public_html) found in $APACHE_SITES — add the block from system/apache/roundcontrol-fpm-handler.conf by hand"
    return 1
  fi
  block=$(render_template "$SRC/system/apache/roundcontrol-fpm-handler.conf" @SOCKET@ "$sock" | render_template - @PANEL_USER@ "$PANEL_USER" | render_template - @RC_DIR@ "$RC_DIR")
  bdir="$RC_BACKUP_ROOT/roundcontrol-apache-backup-$(date +%Y%m%d%H%M%S)"
  for f in "${files[@]}"; do
    tmp=$(mktemp)
    RC_ROOT="$RC_DIR" RC_SOCK="$sock" RC_BLOCK="$block" awk '
      BEGIN { rc = ENVIRON["RC_ROOT"]; sock = ENVIRON["RC_SOCK"]; block = ENVIRON["RC_BLOCK"]; n = 0 }
      /^[ \t]*<VirtualHost[ \t>]/ { inv = 1; hit = 0; has = 0 }
      inv && /^[ \t]*DocumentRoot[ \t]/ { dr = $2; gsub(/"/, "", dr); sub(/\/+$/, "", dr); if (dr == rc || dr == rc "/public_html") hit = 1 }
      inv && index($0, sock) > 0 { has = 1 }
      /^[ \t]*<\/VirtualHost>/ { if (inv && hit && !has) { print block; n++ } inv = 0 }
      { print }
      END { exit (n > 0 ? 0 : 3) }
    ' "$f" > "$tmp"; rc=$?
    if [ "$rc" -eq 0 ]; then
      mkdir -p "$bdir" && cp -p "$f" "$bdir/" && cat "$tmp" > "$f" && changed+=("$f")
    elif [ "$rc" -ne 3 ]; then
      warn "awk did not process $f"
    fi
    rm -f "$tmp"
  done
  if [ "${#changed[@]}" -eq 0 ]; then
    ok "the Roundcube vhosts already send PHP to the $PANEL_USER pool ($sock)"
    return 0
  fi
  if ! apache2ctl configtest >/dev/null 2>&1 || ! systemctl reload apache2; then
    for f in "${changed[@]}"; do cp -p "$bdir/$(basename "$f")" "$f"; done
    systemctl reload apache2 >/dev/null 2>&1
    warn "apache2ctl configtest / reload failed with the new handler — vhosts restored from $bdir"
    return 1
  fi
  ok "vhosts (${changed[*]}): PHP -> $sock (backups in $bdir); Apache reloaded"
}

# Sekcja 1b: użytkownik systemowy, katalog sesji, plik puli (php-fpm -t), reload php-fpm, własność plików dla puli,
# handler w vhostach. Przy błędzie po zmianie własności wraca ona do użytkownika Apache (Roundcube dalej działa w [www]).
setup_pool() {
  local svc="php$PHP_VER-fpm" conf sock sess tmp bdir i
  conf=$(pool_conf); sock=$(pool_sock); sess="/var/lib/php/sessions-$PANEL_USER"
  command -v "php-fpm$PHP_VER" >/dev/null || { warn "php-fpm$PHP_VER not found in PATH"; return 1; }
  if ! id -u "$PANEL_USER" >/dev/null 2>&1; then
    useradd --system --user-group --no-create-home --home-dir /nonexistent --shell /usr/sbin/nologin "$PANEL_USER" \
      || { warn "useradd $PANEL_USER failed"; return 1; }
    ok "system user $PANEL_USER (no shell, no home directory)"
  fi
  install -d -o "$PANEL_USER" -g "$PANEL_USER" -m 700 "$sess" || return 1
  tmp=$(mktemp)
  render_template "$SRC/system/php-fpm/roundcontrol-pool.conf" @POOL@ "$PANEL_USER" | render_template - @PANEL_USER@ "$PANEL_USER" \
    | render_template - @APACHE_USER@ "$APACHE_USER" | render_template - @SOCKET@ "$sock" | render_template - @SESSION_DIR@ "$sess" > "$tmp"
  if [ -f "$conf" ] && cmp -s "$tmp" "$conf"; then
    ok "pool $conf unchanged"
  else
    bdir="$RC_BACKUP_ROOT/roundcontrol-pool-backup-$(date +%Y%m%d%H%M%S)"
    mkdir -p "$bdir"; [ -f "$conf" ] && cp -p "$conf" "$bdir/"
    install -o root -g root -m 644 "$tmp" "$conf"
    if ! "php-fpm$PHP_VER" -t >/dev/null 2>&1 || ! systemctl reload "$svc"; then
      if [ -f "$bdir/$(basename "$conf")" ]; then cp -p "$bdir/$(basename "$conf")" "$conf"; else rm -f "$conf"; fi
      systemctl reload "$svc" >/dev/null 2>&1
      rm -f "$tmp"
      warn "php-fpm$PHP_VER -t / reload rejects the new pool — previous state restored ($("php-fpm$PHP_VER" -t 2>&1 | tail -1))"
      return 1
    fi
    ok "pool [$PANEL_USER]: $conf (socket $sock, sessions $sess)"
  fi
  rm -f "$tmp"
  for i in $(seq 1 20); do [ -S "$sock" ] && break; sleep 0.5; done
  [ -S "$sock" ] || { warn "socket $sock did not appear after reloading $svc"; return 1; }
  runtime_ownership "$PANEL_USER"
  if ! switch_vhosts "$sock"; then
    runtime_ownership "$APACHE_USER"
    warn "file ownership went back to $APACHE_USER (Roundcube keeps running in the shared pool)"
    return 1
  fi
  return 0
}
# --- pool helpers end ------------------------------------------------------------------------------------------------

# Instaluje plik reguł jako /etc/sudoers.d/mailpanel tak, żeby sudo NIGDY nie zostało zepsute: plik sprawdzany sam
# (visudo -cf), kładziony pod nazwą z kropką (sudo takie pliki pomija), przenoszony na miejsce i dopiero wtedy sprawdzana
# jest CAŁA konfiguracja (visudo -c widzi np. zduplikowany alias w dwóch plikach — tego visudo -cf PLIK nie zobaczy, a to
# błąd składni całego sudo). Przy błędzie wraca poprzedni plik (albo nowy jest usuwany) i funkcja zwraca 1.
install_sudoers_file() {
  local src=$1 dst=/etc/sudoers.d/mailpanel stage=/etc/sudoers.d/mailpanel.rc-new prev="" why
  if ! visudo -c >/dev/null 2>&1; then
    warn "visudo -c reports an error BEFORE any change — not touching sudoers, fix it first: visudo -c"
    return 1
  fi
  visudo -cf "$src" >/dev/null 2>&1 || { warn "visudo -cf rejects the rules file ($src)"; return 1; }
  install -o root -g root -m 440 "$src" "$stage" || { rm -f "$stage"; return 1; }
  if [ -f "$dst" ]; then
    prev=$(mktemp /root/sudoers-mailpanel.prev.XXXXXX) && cp -p "$dst" "$prev" || { rm -f "$stage"; return 1; }
  fi
  mv -f "$stage" "$dst" || { rm -f "$stage"; return 1; }
  if visudo -c >/dev/null 2>&1; then
    [ -n "$prev" ] && rm -f "$prev"
    return 0
  fi
  why=$(visudo -c 2>&1 | grep -v -E 'parsed OK$' | head -3 | tr '\n' ' ')
  if [ -n "$prev" ]; then cp -p "$prev" "$dst" && rm -f "$prev"; else rm -f "$dst"; fi
  warn "with the new file the WHOLE sudo configuration fails visudo -c — previous state restored. Reason: $why"
  visudo -c >/dev/null 2>&1 || warn "WARNING: visudo -c still reports an error — fix it NOW, before you close the root shell"
  return 1
}

[ "$(id -u)" -eq 0 ] || die "Run as root (sudo bash install.sh)."
[[ "$PANEL_USER" =~ ^[a-z_][a-z0-9_-]{0,30}$ ]] || die "PANEL_USER='$PANEL_USER' is not a plain user name."
[[ "$APACHE_USER" =~ ^[a-z_][a-z0-9_-]{0,30}$ ]] || die "APACHE_USER='$APACHE_USER' is not a plain user name."
id -u "$APACHE_USER" >/dev/null 2>&1 || die "The Apache user '$APACHE_USER' does not exist (set APACHE_USER=...)."
case "$PANEL_USER" in root|daemon|nobody|bin|sys|vmail|dovecot|postfix|mysql) die "PANEL_USER=$PANEL_USER — choose a separate user (e.g. mailweb).";; esac
[ -n "$PHP_VER" ] || PHP_VER=$(detect_php_ver || true)
[ -z "$PHP_VER" ] || [[ "$PHP_VER" =~ ^[0-9]+\.[0-9]+$ ]] || die "PHP_VER='$PHP_VER' — expected e.g. 8.5"
[[ "$RC_DIR" =~ ^/[A-Za-z0-9._/-]+$ ]] || die "RC_DIR='$RC_DIR' must be an absolute path without spaces or special characters."
[ -d "$RC_DIR" ] || die "$RC_DIR not found (set RC_DIR=... if the path is different)."
command -v mysql >/dev/null || warn "mysql client not found in PATH — sections 0/4/5/6 need MariaDB/MySQL."

# Skrypt celuje w Debiana 13 (składnia Dovecot 2.4 itd.) — na innym systemie
# tylko ostrzegamy, decyzja należy do operatora.
if [ -r /etc/os-release ]; then
  . /etc/os-release
  if [ "${ID:-}" != "debian" ] || [ "${VERSION_ID:-}" != "13" ]; then
    warn "Detected: ${PRETTY_NAME:-unknown system}. The installer is tested on Debian 13."
    ask "Continue anyway" || die "Aborted."
  fi
fi

echo -e "${c_b}  Roundcube panel — system integrations${c_rst}"
hr
set_run_user
echo "Roundcube: $RC_DIR   |   panel PHP pool: $PANEL_USER   |   Apache: $APACHE_USER   |   PHP-FPM: ${PHP_VER:-not detected}"
echo "Roundcube PHP is currently running as: $RUN_USER"
hr

declare -A NEWPW=()   # hasła kont DB utworzonych w tym uruchomieniu (nigdzie indziej nie są zapisane)

# --- 0. Baza danych (mailserver + konta + schemat + granty) ---
if ask "0) Create the mailserver database and the mailuser/mailpanel/mailpostlogin accounts, import the schema and set the grants"; then
  if ! mysql -e 'SELECT 1' >/dev/null 2>&1; then
    warn "the mysql client cannot connect as root (socket) — skipping section 0."
  else
    S0=1
    run_sql "CREATE DATABASE IF NOT EXISTS mailserver CHARACTER SET utf8mb4;" || S0=0
    # Konto, które już istnieje, zostaje NIETKNIĘTE (hasło, granty mailuser). Nowe dostaje losowe hasło, wypisane
    # niżej — to jedyne miejsce, gdzie je zobaczysz. (1.0.x robiło ALTER USER przy każdym uruchomieniu.)
    for u in mailuser mailpanel mailpostlogin; do
      if db_user_exists "$u"; then
        ok "DB account $u exists — password and (for mailuser) grants unchanged"
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
        ok "schema panel_schema.sql imported (IF NOT EXISTS — existing data untouched)"
        if [ -n "${NEWPW[mailuser]:-}" ]; then
          run_sql "GRANT SELECT ON mailserver.virtual_domains TO 'mailuser'@'localhost';
GRANT SELECT ON mailserver.virtual_users   TO 'mailuser'@'localhost';
GRANT SELECT ON mailserver.virtual_aliases TO 'mailuser'@'localhost';
FLUSH PRIVILEGES;" && ok "new mailuser account: SELECT on virtual_domains / virtual_users / virtual_aliases"
        fi
        # granty aplikacji: per tabela, odbierane i nadawane od nowa (idempotentnie; to również sposób na zawężenie istniejącego konta)
        if mysql < "$SRC/sql/panel_grants.sql" 2>"$ERRF"; then
          ok "mailpanel grants (sql/panel_grants.sql): per table, no INSERT/UPDATE rights on aliases"
        else
          warn "sql/panel_grants.sql failed: $(head -1 "$ERRF")"
        fi
        if mysql < "$SRC/sql/postlogin_grants.sql" 2>"$ERRF"; then
          ok "mailpostlogin grants: only EXECUTE on sp_register_login"
        else
          warn "sql/postlogin_grants.sql failed: $(head -1 "$ERRF")"
        fi
      else
        warn "schema import failed: $(head -1 "$ERRF")"
      fi
      rm -f "$ERRF"
    elif [ "$S0" -eq 1 ]; then
      warn "Missing $SRC/sql/panel_schema.sql — import the schema by hand, then sql/panel_grants.sql and sql/postlogin_grants.sql."
    fi
    [ -n "${NEWPW[mailuser]:-}" ]      && warn "NEW mailuser password (put it in /etc/postfix/mysql-virtual-*.cf and dovecot.conf): ${NEWPW[mailuser]}"
    [ -n "${NEWPW[mailpanel]:-}" ]     && warn "NEW mailpanel password (enter it in install.php / put it in .env as PANEL_DB_PASS): ${NEWPW[mailpanel]}"
    [ -n "${NEWPW[mailpostlogin]:-}" ] && ok "new mailpostlogin account — section 6 will save its password to /etc/dovecot/mysql-mailpanel.cnf"
    [ "${#NEWPW[@]}" -gt 0 ] && warn "The passwords above are shown ONLY NOW (except mailpostlogin, whose password goes to a file in section 6)."
  fi
fi
hr

# --- 1. Skrypty /usr/local/bin ---
if ask "1) Install the scripts to /usr/local/bin"; then
  install -o root -g root -m 755 "$SRC/scripts/mailpanel-scan.sh"         /usr/local/bin/ && ok "mailpanel-scan.sh (755)"
  install -o root -g root -m 700 "$SRC/scripts/mailpanel-delete-user.sh"  /usr/local/bin/ && ok "mailpanel-delete-user.sh (700)"
  install -o root -g root -m 755 "$SRC/scripts/update-postfix-limits.sh"  /usr/local/bin/ && ok "update-postfix-limits.sh (755)"
  install -o root -g root -m 755 "$SRC/scripts/mailpanel-policy-daily.php" /usr/local/bin/ && ok "mailpanel-policy-daily.php (755)"
  if getent group dovecot >/dev/null; then
    install -o root -g dovecot -m 750 "$SRC/scripts/dovecot-postlogin.sh" /usr/local/bin/ && ok "dovecot-postlogin.sh (750 root:dovecot)"
  else
    install -o root -g root -m 755 "$SRC/scripts/dovecot-postlogin.sh"    /usr/local/bin/ && warn "dovecot-postlogin.sh (the dovecot group does not exist)"
  fi
fi

# --- 1b. Osobna pula PHP-FPM dla Roundcube i panelu ---
# Domyślnie jedna pula [www] (www-data) obsługuje WSZYSTKIE strony serwera: luka w dowolnej z nich czyta wtedy hasło
# bazy panelu, des_key Roundcube i używa reguł sudo panelu. Osobna pula = osobny użytkownik ($PANEL_USER): sekrety
# root:$PANEL_USER 640, reguły sudo dla $PANEL_USER, sesje w /var/lib/php/sessions-$PANEL_USER. Inne strony bez zmian.
hr
if pool_ready; then
  ok "1b) the separate pool [$PANEL_USER] is already running ($(pool_conf), vhost -> $(pool_sock))"
  if ask "   Refresh it (pool file from the template, file ownership, handler in the vhosts)"; then
    setup_pool || warn "refreshing the pool failed (see the messages above)"
  fi
elif [ "$PANEL_USER" = "$APACHE_USER" ]; then
  warn "1b) PANEL_USER=$PANEL_USER is the Apache user — Roundcube and the panel stay in the shared pool (no separate pool)"
elif [ -z "$PHP_VER" ]; then
  warn "1b) PHP-FPM version not detected (/etc/php/*/fpm) — set PHP_VER=8.x; Roundcube stays in the shared pool"
elif ask "1b) Create a separate PHP-FPM pool [$PANEL_USER] for Roundcube and the panel and switch the Apache vhost(s) to it"; then
  setup_pool || warn "the separate pool was NOT enabled — Roundcube keeps running in the shared pool (see the messages above)"
fi
set_run_user
ok "PHP of Roundcube and the panel runs as: $RUN_USER (this user gets the sudo rules, the secrets and the write directories)"

# --- 2. sudoers ---
hr
if ask "2) Install the Fail2Ban helper and the sudoers rules for $RUN_USER (/etc/sudoers.d/mailpanel), remove the old rules with wildcards"; then
  # Reguły mają DOKŁADNE argumenty (^regex$) — wymagają sudo >= 1.9.10 (Debian 13: 1.9.16).
  # Starsze sudo traktuje ^...$ dosłownie, więc nic by nie pasowało; nie instalujemy wtedy niczego.
  SV=$(sudo -V 2>/dev/null | head -1 | grep -oE '[0-9]+\.[0-9]+\.[0-9]+' | head -1 || true)
  if [ -z "$SV" ] || [ "$(printf '%s\n1.9.10\n' "$SV" | sort -V | head -1)" != "1.9.10" ]; then
    warn "sudo ${SV:-unknown} < 1.9.10 — no ^regex\$ arguments; sudoers NOT installed (upgrade sudo)"
  elif install_f2b_helper; then   # helper najpierw — reguła sudoers wskazuje na niego
    # jedyna zmienna linia pliku: User_Alias z użytkownikiem, który NAPRAWDĘ wykonuje PHP panelu (sekcja 1b)
    tmp=$(mktemp)
    render_template "$SRC/system/sudoers.d/mailpanel" "User_Alias ROUNDCONTROL_USER = mailweb" "User_Alias ROUNDCONTROL_USER = $RUN_USER" > "$tmp"
    SUDOERS_OK=0
    if ! grep -qx "User_Alias ROUNDCONTROL_USER = $RUN_USER" "$tmp"; then
      warn "no User_Alias line found in $SRC/system/sudoers.d/mailpanel — sudoers NOT installed"
    elif install_sudoers_file "$tmp"; then
      SUDOERS_OK=1
      ok "sudoers for $RUN_USER installed: the file (visudo -cf) and the WHOLE configuration (visudo -c) are valid; exact arguments"
    else
      warn "sudoers NOT installed (see the message above; check $SRC/system/sudoers.d/mailpanel and: visudo -c)"
    fi
    rm -f "$tmp"
    # sudo przyznaje polecenie, gdy pasuje KTÓRAKOLWIEK reguła: jedna stara reguła z wildcardem (1.0: fail2ban-client,
    # doveadm, mailpanel-scan.sh; w /etc/sudoers albo w osobnych plikach mailpanel-*) unieważnia wszystkie dokładne.
    # Sprawdzamy użytkownika Apache (stare reguły www-data; z osobną pulą www-data nie ma mieć ŻADNEJ reguły panelu)
    # i użytkownika puli (gdyby ktoś dopisał mu coś ręcznie poza /etc/sudoers.d/mailpanel).
    if [ "$SUDOERS_OK" -eq 1 ]; then
      for CU in $(printf '%s\n' "$APACHE_USER" "$RUN_USER" | sort -u); do
        OLD=$(WEB_USER="$CU" bash "$SRC/scripts/mailpanel-sudoers-cleanup.sh" 2>&1); rc=$?
        if [ "$rc" -ne 0 ]; then
          echo -e "${c_red}✗ OLD panel sudo rules for $CU outside /etc/sudoers.d/mailpanel — wildcards give a path to root:${c_rst}"
          echo "$OLD" | sed 's/^/     /'
          if ask "   Comment them out now (backups in /root/sudoers-backup-*; every file and the whole configuration validated by visudo)"; then
            WEB_USER="$CU" bash "$SRC/scripts/mailpanel-sudoers-cleanup.sh" --apply || warn "some rules need manual editing (visudo) — see above"
          fi
        else
          ok "no old panel sudo rules for $CU outside /etc/sudoers.d/mailpanel"
        fi
      done
    else
      warn "The new sudoers file was not installed, so I am not touching the old rules."
    fi
  else
    warn "Installing the Fail2Ban helper failed — sudoers NOT installed."
  fi
fi

# --- 3. Fail2Ban ---
hr
if ask "3) Configure Fail2Ban (panel filters + jails)"; then
  install -o root -g root -m 644 "$SRC/system/fail2ban/filter.d/panel-permaban.conf" /etc/fail2ban/filter.d/ && ok "filter panel-permaban"
  install -o root -g root -m 644 "$SRC/system/fail2ban/filter.d/panel-tempban.conf"  /etc/fail2ban/filter.d/ && ok "filter panel-tempban"
  install -o root -g root -m 644 "$SRC/system/fail2ban/filter.d/mailpanel-portal.conf" /etc/fail2ban/filter.d/ && ok "filter mailpanel-portal (jail disabled by default)"
  [ -f /etc/fail2ban/filter.d/roundcube-auth.conf ] || install -o root -g root -m 644 "$SRC/system/fail2ban/filter.d/roundcube-auth.conf" /etc/fail2ban/filter.d/
  touch /var/log/mailpanel-permaban.log /var/log/mailpanel-tempban.log
  ok "permaban/tempban logs ready"

  # Jaile panelu żyją w OSOBNYM pliku jail.d/ — nadpisanie = idempotencja,
  # jail.local zostaje czysty dla jaili standardowych (edytowanych z panelu).
  if [ -f /etc/fail2ban/jail.local ] && grep -q '\[panel-permaban\]' /etc/fail2ban/jail.local; then
    warn "the panel jails are (legacy) in jail.local — leaving them there; consider moving them to jail.d/mailpanel.local"
  else
    install -o root -g root -m 644 "$SRC/system/fail2ban/jail.d/mailpanel.local" /etc/fail2ban/jail.d/mailpanel.local
    ok "panel jails: /etc/fail2ban/jail.d/mailpanel.local"
  fi

  # jail.local należy do ROOTA (root:root 644) — panel go nie zapisuje (patrz install_f2b_helper).
  # Stare kopie jail.local.bak-* (robione kiedyś przez panel) też przechodzą na roota, 600.
  if [ -f /etc/fail2ban/jail.local ]; then
    install_f2b_helper
    chown root:root /etc/fail2ban/jail.local && chmod 644 /etc/fail2ban/jail.local
    for b in /etc/fail2ban/jail.local.bak-*; do [ -e "$b" ] && chown root:root "$b" && chmod 600 "$b"; done
    ok "jail.local: root:root 644 (the panel only proposes changes; the mailpanel-f2b-apply helper writes them)"
  else
    warn "Missing /etc/fail2ban/jail.local — create it following the README (jails sshd/dovecot/postfix-sasl/roundcube-auth/recidive), as root:root 644."
  fi

  if command -v fail2ban-client >/dev/null; then
    if fail2ban-client -t >/dev/null 2>&1; then
      fail2ban-client reload >/dev/null 2>&1 && ok "configuration valid, fail2ban reloaded"
    else
      warn "fail2ban-client -t reports a configuration error — NOT reloaded (check: fail2ban-client -t)"
    fi
  fi

  if [ -f /etc/fail2ban/jail.local ] && [ -x /usr/local/sbin/mailpanel-f2b-apply ]; then
    # (a) zapis W MIEJSCU pod tym samym sandboxem co php-fpm (ProtectSystem=full + ReadWritePaths=jail.local) —
    #     dokładnie to, czego 1.0.x nigdy nie sprawdzało (test z roota nie widział read-only /etc)
    if command -v systemd-run >/dev/null; then
      R=$(systemd-run --quiet --wait --pipe --collect -p ProtectSystem=full -p ReadWritePaths=/etc/fail2ban/jail.local \
            /usr/local/sbin/mailpanel-f2b-apply --selftest 2>&1)
      if [ "$R" = "selftest ok" ]; then
        ok "helper under the php-fpm sandbox (ProtectSystem=full, ReadWritePaths=jail.local): in-place write + backups work"
      else
        warn "helper under the sandbox did NOT pass the selftest: ${R:-no response}"
      fi
    else
      warn "systemd-run not found — skipped testing the helper under the php-fpm sandbox"
    fi
    # (b) przez sudo jako użytkownik panelu: propozycja identyczna z jail.local => "unchanged" (przed 1.3.1: "bez zmian"; nic nie zapisuje).
    #     Sprawdza regułę sudoers, argumenty (""), czytanie ze stdin i uprawnienia.
    if grep -q 'mailpanel-f2b-apply' /etc/sudoers.d/mailpanel 2>/dev/null; then
      R=$(sudo -u "$RUN_USER" sudo -n /usr/local/sbin/mailpanel-f2b-apply < /etc/fail2ban/jail.local 2>&1)
      if [ "$R" = "unchanged" ] || [ "$R" = "bez zmian" ]; then   # "bez zmian" = output of helpers before 1.3.1
        ok "helper test as $RUN_USER via sudo: 'unchanged' (sudoers + helper + stdin work)"
      else
        warn "helper test as $RUN_USER did not pass: ${R:-no response} (check step 2 and: sudo -l -U $RUN_USER)"
      fi
    fi
  fi
fi

# --- 4. Policy service (dzienny limit wysyłki) ---
hr
if ask "4) Install the policy service (daily sending limit — counts recipients)"; then
  # Idempotencja: istniejące hasło z /etc/mailpanel-policy.cf jest używane ponownie (po sprawdzeniu kształtu), konto
  # DB istniejące — nietknięte. Nowe konto/hasło tylko, gdy ich brak.
  PW=""
  if [ -f /etc/mailpanel-policy.cf ]; then
    PW=$(file_kv /etc/mailpanel-policy.cf password || true)
    if [ -n "$PW" ] && ! valid_secret "$PW"; then
      warn "the password in /etc/mailpanel-policy.cf contains disallowed characters (allowed: A-Za-z0-9._~+-, 16-128) — NOT using it, a new one is needed"
      PW=""
    fi
  fi
  POLICY_OK=1
  if db_user_exists mailpolicy; then
    if [ -z "$PW" ]; then
      if ask "   DB account mailpolicy exists, but I do not know its password — set a new one (its only user: the file I am about to write)"; then
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
    ok "DB user mailpolicy + grants"
    if [ ! -f /etc/mailpanel-policy.cf ] || [ "$(file_kv /etc/mailpanel-policy.cf password || true)" != "$PW" ]; then
      [ -f /etc/mailpanel-policy.cf ] && cp -p /etc/mailpanel-policy.cf "/etc/mailpanel-policy.cf.bak-$(date +%s)"
      ( umask 077; render_template "$SRC/system/mailpanel-policy.cf.example" HASLO_MAILPOLICY "$PW" > /etc/mailpanel-policy.cf.new ) \
        && mv /etc/mailpanel-policy.cf.new /etc/mailpanel-policy.cf
    fi
    chown root:nogroup /etc/mailpanel-policy.cf && chmod 640 /etc/mailpanel-policy.cf
    ok "/etc/mailpanel-policy.cf (640 root:nogroup)"
    install -o root -g root -m 755 "$SRC/scripts/mailpanel-policy-daily.php" /usr/local/bin/
    echo "   Postfix: finish by hand following system/postfix/master.cf.snippet:"
    echo "     - in submission/submissions add  -o smtpd_data_restrictions=\$mua_data_restrictions  and  -o smtpd_recipient_limit=50"
    echo "     - main.cf: mua_data_restrictions = reject_unauth_pipelining, check_policy_service unix:private/policy-daily"
    echo "     - master.cf: the spawn service policy-daily"
    echo "     - Roundcube must send through 587 with authentication (smtp_host tls://localhost:587, smtp_user %u)"
    echo "     - postfix check && systemctl reload postfix"
  fi
fi

# --- 5. Plugin password (samoobsługa zmiany hasła) ---
hr
if ask "5) Configure the 'password' plugin (password change in webmail)"; then
  PCFG="$RC_DIR/plugins/password/config.inc.php"
  if [ -d "$RC_DIR/plugins/password" ]; then
    # Idempotencja: hasło z istniejącego config.inc.php jest zachowywane (po sprawdzeniu kształtu).
    PW=""
    if [ -f "$PCFG" ] && [ ! -L "$PCFG" ]; then
      PW=$(grep -oP "mysql://roundcubepw:\K[^@]+" "$PCFG" | head -1 || true)
      if [ -n "$PW" ] && ! valid_secret "$PW"; then
        warn "the password in $PCFG contains disallowed characters — NOT using it"
        PW=""
      fi
    fi
    PLUG_OK=1
    if db_user_exists roundcubepw; then
      if [ -z "$PW" ]; then
        if ask "   DB account roundcubepw exists, but I do not know its password — set a new one (its only user: the plugin file)"; then
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
      ok "DB user roundcubepw + grants"
      if [ ! -f "$PCFG" ] || ! grep -qF "mysql://roundcubepw:$PW@" "$PCFG"; then
        [ -f "$PCFG" ] && cp -p "$PCFG" "$PCFG.bak-$(date +%s)"
        ( umask 077; render_template "$SRC/plugins/password/config.inc.php.example" SILNE_HASLO_POLICY "$PW" > "$PCFG.new" ) \
          && mv "$PCFG.new" "$PCFG"
      fi
      chown root:"$RUN_USER" "$PCFG" && chmod 640 "$PCFG"
      ok "$PCFG (640 root:$RUN_USER)"
      grep -q "'password'" "$RC_DIR/config/config.inc.php" \
        && ok "'password' is already in \$config['plugins']" \
        || warn "'password' is not in \$config['plugins'] — section 9 will add it"
      rm -rf "$RC_DIR/temp/"* 2>/dev/null
    fi
  else
    warn "Missing $RC_DIR/plugins/password — the plugin ships with Roundcube; install Roundcube with its plugins."
  fi
fi

# --- 6. Rejestracja logowań IMAP/POP3 (dovecot postlogin -> sp_register_login) ---
hr
if ask "6) Configure /etc/dovecot/mysql-mailpanel.cnf (mailpostlogin account: EXECUTE on a single procedure only)"; then
  CNF=/etc/dovecot/mysql-mailpanel.cnf
  CUR_USER=""
  [ -f "$CNF" ] && CUR_USER=$(file_kv "$CNF" user || true)
  if [ "$CUR_USER" = "mailpostlogin" ]; then
    ok "$CNF already uses the mailpostlogin account — unchanged"
  else
    # Nowy plik albo MIGRACJA z 1.0.x, gdzie skrypt używał konta `mailpanel` (pełny dostęp do bazy panelu).
    [ -n "$CUR_USER" ] && warn "$CNF uses the account '$CUR_USER' — switching to mailpostlogin (the file will be backed up)"
    PLPW="${NEWPW[mailpostlogin]:-}"
    PL_OK=1
    if [ -z "$PLPW" ]; then
      PLPW=$(gen_pw)
      if db_user_exists mailpostlogin; then
        # konto istnieje, ale jego hasło znał tylko plik, którego nie ma / który zaraz zastępujemy
        ask "   DB account mailpostlogin exists, but I do not know its password — set a new one (its only user: this file)" \
          && { run_sql "ALTER USER 'mailpostlogin'@'localhost' IDENTIFIED BY '$PLPW';" || PL_OK=0; } || PL_OK=0
      else
        run_sql "CREATE USER 'mailpostlogin'@'localhost' IDENTIFIED BY '$PLPW';" || PL_OK=0
      fi
    fi
    if [ "$PL_OK" -eq 1 ]; then
      mysql < "$SRC/sql/postlogin_grants.sql" 2>/dev/null || warn "sql/postlogin_grants.sql failed (run section 0 first — schema and procedure)"
      [ -f "$CNF" ] && cp -p "$CNF" "$CNF.bak-$(date +%s)"
      ( umask 077; render_template "$SRC/system/dovecot/mysql-mailpanel.cnf.example" HASLO_MAILPOSTLOGIN "$PLPW" > "$CNF.new" ) \
        && mv "$CNF.new" "$CNF"
      chown dovecot:dovecot "$CNF" && chmod 400 "$CNF"
      ok "$CNF (400 dovecot:dovecot, mailpostlogin account)"
      if mysql --defaults-extra-file="$CNF" -e 'SELECT 1' >/dev/null 2>&1; then
        ok "the database connection as mailpostlogin works"
      else
        warn "the connection as mailpostlogin does NOT work — check the account and $CNF"
      fi
    fi
  fi
  echo "   Remember the imap-postlogin/pop3-postlogin services in /etc/dovecot/dovecot.conf"
  echo "   (template: system/dovecot/postlogin.conf.snippet)."
fi

# --- 7. Symlinki w webroot + właściciel plików ---
hr
if ask "7) Expose portal/panel/assets in the webroot (public_html) and set the file owner (code: root, read-only for $RUN_USER)"; then
  if [ -d "$RC_DIR/public_html" ]; then
    # Roundcube 1.7: DocumentRoot = public_html. Kod nakładki żyje POZA
    # webrootem — publikujemy tylko punkty wejścia (idempotentne ln -sfn).
    for lnk in assets portal.php panel; do
      ln -sfn "$RC_DIR/$lnk" "$RC_DIR/public_html/$lnk"
      chown -h root:root "$RC_DIR/public_html/$lnk"
    done
    [ -e "$RC_DIR/public_html/.htaccess" ] || ln -sfn "$RC_DIR/.htaccess" "$RC_DIR/public_html/.htaccess"
    ok "symlinks public_html/{assets,portal.php,panel,.htaccess}"
  else
    warn "Missing public_html (a layout other than 1.7?) — skip if the files are already in the webroot."
  fi

  # Kod nakładki: root:root, katalogi 755, pliki 644. Proces PHP ($RUN_USER) potrzebuje tylko ODCZYTU: gdy kod należy
  # do użytkownika PHP, KAŻDA luka w KAŻDEJ stronie z tej samej puli FPM pozwala podmienić panel.php i przechwytywać hasła.
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
  ok "overlay code (app assets panel portal.php config/panel_config.php plugins/custom_auth .htaccess): root:root, 755/644"

  # sekrety root:$RUN_USER 640, temp/ logs/ (i log_dir Roundcube poza drzewem) dla $RUN_USER
  runtime_ownership "$RUN_USER"
  secure_secret_copies

  if ask "   The rest of Roundcube (program/, vendor/, skins/, plugins/, config/...) also root:root — only temp/ and logs/ stay writable for $RUN_USER"; then
    chown -R root:root "$RC_DIR"
    runtime_ownership "$RUN_USER"      # chown -R wyżej zabrał je użytkownikowi PHP — oddajemy
    # żaden plik poza temp/ i logs/ nie może być zapisywalny dla grupy ani innych (np. 664 po ręcznych kopiowaniach)
    find "$RC_DIR" \( -path "$RC_DIR/temp" -o -path "$RC_DIR/logs" \) -prune -o -type f \( -perm -g+w -o -perm -o+w \) -exec chmod go-w {} +
    find "$RC_DIR" \( -path "$RC_DIR/temp" -o -path "$RC_DIR/logs" \) -prune -o -type d \( -perm -g+w -o -perm -o+w \) -exec chmod go-w {} +
    LEFT=$(find "$RC_DIR" \( -path "$RC_DIR/temp" -o -path "$RC_DIR/logs" \) -prune -o ! -type l \
             \( -user "$RUN_USER" -o -user "$APACHE_USER" -o -perm -o+w \) -print 2>/dev/null | head -5)
    if [ -z "$LEFT" ]; then
      ok "tree $RC_DIR: root:root, only temp/ and logs/ writable for $RUN_USER (find -user/-perm -o+w: empty)"
    else
      warn "still owned by $RUN_USER/$APACHE_USER or writable by everyone: $(echo "$LEFT" | tr '\n' ' ')"
    fi
    # plik 600/640, który należał do użytkownika PHP, jest teraz roota — proces PHP przestaje go czytać
    UNREAD=$(sudo -u "$RUN_USER" find "$RC_DIR" \( -path "$RC_DIR/temp" -o -path "$RC_DIR/logs" \) -prune -o -type f ! -readable \
               ! \( "${SECRET_COPY_NAMES[@]}" \) -print 2>/dev/null | head -5)
    if [ -z "$UNREAD" ]; then
      ok "$RUN_USER can read every file in the tree (except temp/, logs/ and the copies with secrets)"
    else
      warn "$RUN_USER can NOT read: $(echo "$UNREAD" | tr '\n' ' ') — give it a group: chown root:$RUN_USER <file> && chmod 640 <file>"
    fi
  else
    warn "the rest of Roundcube unchanged — check: find $RC_DIR -user $RUN_USER -o -user $APACHE_USER ; find $RC_DIR -perm -o+w"
  fi
fi

# --- 8. Przestarzały timer auto-reloadu Fail2Ban (wersja 1.0) ---
hr
# Do 1.0 timer mailpanel-f2b-reload co minutę porównywał mtime jail.local i robił ślepy reload. Od 1.0.1 jedyną
# drogą zapisu jest helper mailpanel-f2b-apply (root): sprawdza propozycję, robi fail2ban-client -t i sam przeładowuje —
# timer jest zbędny (i dublował reload po każdym zapisie). Nie instalujemy go już; stary usuwamy (za zgodą).
if [ -e /etc/systemd/system/mailpanel-f2b-reload.timer ] || [ -e /usr/local/sbin/mailpanel-f2b-reload.php ]; then
  if ask "8) Remove the obsolete timer mailpanel-f2b-reload (the mailpanel-f2b-apply helper does the reload)"; then
    systemctl disable --now mailpanel-f2b-reload.timer >/dev/null 2>&1
    rm -f /etc/systemd/system/mailpanel-f2b-reload.timer /etc/systemd/system/mailpanel-f2b-reload.service \
          /usr/local/sbin/mailpanel-f2b-reload.php /var/lib/mailpanel/f2b_state.json
    rmdir /var/lib/mailpanel 2>/dev/null
    systemctl daemon-reload
    ok "timer mailpanel-f2b-reload removed"
  fi
else
  ok "8) no obsolete mailpanel-f2b-reload timer — nothing to do"
fi

# --- 9. Wpięcie panelu w config.inc.php Roundcube ---
hr
if ask "9) Hook the panel into config.inc.php (require panel_config.php + the 'password' plugin)"; then
  CFG="$RC_DIR/config/config.inc.php"
  if [ ! -f "$CFG" ]; then
    warn "Missing $CFG — configure Roundcube first, then run this section again."
  elif ! command -v php >/dev/null; then
    warn "php not found in PATH — hook it in by hand following the README (section 5)."
  else
    # Bezpieczna edycja: kopia zapasowa -> zmiana -> walidacja php -l ->
    # przy błędzie automatyczny powrót do kopii. Ręczne przywrócenie:
    #   cp -a config.inc.php.bak-<czas> config.inc.php
    BAK="$CFG.bak-$(date +%s)"
    cp -a "$CFG" "$BAK"
    CHANGED=0
    if grep -q "panel_config.php" "$CFG"; then
      ok "require panel_config.php — already hooked in"
    else
      printf "\nrequire_once __DIR__ . '/panel_config.php';\n" >> "$CFG"
      CHANGED=1; ok "added require_once panel_config.php (at the end of the file)"
    fi
    if grep -q "'password'" "$CFG"; then
      ok "'password' — already in the plugin list"
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
        CHANGED=1; ok "added 'password' to \$config['plugins']"
      else
        warn "\$config['plugins'] not found in $CFG — add 'password' by hand"
      fi
    fi
    if php -l "$CFG" >/dev/null 2>&1; then
      if [ "$CHANGED" -eq 1 ]; then
        ok "syntax OK (php -l); backup: $BAK"
        echo "   Manual restore if needed: cp -a $BAK $CFG"
        rm -rf "$RC_DIR/temp/"* 2>/dev/null
      else
        rm -f "$BAK"; ok "nothing to change (backup removed)"
      fi
    else
      cp -a "$BAK" "$CFG"
      warn "php -l reported a syntax error — the original was RESTORED from $BAK."
      warn "Hook it in by hand: require_once + 'password' in plugins (README section 5)."
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
if ask "10) Install the php-fpm drop-in (ReadWritePaths=/etc/fail2ban/jail.local /etc/postfix) and restart php-fpm"; then
  mapfile -t FPM_SVCS < <(systemctl list-unit-files --type=service --no-legend 'php*-fpm.service' 2>/dev/null | awk '{print $1}')
  if [ "${#FPM_SVCS[@]}" -eq 0 ]; then
    warn "no php*-fpm.service found — add the drop-in by hand (README: php-fpm sandbox)"
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
    if ask "   Restart php-fpm now (page serving on this pool is briefly interrupted)"; then
      for svc in "${FPM_SVCS[@]}"; do
        [[ "$svc" =~ ^php[0-9.]*-fpm\.service$ ]] || continue
        systemctl restart "$svc" && ok "restart $svc" || warn "restart $svc failed"
        echo "   $(systemctl show -p ProtectSystem -p ReadWritePaths "$svc" | tr '\n' ' ')"
      done
    else
      warn "The drop-in takes effect only after: systemctl restart ${FPM_SVCS[*]}"
    fi
  fi
  # test zapisu main.cf pod takim samym sandboxem (ta sama wartość -> nic się nie zmienia, ale plik jest przepisany)
  if command -v systemd-run >/dev/null && command -v postconf >/dev/null; then
    CUR=$(postconf -h smtpd_client_message_rate_limit 2>/dev/null || true)
    if [[ "$CUR" =~ ^[0-9]{1,6}$ ]]; then
      if systemd-run --quiet --wait --pipe --collect -p ProtectSystem=full -p ReadWritePaths=/etc/postfix \
           /usr/sbin/postconf -e "smtpd_client_message_rate_limit=$CUR" >/dev/null 2>&1; then
        ok "postconf -e under the php-fpm sandbox (ReadWritePaths=/etc/postfix): writing main.cf works"
      else
        warn "postconf -e under the sandbox does NOT work — changing the Postfix limits from the panel will report an error"
      fi
    fi
  fi
fi

# --- 11. Kwarantanna usuniętych skrzynek ---
hr
# Czyszczenie robi timer systemd (Debian 13 z tego poradnika nie ma pakietu cron — /etc/cron.daily nikt by nie uruchomił).
# Wiek wpisu = czas usunięcia zapisany w nazwie katalogu (mv zachowuje mtime skrzynki, więc nie mtime).
if ask "11) Create /var/mail/deleted (quarantine, 700) and a systemd timer that purges entries older than 30 days"; then
  install -d -o root -g root -m 700 /var/mail/deleted && ok "/var/mail/deleted (700 root)"
  if install -o root -g root -m 755 "$SRC/scripts/mailpanel-purge-deleted" /usr/local/sbin/mailpanel-purge-deleted \
     && install -o root -g root -m 644 "$SRC/system/systemd/mailpanel-purge-deleted.service" /etc/systemd/system/ \
     && install -o root -g root -m 644 "$SRC/system/systemd/mailpanel-purge-deleted.timer" /etc/systemd/system/ \
     && systemctl daemon-reload && systemctl enable --now mailpanel-purge-deleted.timer >/dev/null 2>&1; then
    ok "timer mailpanel-purge-deleted (daily, Persistent=true; 30-day retention — RETENTION_DAYS in /usr/local/sbin/mailpanel-purge-deleted)"
  else
    warn "timer mailpanel-purge-deleted is NOT working — check: systemctl status mailpanel-purge-deleted.timer"
  fi
  # 1.1.0 przed poprawką kładło skrypt do /etc/cron.daily (bez crona nigdy nie ruszał); stara kopia jest zbędna
  [ -e /etc/cron.daily/mailpanel-purge-deleted ] && rm -f /etc/cron.daily/mailpanel-purge-deleted && ok "removed /etc/cron.daily/mailpanel-purge-deleted"
fi

# --- 12. Katalog języków dodanych w panelu (1.3.0) ---
hr
# Polski i angielski są w kodzie (root:root, pula nie może do niego pisać). Języki dodane w panelu (kopia, wgrany pakiet)
# to pliki JSON w katalogu DANYCH, do którego pisze wyłącznie użytkownik puli; panel czyta je json_decode — nigdy include.
# Inne miejsce: LANG_DATA_DIR w .env (wtedy utwórz ten katalog z tymi samymi prawami).
LANG_DIR=/var/lib/roundcontrol/lang
if ask "12) Create $LANG_DIR (languages added in the panel; owner $RUN_USER, 0750)"; then
  if install -d -o root -g root -m 755 /var/lib/roundcontrol && install -d -o "$RUN_USER" -g "$RUN_USER" -m 750 "$LANG_DIR"; then
    ok "$LANG_DIR ($RUN_USER:$RUN_USER 750)"
  else
    warn "could not create $LANG_DIR — copying and uploading languages in the panel will report an error (pl and en work without it)"
  fi
fi

hr
ok "Done. Verification:"
echo "   - https://<host>/portal.php  → the login screen"
echo "   - https://<host>/.env  and  /.env.bak  and  /scripts/mailpanel-f2b-apply.php  → 403"
echo "   - panel: Fail2Ban/Security without red warnings; saving in the Fail2Ban tab ends with a message that the configuration was saved, verified and reloaded"
echo "   - limit test: an over-limit SMTP send → 554 (the limit counts RECIPIENTS)"
echo "   - fail2ban-client status     → jails panel-permaban / panel-tempban"
echo "   - stat -c '%A %U:%G' /etc/fail2ban/jail.local  → -rw-r--r-- root:root (the panel does not write it)"
echo "   - sudo -l -U $RUN_USER  → only exact ^regex\$ / \"\" rules, no * character"
[ "$RUN_USER" != "$APACHE_USER" ] && echo "   - sudo -l -U $APACHE_USER  → NO panel rules at all (fail2ban-client, doveadm, mailpanel-*, update-postfix-limits)"
[ "$RUN_USER" != "$APACHE_USER" ] && echo "   - ps -o user= -C php-fpm${PHP_VER} | sort | uniq -c  → $RUN_USER pool processes appear once you open webmail"
echo "   - find $RC_DIR -user $RUN_USER -o -user $APACHE_USER -o -perm -o+w  → only temp/ and logs/"
echo "   - systemctl list-timers mailpanel-purge-deleted.timer  → daily quarantine purge"
echo "   - stat -c '%A %U:%G' /var/lib/roundcontrol/lang  → drwxr-x--- $RUN_USER:$RUN_USER (languages added in the panel)"
