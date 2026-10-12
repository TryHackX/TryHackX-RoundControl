# TryHackX Mail Panel — administrative overlay for Roundcube

A self-contained overlay for Roundcube (pure PHP 8.1+, **zero Composer dependencies**)
that adds its own login/registration portal, an admin panel for mail accounts,
and a security layer tying together Postfix + Dovecot + Fail2Ban.

**Version 1.0.1** (2026-10-13, [CHANGELOG](CHANGELOG.md)) — a **security release**. If you run 1.0, upgrade now:
[Upgrading from 1.0](#upgrading-from-10-security-release-101).

- **Login portal** (`portal.php`) — a shared entry screen for webmail and the panel
  (flow: e-mail → pick service → password) + **public account registration** with per-IP
  limits, a password policy and optional reCAPTCHA,
- **Admin panel** (`panel/`) — accounts (search by e-mail/IP/date/quota/%usage,
  quota in binary units, per-account daily send limit, password reset,
  bans, deletion together with the on-disk mailbox), IP blocks (local + Fail2Ban) with a
  suspicious-IP scanner, logs (registrations, admin logins, audit) with a universal
  search, editing of Fail2Ban and Postfix limits,
- **Password self-service** — the Roundcube `password` plugin (SQL → `$6$` into virtual_users),
- **Daily send limit** — a Postfix policy service (per account / global),
- **Security**: CSRF, rate-limiting, brute-force lockouts, fail-closed reCAPTCHA,
  a CSP with no inline JS, hardened sessions, the PRG pattern, action auditing.

## Quick install

Assumes a working Roundcube 1.6+ and a `mailserver` database (Postfix/Dovecot virtual users).
Copy the package contents into the Roundcube directory (preserving the tree), then:

```bash
php install.php        # .env, schema import, first administrator (Argon2id)
sudo bash install.sh   # scripts, sudoers + Fail2Ban helper, Fail2Ban, policy service, password plugin
# finally: include panel_config.php in config/config.inc.php (see step 5)
```

Both installers are **interactive and idempotent** (a confirmation gate per step,
backups before editing). Details and manual installation — see the sections below.

## Upgrading from 1.0 (security release 1.0.1)

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
- the `ReadWritePaths=/etc/fail2ban/jail.local` php-fpm drop-in (if you added one) and the `mailpanel-f2b-reload`
  timer are no longer needed.

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
#    and the php-fpm drop-in, if you had one:  ReadWritePaths=/etc/fail2ban/jail.local  -> delete it, reload php-fpm
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
# the helper end to end, as the panel calls it: an unchanged proposal -> "bez zmian" ("no changes")
cp /etc/fail2ban/jail.local /var/lib/mailpanel-f2b/jail.local.proposed
chown www-data:www-data /var/lib/mailpanel-f2b/jail.local.proposed
sudo -u www-data sudo -n /usr/local/sbin/mailpanel-f2b-apply
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
├── scripts/                      # scripts for /usr/local/bin (scanner, policy, delete-user…)
│   └── mailpanel-f2b-apply.php   #   root helper → /usr/local/sbin (installs the panel's jail.local proposal)
├── system/                       # integration templates (sudoers, fail2ban, postfix, policy.cf)
├── sql/panel_schema.sql          # database schema (IF NOT EXISTS)
├── tests/run.php                 # tests (dependency-free runner; dev, needs the DB)
├── tests/sudoers_test.php        # sudoers rules + Fail2Ban helper consistency (no DB, no sudo; runs anywhere)
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
├── .htaccess                     ← REPLACE (blocks app/, .env, sql_backup…)
├── .env                          ← CREATE from .env.example (secrets, NOT from the package!)
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
├── sql/panel_schema.sql          ← (need not live on the server — for the DB import)
└── tests/run.php                 ← (optional; run only on dev)
```

The rest of Roundcube (`program/`, `skins/`, `vendor/`, `config/config.inc.php`…)
stays unchanged — apart from one line in step 5.

## 3. Step 1 — Files

```bash
# from the package directory:
rsync -av --exclude README.md --exclude sql/ ./ /var/www/roundcube/
chown -R www-data:www-data /var/www/roundcube/app /var/www/roundcube/assets \
    /var/www/roundcube/portal.php /var/www/roundcube/panel /var/www/roundcube/plugins/custom_auth
```

Remove leftovers of old panel versions if present (replaced by `app/`):
`bootstrap.php` (in the root), `panel/config.php`, `panel/_csrf.php`,
`panel/styles.css`, `panel/light-mode.css`, `panel/dark-mode.css`, `panel/style-index.css`.

## 4. Step 2 — Database

```bash
# 1) DB user for the panel (EXECUTE needed for the sp_register_login procedure)
mysql -u root -p <<'SQL'
CREATE DATABASE IF NOT EXISTS mailserver CHARACTER SET utf8mb4;
CREATE USER IF NOT EXISTS 'mailpanel'@'localhost' IDENTIFIED BY 'STRONG_PASSWORD';
GRANT SELECT, INSERT, UPDATE, DELETE, EXECUTE ON mailserver.* TO 'mailpanel'@'localhost';
FLUSH PRIVILEGES;
SQL

# 2) schema (safe for existing data: IF NOT EXISTS / INSERT IGNORE)
mysql -u root -p mailserver < sql/panel_schema.sql
```

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
chown root:www-data .env && chmod 640 .env
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

The panel runs system operations as `www-data` through `sudo`. The rules live in
**`system/sudoers.d/mailpanel`** — install the whole file (`install.sh` section 2 does this and validates it first):

```bash
# 1) the Fail2Ban helper first: a sudoers rule can only be tested against a command that exists
install -o root -g root -m 755 scripts/mailpanel-f2b-apply.php /usr/local/sbin/mailpanel-f2b-apply
install -d -o www-data -g www-data -m 750 /var/lib/mailpanel-f2b      # where the panel leaves its proposal
# 2) the rules (sudo >= 1.9.10; validate BEFORE installing)
visudo -cf system/sudoers.d/mailpanel
install -o root -g root -m 440 system/sudoers.d/mailpanel /etc/sudoers.d/mailpanel
```

Do **not** paste wildcard rules for `fail2ban-client` (`set * banip *` and the like) into `visudo`: in sudoers a `*`
in arguments also matches spaces, which lets any code running as `www-data` run arbitrary `fail2ban-client`
commands (e.g. replace a jail's `actionban`, which Fail2Ban then runs as root). The shipped file uses exact
`^regex$` arguments instead.

What each entry enables (without an entry, that feature shows a readable message; the rest works):

| Entry | Panel feature |
|---|---|
| `fail2ban-client ping`, `status`, `status <jail>` | the F2B ban list in the "IP blocks" tab |
| `fail2ban-client set <jail> unbanip <ip>` | the "Unban (F2B)" button |
| `fail2ban-client set <jail> banip <ip>` | manual IP block + bans from the scanner |
| `fail2ban-client get <jail> banip --with-time` | the "Expires" column with the remaining ban time |
| `fail2ban-client set <jail> bantime <seconds>` | timed bans from the scanner (jail `panel-tempban`) |
| `fail2ban-client reload` | reload after saving the "Fail2Ban" tab |
| `mailpanel-f2b-apply` | saving the "Fail2Ban" tab (see "Editing Fail2Ban from the panel" below) |
| `update-postfix-limits.sh` | applying Postfix limits ("Security") |
| `mailpanel-delete-user` | deleting mailbox files when removing an account |
| `doveadm -f flow quota get -u <user>` | quota usage on the account list |
| `mailpanel-scan.sh *` | the suspicious-IP scanner (accepts a 3rd argument: `ssh`/`dovecot`/`postfix`). The `*` is acceptable here because the script validates strictly: both dates must match `YYYY-MM-DD`, the source is a whitelist, extra arguments are ignored |

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

`/etc/fail2ban/jail.local` must be **`root:root` 644** — it must *not* be writable by `www-data`: Fail2Ban runs as
root and a jail may name any action, so a `jail.local` the web user can write is a way to root. The "Fail2Ban" tab
therefore does not write the file itself: it writes a *proposal* to `/var/lib/mailpanel-f2b/jail.local.proposed`
(directory `www-data` 750) and runs `sudo -n /usr/local/sbin/mailpanel-f2b-apply`. That root helper installs the
proposal only if it differs from the current file **solely in the values of the options the tab edits**
(`[DEFAULT]`, `[roundcube-auth]`, `[dovecot]`, `[postfix-sasl]`, `[recidive]`: `enabled`, `findtime`, `bantime`,
`maxretry`, `bantime.increment`, `bantime.factor`, `bantime.maxtime`, `ignoreip`), each value well-formed
(times, numbers, true/false, IP / CIDR lists). It keeps a copy in `/var/backups/mailpanel-f2b/`, runs
`fail2ban-client -t` (restoring the old file if Fail2Ban rejects it) and reloads Fail2Ban. Anything else — a new line,
a changed section header, a changed jail outside that list — is refused with `odrzucone: …` and nothing is changed.
Edit everything else in `jail.local` as root, by hand. `F2B_JAIL_LOCAL` in `.env` must stay
`/etc/fail2ban/jail.local` (the only file the helper handles).

There is no reload timer any more: the helper reloads Fail2Ban itself. (1.0 shipped `mailpanel-f2b-reload`, a
timer that blindly reloaded on every `jail.local` mtime change; `install.sh` section 8 removes it.)

The scripts `/usr/local/bin/update-postfix-limits.sh` and `/usr/local/bin/mailpanel-delete-user`
are your existing scripts (the same ones the old panel used). The panel detects
their availability automatically (a probe every 5 minutes).

Note: `APP_ENV=prod` in `.env` is required — in `dev` mode the system
integrations are deliberately disabled.

### Daily send limit (Postfix policy service)

The "Send limit (per day)" setting and individual limits (the "Change
Quota" modal) are enforced by a separate Postfix policy service — without it the values are
only saved in the database. Installation:

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
`-o smtpd_data_restrictions=$mua_data_restrictions`, and at the end of the file add the service:

```
policy-daily unix  -       n       n       -       0       spawn
  user=nobody argv=/usr/bin/php /usr/local/bin/mailpanel-policy-daily.php
```

After `postfix check && systemctl reload postfix` the limit applies to ALL
SASL-authenticated sends (Roundcube webmail and external clients such as
Thunderbird — both go through port 587/465). Port 25 (incoming mail) is not
touched. A database failure = fail-open (mail goes out, the limit unenforced).
Limit semantics: account `NULL` = the global one from the panel, `0` = no limit.

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

The `.htaccess` from the package blocks web access to: `app/`, `config/`, `logs/`, `temp/`,
`vendor/`, `sql_backup/`, `_legacy/`, `tests/`, `.env` files and documentation.

## 8. Post-install verification

| Test | Expected result |
|---|---|
| `https://domain/portal.php` | login screen (dark theme) |
| `https://domain/.env` | **403** |
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
error messages. Tests: `php tests/run.php` (uses the database from `.env`, cleans up
after itself — run on dev, not in production); `php tests/sudoers_test.php` (the sudoers rules and the
Fail2Ban helper vs. `Commands.php`; needs no database and no sudo, safe anywhere).

In `dev` mode the Fail2Ban tab cannot save (there is no root helper): editing `jail.local` is a server feature.

## 11. Security — what's inside and what to keep in mind

Built in: CSRF on every mutation (all mutations via POST + PRG),
login rate-limiting (account and IP) with an automatic ban, per-IP registration
throttling, Argon2id for admins / SHA-512 crypt for mailboxes,
a CSP with no inline JS (self-hosted icons), a `HttpOnly/SameSite/Secure` session +
`use_strict_mode` + ID regeneration after login + a 60-minute idle limit,
HSTS/X-Frame-Options/nosniff headers, an audit log of administrator actions.
System operations run through `sudo` with **exact-argument** rules (no `*` wildcards on `fail2ban-client`), and the
panel no longer writes a file that a root service reads: `jail.local` is `root:root`, edits go through a
validating root helper (see "Editing Fail2Ban from the panel").

Your responsibilities:
- **keep database backups outside the webroot** (never in the Roundcube directory),
- after migrating from the old panel, **rotate** the DB passwords and reCAPTCHA keys that
  were hardcoded in the old files (`panel/config.php` etc.),
- treat Roundcube's `des_key` as a secret (it lives in `config.inc.php`),
- update Roundcube (`program/`, `vendor/`) independently — the package does not
  modify the core, so RC updates don't overwrite the panel.

## 12. Troubleshooting

| Symptom | Cause / fix |
|---|---|
| Portal/panel with no styles | browser cache (Ctrl+Shift+R) or `assets/` missing on the server |
| 404/500 everywhere after upload | no `AllowOverride All`, or `.env` unreadable by www-data |
| "DB Error" / 500 on the portal | wrong credentials in `.env` (DSN/user/password) |
| Red "No access to Fail2Ban" box | missing sudoers entries or `APP_ENV=dev` |
| "No sudo permission" on Postfix | missing sudoers entry for `update-postfix-limits.sh` |
| Fail2Ban save: "service not reloaded" | the `fail2ban-client reload` rule is missing from `/etc/sudoers.d/mailpanel` (install the 1.0.1 file) |
| Fail2Ban save: "Fail2Ban: sudo: a password is required" / "…not allowed to execute…" | the helper or its sudoers rule is missing: `install.sh` section 2 (or the manual steps in "Upgrading from 1.0") |
| Fail2Ban save: "Nie mogę zapisać /var/lib/mailpanel-f2b/jail.local.proposed" | the directory is missing or not `www-data` 750: `install -d -o www-data -g www-data -m 750 /var/lib/mailpanel-f2b` |
| Fail2Ban save: "Fail2Ban: odrzucone: …" | the helper refused the proposal (the text says which line/value); it changed nothing. `jail.local` must be the original with only the edited values changed — see "Editing Fail2Ban from the panel" |
| Fail2Ban tab: "no read permission" | `jail.local` must be readable by `www-data`: `root:root` **644** (do **not** make it writable by `www-data`) |
| Webmail login fails, panel works | check `imap_host` in `config.inc.php` (that's plain RC login) |
| After login it returns to the portal | cookies: HTTPS required in prod (the session has the Secure flag) |
