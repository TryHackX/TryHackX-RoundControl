#!/bin/bash
# /usr/local/bin/update-postfix-limits.sh

MSG_LIMIT=$1
CONN_RATE=$2
CONN_COUNT=$3

# Walidacja - muszą być liczby
if ! [[ "$MSG_LIMIT" =~ ^[0-9]+$ ]] || ! [[ "$CONN_RATE" =~ ^[0-9]+$ ]] || ! [[ "$CONN_COUNT" =~ ^[0-9]+$ ]]; then
    echo "Error: Inputs must be integers"
    exit 1
fi

# Używamy postconf do bezpiecznej edycji
postconf -e "smtpd_client_message_rate_limit = $MSG_LIMIT"
postconf -e "smtpd_client_connection_rate_limit = $CONN_RATE"
postconf -e "smtpd_client_connection_count_limit = $CONN_COUNT"

# Przeładuj postfixa
systemctl reload postfix

echo "Postfix limits updated: Msg=$MSG_LIMIT, ConnRate=$CONN_RATE, ConnCount=$CONN_COUNT"
