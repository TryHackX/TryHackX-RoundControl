# Changelog

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
