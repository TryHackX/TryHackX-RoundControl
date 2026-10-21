# Changelog

## 1.4.0 — 2026-10-21 — second review: timed bans, one collation, safer numbers

**Code, `sql/upgrade_1.4.0.sql`, the two root helpers, the sudoers file, one Fail2Ban value and a new timer.** Steps:
[README → Upgrading from 1.3.2 to 1.4.0](README.md#upgrading-from-132-to-140).

Fixed — timed bans:

- **Every timed ban ends at its own time.** Fail2Ban gives a manually banned address no ban time of its own: it ends
  every such ban by the jail's *current* bantime. The panel set `panel-tempban`'s bantime right before each ban, so each
  new timed ban re-timed all the others — a 30-day ban was lifted soon after a later 1-hour one. Now the jail keeps
  `bantime = 90d` (the longest ban the panel hands out, never changed at runtime; the sudoers rule
  `set panel-tempban bantime` is gone), the panel stores the end of each of its bans in the new table `f2b_tempbans`
  (`App\Panel\TempBans`: the row is written before the ban and rolled back when the ban fails), and the new systemd
  timer `mailpanel-tempban-expire` — every minute, as the pool user, through the panel's existing `unbanip` rule —
  lifts the bans whose time is up, as does the panel whenever it lists the bans. The *Expires* column shows the stored
  end. If both stop, Fail2Ban still lifts every ban after 90 days.
- **Long bans survive a Fail2Ban restart.** A starting jail restores only the bans younger than its configured bantime:
  with 24 h every longer ban vanished on a package upgrade or a reboot. With 90 days a restart restores every ban still
  within its own stored time.
- Unbanning and moving a ban out of `panel-tempban` forget its end; moving a ban into `panel-tempban` needs a duration.

Fixed — database:

- **One collation.** On MariaDB 11.5+ new panel tables got `utf8mb4_uca1400_ai_ci` next to older `general_ci` mail
  tables, and every comparison across them failed (the account list, log retention, the login history). The schema and
  `sql/upgrade_1.4.0.sql` give the panel's tables the collation of `virtual_users.email`; tables that already match are
  not touched (`utf8mb3_x` and `utf8mb4_x` count as one).
- **Mailbox files left on disk:** a deleted account whose maildir could not be moved to the quarantine keeps
  `files_left` on its tombstone — the portal never gives the name out again, the panel moves the files first when it
  creates the name again, and log retention keeps such tombstones.
- A name created again no longer shows the previous owner's registration date and IP; the aliases removed with an
  account are listed in the audit log (source → destination, for restoring by hand). An index on `virtual_aliases.source`.

Fixed — numbers and limits:

- An empty or negative field keeps the stored value (it used to become 0 = no limit); values are clamped to what
  Roundcube and Postfix accept, and Postfix values are stored only when they were applied. A quota under 1 MiB becomes
  1 MiB, 0 shows as ∞. The send limit is checked before an account or a quota is written, in one `INSERT` or one
  transaction.
- The day of the send counter comes from the database clock (the policy service and the panel could disagree around
  midnight), as does the registration interval.

Fixed — Fail2Ban tab and its root helper:

- The helper and the panel refuse what makes a jail useless: `ignoreip` broader than IPv4 /8 or IPv6 /32 (`0.0.0.0/0`
  exempted everyone, `sshd` included), `maxretry` outside 1..100, times under a minute or over a year. Host names in
  `ignoreip` (dynamic DNS) are accepted. An empty field keeps the option (it wrote `maxretry 0` or `10m`), and values
  such as `1h30m` or `bantime = -1` are kept instead of becoming `10m`.
- Read and ban calls have a time limit (a wedged Fail2Ban or Dovecot held a PHP worker for good) and all use `sudo -n`.

Security:

- A mailbox password reset and taking a reserved or held name ask for the administrator's password.
- Trusted addresses (127.0.0.1, ::1, `SCAN_TRUSTED_IPS`) cannot be banned by hand or by a ban move (127.0.0.1 in
  `recidive` cut PHP off from MariaDB); they skip the sign-in CAPTCHA, the administrator's way in when the keys stop
  working. A CAPTCHA secret the provider rejects is not switched on.
- An IPv4 client reported as `::ffff:a.b.c.d` has one form everywhere (the registration limit counted 0 of its own
  registrations).
- Every panel response carries the security headers (logout, `user_ips`, the session guard's 403); both CSPs add
  `frame-ancestors 'none'`. The CSRF token is renewed at sign-in.
- Passwords longer than 256 characters are refused (SHA-512-crypt's work grows with the length); a longer sign-in
  attempt is answered as a wrong password without hashing it. `LIKE` wildcards in searches are literal.
- A ban move bans in the target jail before it unbans the source (the old order left the address banned nowhere when
  the ban failed).

Front end:

- *Add user*: the quota with its unit (KiB / MiB / GiB) and the default saved in Settings, without a reload; the admin
  password field appears with the reserved-name consent. *Reset password* asks for the admin password.
- Confirmations: a partial success shows both messages, closes the dialog and refreshes the lists; destructive ones open
  with *Cancel* focused; focus stays in the row after unban, language changes and scanner actions.
- Accessibility: contrast of every text and badge at least 4.5:1 in both themes, 24 px targets (32 px on touch),
  sortable headers are buttons (Enter, Space, Shift+Enter adds a column), list totals are announced only after the
  admin changed the list.
- No horizontal scroll of the account list down to 1280 px (icon-only row actions up to 1440 px, a sticky actions
  column), long addresses wrap; the scanner's dates in local time; plural counts; "Sign in" / "Sign out" throughout;
  remaining ban times with spaces ("1 h 30 min").
- The unused Font Awesome copy and dead CSS / JS / attributes are gone.

Installer:

- **A clean install in the right order:** `install.sh` section 0 first (database, accounts, schema, grants), then
  `install.php`. The installer tests the connection before it writes anything, says ".env written" only for a written
  file, imports the schema only with an account that may create tables (otherwise it prints the administrator's
  commands), prints the SQL for the first domain when the panel account may not insert it (no more "18 of 20 statements
  failed" or an uncaught PDOException), checks the domain (lower case, a dot, at most 50 characters), defaults
  `APP_TIMEZONE` to the server's zone, accepts `host:port` and stops when its input ends.
- `install.sh`: section 3 offers a base `jail.local` (`system/fail2ban/jail.local.example`, exactly what the Fail2Ban
  tab edits; never over an existing file) and keeps `[panel-tempban]` at 90 days — also in a `jail.local` of the 1.0
  layout, written in place, then a restart of that jail; section 6 offers to wire the Dovecot post-login script
  (`conf.d/99-roundcontrol-postlogin.conf`, `doveconf -n`, removed again on failure); section 9 adds `custom_auth` as
  well as `password`; the new section 11b installs the timed-ban timer; section 1b refuses the pool handler without
  `mod_proxy_fcgi`; section 4 writes the server's time zone into the policy file; `*.pre-*` copies are secrets
  (root:root 600).

Other:

- English operator texts: `.env.example`, the `/etc` templates (policy service, Dovecot, Postfix, Fail2Ban filters and
  jails) and the password-plugin config — the placeholders `install.sh` fills in are unchanged. Remix Icon ships with
  its Apache-2.0 licence; the README has a third-party section.
- The policy notice goes only to plain mailbox names; the standalone password-plugin installer is gone (`install.sh`
  section 5 keeps the generated password).

Tests: `tests/db_test.php` (`upgrade_1.4.0.sql` on a database in the 1.3.2 state — column, index, `f2b_tempbans` and
its grant, collation, procedure, second run; mailbox files left on disk; timed bans — the stored end, a failed ban rolled
back, expiry, Fail2Ban not answering, a ban while an expiry run holds the row, the timer's script; ranges and limits;
`LIKE` wildcards; trusted addresses; `install.php` with the grants-only account), `tests/http_test.php` (the new
dialog fields, list announcements), `tests/scripts_test.php` (the helper's ranges, `install.sh` sections 1b, 3, 4, 6, 7,
9 and 11b with shell fakes), `tests/security_test.php`, `tests/sudoers_test.php` (no `bantime` rule for any jail).
Interrupted runs clean up, skips are counted per check, `MP_TEST_DB_ACCOUNT_HOST` / `MP_TEST_REQUIRE_DB`;
`tests/run.php` is gone (its `ORDER BY` whitelist checks moved to `db_test.php`); `db_test.php` no longer calls
`Reflection*::setAccessible()` (deprecated in PHP 8.5). All suites pass on PHP 8.4 and 8.5 with MariaDB 11.4. CI (GitHub
Actions): ShellCheck plus the six suites on PHP 8.1 / 8.4 / 8.5 with MariaDB 11.4 / 11.8.

## 1.3.2 — 2026-10-13 — log retention

**Code plus `sql/upgrade_1.3.2.sql`** (one stored procedure, one EXECUTE grant, the setting stored explicitly). No sudo /
php-fpm / Apache / Fail2Ban change. Steps: [README → Upgrading from 1.3.1 to 1.3.2](README.md#upgrading-from-131-to-132).

Added — the panel's logs no longer grow forever:

- **Settings → Log retention:** 90 / 180 / 365 / 730 days or no limit (`log_retention_days`, **365 by default** —
  stored by the schema for fresh installs and by the upgrade file for existing ones). Under every table of the *Logs* tab
  the active period is shown; saving updates the notes in place.
- **What is removed** (entries older than the period, in batches of 1000 rows — short locks, at most 20 batches per
  table and run, the rest the next day):
  - `admin_audit_logs`, `admin_login_attempts`: entries older than the period;
  - `registration_events`: failed / blocked attempts and the rows of accounts that no longer exist — the successful
    registration of an existing account stays (the account list shows its creation date and registration IP);
  - `user_login_events` (already capped per account by `sp_register_login`): older rows, but every existing account
    keeps its latest login (the "last login" column); rows of accounts that no longer exist go;
  - `send_counters`: days older than the period (the daily limit only reads today's row);
  - `deleted_accounts`: only after the longest of the period, *deleted_name_hold_days* and the 30-day quarantine, so the
    re-registration protection keeps working;
  - `throttle_events`: unchanged (the limiter already keeps one day).
- **When:** at most once a day without cron — after a portal or panel request has been answered
  (`fastcgi_finish_request`), guarded by a timestamp in `app_settings` that exactly one request can claim (a conditional
  `UPDATE`), plus **Clean up now** in the panel (administrator session + CSRF), which reports how many rows went. Every
  run that removed something — and every manual run — writes **one** audit entry (`LOG_RETENTION`) with the numbers per
  table; automatic runs are logged as `system`. "No limit" removes nothing and schedules nothing.
- **Least privilege kept:** the panel's database account still has **no DELETE** on the audit, admin-login and
  registration logs (nor on `deleted_accounts`). It may only call `sp_prune_logs` (SQL SECURITY DEFINER), which clamps
  the period to **at least 90 days** — even a hijacked panel account cannot erase recent traces.

Tests: `tests/db_test.php` (per-table rules with backdated rows, existing accounts keep registration / last login, name
hold longer than the period, "no limit" removes nothing, the procedure clamps 10 days to 90, the once-a-day claim, one
aggregated audit entry, the panel account still cannot DELETE logs, schema and upgrade file carry the same procedure),
`tests/http_test.php` (automatic run on the first requests, *Clean up now* needs a session and the CSRF token, invalid
period refused, notes under the three tables), `tests/i18n_test.php` (option labels in both languages).

## 1.3.1 — 2026-10-13 — English by default, phones, language switcher

**Code plus the two root helpers whose messages changed** (same paths, arguments and exit codes). No schema change, no
new grant, no sudo / php-fpm / Apache / Fail2Ban configuration change. **An existing installation must store its
language before the new code goes in** — see [README → Upgrading from 1.3.0 to 1.3.1](README.md#upgrading-from-130-to-131).

Changed — English by default (the repository is public):

- **Fallback language:** without `app_settings.default_language` the portal, the panel, the audit log and the policy
  service now use **English** (1.3.0: Polish). Polish stays the reference language of the keys. Upgrades store the
  current language explicitly first (`INSERT IGNORE … ('default_language', 'pl')`), so a running site keeps Polish.
- **`install.php` speaks English** by default; `--lang=pl` (or the first question in an interactive terminal) switches
  it to Polish. Its texts are a small catalogue inside the installer (English + Polish, identical keys — a test checks
  it), not app/lang: the installer runs as root before `.env` and the database exist, and its texts are not part of the
  interface packs administrators translate in the panel. It now **asks for the default interface language** (English by
  default; a re-run offers the stored value, so it never flips a running site) and stores it. Yes / no accepts y / t.
- **`install.sh` and the helpers print English.** `mailpanel-f2b-apply` answers `unchanged` / `saved and reloaded (N
  changes)` / `refused: …` / `saved and checked, but the reload failed: …` / `FAILED: …` (exit codes unchanged); the panel
  keys on the exit code and shows a translated message with the helper's detail, and still understands the old Polish
  `bez zmian` (helper and code can be updated in either order). The other helpers already printed English.
  `install-password-plugin.sh` too. The policy service follows the stored default language as before.

Changed — phones and the header:

- **Portal on a phone:** the language button sits in its own row above the card (it used to sit on the card's corner),
  the card has rounded corners and a margin instead of sticking to the top edge, the hidden card (registration / login)
  no longer lengthens the page (the footer is at the bottom of the screen), slimmer padding at 360 px so reCAPTCHA keeps
  its full size; landscape phones get the card at the top instead of below the fold.
- **Panel on a phone:** the e-mail without the "Logged in as" label next to the buttons, four counters in one row on
  landscape phones / small tablets (was 3 + 1), smaller header and dialog margins on low screens, a narrower coverage
  bar in the language table. Checked at 360–430 px and in landscape: no horizontal page scroll; tables scroll inside
  their frame, tabs scroll, dialogs fit.
- **Header:** the language switcher sits right next to the light / dark toggle (Log out comes before them).
- **Language switcher refreshes itself:** after copying, uploading, deleting, enabling or disabling a language (or
  changing the default) the header switcher and the "source language" list update immediately — no page reload. (The
  portal's switcher is on another page: it shows the new list on its next load.)

Tests: `tests/i18n_test.php` (installer catalogue parity, every installer key exists, no Polish outside the catalogue,
`--help` / `--lang` handling, English fallback), `tests/http_test.php` (no stored language → English portal, stored `pl`
→ Polish, switcher and source list in the language-operation response, header order), `tests/db_test.php` (policy
service: English without a stored language), `tests/scripts_test.php` (English helper output).

## 1.3.0 — 2026-10-13 — languages, CAPTCHA providers, footer, polish

**Code** plus one new data directory (`/var/lib/roundcontrol/lang`, owned by the pool user) and a reinstalled policy
script (`/usr/local/bin/mailpanel-policy-daily.php`, now bilingual). No schema change, no new grant, no sudo / php-fpm /
Apache / Fail2Ban change; new settings are created on first save and default to the 1.2.0 behaviour (Polish,
reCAPTCHA v2 with the existing keys). Steps: [README → Upgrading from 1.2.0 to 1.3.0](README.md#upgrading-from-120-to-130).

Added — interface languages (like the tracker panel):

- **Polish (default) and English ship with the code** (`app/lang/<code>/<area>.php`, flat `key => text` arrays,
  identical key sets — a test enforces it). Every user-visible text moved there: portal and panel templates, the screens
  injected by AJAX, controller / JSON messages, exception messages that reach the admin, the row templates, pagination,
  both scripts (`js.portal.*` / `js.panel.*`, handed to the page as a `<script type="application/json">` data block —
  CSP unchanged), the footer, and the daily-limit notification e-mail / SMTP rejections of the policy service (Polish or
  English after the site default).
- **Choice of language:** `?lang=xx` (the switcher on the portal and in the panel header — plain links, no JavaScript
  needed) → the `lang` cookie (1 year, `HttpOnly`, `SameSite=Lax`, `Secure`, same parameters as the other preference
  cookie; validated against the enabled languages) → `Accept-Language` (among enabled languages, q-weights honoured) →
  the site default. PHP renders the chosen language from the first byte (no flash). The audit log is always written in
  the site's default language. Webmail: the portal hands its language to Roundcube before login
  (`rcmail::load_language`) — used for the session and for accounts logging in for the first time; a language a user
  saved in Roundcube's own settings still wins.
- **Settings → Interface languages:** installed languages with % translated, enable / disable, set the default,
  duplicate a language as a starting point (the copy starts disabled), download a pack (JSON), upload a pack, delete an
  added language. Built-in languages cannot be overwritten or deleted, the default cannot be disabled or deleted.
- **Security model:** the code tree stays `root:root` and the pool user cannot write to it, so added languages are JSON
  files in a data directory (`LANG_DATA_DIR`, default `/var/lib/roundcontrol/lang`, `mailweb` 0750; `install.sh`
  section 12). They are read with `json_decode()` only — nothing is ever included from a writable place — and every value
  is filtered again when loaded. Uploads: admin session + CSRF, 512 KB, strictly a flat JSON object `string → string`
  (lists, nesting, non-text values rejected), at most 10 000 keys, only keys the reference language (pl) knows; values
  go through an allow-list sanitizer (tags b, strong, em, i, code, kbd, br, span, small, sup and `<a href>` with
  http(s)/relative links only; no `<script>`, `on…=` attributes, `javascript:` links, PHP tags or control characters).
  Rejected keys are named in the reply. All operations are in the audit log (`LANGUAGE`).

Added — CAPTCHA providers (like the tracker):

- `captcha_provider` = **Google reCAPTCHA v2** (default — the existing keys keep working, no migration), **reCAPTCHA v3**
  (invisible, minimum score, the action — `login` / `register` — must match), **Cloudflare Turnstile** or **hCaptcha**,
  each with its own site key and secret, chosen in Settings. Secrets are never sent back to the browser (an empty field
  keeps the saved one); enabling a provider without both keys is refused (CAPTCHA stays off, the message says why) —
  otherwise nobody, the administrator included, could get past the e-mail step.
- One helper (`App\Security\Captcha`) for every use; verification is **fail-closed** (missing secret or token,
  transport error, non-JSON answer, `success` not `true`, v3 score below the threshold or another action = refusal).
- The portal's CSP allows only the active provider's hosts; no CAPTCHA = no third-party host at all.
- The widget is clipped to its exact size in a box of its colour (no white edges from the iframe in the dark theme),
  centred, scaled down on narrow phones, reset after a failed attempt for each provider, and follows the page language.
  With v3 a wrong password is retried silently with a fresh token (widgets need the e-mail step again).

Added — footer: "TryHackX RoundControl", author TryHackX, links to the GitHub repository and the licence, at the bottom
of the portal and the panel. **The version number is shown only in the admin panel's footer**; public pages carry no
version anywhere (the header badge is gone, asset URLs use an 8-character file hash instead of a version, release
numbers were removed from the comments of the public CSS / JS, `X-Powered-By` is removed).

Changed — polish:

- **reCAPTCHA in the dark theme** — no white strips at the right / bottom edge (the iframe is 2 px larger than the
  widget), centred, correct size on desktop and phones.
- **Messages after saving** use the alert look of 1.1.0 again, placed at the top of the saved card / form (or a sticky
  slot under the tabs for lists and dialogs) as an overlay — nothing below moves; success hides itself after 6 s,
  an error after 12 s (paused while hovered / focused; a close button), `aria-live`. The corner toasts are gone.
- **Tiles:** the chosen tile turns into a spinner with a progress bar, the other one fades; the page then goes straight to
  the panel / webmail (the same smooth hand-off for an account that goes directly to webmail). No "Otwieram panel…"
  line under the tiles (screen readers still get it).
- **No server-side delay after a password — on purpose.** 1.1.0 removed `secureSleep()` (0.3–0.6 s `usleep` after every
  error) because each sleeping request holds one of the shared php-fpm workers: a handful of parallel wrong passwords
  could occupy the whole pool and slow every site on the server. Guessing is limited by the per-account / per-client
  limits (instant refusal), Fail2Ban on the journal lines and the CAPTCHA, so a delay would buy nothing. The page shows
  the same short "checking" state (≈0.45 s) for right and wrong passwords so the answer does not flash — in the browser
  only.
- **Table headers**: one line everywhere (users, bans, registrations, admin logins, audit, Fail2Ban), shorter labels with
  the full name in a tooltip (*Ost. logowanie*, *IP rej.*), sort arrows in a fixed slot next to the label, the multi-sort
  number as a small badge, the "sort by messages" icon in the *Usage* header on the same line.

Tests: `tests/i18n_test.php` (new: pl/en key parity and parameters, every key used in PHP / JS exists, no hard-coded
Polish left in templates / JS / PHP, language resolution order, sanitizer against malicious values),
`tests/security_test.php` (CAPTCHA dispatch per provider, fail-closed cases, v3 score / action, CSP per provider),
`tests/http_test.php` (CAPTCHA settings: secret never echoed, enabling without keys refused, CSP and widget on the portal;
no version on public pages, version in the panel; `?lang` / cookie / Accept-Language, English pages without Polish;
language management: session + CSRF, path traversal, malicious and malformed packs, size limit, built-ins protected),
`tests/db_test.php` (policy service answers in the site language).

## 1.2.0 — 2026-10-13 — usability

**Code only** (no database change, no new grants or sudo rules, no php-fpm / Apache change) plus a corrected Fail2Ban
filter. Steps: [README → Upgrading from 1.1.0 to 1.2.0](README.md#upgrading-from-110-to-120).

Changed — the panel works without page reloads:

- Every form of the panel (settings, security / anti-spam, admin password, Fail2Ban, add user, quota, password reset,
  ban, delete, manual Fail2Ban ban, unban / move) is sent with `fetch()` (field `_ajax`, the existing per-session CSRF
  token) and answered with JSON `{ok, msg, error, refresh, values, data, done}`. The message is always the server's text;
  `values` are the values the server actually stored (clamped / normalised — e.g. the hold days capped at 3650, the
  reserved list lower-cased, the "apply quota to all" box unticked again, the reCAPTCHA secret field emptied) and are
  written back into the form; `refresh` names the lists / counters to update. Without JavaScript the same forms still
  post and redirect (PRG, now to `index.php?tab=…`). Server-side validation is unchanged.
- Feedback without layout shift: notifications in a corner overlay (success fades after a few seconds, errors stay until
  closed), errors of a dialog inside the dialog in a reserved slot (the dialog stays open, e.g. after a wrong password),
  buttons keep their width while working (spinner, then "Zapisano"), a one-line session-expired bar with a login link.
- Lists refresh in place: the old rows stay (dimmed, with a thin progress bar) until the new ones arrive, the table keeps
  its height while paging (the pagination stays under the cursor), columns only grow, stale responses are dropped, the
  keyboard focus returns to the same button of the same row. After add / delete / quota / ban / unban the list, the
  counters (`?ajax_stats`) and the audit log refresh by themselves; a changed row flashes. The pagination always shows
  first / previous / next / last (disabled when there is nowhere to go), so buttons no longer move between pages.
- The first page of every list is rendered with the page (empty tables used to fill in after load and push everything
  down); the active tab comes from `?tab=` (survives F5 without flashing the first tab); the theme is a cookie read by the
  server (no dark → light flash); `scrollbar-gutter: stable` (switching tabs or opening a dialog no longer moves the page
  sideways); tab content fades instead of sliding.
- Accessibility: real buttons instead of clickable `div`/`span` (tile, IP badges, sort icons, dialog close), tabs with
  `role=tablist` and arrow keys, sortable headers reachable with Tab and Enter (`aria-sort`), dialogs with
  `role=dialog`, focus moved in and trapped, Escape closes the top one, focus returns to the button that opened it;
  labels tied to every field; `prefers-reduced-motion` honoured; phone layout: counters 2×2, notifications full width.
- Confirmations run in the dialog (the button shows progress, an error stays in the dialog). "Apply quota to all" asks
  first and is logged (`QUOTA_ALL`); quota changes are logged (`USER_QUOTA`); a quota change or deletion of an account
  that no longer exists is an error that refreshes the list, not a silent success. An unknown POST is an error.
- Session expiry: `fetch()` requests get `403 {auth:true}` JSON (not the portal's HTML), CSRF failures `403 {csrf:true}`.

Changed — login (portal):

- **E-mail → password → (administrator) tiles.** The password screen has no service choice any more. A password is
  checked first as the panel password (always one Argon2id verification, against a dummy hash when the address is no
  administrator) and then as the mailbox password (IMAP through Roundcube, `kill_session` + `authenticate` hook as in
  1.1.0). Every wrong password takes the same path and gets the same answer for a nonexistent, a normal and an
  administrator address (tested over HTTP). A correct administrator password for an address with a mailbox shows two
  tiles (*Webmail* / *Panel Admina*, the last choice marked); without a mailbox it goes straight to the panel; the
  mailbox password of an administrator's address goes straight to webmail. When the panel password does not open the
  mailbox (other password), the Webmail tile is disabled with an explanation. For the Webmail tile the password is kept
  in the session encrypted with AES-256-GCM under a key held only by the page (single use, bound to address, admin and
  IP, 2 minutes). Limits, failure logging (`throttle_events`, `admin_login_attempts`, syslog) and session hardening are
  unchanged; a successful panel entry is logged when the panel is entered (not when webmail is chosen).
- A wrong password keeps the password screen (the page starts the e-mail step again in the background, identically for
  every address); with reCAPTCHA enabled it returns to the e-mail step (new token). Show-password button, Caps Lock hint,
  password-manager friendly (`autocomplete=username` on the password step), messages that expand instead of jumping,
  real buttons, `inert` on the hidden card, Escape = back, reduced motion.

Fixed:

- **Delete account dialog** said "Pliki: Zostaną usunięte z dysku"; since 1.1.0 the maildir is moved to the quarantine
  `/var/mail/deleted/<domain>__<user>__<YYYYmmddHHMMSS>` and purged after 30 days. The dialog, the result messages, the
  audit entry and the README say so; without the sudo rule the dialog says the files stay on disk.
- **A wrong admin password in a confirmation prompt** (deleting an account, changing the admin password) was recorded
  nowhere. It is now an audit entry `REAUTH_FAILED` (which action, which account, attempt n of 5) and a journal line
  `reauth-failure ip=… kind=admin user=…`; after 5 in one session the session ends (`REAUTH_LOCKOUT`). The Fail2Ban
  filter deliberately does not count `reauth-failure` (an administrator's typos must not ban the administrator; guessing
  is stopped by the lockout) — README "Optional: Fail2Ban for portal logins" shows the one line to add for those who
  want it.
- **Fail2Ban `mailpanel-portal` filter never matched**: Fail2Ban sees `host mailpanel[pid]: auth-failure …`, the 1.1.0
  regex started with `^auth-failure`. Verified with `fail2ban-regex` on Fail2Ban 1.1.0 (old: 0 matched; new: matches
  IPv4 / IPv6 / no host, not `reauth-failure`, not a line from another program, not an `ip=` smuggled into the login).
- **Reserved names / aliases in Add user**: the consent box appears only when it can help and says why the name is
  held (built-in role address — with the certificate-authority reason —, an entry of the editable list, a recent
  deletion with its date, an alias pointing here). An **alias address** (e.g. `postmaster@` → `admin@`) cannot be
  overridden — a mailbox of that name would never receive mail — and the dialog explains this, shows the alias and offers
  *Delete alias* (confirmation; full row in the audit log as `DELETE_ALIAS`, since the panel cannot recreate aliases).
  An existing account offers "show on the list".

Tests: `tests/http_test.php` (new: real entry points over `php -S` with a scratch database — login non-enumeration,
tiles, encrypted tile password, JSON endpoints: no session / CSRF / errors / normalised values / refresh hints,
`REAUTH_FAILED` + lockout, alias deletion, reserved names, `?tab=`; `--serve` keeps it running for a manual browser
check), `security_test.php` (screens, tiles, `AuthFlow` encryption, syslog lines against the shipped filter regex,
`Accounts::reservedBy`). `db_test.php`, `scripts_test.php`, `sudoers_test.php` unchanged and green.

## 1.1.0 — 2026-10-13 — security

**Upgrade is recommended for every installation** (exact steps:
[README → Upgrading from 1.0.1 to 1.1.0](README.md#upgrading-from-101-to-110)). It needs a database import
(`sql/panel_schema.sql`, `sql/upgrade_1.1.0.sql`, grants), new helper / script versions, the panel's own PHP-FPM pool,
the php-fpm drop-in and a php-fpm **restart** — do not just copy the PHP files. `sudo bash install.sh` does all of it.

Added — Roundcube + the panel in their own PHP-FPM pool:

- Until now one pool `[www]` (`www-data`) ran every PHP site of the server, so a flaw in any of them could read the
  panel's database password and Roundcube's `des_key` and use the panel's sudo rules. `install.sh` section 1b creates
  the system user `mailweb` (`PANEL_USER=`), the pool `[mailweb]` (`system/php-fpm/roundcontrol-pool.conf`: own socket
  `0660 www-data`, `pm = ondemand`, own session directory with its own garbage collection), points Roundcube's files in
  every `<VirtualHost>` of the mail site at it (`system/apache/roundcontrol-fpm-handler.conf`, nested in `<Directory>` so
  global aliases such as `/phpmyadmin` stay in `[www]`; validated with `apache2ctl configtest`, restored
  on failure), and gives `mailweb` what it writes (`temp/`, `logs/`, Roundcube's `log_dir` and its logrotate `create`
  line) and reads (secrets `root:mailweb 640`). The sudo rules name the pool user through one `User_Alias` line;
  `www-data` keeps none of them. The other sites are not touched. `PANEL_USER=www-data` keeps the shared pool.

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
  `/var/mail/deleted` (purged after 30 days by the systemd timer `mailpanel-purge-deleted.timer`, which takes the age from
  the deletion time in the entry's name — `mv` keeps the maildir's old mtime, and servers without `cron` never run
  `/etc/cron.daily`) instead of `rm -rf`. The panel checks the exit code and shows
  a visible error when the files were not dealt with. Deleting an account removes, in one transaction, the aliases that
  point at it (alias rows with a *list* of targets are reported, not edited), its login history and its send counter,
  and records a tombstone: the name is not given out again for `deleted_name_hold_days` (default 365). `upgrade_1.1.0.sql`
  lower-cases existing addresses — note that `WHERE email <> LOWER(email)` finds nothing (case-insensitive column),
  it needs `BINARY` — and stops before any change when two accounts differ only by case.
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
  `/etc/sudoers` and `/etc/sudoers.d/*` and removed on request (`scripts/mailpanel-sudoers-cleanup.sh`: reads rules as
  logical lines, so a multi-line `Cmnd_Alias` is removed whole; comments them out, `visudo -cf`, backup, and restores
  everything it changed if `visudo -c` on the whole configuration fails). Every rule has exact arguments now:
  `doveadm quota` and `mailpanel-scan.sh` `^regex$` (the latter kept a `*`), `update-postfix-limits.sh` and
  `mailpanel-delete-user.sh` `""` or `^regex$` (they used to accept any arguments). The rules file is installed under a
  name sudo ignores, moved into place and the WHOLE configuration checked with `visudo -c` (previous file restored on
  failure); the alias is named `ROUNDCONTROL_F2B`, because a second definition of the same alias in another file (1.0.x
  installs may have `MAILPANEL_F2B`) is a syntax error that disables sudo for everyone.

Changed — hardening:

- **File ownership:** code is `root:root` (755 / 644); the PHP user only reads it; `temp/` and `logs/` (and Roundcube's
  `log_dir` outside the tree) are the only writable directories; copies with secrets (`config.inc.php.bak*`,
  `config.old.php`, `.env.*`) become `root:root 600` (`install.sh` sections 1b and 7, README). `.htaccess` now really blocks `scripts/`, `system/`, `sql/`, `sql_backup/`,
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
