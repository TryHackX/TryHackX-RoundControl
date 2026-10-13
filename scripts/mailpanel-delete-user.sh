#!/bin/bash
# mailpanel-delete-user.sh user@domain   (root, through sudo from the panel)  — 1.1.0
#
# Moves the maildir of a DELETED account out of /var/mail/vhosts into a quarantine directory
# (/var/mail/deleted, mode 700; install.sh section 11 adds the systemd timer mailpanel-purge-deleted that purges
# entries older than 30 days). The quarantine name carries the DELETION time (<domain>__<user>__<YYYYmmddHHMMSS>):
# the purge reads it from there, because `mv` keeps the maildir's old mtime.
# A quarantine instead of `rm -rf`: this script is reachable by any code running as the panel's PHP user (sudoers
# cannot say WHICH mailbox), so a wrong or hostile call must be recoverable.
#
# It refuses unless ALL of these hold:
#   * the argument is exactly one well-formed lower-case address (no '/', no '..', no '.', nothing empty);
#   * Dovecot's userdb says the account does NOT exist (`doveadm user`): a live mailbox is never touched. If that
#     cannot be established (doveadm missing / failing) it refuses too;
#   * the real path of the directory sits exactly one level under the domain directory inside /var/mail/vhosts.
# Every step is checked; the exit code is non-zero on anything but a completed move.
#
# Exit codes:  0 moved to quarantine        1 usage / not an address        2 forbidden characters
#              3 path outside the mail tree 4 no such directory (nothing to delete)
#              5 move failed                6 account still exists in Dovecot (refused)
#              7 cannot verify with doveadm (refused)    8 quarantine directory unusable
#
# Under php-fpm's ProtectSystem=full this script inherits a read-only /usr and /etc; it writes only under /var/mail,
# which stays writable, so no ReadWritePaths entry is needed for it.
#
# The three paths below are edited by the tests (sed); do not turn them into environment variables — a root script
# must not take its targets from the environment.
BASE_DIR="/var/mail/vhosts"
QUARANTINE_DIR="/var/mail/deleted"
DOVEADM="/usr/bin/doveadm"

set -u
set -o pipefail
umask 077   # directories this script creates (quarantine) are private even before the explicit chmod

die() { echo "$2"; exit "$1"; }

# 1. exactly one non-empty argument
if [ "$#" -ne 1 ] || [ -z "$1" ]; then
    die 1 "Error: Invalid email format"
fi
EMAIL="${1,,}"

# 2. characters that could turn the address into a path: slashes, backslashes, "..", whitespace, control characters
case "$EMAIL" in
    */* | *\\* | *..*) die 2 "Error: Invalid characters detected" ;;
esac
if [[ "$EMAIL" =~ [[:space:][:cntrl:]] ]]; then
    die 2 "Error: Invalid characters detected"
fi

# 3. shape of the address: alnum at both ends of both parts (so '.', '-x', 'x.' are impossible)
if [[ ! "$EMAIL" =~ ^[^@]+@[^@]+$ ]]; then
    die 1 "Error: Invalid email format"
fi
USER_PART="${EMAIL%@*}"
DOMAIN_PART="${EMAIL##*@}"
re_local='^[a-z0-9]([a-z0-9._+-]{0,62}[a-z0-9])?$'
re_domain='^([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$'
if [[ ! "$USER_PART" =~ $re_local ]] || [[ ! "$DOMAIN_PART" =~ $re_domain ]]; then
    die 1 "Error: Invalid email format"
fi

# 4. the directory exists, is a real directory (not a symlink) and sits exactly at BASE/domain/user
TARGET="$BASE_DIR/$DOMAIN_PART/$USER_PART"
if [ ! -e "$TARGET" ] && [ ! -L "$TARGET" ]; then
    die 4 "Warning: Directory not found, nothing to delete: $TARGET"
fi
if [ -L "$TARGET" ] || [ ! -d "$TARGET" ]; then
    die 3 "Error: Path sanity check failed (not a plain directory): $TARGET"
fi
BASE_REAL=$(realpath -e -- "$BASE_DIR") || die 3 "Error: Path sanity check failed (mail base missing)"
DOMAIN_REAL=$(realpath -e -- "$BASE_DIR/$DOMAIN_PART") || die 3 "Error: Path sanity check failed (domain directory)"
REAL=$(realpath -e -- "$TARGET") || die 3 "Error: Path sanity check failed (realpath)"
if [ "$(dirname -- "$DOMAIN_REAL")" != "$BASE_REAL" ] \
    || [ "$(dirname -- "$REAL")" != "$DOMAIN_REAL" ] \
    || [ "$(basename -- "$REAL")" != "$USER_PART" ]; then
    die 3 "Error: Path sanity check failed (resolved outside $BASE_DIR/$DOMAIN_PART): $REAL"
fi

# 5. never touch the mailbox of a LIVE account: Dovecot's userdb must say the user does not exist (exit 67)
if [ ! -x "$DOVEADM" ]; then
    die 7 "Error: cannot verify the account with doveadm ($DOVEADM missing) - refusing"
fi
# With auth_cache_size > 0 Dovecot could still answer from its cache for a while after the database row is gone
# (the lookup would then say "exists" and we would refuse). Dropping this one user from the cache is harmless.
"$DOVEADM" auth cache flush "$EMAIL" >/dev/null 2>&1 || true
dove_out=$("$DOVEADM" user "$EMAIL" 2>&1)
rc=$?
if [ "$rc" -eq 0 ]; then
    die 6 "Error: account $EMAIL still exists in Dovecot - refusing to move its mailbox"
elif [ "$rc" -ne 67 ] && ! printf '%s' "$dove_out" | grep -qiE "(doesn'?t|does not) exist|no such user|unknown user"; then
    # 67 (EX_NOUSER) is what doveadm user returns for a missing user; the message is a second, independent signal.
    # Anything else (auth socket down, config error ...) is NOT proof that the account is gone.
    die 7 "Error: doveadm could not confirm that $EMAIL does not exist (exit $rc) - refusing"
fi

# 6. quarantine
if [ -L "$QUARANTINE_DIR" ]; then
    die 8 "Error: $QUARANTINE_DIR is a symlink - refusing"
fi
if [ ! -d "$QUARANTINE_DIR" ]; then
    mkdir -p -- "$QUARANTINE_DIR" || die 8 "Error: cannot create $QUARANTINE_DIR"
fi
chmod 700 -- "$QUARANTINE_DIR" || die 8 "Error: cannot set mode 700 on $QUARANTINE_DIR"
DEST="$QUARANTINE_DIR/${DOMAIN_PART}__${USER_PART}__$(date +%Y%m%d%H%M%S)"
if [ -e "$DEST" ]; then
    DEST="$DEST-$$"
fi
if ! mv -T -- "$REAL" "$DEST"; then
    die 5 "Error: could not move $REAL to $DEST"
fi
touch -- "$DEST" || echo "Warning: could not refresh the quarantine timestamp of $DEST"
echo "Success: $EMAIL moved to quarantine $DEST"
exit 0
