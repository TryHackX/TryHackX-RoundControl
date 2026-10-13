#!/bin/bash
# /usr/local/bin/update-postfix-limits.sh MSG_RATE CONN_RATE CONN_COUNT   (root, through sudo from the panel)  — 1.1.0
#
# Sets smtpd_client_message_rate_limit / smtpd_client_connection_rate_limit / smtpd_client_connection_count_limit in
# /etc/postfix/main.cf, checks the result and reloads Postfix. It prints a success line ONLY when the new values read
# back from Postfix (`postconf -h`), `postfix check` passed and the reload exit 0 — never otherwise.
#
# main.cf lives in /etc. php-fpm runs with ProtectSystem=full, and a process started through sudo from PHP-FPM inherits
# that sandbox: /etc is READ-ONLY for this script too. `postconf -e` creates a temporary file in /etc/postfix and
# renames it over main.cf, so it needs the whole directory writable: add /etc/postfix to the php-fpm drop-in
# (ReadWritePaths=/etc/fail2ban/jail.local /etc/postfix; install.sh section 10). File permissions still keep the PHP user
# out of /etc/postfix — ReadWritePaths only lifts the read-only mount, not the owner/mode checks.
#
# Exit codes: 0 applied and reloaded   1 bad arguments   2 cannot read the current values
#             3 write failed / values did not stick (previous values restored if possible)
#             4 `postfix check` failed (previous values restored if possible)   5 saved but the reload failed
POSTCONF="/usr/sbin/postconf"
POSTFIX="/usr/sbin/postfix"
SYSTEMCTL="/usr/bin/systemctl"

set -u
set -o pipefail

MSG_LIMIT="${1:-}"
CONN_RATE="${2:-}"
CONN_COUNT="${3:-}"

# Validation: exactly three non-negative integers (1-6 digits; 0 = unlimited in Postfix). The text below is what the
# panel's availability probe looks for when the script is run without arguments.
if [ "$#" -ne 3 ] \
    || ! [[ "$MSG_LIMIT" =~ ^[0-9]{1,6}$ ]] || ! [[ "$CONN_RATE" =~ ^[0-9]{1,6}$ ]] || ! [[ "$CONN_COUNT" =~ ^[0-9]{1,6}$ ]]; then
    echo "Error: Inputs must be integers"
    exit 1
fi
MSG_LIMIT=$((10#$MSG_LIMIT))
CONN_RATE=$((10#$CONN_RATE))
CONN_COUNT=$((10#$CONN_COUNT))

KEYS=(smtpd_client_message_rate_limit smtpd_client_connection_rate_limit smtpd_client_connection_count_limit)
NEW=("$MSG_LIMIT" "$CONN_RATE" "$CONN_COUNT")
OLD=()
for k in "${KEYS[@]}"; do
    v=$("$POSTCONF" -h "$k") || { echo "Error: cannot read $k with postconf"; exit 2; }
    OLD+=("$v")
done

args_for() {   # args_for ARRAY_NAME -> key=value ... for postconf -e
    local -n vals=$1
    local i
    for i in "${!KEYS[@]}"; do
        printf '%s\n' "${KEYS[$i]}=${vals[$i]}"
    done
}

restore_old() {
    local a=()
    mapfile -t a < <(args_for OLD)
    "$POSTCONF" -e "${a[@]}" >/dev/null 2>&1
}

mapfile -t ARGS < <(args_for NEW)
if ! out=$("$POSTCONF" -e "${ARGS[@]}" 2>&1); then
    echo "Error: postconf -e failed: ${out} (is /etc/postfix writable? php-fpm ProtectSystem=full needs ReadWritePaths=/etc/postfix)"
    restore_old
    exit 3
fi

# The values must have stuck: read them back instead of trusting the exit status.
for i in "${!KEYS[@]}"; do
    got=$("$POSTCONF" -h "${KEYS[$i]}") || got="?"
    if [ "$got" != "${NEW[$i]}" ]; then
        echo "Error: ${KEYS[$i]} is '$got' after the update, expected '${NEW[$i]}' (main.cf not writable?)"
        restore_old
        exit 3
    fi
done

if ! out=$("$POSTFIX" check 2>&1); then
    echo "Error: postfix check failed: ${out}"
    restore_old
    exit 4
fi

if ! out=$("$SYSTEMCTL" reload postfix 2>&1); then
    echo "Error: values saved and checked, but 'systemctl reload postfix' failed: ${out}"
    exit 5
fi

echo "Postfix limits updated: Msg=$MSG_LIMIT, ConnRate=$CONN_RATE, ConnCount=$CONN_COUNT"
exit 0
