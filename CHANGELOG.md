# Changelog

## 1.1.0 — 2026-10-13 — security

**Upgrade is recommended for every installation** (exact steps:
[README → Upgrading from 1.0.1 to 1.1.0](README.md#upgrading-from-101-to-110)). It needs a database import
(`sql/panel_schema.sql`, `sql/upgrade_1.1.0.sql`, grants), new helper / script versions, the php-fpm drop-in and a
php-fpm **restart** — do not just copy the PHP files.

Fixed — account names and deletion:

- **Public registration could hand out role addresses.** `admin@`, `administrator@`, `hostmaster@`, `postmaster@` and
  `webmaster@` are the addresses certificate authorities accept for e-mail domain validation; `abuse@`, `security@`,
  `noreply@` and similar are trusted by third parties. Registration (and *Add user* in the panel) now requires a name
  of the form `[a-z0-9]` at both ends and `[a-z0-9._-]` inside (≤ 64 characters; `-rf`, `--help`, `a..b` are refused),
  refuses a built-in reserved list (the five above, RFC 2142 / system names, `owner-*`, `*-request` …) plus an editable
  list (*Settings → Registration*, default `info support contact sales …`), and refuses names that are the source or the
  target of a `virtual_aliases` row. An administrator can still create such a mailbox on purpose (tick-box in *Add user*;
  never over an existing account or an alias source). Recommended: a DNS CAA record.
- **Deleted accounts could be re-registered and inherit mail.** Addresses are now stored and compared in lower case
  everywhere (Dovecot lower-cases user names, so the maildir is always lower case); previously a mixed-case account's
  maildir was never found by the delete script, which still reported success. `mailpanel-delete-user.sh` now exits
  non-zero when nothing is found (4) or on any failure, refuses `.`, `..`, empty names and slashes, asks Dovecot whether
  the account still exists (refuses if it does or cannot tell), checks the real path, and **moves** the maildir to
  `/var/mail/deleted` (purged after 30 days by a cron job) instead of `rm -rf`. The panel checks the exit code and shows
  a visible error when the files were not dealt with. Deleting an account removes, in one transaction, the aliases that
  point at it (alias rows with a *list* of targets are reported, not edited), its login history and its send counter,
  and records a tombstone: the name is not given out again for `deleted_name_hold_days` (default 365). `upgrade_1.1.0.sql`
  lower-cases existing addresses — note that `WHERE email <> LOWER(email)` finds nothing (case-insensitive column),
  it needs `BINARY`.
- **Root helpers reported success when their writes failed.** php-fpm runs with `ProtectSystem=full` and a process
  started through `sudo` inherits that sandbox, so `/etc` is read-only for the root helpers as well (except the
  `ReadWritePaths=` entries of the php-fpm drop-in). `mailpanel-f2b-apply` now rewrites `jail.local` **in place** (open,
  `flock`, write, truncate, read back, compare; backups in `/var/backups/mailpanel-f2b`, newest 30 kept), restores the
  previous content in place when the write, the read-back or `fail2ban-client -t` fails, checks every write / `chown` /
  `chmod`, and exits non-zero with a message otherwise (0 ok, 1 refused, 2 saved but reload failed, 3 failed and could
  not restore). The proposal now travels on **stdin**: no proposal file, no `/var/lib/mailpanel-f2b`, no race with
  `www-data`; the sudoers rule is `mailpanel-f2b-apply ""` (no arguments), `enabled` is refused under `[DEFAULT]`, and
  the panel no longer calls `fail2ban-client reload` itself (rule removed). `update-postfix-limits.sh` writes all three
  limits with one `postconf -e`, reads them back, runs `postfix check`, restores the old values on failure and reports
  the reload result. **The php-fpm drop-in `ReadWritePaths=/etc/fail2ban/jail.local` is required** (1.0.1 wrongly called
  it unnecessary) and `/etc/postfix` must be added for the Postfix limits; `install.sh` section 10 installs it, section 3
  tests the helper under the same sandbox (`--selftest`).

Fixed — login, bans, limits:

- **Login limiting:** failures are recorded in a dedicated table (`throttle_events`) instead of `user_login_events`
  (a successful IMAP login by the victim trimmed that table and reset the attacker's counter); `admin_login_attempts` is
  never pruned (failed attempts used to be deleted every few failures); the lockout is per *(account, client)* and per
  *client* (IPv4 address or IPv6 `/64`), never per account alone (two foreign addresses could keep the administrator
  locked out of the panel and webmail); addresses in `SCAN_TRUSTED_IPS` (now also CIDR) are exempt; refusals are immediate
  — `Http::secureSleep()` (0.3-0.6 s of `usleep` per failure, holding a worker of the single FPM pool shared by every
  site) is gone. Failures are also logged to syslog (`mailpanel`) with an optional, disabled Fail2Ban jail.
- **No account / role enumeration:** the first login step answers the same for every well-formed address; Webmail / Admin
  panel is chosen on the password screen; the admin password is checked against a dummy hash when the administrator does
  not exist. `check_availability` is limited per client, not per session.
- **"Ban IP"** now also bans in the Fail2Ban jail `panel-tempban` (webmail, IMAP, SMTP) when Fail2Ban is available, and
  says which layers took effect; the local ban (`ip_blocks`) now applies to every portal action; durations are validated.
  The `bantime` sudoers rule is limited to `panel-tempban`.
- **Daily send limit counts recipients** (policy attribute `recipient_count`; atomic counter), not messages; `master.cf`
  snippet adds `-o smtpd_recipient_limit=50`. Registration limits use the IPv6 `/64`, and limit check + account creation
  run under a lock.
- `install.sh`: secrets read from files (Postfix maps, `mailpanel-policy.cf`, plugin config) are validated
  (`[A-Za-z0-9._~+-]{16,128}`) and substituted with `awk` through the environment — never `sed`/SQL built from file
  content, and the application `.env` (writable by `www-data` when the code belongs to it) is not read at all; section 0 no
  longer runs `ALTER USER` on existing accounts (a re-run could reset the `mailuser` password and lock Postfix / Dovecot
  out of the database) and creates missing accounts with random passwords; the old wildcard sudo rules are found in
  `/etc/sudoers` and `/etc/sudoers.d/*` and removed on request (`scripts/mailpanel-sudoers-cleanup.sh`: comments them
  out, `visudo -cf`, backup). `doveadm quota` and `mailpanel-scan.sh` rules are exact `^regex$` (the latter kept a `*`).

Changed — hardening:

- **File ownership:** code is `root:root` (755 / 644); `www-data` only reads it; `temp/` and `logs/` are the only writable
  directories (`install.sh` section 7, README). `.htaccess` now really blocks `scripts/`, `system/`, `sql/`, `sql_backup/`,
  `_legacy/`, `tests/`, installers, `.env*` copies and backup / dump extensions (the old rules only matched names without
  a dot), with a `<FilesMatch>` fallback; `scripts/*.php` refuse to run outside the CLI; the configuration is read from
  `/etc/mailpanel/panel.env` when present; `.gitignore` added.
- **Database:** per-table grants (`sql/panel_grants.sql`; no `INSERT`/`UPDATE` on `virtual_aliases`, no `DELETE` on audit
  logs); the Dovecot post-login script uses its own account `mailpostlogin` with `EXECUTE` on one procedure
  (`sql/postlogin_grants.sql`); `sp_register_login` is created with `CREATE OR REPLACE` so a schema re-import keeps its
  grants; `virtual_users.password` is `VARCHAR(255)` (a `$6$rounds=…` hash is 120 characters); `config/panel_config.php`
  imports only five numeric `rc_*` keys.
- **Sessions / panel:** the admin session ends after 1 h idle, 12 h absolute, when the admin is removed or the password
  changes (also ends other sessions); logout is POST + CSRF; `user_ips.php` applies the same checks; read-only AJAX
  endpoints release the session lock; password change requires 12 characters + the mailbox policy classes (as does
  `install.php`); the reCAPTCHA secret is no longer printed into the settings page; error details go to the server log
  (not the browser) and messages are no longer HTML-escaped twice; all `fetch()` calls have a timeout; `Quota::toMb()`
  clamps instead of overflowing (a huge value used to become 0 = unlimited); the Roundcube hand-off now mirrors
  `index.php` (`kill_session()` before login, `authenticate` hook).
- `tests/run.php` refuses to run outside the CLI / `APP_ENV=dev`. New tests: `security_test.php`, `scripts_test.php`,
  `db_test.php` (own scratch database) and an extended `sudoers_test.php`.

Not changed: the password hash scheme (still `$6$` SHA-512 crypt), the global scope of the Postfix client limits (see the
note in the README), one `fail2ban-client` process per ban list on every page load.

## 1.0.1 — 2026-10-13 — security

**Upgrade 1.0 → 1.0.1 is recommended for every installation that has the Fail2Ban integration** (steps:
[README → Upgrading from 1.0](README.md#upgrading-from-10-security-release-101)).

Fixed — local privilege escalation `www-data` → `root` (any PHP code on the server, not only the panel):

- **sudoers: wildcard rules for `fail2ban-client`.** The 1.0 rules (`set * banip *`, `set * unbanip *`, `status *`,
  `get * banip *`, `set * bantime *`) used `*`, which in sudoers arguments also matches spaces. `www-data` could run
  any `fail2ban-client` command containing ` banip `, for example replace a jail's `actionban` with an arbitrary
  command that Fail2Ban then executes as root. `system/sudoers.d/mailpanel` now grants exact arguments only
  (`^regex$`, needs sudo ≥ 1.9.10; Debian 13 ships 1.9.16): `status`, `status <jail>`, `set <jail> banip|unbanip <ip>`,
  `set <jail> bantime <seconds>`, `get <jail> banip --with-time`, `ping`, `reload`.
- **`/etc/fail2ban/jail.local` was writable by `www-data`** (664, written by the panel) — the same escalation,
  because a jail may name any action. It is now `root:root` 644. `Commands::fail2banSaveConfig()` writes a *proposal*
  to `/var/lib/mailpanel-f2b/jail.local.proposed` (directory `www-data` 750) and runs the new root helper
  `/usr/local/sbin/mailpanel-f2b-apply` through sudo. The helper installs the proposal only if it differs from the
  live file solely in the values of the options the "Fail2Ban" tab edits, each well-formed; keeps a backup in
  `/var/backups/mailpanel-f2b/`; runs `fail2ban-client -t` (restoring the old file on failure); reloads Fail2Ban.
- The `doveadm -f flow quota get -u *` rule is now exact (`^-f flow quota get -u [A-Za-z0-9._@+-]+$`).
  `mailpanel-scan.sh *` keeps its `*`: the script validates its arguments strictly (dates `YYYY-MM-DD`, source from a
  whitelist, extra arguments ignored).

Changed:

- `install.sh`: section 2 installs the helper, `/var/lib/mailpanel-f2b` and the exact-argument sudoers file (refuses
  sudo < 1.9.10, validates with `visudo -cf`, lists leftover wildcard rules in `/etc/sudoers` and `/etc/sudoers.d/`);
  section 3 makes `jail.local` `root:root` 644 and tests the helper through sudo as `www-data`; section 8 removes the
  obsolete `mailpanel-f2b-reload` timer instead of installing it (the helper reloads Fail2Ban itself; the timer did a
  blind reload on every mtime change and duplicated the reload after each save). Removed from the repository:
  `scripts/mailpanel-f2b-reload.php`, `system/systemd/mailpanel-f2b-reload.{service,timer}`.
- The php-fpm drop-in `ReadWritePaths=/etc/fail2ban/jail.local` (if you added one) is no longer needed.
- `F2B_JAIL_LOCAL` must stay `/etc/fail2ban/jail.local` (the only file the helper handles).
- Added `tests/sudoers_test.php` (no database, no sudo): checks the sudoers rules (every command the panel runs is
  allowed, hostile forms are refused, no `*` in `fail2ban-client` rules) and that the helper and `Commands.php` agree on
  the editable sections/options.

## 1.0 — initial release

TryHackX RoundControl: login/registration portal, admin panel, Postfix daily send limit, Fail2Ban integration
(Debian 13, Dovecot 2.4, Roundcube 1.6+).
