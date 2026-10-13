#!/bin/bash
# mailpanel-sudoers-cleanup.sh [--apply]      (run as root from an admin shell, NOT through sudo from the panel)
#
# Finds - and with --apply removes - OLD sudo rules for the web user (default www-data) that grant the mail panel's
# commands outside /etc/sudoers.d/mailpanel: wildcard rules left by 1.0 / 1.0.1 for fail2ban-client, doveadm
# and mailpanel-scan.sh, and the old per-feature files (mailpanel-baninfo, -banip, -quota, -reload, -scan, -f2b ...) or
# a block pasted into /etc/sudoers. sudo grants a command if ANY rule matches, so a single leftover wildcard rule
# makes the exact rules of /etc/sudoers.d/mailpanel worthless: the web user can still reach root.
# Run it once per user that ever had panel rules (install.sh: www-data and the panel's own pool user).
#
# Rules are read as LOGICAL lines: a line ending in "\" continues on the next one (a multi-line Cmnd_Alias is one
# rule). Without --apply it only lists what it found and exits 1 when something is left (0 = clean).
# With --apply, for every affected file it
#   * refuses to touch rules that also grant OTHER commands (prints them: edit them yourself with `visudo -f FILE`);
#   * otherwise comments every physical line of the offending rules out ("# [RoundControl <date> removed] <original>";
#     a trailing "\" is dropped from the comment, so nothing is continued into the next line),
#   * validates the result with `visudo -cf` and only then replaces the file (atomic rename, owner/mode kept),
#   * keeps the original under /root/sudoers-backup-<time>/.
# At the end the WHOLE configuration is checked with `visudo -c`; if that fails, every file this run replaced is put
# back from the backup (a broken sudoers locks every sudo user out) and the exit code is 2.
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

# analyse FILE: one line per offending LOGICAL rule: "S<TAB>first<TAB>last<TAB>text" when it can be removed,
# "M<TAB>first<TAB>last<TAB>text" when it needs a manual edit (it also grants other commands / other aliases)
analyse() {
    awk -v user="$WEB_USER" -v cmdre="$CMD_RE" -v basere="$BASE_RE" '
        { line[NR] = $0 }
        function ignorable(l) { return (l ~ /^[ \t]*#/ || l ~ /^[ \t]*$/) }
        # every /path in the text is one of the panel commands
        function paths_ours(l,    s, tok, n, p) {
            s = l
            while (match(s, "/[A-Za-z0-9_./+-]+")) {
                tok = substr(s, RSTART, RLENGTH)
                s = substr(s, RSTART + RLENGTH)
                n = split(tok, p, "/")
                if (p[n] !~ basere) return 0
            }
            return 1
        }
        # every item of the command list of a user rule is a panel command or one of the panel aliases found above
        function cmds_ours(l,    rest, n, items, k, it, m, parts) {
            rest = l
            sub(/^[ \t]*[^ \t]+[ \t]+/, "", rest)              # user
            sub(/^[^=]*=[ \t]*/, "", rest)                      # host =
            sub(/^\([^)]*\)[ \t]*/, "", rest)                   # (runas)
            while (match(rest, /^[A-Z_]+:[ \t]*/)) rest = substr(rest, RLENGTH + 1)   # NOPASSWD: and other tags
            n = split(rest, items, /,/)
            for (k = 1; k <= n; k++) {
                it = items[k]
                gsub(/^[ \t]+|[ \t]+$/, "", it)
                if (it == "") continue
                if (it in alias) continue
                if (match(it, /^\/[^ \t]+/)) {
                    m = split(substr(it, 1, RLENGTH), parts, "/")
                    if (parts[m] ~ basere) continue
                }
                return 0
            }
            return 1
        }
        END {
            # logical lines: a non-comment line ending in "\" continues on the next physical line
            n = 0
            i = 1
            while (i <= NR) {
                s = i; txt = line[i]
                if (!ignorable(txt)) {
                    while (txt ~ /\\[ \t]*$/ && i < NR) {
                        sub(/\\[ \t]*$/, "", txt)
                        i++
                        txt = txt " " line[i]
                    }
                }
                n++; ls[n] = s; le[n] = i; lt[n] = txt
                i++
            }
            urx = "^[ \t]*" user "[ \t]"
            for (k = 1; k <= n; k++) {
                l = lt[k]
                if (ignorable(l)) continue
                if (l ~ /^[ \t]*Cmnd_Alias[ \t]/ && l ~ cmdre) {
                    split(l, f, /[ \t=]+/)
                    alias[f[2]] = 1
                    kind[k] = paths_ours(l) ? "S" : "M"
                }
            }
            for (k = 1; k <= n; k++) {
                l = lt[k]
                if (ignorable(l) || (k in kind)) continue
                if (l !~ urx) continue
                hit = (l ~ cmdre)
                if (!hit) { for (a in alias) { if (l ~ ("(^|[^A-Za-z0-9_])" a "([^A-Za-z0-9_]|$)")) { hit = 1 } } }
                if (!hit) continue
                kind[k] = (paths_ours(l) && cmds_ours(l)) ? "S" : "M"
            }
            for (k = 1; k <= n; k++) if (k in kind) print kind[k] "\t" ls[k] "\t" le[k] "\t" line[ls[k]]
        }
    ' "$1"
}

rewrite() {   # rewrite FILE RANGES -> stdout with the physical lines of the removable rules commented out
    local file=$1 ranges=$2
    awk -v ranges="$ranges" -v stamp="$STAMP" '
        BEGIN {
            n = split(ranges, a, ",")
            for (i = 1; i <= n; i++) { split(a[i], r, "-"); for (j = r[1]; j <= r[2]; j++) rm[j] = 1 }
        }
        {
            if (NR in rm) { t = $0; sub(/\\[ \t]*$/, "", t); print "# [RoundControl " stamp " removed] " t }
            else print $0
        }
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
REPLACED=()      # files this run replaced (restored if the whole configuration does not validate)
bdir="$BACKUP_ROOT/sudoers-backup-$STAMP"
for file in "${TARGETS[@]}"; do
    out=$(analyse "$file") || { echo "cannot read $file" >&2; STATUS=2; continue; }
    [ -n "$out" ] || continue
    FOUND=1
    removable=""
    manual=0
    while IFS=$'\t' read -r kind first last text; do
        [ -n "$kind" ] || continue
        span=$([ "$first" = "$last" ] && echo "$first" || echo "$first-$last")
        if [ "$kind" = "S" ]; then
            echo "  $file:$span: $text"
            removable="${removable:+$removable,}$first-$last"
        else
            echo "  $file:$span: $text    <== also grants other commands: edit it yourself (visudo -f $file)"
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
    if ! rewrite "$file" "$removable" >"$new.body" || ! cat -- "$new.body" >"$new"; then
        echo "cannot write $new" >&2; rm -f -- "$new" "$new.body"; STATUS=2; continue
    fi
    rm -f -- "$new.body"
    if ! "$VISUDO" -cf "$new" >/dev/null 2>&1; then
        echo "  visudo rejects the edited $file - NOT replaced; edit it yourself: visudo -f $file" >&2
        rm -f -- "$new"
        STATUS=2
        continue
    fi
    mkdir -p -- "$bdir" && cp -p -- "$file" "$bdir/$(basename -- "$file")" || { echo "cannot back up $file" >&2; rm -f -- "$new"; STATUS=2; continue; }
    mv -- "$new" "$file" || { echo "cannot replace $file" >&2; rm -f -- "$new"; STATUS=2; continue; }
    REPLACED+=("$file")
    echo "  -> $file cleaned (original in $bdir/)"
done

if [ "$FOUND" -eq 0 ]; then
    echo "No leftover sudo rules for the mail panel ($WEB_USER) outside $SUDOERS_DIR/$OWN_FILE."
    exit "$STATUS"
fi
if [ "$APPLY" -eq 0 ]; then
    echo "Leftover rules above. Re-run with --apply to comment them out (or remove them with visudo)."
    exit 1
fi
if "$VISUDO" -c >/dev/null 2>&1; then
    echo "visudo -c: OK"
else
    echo "visudo -c reports a problem with the WHOLE configuration - restoring the files this run changed" >&2
    for f in "${REPLACED[@]}"; do
        cp -p -- "$bdir/$(basename -- "$f")" "$f" && echo "  restored $f" >&2
    done
    if "$VISUDO" -c >/dev/null 2>&1; then
        echo "visudo -c: OK after the restore (nothing of this run is left)" >&2
    else
        echo "visudo -c STILL fails - fix it NOW, before closing this root shell: visudo -c" >&2
    fi
    STATUS=2
fi
exit "$STATUS"
