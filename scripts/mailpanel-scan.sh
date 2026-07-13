#!/bin/bash
# /usr/local/bin/mailpanel-scan.sh SINCE UNTIL [SOURCE]
# Agreguje NIEUDANE proby uwierzytelnienia z logow systemowych.
# SOURCE: ssh | dovecot | postfix | all (domyslnie all — tryb legacy).
# Wyjscie: "count service ip" per unikalna para (service,ip).
SINCE="$1"; UNTIL="$2"; SRC="${3:-all}"
[[ "$SINCE" =~ ^[0-9]{4}-[0-9]{2}-[0-9]{2}$ ]] || { echo "ERR_DATE"; exit 1; }
[[ "$UNTIL" =~ ^[0-9]{4}-[0-9]{2}-[0-9]{2}$ ]] || { echo "ERR_DATE"; exit 1; }
[[ "$SRC" =~ ^(ssh|dovecot|postfix|all)$ ]] || { echo "ERR_SRC"; exit 1; }
U="$UNTIL 23:59:59"

scan_ssh() {
  journalctl -u ssh --since "$SINCE" --until "$U" -q --no-pager 2>/dev/null \
    | grep -oE 'Failed password .* from [0-9a-fA-F:.]+' \
    | grep -oE '[0-9a-fA-F:.]+$' | sed 's/^/ssh /'
}

scan_dovecot() {
  # Tylko realne niepowodzenia auth ("(auth failed"). Zwykle rozlaczenia
  # i "no auth attempts" (skanery portow, monitoring) NIE sa liczone.
  journalctl -u dovecot --since "$SINCE" --until "$U" -q --no-pager 2>/dev/null \
    | grep 'auth failed' \
    | grep -oE 'rip=[0-9a-fA-F:.]+' | sed 's/rip=/dovecot /'
}

scan_postfix() {
  # -u 'postfix*' zamiast calego journala (kilkaset x mniej linii do grepowania).
  # Filtr [.:]+ odsiewa PID-y procesow (smtpd[12345]) - IP zawiera kropke/dwukropek.
  journalctl -u 'postfix*' --since "$SINCE" --until "$U" -q --no-pager 2>/dev/null \
    | grep -iE 'authentication failed' \
    | grep -oE '\[[0-9a-fA-F:.]+\]' | tr -d '[]' | grep -E '[.:]' | sed 's/^/postfix /'
}

{
  case "$SRC" in
    ssh)     scan_ssh ;;
    dovecot) scan_dovecot ;;
    postfix) scan_postfix ;;
    all)     scan_ssh; scan_dovecot; scan_postfix ;;
  esac
} | sort | uniq -c | sort -rn | head -500
