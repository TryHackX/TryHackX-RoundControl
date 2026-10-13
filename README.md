# TryHackX Mail Panel — administrative overlay for Roundcube

A self-contained overlay for Roundcube (pure PHP 8.1+, **zero Composer dependencies**)
that adds its own login/registration portal, an admin panel for mail accounts,
and a security layer tying together Postfix + Dovecot + Fail2Ban.

**Version 1.1.0** (2026-10-13, [CHANGELOG](CHANGELOG.md)) — a **security release** (registration rules, account
deletion, helpers that report success only when they verified it, login limiting, file ownership, Roundcube + panel in
their own PHP-FPM pool). If you run 1.0.1: [Upgrading from 1.0.1](#upgrading-from-101-to-110). If you run 1.0: run
`install.sh` from this package (it covers both) or follow the same 1.0.1 → 1.1.0 steps.

- **Login portal** (`portal.php`) — a shared entry screen for webmail and the panel
  (flow: e-mail → password and service → in) + **public account registration** with per-IP
  (IPv4) / per-/64 (IPv6) limits, reserved names, a password policy and optional reCAPTCHA,
- **Admin panel** (`panel/`) — accounts (search by e-mail/IP/date/quota/%usage,
  quota in binary units, per-account daily send limit, password reset,
  bans, deletion together with the on-disk mailbox), IP blocks (local + Fail2Ban) with a
  suspicious-IP scanner, logs (registrations, admin logins, audit) with a universal
  search, editing of Fail2Ban and Postfix limits,
- **Password self-service** — the Roundcube `password` plugin (SQL → `$6$` into virtual_users),
- **Daily send limit** — a Postfix policy service (per account / global), counted in **recipients**,
- **Security**: CSRF, rate-limiting (per account+client and per client, never per account alone), fail-closed
  reCAPTCHA, no account / role enumeration at login, a CSP with no inline JS, hardened sessions, the PRG pattern,
  action auditing, least-privilege database accounts, code owned by root, Roundcube + panel in their own PHP-FPM
  pool and user (`mailweb`) — other PHP sites on the server can neither read its secrets nor use its sudo rules.

## Quick install

Assumes a working Roundcube 1.6+ and a `mailserver` database (Postfix/Dovecot virtual users).
Copy the package contents into the Roundcube directory (preserving the tree), then:

```bash
php install.php        # .env, schema import, first administrator (Argon2id)
sudo bash install.sh   # own PHP-FPM pool (mailweb), scripts, sudoers + Fail2Ban helper, Fail2Ban, policy service,
                       # password plugin, file ownership, quarantine timer
# finally: include panel_config.php in config/config.inc.php (see step 5)
```

Both installers are **interactive and idempotent** (a confirmation gate per step,
backups before editing). Details and manual installation — see the sections below.

## Upgrading from 1.0.1 to 1.1.0

**What changes** (details: [CHANGELOG](CHANGELOG.md)): public registration refuses reserved / role names, names that an
alias still points at and recently deleted names, and stores every address in lower case; deleting an account removes
the aliases that point at it and quarantines its maildir instead of `rm -rf` (a daily systemd timer purges the
quarantine after 30 days); the root helpers (`mailpanel-f2b-apply`, `update-postfix-limits.sh`,
`mailpanel-delete-user.sh`) check every write and **never report a success they have not verified**; login limiting no
longer reveals accounts / roles and cannot lock the admin out; the daily send limit counts recipients; Roundcube and the
panel get **their own PHP-FPM pool and user** (`mailweb`) instead of sharing `www-data` with every other site; the code
belongs to root; database accounts get per-table grants; `.htaccess` really blocks what the README says it blocks.

**The simple way:** unpack the package and run `sudo bash install.sh` in it. It does every step below, asks before each
one, backs up what it changes and checks the result (sudoers with `visudo -c` on the whole configuration, the pool with
`php-fpm -t`, the vhosts with `apache2ctl configtest`, restoring the previous file when a check fails). The manual steps
below are the same thing by hand.

**You need to know five things before you start.**

1. *Own PHP-FPM pool.* By default one pool `[www]` (user `www-data`) runs **every** PHP site on the server, so a flaw in
   any of them (forum, tracker, …) can read the panel's database password and Roundcube's `des_key` and use the
   panel's sudo rules. 1.1.0 moves Roundcube + the panel to a pool of their own (`[mailweb]`, user `mailweb`, its own
   socket and session directory); the vhost of the mail site points PHP at that socket; secrets become
   `root:mailweb 640`; the sudo rules name `mailweb`, and `www-data` keeps **no** panel rule. The other sites and their
   pool are not touched. (`PANEL_USER=www-data bash install.sh` keeps the old shared pool, if you must.)
2. *php-fpm sandbox.* Debian's `php*-fpm.service` runs with `ProtectSystem=full`, and a process started through
   `sudo` from PHP-FPM **inherits that sandbox** (one master process serves all pools, so this holds for `mailweb`
   too): `/etc` is read-only for the root helpers. 1.0.1 wrote `jail.local.new` next to `jail.local` and renamed it —
   impossible in that namespace (and it reported success). 1.1.0 rewrites `jail.local` **in place** and needs the
   drop-in `ReadWritePaths=/etc/fail2ban/jail.local`, and `update-postfix-limits.sh` needs `/etc/postfix` writable too
   (`postconf -e` creates a temporary file and renames it). **Keep / install the drop-in** (step 6). `ReadWritePaths`
   only lifts the read-only *mount*; the files stay `root:root 644`.
3. *Old sudo rules.* sudo grants a command if **any** rule matches. A leftover `www-data … doveadm … quota get -u *`
   (in `/etc/sudoers` or an old `/etc/sudoers.d/mailpanel-*` file) or `… mailpanel-scan.sh *` makes the exact rules
   worthless. Step 4 removes them (`scripts/mailpanel-sudoers-cleanup.sh`, which understands multi-line aliases).
   Also: **two files defining the same alias are a sudoers syntax error that disables sudo for everyone** — 1.1.0's
   alias is called `ROUNDCONTROL_F2B` (1.0.x files may define `MAILPANEL_F2B`), and after installing the file you
   check the **whole** configuration with `visudo -c`, not just the file.
4. *Lower case.* Run `upgrade_1.1.0.sql` (step 1) **before** anyone deletes an account. A plain
   `WHERE email <> LOWER(email)` finds nothing, because the column is compared case-insensitively — use `BINARY`. The
   file stops without changing anything if two accounts differ only by case (then decide by hand which one stays).
5. *No cron.* The quarantine purge is a systemd timer (`mailpanel-purge-deleted.timer`); nothing goes to
   `/etc/cron.daily`, which a server without the `cron` package never runs. The 1.0 `mailpanel-f2b-reload` timer is
   removed (the helper reloads Fail2Ban itself).

**Steps** (as root, package unpacked in `$PKG`; everything is idempotent; the webmail, portal and panel may show an
error for a few seconds during step 3).

```bash
PKG=/root/rc-1.1.0 ; RC=/var/www/roundcube ; V=8.5 ; FPM=php$V-fpm ; PU=mailweb    # adjust (ls /etc/php)
SOCK=/run/php/php$V-fpm-$PU.sock ; TS=$(date +%F-%H%M%S)

# 0) preconditions and backups
sudo -V | head -1                                                  # >= 1.9.10
visudo -c                                                          # must already pass before you change anything
( umask 077; mysqldump mailserver > /root/mailserver-pre-1.1.0-$TS.sql )
cp -a /etc/sudoers /root/sudoers.pre-1.1.0-$TS ; cp -a /etc/sudoers.d /root/sudoers.d.pre-1.1.0-$TS
cp -a /etc/fail2ban/jail.local /root/jail.local.pre-1.1.0-$TS
cp -a /etc/apache2/sites-available /root/apache-sites.pre-1.1.0-$TS ; cp -a /etc/php/$V/fpm/pool.d /root/fpm-pool.d.pre-1.1.0-$TS
tar czf /root/roundcube-pre-1.1.0-$TS.tgz -C "$(dirname $RC)" "$(basename $RC)"     # owners and modes change below

# 1) database: structure, data migration (lower case; READ the reports it prints), new account, grants
mysql mailserver < $PKG/sql/panel_schema.sql
mysql mailserver < $PKG/sql/upgrade_1.1.0.sql
if [ "$(mysql -N -B -e "SELECT COUNT(*) FROM mysql.user WHERE User='mailpostlogin' AND Host='localhost'")" = 0 ]; then
  PLPW=$(openssl rand -hex 24)
  mysql -e "CREATE USER 'mailpostlogin'@'localhost' IDENTIFIED BY '$PLPW';"
  ( umask 077; printf '[client]\nuser=mailpostlogin\npassword=%s\nhost=localhost\n' "$PLPW" > /etc/dovecot/mysql-mailpanel.cnf.new )
  cp -p /etc/dovecot/mysql-mailpanel.cnf /root/mysql-mailpanel.cnf.pre-1.1.0-$TS
  chown dovecot:dovecot /etc/dovecot/mysql-mailpanel.cnf.new ; chmod 400 /etc/dovecot/mysql-mailpanel.cnf.new
  mv /etc/dovecot/mysql-mailpanel.cnf.new /etc/dovecot/mysql-mailpanel.cnf
fi
mysql < $PKG/sql/panel_grants.sql ; mysql < $PKG/sql/postlogin_grants.sql
mysql --defaults-extra-file=/etc/dovecot/mysql-mailpanel.cnf -e 'SELECT 1'      # the postlogin account connects

# 2) code: root-owned (no --delete: nothing else of yours is touched; tests/ and sql/ are not deployed)
rsync -a --chown=root:root --chmod=D755,F644 $PKG/app $PKG/assets $PKG/panel $PKG/portal.php $PKG/.htaccess $RC/
rsync -a --chown=root:root --chmod=D755,F644 $PKG/config/panel_config.php $RC/config/
rsync -a --chown=root:root --chmod=D755,F644 $PKG/plugins/custom_auth $RC/plugins/
rm -rf $RC/tests ; for f in $RC/app/src/*/*.php $RC/panel/*.php $RC/portal.php ; do php -l "$f" >/dev/null || echo "SYNTAX ERROR: $f" ; done

# 3) own PHP-FPM pool for Roundcube + the panel (user $PU); the other sites stay in [www]
id $PU >/dev/null 2>&1 || useradd --system --user-group --no-create-home --home-dir /nonexistent --shell /usr/sbin/nologin $PU
install -d -o $PU -g $PU -m 700 /var/lib/php/sessions-$PU
sed -e "s|@POOL@|$PU|g; s|@PANEL_USER@|$PU|g; s|@APACHE_USER@|www-data|g; s|@SOCKET@|$SOCK|g; s|@SESSION_DIR@|/var/lib/php/sessions-$PU|g" \
    $PKG/system/php-fpm/roundcontrol-pool.conf > /etc/php/$V/fpm/pool.d/$PU.conf
php-fpm$V -t && systemctl reload $FPM && sleep 2 && ls -l $SOCK                  # srw-rw---- www-data www-data
#   what the pool user WRITES (and Roundcube's log_dir if it lies outside the tree - grep log_dir $RC/config/config.inc.php)
chown -R $PU:$PU $RC/temp $RC/logs ; [ -d /var/log/roundcube ] && chown -R $PU:$PU /var/log/roundcube
#   ... and READS (secrets: through the group only)
for f in $RC/config/config.inc.php $RC/plugins/password/config.inc.php $RC/.env /etc/mailpanel/panel.env ; do
  [ -f "$f" ] && chown root:$PU "$f" && chmod 640 "$f"
done
grep -l /var/log/roundcube /etc/logrotate.d/*       # in each file found: `create 0640 www-data www-data` -> `create 0640 $PU $PU`
#   the mail vhost(s): add before EVERY </VirtualHost> whose DocumentRoot is $RC/public_html (both :80 and :443)
#   the block from system/apache/roundcontrol-fpm-handler.conf, i.e.
#       <Directory "/var/www/roundcube">
#           <FilesMatch "\.ph(?:ar|p|tml)$">
#               SetHandler "proxy:unix:/run/php/php8.5-fpm-mailweb.sock|fcgi://localhost"
#           </FilesMatch>
#       </Directory>
#   (inside <Directory>: only Roundcube's files; global aliases such as /phpmyadmin stay in [www] on this host too)
apache2ctl configtest && systemctl reload apache2
curl -s -o /dev/null -w '%{http_code}\n' https://<mail-host>/portal.php ; ps -o user=,cmd= -C php-fpm$V | grep "pool $PU"

# 4) root helpers, scripts, sudoers for $PU (the file alone, then the WHOLE configuration), removal of the OLD rules
install -o root -g root -m 755 $PKG/scripts/mailpanel-f2b-apply.php /usr/local/sbin/mailpanel-f2b-apply
php -l /usr/local/sbin/mailpanel-f2b-apply
install -o root -g root -m 755 $PKG/scripts/mailpanel-scan.sh         /usr/local/bin/
install -o root -g root -m 700 $PKG/scripts/mailpanel-delete-user.sh  /usr/local/bin/
install -o root -g root -m 755 $PKG/scripts/update-postfix-limits.sh  /usr/local/bin/
install -o root -g root -m 755 $PKG/scripts/mailpanel-policy-daily.php /usr/local/bin/
install -o root -g dovecot -m 750 $PKG/scripts/dovecot-postlogin.sh   /usr/local/bin/
sed "s/^User_Alias ROUNDCONTROL_USER = mailweb\$/User_Alias ROUNDCONTROL_USER = $PU/" $PKG/system/sudoers.d/mailpanel > /root/mailpanel.sudoers
visudo -cf /root/mailpanel.sudoers && install -o root -g root -m 440 /root/mailpanel.sudoers /etc/sudoers.d/mailpanel
visudo -c || { cp -a /root/sudoers.d.pre-1.1.0-$TS/mailpanel /etc/sudoers.d/ 2>/dev/null || rm -f /etc/sudoers.d/mailpanel ; visudo -c ; }
WEB_USER=www-data bash $PKG/scripts/mailpanel-sudoers-cleanup.sh            # lists the old www-data rules (exit 1 = some left)
WEB_USER=www-data bash $PKG/scripts/mailpanel-sudoers-cleanup.sh --apply    # comments them out, validated, originals in /root/sudoers-backup-*
WEB_USER=$PU bash $PKG/scripts/mailpanel-sudoers-cleanup.sh                 # "No leftover ..."
visudo -c ; sudo -l -U $PU ; sudo -l -U www-data      # $PU: only ^regex$ / "" rules, no '*'; www-data: NO panel rule at all
rm -rf /var/lib/mailpanel-f2b                                               # 1.0.1 proposal directory, no longer used
systemctl disable --now mailpanel-f2b-reload.timer 2>/dev/null ; rm -f /etc/systemd/system/mailpanel-f2b-reload.timer \
  /etc/systemd/system/mailpanel-f2b-reload.service /usr/local/sbin/mailpanel-f2b-reload.php ; systemctl daemon-reload   # the 1.0 timer

# 5) Fail2Ban: new filter + the (disabled) portal jail; jail.local stays root:root 644
install -o root -g root -m 644 $PKG/system/fail2ban/filter.d/mailpanel-portal.conf /etc/fail2ban/filter.d/
#   panel jails already in jail.local (legacy layout)? then copy ONLY the [mailpanel-portal] block of
#   $PKG/system/fail2ban/jail.d/mailpanel.local into /etc/fail2ban/jail.d/mailpanel-portal.local; otherwise:
grep -q '^\[panel-permaban\]' /etc/fail2ban/jail.local || install -o root -g root -m 644 $PKG/system/fail2ban/jail.d/mailpanel.local /etc/fail2ban/jail.d/
chown root:root /etc/fail2ban/jail.local ; chmod 644 /etc/fail2ban/jail.local
fail2ban-client -t && fail2ban-client reload

# 6) php-fpm drop-in (additive to an existing one) + RESTART (reload does not rebuild the sandbox)
install -d -m 755 /etc/systemd/system/$FPM.service.d
printf '%s\n' '[Service]' 'ReadWritePaths=-/etc/fail2ban/jail.local -/etc/postfix' > /etc/systemd/system/$FPM.service.d/mailpanel.conf
systemctl daemon-reload ; systemctl restart $FPM
systemctl show $FPM -p ProtectSystem -p ReadWritePaths

# 7) prove the helpers work under THE SAME sandbox and through sudo as $PU (what the panel does)
systemd-run --quiet --wait --pipe --collect -p ProtectSystem=full -p ReadWritePaths=/etc/fail2ban/jail.local \
  /usr/local/sbin/mailpanel-f2b-apply --selftest                                # -> selftest ok
sudo -u $PU sudo -n /usr/local/sbin/mailpanel-f2b-apply < /etc/fail2ban/jail.local         # -> bez zmian
CUR=$(postconf -h smtpd_client_message_rate_limit)
systemd-run --quiet --wait --pipe --collect -p ProtectSystem=full -p ReadWritePaths=/etc/postfix \
  /usr/sbin/postconf -e "smtpd_client_message_rate_limit=$CUR" && echo "postconf -e works under the sandbox"

# 8) file ownership: code root:root; only what step 3 gave $PU stays writable / readable for it
chown -R root:root $RC
chown -R $PU:$PU $RC/temp $RC/logs
for f in $RC/config/config.inc.php $RC/plugins/password/config.inc.php $RC/.env ; do [ -f "$f" ] && chown root:$PU "$f" && chmod 640 "$f" ; done
#   copies with secrets (config.inc.php.bak*, config.old.php, .env.bak ...) must not stay world-readable
find $RC $RC/config $RC/plugins/password -maxdepth 1 -type f \( -name 'config.inc.php.bak*' -o -name 'config.old.php' \
     -o -name '.env.*' ! -name .env.example \) -exec chown root:root {} + -exec chmod 600 {} +
find $RC \( -path $RC/temp -o -path $RC/logs \) -prune -o -type f \( -perm -g+w -o -perm -o+w \) -exec chmod go-w {} +
find $RC \( -path $RC/temp -o -path $RC/logs \) -prune -o ! -type l \( -user $PU -o -user www-data -o -perm -o+w \) -print   # must print nothing
sudo -u $PU php -r 'echo is_readable("'$RC'/config/config.inc.php") ? "config.inc.php readable\n" : "!!! config.inc.php NOT readable\n";'

# 9) mailbox quarantine + its daily purge (systemd timer; age = deletion time from the entry name)
install -d -o root -g root -m 700 /var/mail/deleted
install -o root -g root -m 755 $PKG/scripts/mailpanel-purge-deleted /usr/local/sbin/
install -o root -g root -m 644 $PKG/system/systemd/mailpanel-purge-deleted.service $PKG/system/systemd/mailpanel-purge-deleted.timer /etc/systemd/system/
systemctl daemon-reload ; systemctl enable --now mailpanel-purge-deleted.timer ; systemctl list-timers mailpanel-purge-deleted.timer
```

Then, by hand: (a) in `/etc/postfix/master.cf`, in the `submission` and `submissions` blocks add
`-o smtpd_recipient_limit=50` next to the existing `-o smtpd_data_restrictions=$mua_data_restrictions`
(`postfix check && systemctl reload postfix`) — the daily limit now counts recipients; (b) Roundcube must send through
port 587 **with authentication** (`smtp_host = 'tls://localhost:587'`, `smtp_user = '%u'`), otherwise no limit applies;
(c) optionally move the configuration out of the web tree:
`install -d -o root -g mailweb -m 750 /etc/mailpanel && mv $RC/.env /etc/mailpanel/panel.env` (it is read first when
present); (d) optionally enable the `[mailpanel-portal]` jail (see `jail.d/mailpanel.local`); (e) a DNS **CAA** record
(`0 issue "letsencrypt.org"` or your CA) limits who may issue certificates for your domain.

**Verify.**

```bash
stat -c '%a %U:%G' /etc/fail2ban/jail.local                       # 644 root:root
mysql -e "SHOW GRANTS FOR 'mailpanel'@'localhost'"                # per-table list, no ON mailserver.*
sudo -l -U www-data | grep -E 'fail2ban|doveadm|mailpanel|postfix-limits'   # nothing
curl -sk -o /dev/null -w '%{http_code}\n' https://<host>/.env     # 403 (also /.env.bak, /scripts/mailpanel-f2b-apply.php, /install.sh)
```

In the panel: save once in the **Fail2Ban** tab (a real change, e.g. `maxretry`) — the message must say it was saved,
checked and reloaded, and `sha256sum /etc/fail2ban/jail.local` must change; save the **Security** (Postfix) form and
check `postconf smtpd_client_message_rate_limit`; log in as a normal user and as the admin; in **Users** try *Add* with
`postmaster` (refused without the tick) and delete a throw-away account (maildir appears in `/var/mail/deleted/`).
If a step goes wrong, the pre-1.1.0 copies in `/root/*pre-1.1.0*` restore the previous state; do not "roll back" by
making `jail.local` writable for the web user, and do not give the panel's sudo rules back to `www-data`.

## Upgrading from 1.0 (security release 1.0.1)

> Historical. The 1.0.1 helper protocol (a proposal *file* in `/var/lib/mailpanel-f2b`, atomic rename) was replaced in
> 1.1.0 (proposal on stdin, in-place write). From 1.0, the simplest path is to unpack the **1.1.0** package and run
> `sudo bash install.sh` (sections 0-3, 7, 8, 10, 11); the steps below are what 1.0.1 changed.

**What was wrong.** In 1.0 the sudo rules for `fail2ban-client` used `*` wildcards (`set * banip *`, `status *` …).
In sudoers a `*` inside arguments also matches **spaces**, so any code running as `www-data` — any PHP site on the
box, not only the panel — could run e.g. `fail2ban-client set <jail> action <name> actionban '<command>' banip x`
… and Fail2Ban would run that command **as root**. The same escalation was open through `/etc/fail2ban/jail.local`,
which 1.0 made writable by `www-data` (664): a jail may name any action.

**What 1.0.1 changes.**
- `fail2ban-client` rules take **exact arguments** (`^regex$`, needs sudo ≥ 1.9.10 — Debian 13 has 1.9.16); the
  `doveadm quota` rule is exact too;
- `jail.local` is **`root:root` 644**. The "Fail2Ban" tab writes a *proposal* to
  `/var/lib/mailpanel-f2b/jail.local.proposed` and runs the root helper `mailpanel-f2b-apply` through sudo; the helper
  installs it only if **nothing but the values of the options edited in the tab changed** and each value is well-formed,
  tests it with `fail2ban-client -t`, and reloads Fail2Ban (otherwise it refuses and changes nothing);
- the `mailpanel-f2b-reload` timer is no longer needed (the `ReadWritePaths=/etc/fail2ban/jail.local` php-fpm drop-in,
  however, IS needed from 1.1.0 on — see below; 1.0.1 wrongly called it unnecessary).

**Upgrade — option A (installer).** Copy the 1.0.1 package over the Roundcube directory (the `rsync` from step 1
below), run `sudo bash install.sh` and answer **yes (`t`)** to sections 2, 3 and 8. Section 2 lists any *old* wildcard
rules it finds in `/etc/sudoers` or `/etc/sudoers.d/` — remove them as shown in step 5 below.

**Upgrade — option B (the same steps by hand).** As root, in the unpacked 1.0.1 package, `RC=/var/www/roundcube`.
The order matters: new rules and helper first, old rules last.

```bash
# 0) preconditions: sudo >= 1.9.10, php-cli, fail2ban
RC=/var/www/roundcube                     # your Roundcube directory
sudo -V | head -1 ; php -v | head -1 ; fail2ban-client -t

# 1) the helper first (a sudoers rule can only be tested against a command that exists) + the proposal directory
install -o root -g root -m 755 scripts/mailpanel-f2b-apply.php /usr/local/sbin/mailpanel-f2b-apply
php -l /usr/local/sbin/mailpanel-f2b-apply
install -d -o www-data -g www-data -m 750 /var/lib/mailpanel-f2b

# 2) the new sudoers file (validated BEFORE it is installed)
visudo -cf system/sudoers.d/mailpanel
install -o root -g root -m 440 system/sudoers.d/mailpanel /etc/sudoers.d/mailpanel
visudo -c

# 3) the only application file that changed: the panel now proposes instead of writing jail.local
install -o www-data -g www-data -m 644 app/src/System/Commands.php $RC/app/src/System/Commands.php
php -l $RC/app/src/System/Commands.php        # if opcache.validate_timestamps=0: systemctl reload php*-fpm

# 4) jail.local becomes root's (and the old copies the panel used to make)
chown root:root /etc/fail2ban/jail.local && chmod 644 /etc/fail2ban/jail.local
for b in /etc/fail2ban/jail.local.bak-*; do [ -e "$b" ] && chown root:root "$b" && chmod 600 "$b"; done
fail2ban-client -t

# 5) remove the OLD wildcard rules wherever they live (README 1.0 said "sudo visudo and add at the end", so usually
#    /etc/sudoers; other files if you split them). The first command lists them; the new file is not listed.
grep -nE '^[[:space:]]*[^#[:space:]].*fail2ban-client.*[*]' /etc/sudoers /etc/sudoers.d/* | grep -v '^/etc/sudoers.d/mailpanel:'
#    For /etc/sudoers (backup, filter, VALIDATE, only then install; if such a line also lists OTHER commands,
#    edit it with `visudo` instead of filtering):
cp -a /etc/sudoers /root/sudoers.bak-$(date +%F)
grep -vE '^www-data[[:space:]].*fail2ban-client' /etc/sudoers > /etc/sudoers.new
visudo -cf /etc/sudoers.new && install -o root -g root -m 440 /etc/sudoers.new /etc/sudoers ; rm -f /etc/sudoers.new
#    For another file /etc/sudoers.d/<name>: `visudo -f /etc/sudoers.d/<name>` and delete the fail2ban-client lines.
#    Re-run the grep: it must print nothing.

# 6) optional: the old mtime-watching timer (the helper reloads Fail2Ban itself)
systemctl disable --now mailpanel-f2b-reload.timer
rm -f /etc/systemd/system/mailpanel-f2b-reload.{timer,service} /usr/local/sbin/mailpanel-f2b-reload.php
systemctl daemon-reload
#    KEEP the php-fpm drop-in ReadWritePaths=/etc/fail2ban/jail.local if you have one (1.1.0 needs it; see "Upgrading from 1.0.1")
```

**Verify.**

```bash
stat -c '%A %U:%G' /etc/fail2ban/jail.local      # -rw-r--r-- root:root
for c in "ping" "status" "status dovecot" "set dovecot banip 203.0.113.7" "set dovecot unbanip 2001:db8::7" \
         "set panel-tempban bantime 86400" "get dovecot banip --with-time" "reload"; do
  sudo -l -U www-data /usr/bin/fail2ban-client $c >/dev/null 2>&1 && echo "allowed (as it should be): $c" || echo "NOT allowed (should be): $c"
done
for c in "set dovecot action nftables actionban touch banip 1.2.3.4" "set dovecot banip 1.2.3.4 extra" "start" "stop"; do
  sudo -l -U www-data /usr/bin/fail2ban-client $c >/dev/null 2>&1 && echo "!!! allowed (must NOT be): $c" || echo "refused (good): $c"
done
# the helper end to end, as the panel calls it (1.1.0: the proposal is read from stdin): unchanged -> "bez zmian" ("no changes")
sudo -u www-data sudo -n /usr/local/sbin/mailpanel-f2b-apply < /etc/fail2ban/jail.local
```

Then open the panel's **Fail2Ban** tab and save once. If something in step 5 goes wrong, `/root/sudoers.bak-*` is
the copy of `/etc/sudoers` from before; do not "roll back" by making `jail.local` writable for `www-data` again —
that is the hole this release closes (an error message from the helper says what it refused and why).

## Repository layout

```
├── install.php / install.sh      # installers (application / system)
├── portal.php  app/  assets/     # overlay: portal + panel (code + templates + CSS/JS)
├── panel/  config/panel_config.php
├── plugins/custom_auth/          # redirect unauthenticated users to the portal
├── plugins/password/config.inc.php.example
├── scripts/                      # scripts for /usr/local/bin (scanner, policy, delete-user, postfix limits…)
│   ├── mailpanel-f2b-apply.php   #   root helper → /usr/local/sbin (installs the panel's jail.local proposal, in place)
│   └── mailpanel-sudoers-cleanup.sh  # finds / removes OLD wildcard sudo rules (run from an admin shell)
├── system/                       # integration templates (sudoers, fail2ban, postfix, php-fpm pool, Apache handler,
│                                 #   systemd purge timer, policy.cf)
├── sql/panel_schema.sql          # database schema (IF NOT EXISTS) — also upgrades an existing one
├── sql/panel_grants.sql          # least-privilege grants for the panel DB account (+ postlogin_grants.sql)
├── sql/upgrade_1.1.0.sql         # data migration 1.0.x → 1.1.0 (lower-case addresses) + reports
├── tests/                        # run.php (dev DB), db_test.php (own scratch DB), security_test.php, scripts_test.php,
│                                 #   sudoers_test.php — the last three need no database and no sudo; run anywhere
├── CHANGELOG.md
├── .env.example  .gitignore  LICENSE
```

> **Secrets stay out of the repo:** `.env`, `plugins/password/config.inc.php`,
> `/etc/mailpanel-policy.cf` and the `all_settings/` dumps are in `.gitignore`.
> Never commit real passwords or DKIM keys.

---

## 1. Requirements

| Component | Version / note |
|---|---|
| OS | **Debian 13 (trixie)** — the target of the installers and templates (Dovecot 2.4, Postfix 3.10, Fail2Ban 1.1); on Windows/dev the system integrations disable themselves |
| Roundcube | 1.6+ (tested on 1.7.2), installed and running |
| PHP | 8.1+ with extensions: pdo_mysql, sodium/argon2 (standard in distributions) |
| MariaDB / MySQL | a `mailserver` database (Postfix/Dovecot virtual users) |
| Apache | `mod_rewrite` + `AllowOverride All` for the Roundcube directory |
| HTTPS | required in production (session with the Secure flag, HSTS) |

We assume Roundcube sits at the **domain root** (e.g. `/var/www/roundcube` →
`https://mail.yourdomain.com/`). For an installation in a subdirectory, fix the two
redirects in `plugins/custom_auth/custom_auth.php` (`/portal.php` → full path).

## 2. Package structure (= structure on the server)

You copy everything into the Roundcube root directory, **preserving the tree**:

```
roundcube/                        ← your Roundcube root directory
├── .htaccess                     ← REPLACE (blocks app/, scripts/, sql/, .env*, backups, installers…)
├── .env                          ← CREATE from .env.example (secrets, NOT from the package!); better
│                                    /etc/mailpanel/panel.env (read first when present)
├── portal.php                    ← NEW: login/registration portal
├── app/                          ← NEW: all application code (blocked from the web)
│   ├── .htaccess                 ←   deny all
│   ├── bootstrap.php             ←   autoloader + .env
│   ├── web.php                   ←   web kernel (session, error mode)
│   ├── src/                      ←   classes (Auth, Panel, Portal, Security…)
│   └── templates/                ←   HTML templates (portal + panel)
├── assets/                       ← NEW: CSS/JS/icons (the only public directory)
│   ├── design-system.css  portal.css  portal.js  panel.css  panel.js
│   └── vendor/remixicon/         ←   self-hosted icons (no CDN)
├── panel/                        ← REPLACE contents (3 files)
│   ├── index.php  logout.php  user_ips.php
├── config/
│   └── panel_config.php          ← NEW/REPLACE: wires rc_* settings into RC
├── plugins/
│   └── custom_auth/              ← NEW: redirect unauthenticated users to the portal
├── sql/*.sql                     ← (need not live on the server — for the DB import)
└── tests/                        ← do NOT deploy (the rsync below excludes it)
```

The rest of Roundcube (`program/`, `skins/`, `vendor/`, `config/config.inc.php`…)
stays unchanged — apart from one line in step 5.

## 3. Step 1 — Files

```bash
# from the package directory (the code is owned by ROOT; the web user only reads it):
rsync -av --chown=root:root --chmod=D755,F644 \
    --exclude .git --exclude README.md --exclude CHANGELOG.md --exclude sql/ --exclude tests/ --exclude scripts/ \
    --exclude system/ --exclude install.php --exclude install.sh --exclude .env.example --exclude .gitignore --exclude LICENSE \
    ./ /var/www/roundcube/
```

**File ownership (1.1.0).** The PHP user of Roundcube + the panel (`mailweb`, their own pool — see "Own PHP-FPM pool"
in step 4; `www-data` only without it) must be able to *read* the code but never *write* it: when the code belongs to
the PHP user, a flaw in **any** site served by the same PHP-FPM pool can replace `panel/index.php` or `portal.php` and
read admin and webmail passwords (and use every `sudo` rule the panel has). So: code `root:root` (directories 755,
files 644), secrets (`.env`, `config/config.inc.php`, `plugins/password/config.inc.php`) `root:mailweb` 640, and
**only** Roundcube's own `temp/` and `logs/` (plus its `log_dir`, e.g. `/var/log/roundcube`, if that lies outside the
tree) writable by `mailweb`. Copies with secrets (`config.inc.php.bak*`, `config.old.php`, `.env.*`) become
`root:root 600`. `install.sh` sections 1b and 7 do this (they also fix stray 664 files and the logrotate `create` line);
to check: `find /var/www/roundcube -user mailweb -o -user www-data -o -perm -o+w` should list only `temp/` and `logs/`.

Remove leftovers of old panel versions if present (replaced by `app/`):
`bootstrap.php` (in the root), `panel/config.php`, `panel/_csrf.php`,
`panel/styles.css`, `panel/light-mode.css`, `panel/dark-mode.css`, `panel/style-index.css`.

## 4. Step 2 — Database

`install.sh` section 0 does all of this (creates the accounts that are missing — with random passwords it prints once —
and never touches the password of one that exists). By hand:

```bash
# 1) database and the two application accounts (passwords: only [A-Za-z0-9._~+-], e.g. openssl rand -hex 24)
mysql -u root -p -e "CREATE DATABASE IF NOT EXISTS mailserver CHARACTER SET utf8mb4;
  CREATE USER IF NOT EXISTS 'mailpanel'@'localhost' IDENTIFIED BY 'STRONG_PASSWORD';
  CREATE USER IF NOT EXISTS 'mailpostlogin'@'localhost' IDENTIFIED BY 'ANOTHER_STRONG_PASSWORD';"

# 2) schema (safe for existing data: IF NOT EXISTS / INSERT IGNORE / CREATE OR REPLACE) ...
mysql -u root -p mailserver < sql/panel_schema.sql

# 3) ... and the grants: per table, not `mailserver.*` (REVOKEs everything first, so it is also the way to tighten an
#    existing account). `mailpostlogin` (used by the Dovecot post-login script) may only EXECUTE one procedure.
mysql -u root -p < sql/panel_grants.sql
mysql -u root -p < sql/postlogin_grants.sql
```

What the grants leave out on purpose: no `INSERT`/`UPDATE` on `virtual_aliases` (whoever takes over the panel's database
account cannot redirect anybody's mail), no `DELETE` on the audit / login / registration logs, no access to tables the
panel does not use. The reCAPTCHA secret is no longer printed in the settings page.

Then (if it is a fresh database) add the domain and the first administrator:

```bash
php -r 'echo password_hash("YourAdminPassword", PASSWORD_ARGON2ID), PHP_EOL;'
```

```sql
INSERT INTO virtual_domains (name) VALUES ('yourdomain.com');
INSERT INTO panel_admins (email, pwd_hash, created_at)
VALUES ('admin@yourdomain.com', '<HASH_FROM_THE_COMMAND_ABOVE>', NOW());
```

> **Two kinds of passwords** (don't mix them up!):
> - `panel_admins.pwd_hash` — the **panel** password, Argon2id (`$argon2id$...`),
> - `virtual_users.password` — the **mailbox** password (Dovecot), SHA-512 crypt (`$6$...`).
>   Accounts created via portal registration get it automatically.

## 5. Step 3 — `.env` and wiring into Roundcube

```bash
cd /var/www/roundcube
cp .env.example .env && nano .env          # APP_ENV=prod + database credentials
chown root:mailweb .env && chmod 640 .env      # the group = the panel's PHP user (www-data only without its own pool)
```

In `config/config.inc.php`:

```php
// 1) add the plugins to the list (password = mailbox password self-service):
$config['plugins'] = ['archive', 'zipdownload', 'newmail_notifier', 'custom_auth', 'password'];

// 2) at the VERY END of the file, include the panel configuration:
require_once __DIR__ . '/panel_config.php';
```

`panel_config.php` maps the `rc_*` keys from the database (editable in the panel) to Roundcube
options (send delay, recipient limit, etc.) and enforces
`force_https`, `ip_check`, `identities_level=3`.

### Password self-service (the `password` plugin)

Without it a user cannot change their password in webmail. The `password` plugin ships with
Roundcube — you only need to configure it (SQL driver → writing a `$6$`
hash into `virtual_users`, consistent with Dovecot). Install via script:

```bash
bash scripts/install-password-plugin.sh    # creates the roundcubepw DB user + config
# then manually add 'password' to $config['plugins'] (see above)
```

The script creates the `roundcubepw` DB user (only `SELECT, UPDATE ON
mailserver.virtual_users`) and `plugins/password/config.inc.php` (template:
`plugins/password/config.inc.php` in the package). Key options: the
`sql` driver, `password_algorithm = sha512-crypt`, an empty prefix (Dovecot stores `$6$`
without `{SHA512-CRYPT}`), `password_confirm_current = true` (requires the old
password), min. length 10 + a required non-alphanumeric character. Result: **Settings
→ Password** in webmail; additionally the admin resets account passwords from the panel
(Users tab → "Password").

> Note: this password is independent of the SSH password of the system account. Changing
> the mailbox password does not touch `/etc/shadow` and vice versa.

## 6. Step 4 — sudoers and permissions (system integrations)

The panel runs system operations through `sudo` as the user of its PHP-FPM pool (`mailweb` — see "Own PHP-FPM pool"
below; `www-data` only if Roundcube still shares the `[www]` pool). The rules live in **`system/sudoers.d/mailpanel`**;
the user is named once, in its `User_Alias ROUNDCONTROL_USER = mailweb` line. Install the whole file (`install.sh`
section 2 does this, writes the right user into that line, and checks the file AND the whole configuration):

```bash
# 1) the Fail2Ban helper first: a sudoers rule can only be tested against a command that exists
install -o root -g root -m 755 scripts/mailpanel-f2b-apply.php /usr/local/sbin/mailpanel-f2b-apply
# 2) the rules (sudo >= 1.9.10): the file alone, then the WHOLE configuration (a duplicate alias in another file is a
#    syntax error that disables sudo for everyone - put the old file back at once if `visudo -c` fails)
visudo -cf system/sudoers.d/mailpanel
install -o root -g root -m 440 system/sudoers.d/mailpanel /etc/sudoers.d/mailpanel && visudo -c
# 3) remove OLD wildcard rules for the same commands, wherever they live (sudo grants a command if ANY rule matches)
WEB_USER=www-data bash scripts/mailpanel-sudoers-cleanup.sh   # lists them; add --apply to comment them out (validated, backed up)
sudo -l -U mailweb                                   # only exact ^regex$ / "" rules - no '*'
sudo -l -U www-data                                  # NO panel rule at all (when the panel has its own pool)
```

Do **not** paste wildcard rules for `fail2ban-client`, `doveadm` or `mailpanel-scan.sh` (`set * banip *`, `-u *`, `*`)
into `visudo`: in sudoers a `*` in arguments also matches spaces, which lets any code running as `www-data` run
arbitrary `fail2ban-client` commands (e.g. replace a jail's `actionban`, which Fail2Ban then runs as root). The shipped
file uses exact `^regex$` arguments instead. (In a `^regex$` argument the sudoers characters `,` `:` `=` `\` are
escaped with a backslash — see the file.)

### Own PHP-FPM pool (Roundcube + the panel) — `install.sh` section 1b

Debian ships one PHP-FPM pool, `[www]` (user `www-data`), and every PHP site of the server runs in it. Then a flaw in
**any** of them (a forum, a tracker, a file host …) runs as the same user as the webmail: it reads the panel's database
password from `.env` and Roundcube's `des_key` and database password from `config.inc.php`, reads the portal/panel
session files, and can use every `sudo` rule of the panel. So Roundcube and the panel get a pool of their own:

| | |
|---|---|
| user | `mailweb` (system user, no shell, no home; `PANEL_USER=` in `install.sh`) |
| pool file | `/etc/php/8.5/fpm/pool.d/mailweb.conf` from `system/php-fpm/roundcontrol-pool.conf` (`pm = ondemand`, `pm.max_children = 6`) |
| socket | `/run/php/php8.5-fpm-mailweb.sock`, `0660 www-data:www-data` (only Apache connects) |
| sessions | `/var/lib/php/sessions-mailweb` (0700 `mailweb`); the pool runs its own session garbage collection, because Debian's `phpsessionclean` only cleans the `php.ini` path |
| Apache | in **every** `<VirtualHost>` of the mail site (`:80` and `:443`), the block from `system/apache/roundcontrol-fpm-handler.conf`: `<Directory "/var/www/roundcube">` holding a `<FilesMatch "\.ph(?:ar\|p\|tml)$">` with `SetHandler "proxy:unix:/run/php/php8.5-fpm-mailweb.sock\|fcgi://localhost"`. It overrides `conf-enabled/php8.5-fpm.conf` for Roundcube's files only — global aliases such as `/phpmyadmin` keep the `[www]` pool even on the mail host name (a vhost-wide `FilesMatch` would run them as `mailweb`, next to the panel's secrets) |
| files | code `root:root`; `temp/`, `logs/` (and Roundcube's `log_dir`, e.g. `/var/log/roundcube`, with its logrotate `create` line) `mailweb`; secrets `root:mailweb 640` |
| sudo | the rules name `mailweb`; `www-data` keeps **none** of the panel's rules |

The other sites and the `[www]` pool are not touched. One php-fpm master runs all pools, so the systemd drop-in below
(`ReadWritePaths`) covers `mailweb` as well. `install.sh` section 1b creates all of it, checks the pool with
`php-fpm8.5 -t` and the vhosts with `apache2ctl configtest`, and puts the previous files back if a check fails.
By hand: steps 3 and 8 of [Upgrading from 1.0.1 to 1.1.0](#upgrading-from-101-to-110). To keep the shared pool
instead (not recommended): `PANEL_USER=www-data bash install.sh`.

### php-fpm sandbox (`ReadWritePaths`) — needed for the root helpers

Debian's `php*-fpm.service` ships with `ProtectSystem=full`: `/usr`, `/boot` and **`/etc` are read-only**, and a process
started through `sudo` from PHP-FPM **inherits that mount namespace — root included**. The helpers that write under
`/etc` therefore need two exceptions, as a systemd drop-in (`install.sh` section 10 writes it):

```ini
# /etc/systemd/system/php8.5-fpm.service.d/mailpanel.conf          (use your service name)
[Service]
ReadWritePaths=-/etc/fail2ban/jail.local -/etc/postfix
```

```bash
systemctl daemon-reload && systemctl restart php8.5-fpm       # a RESTART: reload does not rebuild the sandbox
systemctl show php8.5-fpm -p ProtectSystem -p ReadWritePaths
```

| Helper | Writes | Needs |
|---|---|---|
| `mailpanel-f2b-apply` | `/etc/fail2ban/jail.local` **in place** (open, `flock`, rewrite, truncate, read back; it cannot create or rename files there), backups in `/var/backups/mailpanel-f2b` | `ReadWritePaths=/etc/fail2ban/jail.local` |
| `update-postfix-limits.sh` | `/etc/postfix/main.cf` (`postconf -e` writes a temporary file and renames it) | `ReadWritePaths=/etc/postfix` (the directory) |
| `mailpanel-delete-user.sh` | moves a maildir under `/var/mail` (stays writable) | nothing |

`ReadWritePaths` only lifts the read-only *mount*: the files stay `root:root 644`, so the PHP user still cannot write them.
**Test the helpers under the same sandbox**, not from a plain root shell (a plain root shell is what hid the problem):

```bash
systemd-run --quiet --wait --pipe --collect -p ProtectSystem=full -p ReadWritePaths=/etc/fail2ban/jail.local \
  /usr/local/sbin/mailpanel-f2b-apply --selftest                 # -> selftest ok
```

Every helper checks each write and exits non-zero with a clear message on failure; the panel shows that message instead
of "saved". If you see `read-only file system` or `nie mogę otworzyć … do zapisu`, the drop-in is missing or php-fpm was
not restarted.

What each entry enables (without an entry, that feature shows a readable message; the rest works):

| Entry | Panel feature |
|---|---|
| `fail2ban-client ping`, `status`, `status <jail>` | the F2B ban list in the "IP blocks" tab |
| `fail2ban-client set <jail> unbanip <ip>` | the "Unban (F2B)" button |
| `fail2ban-client set <jail> banip <ip>` | manual IP block + bans from the scanner |
| `fail2ban-client get <jail> banip --with-time` | the "Expires" column with the remaining ban time |
| `fail2ban-client set panel-tempban bantime <seconds>` | timed bans (jail `panel-tempban` **only** — a rule for any jail would let the PHP user set the bantime of `sshd` or `recidive` to one second) |
| `mailpanel-f2b-apply ""` | saving the "Fail2Ban" tab; takes no arguments, reads the proposal from stdin, reloads Fail2Ban itself (so there is no `fail2ban-client reload` rule) |
| `update-postfix-limits.sh` | applying Postfix limits ("Security"); three integers, read back and checked |
| `mailpanel-delete-user.sh` | removing an account's maildir: validates the address, asks Dovecot whether the account still exists (refuses if it does or cannot tell), checks the real path, and **moves** the maildir to `/var/mail/deleted` (a quarantine purged after 30 days by the systemd timer `mailpanel-purge-deleted.timer` — the age is the deletion time in the entry's name, since `mv` keeps the maildir's old mtime) instead of `rm -rf` |
| `doveadm -f flow quota get -u <user>` | quota usage on the account list (exact `^regex$`) |
| `mailpanel-scan.sh SINCE UNTIL [source]` | the suspicious-IP scanner: exactly two `YYYY-MM-DD` dates and optionally `ssh`/`dovecot`/`postfix` (exact `^regex$`; the script validates again) |

### Panel jails ("permanent" and timed blocks)

The scanner and the "Manage" button use two dedicated jails whose filters
never catch anything automatically (they only accept manual `banip` from the panel).

> `install.sh` installs them as **`/etc/fail2ban/jail.d/mailpanel.local`**
> (ready-made file: `system/fail2ban/jail.d/mailpanel.local`) — Fail2Ban reads
> `jail.d/*.local` automatically, and `jail.local` stays clean for the standard
> jails edited from the panel. You can also add them manually to
> `jail.local` (legacy variant) — but not in both places at once:

```ini
# Jail for PERMANENT blocks from the panel (bantime = -1).
[panel-permaban]
enabled = true
filter = panel-permaban
backend = polling
logpath = /var/log/mailpanel-permaban.log
banaction = nftables-allports
bantime = -1
bantime.increment = false
findtime = 1d
maxretry = 1000000

# Jail for TIMED blocks from the panel (scanner: 1h..30 days).
# The panel sets bantime right before banip, so each ban has its own duration.
[panel-tempban]
enabled = true
filter = panel-tempban
backend = polling
logpath = /var/log/mailpanel-tempban.log
banaction = nftables-allports
bantime = 24h
bantime.increment = false
findtime = 1d
maxretry = 1000000
```

Filters (`/etc/fail2ban/filter.d/panel-permaban.conf` and `panel-tempban.conf` —
in the second one replace the word `permaban` with `tempban`):

```ini
[Definition]
failregex = ^__mailpanel_permaban_never_match__ <HOST>$
ignoreregex =
```

Empty logs: `touch /var/log/mailpanel-permaban.log /var/log/mailpanel-tempban.log`,
and when done `fail2ban-client reload`.

### Editing Fail2Ban from the panel (`jail.local` belongs to root)

`/etc/fail2ban/jail.local` must be **`root:root` 644** — it must *not* be writable by the web user: Fail2Ban runs as
root and a jail may name any action, so a `jail.local` the web user can write is a way to root. The "Fail2Ban" tab
therefore does not write the file itself: it computes the new file and pipes it, on **standard input**, to
`sudo -n /usr/local/sbin/mailpanel-f2b-apply` (no proposal file exists anywhere the PHP user could swap it). The root
helper accepts it only if it differs from the current file **solely in the values of the options the tab edits** —
`[DEFAULT]`: `findtime`, `bantime`, `bantime.maxtime`, `maxretry`, `bantime.factor`, `bantime.increment`, `ignoreip`;
`[roundcube-auth]`, `[dovecot]`, `[postfix-sasl]`, `[recidive]`: `enabled`, `findtime`, `maxretry`, `bantime` (`enabled`
is refused under `[DEFAULT]`, where it would switch on every stock jail) — each value well-formed (times, numbers,
true/false, IP / CIDR lists).

Then, **in place** (see "php-fpm sandbox" above: under `ProtectSystem=full` the helper can neither create a temporary
file next to `jail.local` nor rename over it): backup to `/var/backups/mailpanel-f2b/` (newest 30 kept) → open
`jail.local` for writing → `flock` (a second concurrent save waits) → rewrite from offset 0 and truncate → read the
file back and compare byte for byte → `fail2ban-client -t` → `reload`. Every write, `chown` and `chmod` result is
checked. If the write, the read-back or `-t` fails, the previous content is restored in place and the helper exits
non-zero with a message; if the reload fails after a good test it exits 2. The panel shows the message — it never
says "saved" for something that was not written. A new line, a changed section header or a changed jail outside the
list is refused with `odrzucone: …` and nothing changes. Edit everything else in `jail.local` as root, by hand.
`F2B_JAIL_LOCAL` in `.env` must stay `/etc/fail2ban/jail.local` (the only file the helper handles).

There is no reload timer any more: the helper reloads Fail2Ban itself. (1.0 shipped `mailpanel-f2b-reload`, a
timer that blindly reloaded on every `jail.local` mtime change; `install.sh` section 8 removes it.)

The scripts `/usr/local/bin/update-postfix-limits.sh` and `/usr/local/bin/mailpanel-delete-user.sh` are installed by
`install.sh` section 1; the panel detects their availability automatically (a probe every 5 minutes). Both check every
step and exit non-zero on any failure (see the table in "php-fpm sandbox").

Note: `APP_ENV=prod` in `.env` is required — in `dev` mode the system
integrations are deliberately disabled.

#### Optional: Fail2Ban for portal logins

The portal writes every failed login to syslog (`mailpanel`, facility auth: `auth-failure ip=… kind=admin|webmail
user=…`, IP first, user URL-encoded last). `system/fail2ban/jail.d/mailpanel.local` ships a **disabled**
`[mailpanel-portal]` jail with a matching filter: enable it (after `journalctl -t mailpanel` shows the lines) to ban at
the firewall instead of slowing requests down in PHP.

### Daily send limit (Postfix policy service)

The "Send limit (per day)" setting and individual limits (the "Change
Quota" modal) are enforced by a separate Postfix policy service — without it the values are
only saved in the database. **The limit counts recipients** (1.1.0): a message to 20 addresses uses 20 units, and a
single message with more recipients than the whole daily limit is refused outright (Postfix reports `recipient_count`
at the DATA stage; the counter is increased by one atomic SQL statement, so parallel sessions cannot overshoot).
Before 1.1.0 it counted messages, so a limit of 50 allowed 50 × `smtpd_recipient_limit` (Postfix default 1000)
recipients. Installation:

```bash
# 1) policy script
install -o root -g root -m 755 scripts/mailpanel-policy-daily.php /usr/local/bin/

# 2) DB user with minimal privileges + counter (the table is in panel_schema.sql)
mysql -u root -p <<'SQL'
CREATE USER IF NOT EXISTS 'mailpolicy'@'localhost' IDENTIFIED BY 'STRONG_POLICY_PASSWORD';
GRANT SELECT ON mailserver.virtual_users TO 'mailpolicy'@'localhost';
GRANT SELECT ON mailserver.app_settings  TO 'mailpolicy'@'localhost';
GRANT SELECT, INSERT, UPDATE ON mailserver.send_counters TO 'mailpolicy'@'localhost';
FLUSH PRIVILEGES;
SQL

# 3) configuration (readable by "nobody", the user the spawn runs as)
cat > /etc/mailpanel-policy.cf <<'CFG'
dsn = "mysql:host=127.0.0.1;dbname=mailserver;charset=utf8mb4"
user = mailpolicy
password = "STRONG_POLICY_PASSWORD"
timezone = Europe/Warsaw
CFG
chown root:nogroup /etc/mailpanel-policy.cf && chmod 640 /etc/mailpanel-policy.cf
```

In `/etc/postfix/main.cf`:

```
mua_data_restrictions = reject_unauth_pipelining, check_policy_service unix:private/policy-daily
```

In `/etc/postfix/master.cf` — in the `submission` and `submissions` blocks add
`-o smtpd_data_restrictions=$mua_data_restrictions` and `-o smtpd_recipient_limit=50` (caps the recipients of one
message on those ports), and at the end of the file add the service:

```
policy-daily unix  -       n       n       -       0       spawn
  user=nobody argv=/usr/bin/php /usr/local/bin/mailpanel-policy-daily.php
```

After `postfix check && systemctl reload postfix` the limit applies to ALL
SASL-authenticated sends (Roundcube webmail and external clients such as
Thunderbird — both go through port 587/465). Port 25 (incoming mail) is not
touched. A database failure = fail-open (mail goes out, the limit unenforced).
Limit semantics: account `NULL` = the global one from the panel, `0` = no limit.
**Roundcube must send through port 587 with authentication** (`smtp_host = 'tls://localhost:587'`,
`smtp_user = '%u'`, `smtp_pass = '%p'`): without SASL the policy sees no `sasl_username` and answers DUNNO — no limit.

Note: the Postfix client limits edited in the panel's "Security" tab (`smtpd_client_message_rate_limit` and friends)
go to `main.cf`, so they apply to **every** smtpd service including port 25 (incoming mail). To confine them to
submission, keep the defaults in `main.cf` and set them with `-o …` in the `submission`/`submissions` blocks instead.

The sender of a bounced message gets a `554 5.7.1` error already in the client (webmail
shows it on "Send"), and on the FIRST bounce of a given day additionally a
courtesy e-mail to their own mailbox (the `send_counters.notified` flag, envelope
sender `<>` — no bounce loops). To disable notifications:
`notify = 0` in `/etc/mailpanel-policy.cf`.

Manual test:

```bash
printf 'request=smtpd_access_policy\nsasl_username=user@yourdomain.com\n\n' \
  | sudo -u nobody php /usr/local/bin/mailpanel-policy-daily.php   # -> action=DUNNO
```

## 7. Step 5 — Apache

In the vhost, the Roundcube directory must honor `.htaccess`:

```apache
<Directory /var/www/roundcube>
    AllowOverride All
    Require all granted
</Directory>
```

> **Roundcube 1.7+** (webroot = `public_html/`): the overlay code stays OUTSIDE
> the webroot, and only symlinks land in `public_html/` — created by
> `install.sh` (section 7): `assets`, `portal.php`, `panel`, `.htaccess`.
> In that case point the `<Directory>` directive at `/var/www/roundcube/public_html`
> with `Options FollowSymLinks`.

With the panel's own PHP-FPM pool (step 4, "Own PHP-FPM pool"), every `<VirtualHost>` of the mail site also carries
the handler block from `system/apache/roundcontrol-fpm-handler.conf` (`install.sh` section 1b adds it):

```apache
    <Directory "/var/www/roundcube">
        <FilesMatch "\.ph(?:ar|p|tml)$">
            SetHandler "proxy:unix:/run/php/php8.5-fpm-mailweb.sock|fcgi://localhost"
        </FilesMatch>
    </Directory>
```

Nested in `<Directory>` it applies to Roundcube's files only; a `FilesMatch` directly in the `<VirtualHost>` would also
send global aliases on that host name (`/phpmyadmin`, …) to the panel's pool.

The `.htaccess` from the package blocks web access to: `app/`, `config/`, `logs/`, `temp/`, `vendor/`, `scripts/`,
`system/`, `sql/`, `sql_backup/`, `_legacy/`, `tests/`, the installers (`install.php`, `install.sh`), every `.env*`
file (`.env.bak`, `.env.save`, `panel.env` …), backups / dumps / keys / logs by extension (`.sql .bak .old .orig .save
.swp .log .sh .md .pem .key .cnf .dist .example`, `~`) and documentation — with mod_rewrite rules **and** a
`<FilesMatch>` fallback. (1.0.x claimed this but its rules only matched names *without* a dot, so e.g. `.env.bak`,
`sql_backup/*.sql` and `scripts/*` were served.) Everything depends on `AllowOverride All`, so also keep the secrets
**out of the web tree**: put the configuration in `/etc/mailpanel/panel.env` (`root:mailweb` 640; it is read before
`<root>/.env`), and with Roundcube 1.7 point the DocumentRoot at `public_html/` (symlinks only). The scripts in
`scripts/` also refuse to run outside the CLI.

## 8. Post-install verification

| Test | Expected result |
|---|---|
| `https://domain/portal.php` | login screen (dark theme) |
| `https://domain/.env`, `/.env.bak`, `/install.sh`, `/scripts/mailpanel-f2b-apply.php`, `/sql/panel_schema.sql` | **403** |
| `https://domain/app/bootstrap.php` | **403** |
| `https://domain/config/panel_config.php` | **403** |
| log in with the admin e-mail → "Admin Panel" | panel with styles and icons |
| the "IP blocks" tab | no red Fail2Ban warning |
| the "Security" tab | no warning about sudo for Postfix |
| log in with a regular account → "Webmail" | Roundcube mailbox |
| visit `https://domain/` without a session | redirect to the portal (custom_auth plugin) |

## 9. reCAPTCHA (optional)

Panel → Settings → Google reCAPTCHA: paste the Site Key + Secret Key (type v2
"I'm not a robot") and check "Active". Verification is **fail-closed** —
an error/timeout on Google's side rejects the attempt instead of letting it through.
Emergency disable from SQL: `UPDATE app_settings SET v='0' WHERE k='recaptcha_enabled';`

## 10. Developer mode (locally, without a mail server)

`.env`: `APP_ENV=dev` → logging into webmail verifies the password against the
`$6$` hash from `virtual_users` (no IMAP), system integrations disabled, full
error messages. Tests (never deploy `tests/`):

| Command | What it covers | Needs |
|---|---|---|
| `php tests/sudoers_test.php` | the sudoers rules (what the panel runs is allowed, hostile forms are refused, no `*`) and the Fail2Ban helper vs. `Commands.php` | nothing (safe anywhere) |
| `php tests/security_test.php` | account-name rules, reserved names, `/64` keys, CIDR, quota clamping, the identical login screen | nothing |
| `php tests/scripts_test.php` | the helper (in-place write, refusals, restore, no false success), the delete / Postfix / sudoers-cleanup scripts and the `install.sh` secret handling, with fake `fail2ban-client` / `postconf` / `doveadm`; static checks (`php -l`, `bash -n`, no `sleep`) | PHP, bash |
| `php tests/db_test.php` | registration, reserved names, aliases, tombstones, login limits (pair / client / IPv6 `/64`), account deletion, policy service (recipients, atomic counter), grants — in a **scratch database it creates and drops**, with an app account holding exactly the `sql/panel_grants.sql` privileges | MariaDB: `MP_TEST_DB_DSN` (default `mysql:host=127.0.0.1;port=3307`), `MP_TEST_DB_USER`, `MP_TEST_DB_PASS`; skips itself without |
| `php tests/run.php` | older integration tests against the dev database from `.env` (refuses to run unless `APP_ENV=dev`) | dev database |

In `dev` mode the Fail2Ban tab cannot save (there is no root helper): editing `jail.local` is a server feature.

## 11. Security — what's inside and what to keep in mind

Built in: CSRF on every mutation (all mutations via POST + PRG, including logout), Argon2id for admins /
SHA-512 crypt for mailboxes, a CSP with no inline JS (self-hosted icons), a `HttpOnly/SameSite/Secure` session +
`use_strict_mode` + ID regeneration after login, HSTS/X-Frame-Options/nosniff headers, an audit log of administrator
actions. System operations run through `sudo` with **exact-argument** rules (no `*` wildcards), and the panel never
writes a file that a root service reads: `jail.local` is `root:root`, edits go through a validating root helper (see
"Editing Fail2Ban from the panel").

Behaviour worth knowing (1.1.0):

- **Login limits** are per *(account, client)* and per *client* (IPv4 address or IPv6 `/64`), 4× higher for the client as
  a whole; **never per account alone**, so strangers cannot lock the administrator out. Failures go to their own table
  (`throttle_events`) — a successful IMAP login (which trims `user_login_events`) cannot reset them — and the admin login
  log (`admin_login_attempts`) is never pruned. A blocked client is refused immediately: nothing sleeps in PHP (one FPM
  pool serves every site on the server). Addresses in `SCAN_TRUSTED_IPS` (exact or CIDR) are not limited.
- **No enumeration:** the first login step answers the same for any well-formed address, existing or not, administrator
  or not; the choice *Webmail / Admin panel* is made by the user on the password screen, and the admin password is
  verified against a dummy hash when no such administrator exists (same timing).
- **Registration** lower-cases the name, accepts `[a-z0-9]` ends with `[a-z0-9._-]` inside (≤ 64), refuses the built-in
  reserved list (`admin administrator hostmaster postmaster webmaster` — the addresses CAs accept for e-mail domain
  validation — plus `abuse security noc root noreply mailer-daemon owner-* *-request …`), the editable list in
  *Settings* (default `info support contact sales …`), names that are the source or target of a virtual alias, and
  names deleted in the last `deleted_name_hold_days` (365). The administrator can override reserved / recently deleted
  names deliberately (tick-box in *Add user*), never an existing account or an alias source.
- **Deleting an account** (one transaction): the aliases that point at it, its login history and today's send counter
  go; a tombstone is kept; the maildir is quarantined and the result of the script is checked and shown (a failed or
  "not found" outcome is a visible error, not a success message).
- **"Ban IP"** (Users tab) acts in two layers: the portal and panel (`ip_blocks`) **and**, when Fail2Ban is available,
  the jail `panel-tempban` (webmail, IMAP, SMTP at the firewall); the confirmation says which layers took effect.
  Admin sessions end after 1 h idle / 12 h absolute, when the admin is removed or changes the password.
- **Database accounts:** `mailpanel` gets per-table grants (`sql/panel_grants.sql`), the Dovecot post-login script its
  own account `mailpostlogin` (`EXECUTE` on one procedure). `config/panel_config.php` imports only five numeric
  `rc_*` settings into Roundcube.

Your responsibilities:
- **keep database backups outside the webroot** (never in the Roundcube directory),
- after migrating from the old panel, **rotate** the DB passwords and reCAPTCHA keys that
  were hardcoded in the old files (`panel/config.php` etc.),
- treat Roundcube's `des_key` as a secret (it lives in `config.inc.php`),
- keep Roundcube + the panel in their own pool: never point another site's vhost at the `mailweb` socket, never add
  `mailweb` to another site, and never grant the panel's sudo rules to `www-data` (every PHP site running as
  `www-data` could use them),
- add a DNS **CAA** record for your domain (`0 issue "letsencrypt.org"`, or your CA): it limits who may issue
  certificates, which matters most for the role addresses reserved above,
- keep `ignoreip` in the Fail2Ban tab (it edits `[DEFAULT]`, which `sshd` inherits) set to your own address, and
  `dbpurgeage` ≥ the longest ban you hand out (temporary bans last up to 90 days),
- run `scripts/mailpanel-sudoers-cleanup.sh` after any manual sudoers edit: one leftover wildcard rule voids all the exact ones,
- update Roundcube (`program/`, `vendor/`) independently — the package does not
  modify the core, so RC updates don't overwrite the panel.

## 12. Troubleshooting

| Symptom | Cause / fix |
|---|---|
| Portal/panel with no styles | browser cache (Ctrl+Shift+R) or `assets/` missing on the server |
| 404/500 everywhere after upload | no `AllowOverride All`, or `.env` unreadable by the PHP user (`mailweb`) |
| 503 / "Service Unavailable" on the mail site only | the vhost points at the pool socket but the pool is not running: `systemctl status php8.5-fpm`, `ls -l /run/php/php8.5-fpm-mailweb.sock`, `php-fpm8.5 -t` |
| Webmail / portal error right after the switch to the own pool | a file the pool user must read is not `root:mailweb 640`, or `temp/` / the log dir is not `mailweb`'s: `sudo -u mailweb find /var/www/roundcube -type f ! -readable` and the ownership list in "Own PHP-FPM pool" |
| "DB Error" / 500 on the portal | wrong credentials in `.env` (DSN/user/password) |
| Red "No access to Fail2Ban" box | missing sudoers entries or `APP_ENV=dev` |
| "No sudo permission" on Postfix | missing sudoers entry for `update-postfix-limits.sh` |
| Fail2Ban save: "Fail2Ban: sudo: a password is required" / "…not allowed to execute…" | the helper or its sudoers rule is missing: `install.sh` section 2 (or "Upgrading from 1.0.1 to 1.1.0", step 3). The rule is `mailpanel-f2b-apply ""` — the helper takes no arguments |
| Fail2Ban save: "odrzucone: nie mogę otworzyć /etc/fail2ban/jail.local do zapisu" / any `read-only file system` | the php-fpm drop-in is missing or php-fpm was not restarted: "php-fpm sandbox" above (`ReadWritePaths=/etc/fail2ban/jail.local`, `systemctl restart`) |
| Fail2Ban save: "odrzucone: …" | the helper refused the proposal (the text says which line/value) or Fail2Ban rejected it (`fail2ban-client -t`; the previous file was restored); it changed nothing. `jail.local` must be the original with only the edited values changed — see "Editing Fail2Ban from the panel" |
| Fail2Ban save: "BŁĄD: …" (exit 3) | the write could not be completed **and** the previous content could not be restored: the message names the backup in `/var/backups/mailpanel-f2b/` — copy it back as root and run `fail2ban-client -t` |
| Postfix limits: "Postfix NIE został zmieniony: … ReadWritePaths=/etc/postfix" | the drop-in lacks `/etc/postfix` (or no restart): "php-fpm sandbox" above |
| Deleting an account: "NIE usunięto plików poczty (kod N)" | the account is gone from the database but the maildir is still there; codes: 4 no such directory, 6 the account still exists in Dovecot, 7 `doveadm` could not confirm, 3 path outside `/var/mail/vhosts`, 5/8 move or quarantine failed — see the header of `scripts/mailpanel-delete-user.sh` |
| Registration says a name is "zarezerwowana" / "niedostępna" | reserved list, an alias that names it, or a recently deleted account (*Settings*: reserved names, hold days); an administrator can create it by ticking the override in *Add user* |
| Fail2Ban tab: "no read permission" | `jail.local` must be readable by the PHP user: `root:root` **644** (do **not** make it writable for it) |
| Webmail login fails, panel works | check `imap_host` in `config.inc.php` (that's plain RC login) |
| After login it returns to the portal | cookies: HTTPS required in prod (the session has the Secure flag) |
