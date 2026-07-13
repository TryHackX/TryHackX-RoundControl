#!/bin/bash
# /usr/local/bin/dovecot-postlogin.sh
# Wersja z ignorowaniem połączeń lokalnych (Roundcube)

EMAIL="${USER}"
CLIENT_IP="${IP:-${REMOTE_IP}}"

# === WALIDACJA DANYCH WEJŚCIOWYCH ===

if [ -z "$EMAIL" ] || [ -z "$CLIENT_IP" ]; then
    exec "$@"
    exit 0
fi

# === IGNOROWANIE LOKALNYCH POŁĄCZEŃ (FIX dla Roundcube) ===
# Jeśli IP to localhost lub adres wewnętrzny (fe80...), nie logujemy tego.
# Roundcube łączy się lokalnie, więc zaśmieca logi adresem serwera.
if [[ "$CLIENT_IP" == "127.0.0.1" ]] || [[ "$CLIENT_IP" == "::1" ]] || [[ "$CLIENT_IP" == fe80:* ]]; then
    exec "$@"
    exit 0
fi

# Walidacja formatu email
if ! echo "$EMAIL" | grep -qE '^[a-zA-Z0-9][a-zA-Z0-9._%+-]*@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}$'; then
    exec "$@"
    exit 0
fi

# Walidacja IP
if ! echo "$CLIENT_IP" | grep -qE '^([0-9]{1,3}\.){3}[0-9]{1,3}$|^([0-9a-fA-F]{0,4}:){2,7}[0-9a-fA-F]{0,4}$'; then
    exec "$@"
    exit 0
fi

# === SANITYZACJA ===
SAFE_EMAIL=$(echo "$EMAIL" | tr -cd 'a-zA-Z0-9@._+-')
SAFE_IP=$(echo "$CLIENT_IP" | tr -cd '0-9a-fA-F.:')

if [ ${#SAFE_EMAIL} -gt 190 ] || [ ${#SAFE_IP} -gt 45 ]; then
    exec "$@"
    exit 0
fi

# === ASYNCHRONICZNE LOGOWANIE ===
(
    mysql --defaults-extra-file=/etc/dovecot/mysql-mailpanel.cnf \
          --batch --skip-column-names \
          --connect-timeout=2 \
          mailserver -e "CALL sp_register_login('${SAFE_EMAIL}', '${SAFE_IP}');"
) &

exec "$@"
