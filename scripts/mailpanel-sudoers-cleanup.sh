#!/bin/bash
# mailpanel-sudoers-cleanup.sh [--apply]      (run as root from an admin shell, NOT through sudo from the panel)
#
# Finds - and with --apply removes - OLD sudo rules for the web user (default www-data) that grant the mail panel's
# commands outside /etc/sudoers.d/mailpanel: wildcard rules left by 1.0 / 1.0.1 for fail2ban-client, doveadm
# and mailpanel-scan.sh, and the old per-feature files (mailpanel-baninfo, -banip, -quota, -reload, -scan ...) or a
# block pasted into /etc/sudoers. sudo grants a command if ANY rule matches, so a single leftover wildcard rule
# makes the exact rules of /etc/sudoers.d/mailpanel worthless: `www-data` can still reach root.
#
# Without --apply it only lists what it found and exits 1 when something is left (0 = clean).
# With --apply, for every affected file it
#   * refuses to touch lines that also grant OTHER commands (prints the line: edit it yourself with `visudo -f FILE`);
#   * otherwise comments the offending lines out ("# [RoundControl <date> removed] <original>"),
#   * validates the result with `visudo -cf` and only then replaces the file (atomic rename, owner/mode kept),
#   * keeps the original under /root/sudoers-backup-<time>/.
# Exit: 0 clean / all fixed, 1 something left (dry run), 2 something needs a manual edit or a file failed validation.
#
# Files whose name contains '.' or ends in '~' are ignored by sudo (@includedir) and are ignored here too.
SUDOERS_MAIN="/etc/sudoers"
SUDOERS_DIR="/etc/sudoers.d"
OWN_FILE="mailpanel"
VISUDO="/usr/sbin/visudo"
BACKUP_ROOT="/root"
WEB_USER="${WEB_USER:-www-data}"

set -u
set -o pipefail
umask 077   # backups of sudoers files are private

APPLY=0
[ "${1:-}" = "--apply" ] && APPLY=1

if ! [[ "$WEB_USER" =~ ^[a-z_][a-z0-9_-]*$ ]]; then
    echo "WEB_USER '$WEB_USER' is not a plain user name" >&2
    exit 2
fi

CMD_RE='(fail2ban-client|doveadm|mailpanel-scan|update-postfix-limits|mailpanel-delete-user|mailpanel-f2b)'
BASE_RE='^(fail2ban-client|doveadm|mailpanel-scan[.]sh|update-postfix-limits[.]sh|mailpanel-delete-user[.]sh|mailpanel-f2b-apply|mailpanel-f2b-reload[.]php)$'
STAMP=$(date +%Y%m%d%H%M%S)
STATUS=0

# analyse FILE: prints "S<TAB>lineno<TAB>text" for a line that can be removed, "M<TAB>lineno<TAB>text" for one that
# needs a manual edit (grants other commands too, or continues on the next line)
analyse() {
    awk -v user="$WEB_USER" -v cmdre="$CMD_RE" -v basere="$BASE_RE" '
        { line[NR] = $0 }
        function ignorable(l) { return (l ~ /^[ \t]*#/ || l ~ /^[ \t]*$/) }
        function only_ours(l,    s, tok, n, p, base) {
            s = l
            while (match(s, "/[A-Za-z0-9_./+-]+")) {
                tok = substr(s, RSTART, RLENGTH)
                s = substr(s, RSTART + RLENGTH)
                n = split(tok, p, "/")
                base = p[n]
                if (base !~ basere) return 0
            }
            return 1
        }
        END {
            urx = "^[ \t]*" user "[ \t]"
            for (i = 1; i <= NR; i++) {
                l = line[i]
                if (ignorable(l)) continue
                if (l ~ /^[ \t]*Cmnd_Alias[ \t]/ && l ~ cmdre) {
                    split(l, f, /[ \t=]+/)
                    alias[f[2]] = 1
                    kind[i] = (l ~ /\\$/ || !only_ours(l)) ? "M" : "S"
                }
            }
            for (i = 1; i <= NR; i++) {
                l = line[i]
                if (ignorable(l) || (i in kind)) continue
                if (l !~ urx) continue
                hit = (l ~ cmdre)
                if (!hit) { for (a in alias) { if (index(l, a) > 0) { hit = 1 } } }
                if (!hit) continue
                kind[i] = (l ~ /\\$/ || !only_ours(l)) ? "M" : "S"
            }
            for (i = 1; i <= NR; i++) if (i in kind) print kind[i] "\t" i "\t" line[i]
        }
    ' "$1"
}

rewrite() {   # rewrite FILE -> stdout with the removable lines commented out
    local file=$1 lines=$2
    awk -v lines="$lines" -v stamp="$STAMP" '
        BEGIN { n = split(lines, a, ","); for (i = 1; i <= n; i++) rm[a[i]] = 1 }
        { if (NR in rm) print "# [RoundControl " stamp " removed] " $0; else print $0 }
    ' "$file"
}

TARGETS=()
[ -f "$SUDOERS_MAIN" ] && TARGETS+=("$SUDOERS_MAIN")
if [ -d "$SUDOERS_DIR" ]; then
    for f in "$SUDOERS_DIR"/*; do
        [ -f "$f" ] || continue
        base=$(basename -- "$f")
        [ "$base" = "$OWN_FILE" ] && continue
        case "$base" in *.* | *~) continue ;; esac
        TARGETS+=("$f")
    done
fi

FOUND=0
for file in "${TARGETS[@]}"; do
    out=$(analyse "$file") || { echo "cannot read $file" >&2; STATUS=2; continue; }
    [ -n "$out" ] || continue
    FOUND=1
    removable=""
    manual=0
    while IFS=$'\t' read -r kind no text; do
        [ -n "$kind" ] || continue
        if [ "$kind" = "S" ]; then
            echo "  $file:$no: $text"
            removable="${removable:+$removable,}$no"
        else
            echo "  $file:$no: $text    <== also grants other commands / continues on the next line: edit it yourself (visudo -f $file)"
            manual=1
        fi
    done <<<"$out"

    [ "$APPLY" -eq 1 ] || continue
    if [ "$manual" -eq 1 ]; then
        STATUS=2
        continue
    fi
    new="$file.new"
    cp -p -- "$file" "$new" || { echo "cannot copy $file" >&2; STATUS=2; continue; }
    rewrite "$file" "$removable" >"$new.body" && cat -- "$new.body" >"$new"
    rm -f -- "$new.body"
    if ! "$VISUDO" -cf "$new" >/dev/null 2>&1; then
        echo "  visudo rejects the edited $file - NOT replaced; edit it yourself: visudo -f $file" >&2
        rm -f -- "$new"
        STATUS=2
        continue
    fi
    bdir="$BACKUP_ROOT/sudoers-backup-$STAMP"
    mkdir -p -- "$bdir" && cp -p -- "$file" "$bdir/$(basename -- "$file")" || { echo "cannot back up $file" >&2; rm -f -- "$new"; STATUS=2; continue; }
    mv -- "$new" "$file" || { echo "cannot replace $file" >&2; STATUS=2; continue; }
    echo "  -> $file cleaned (original in $bdir/)"
done

if [ "$FOUND" -eq 0 ]; then
    echo "No leftover sudo rules for the mail panel outside $SUDOERS_DIR/$OWN_FILE."
    exit "$STATUS"
fi
if [ "$APPLY" -eq 0 ]; then
    echo "Leftover rules above. Re-run with --apply to comment them out (or remove them with visudo)."
    exit 1
fi
if "$VISUDO" -c >/dev/null 2>&1; then
    echo "visudo -c: OK"
else
    echo "visudo -c reports a problem - check it before closing this root shell!" >&2
    STATUS=2
fi
exit "$STATUS"
