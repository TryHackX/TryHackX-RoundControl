# TryHackX Mail Panel — nakładka administracyjna dla Roundcube

Samodzielna nakładka na Roundcube (czysty PHP 8.1+, **zero zależności Composera**)
dająca własny portal logowania/rejestracji, panel administracyjny kont pocztowych
i warstwę bezpieczeństwa spinającą Postfix + Dovecot + Fail2Ban.

- **Portal logowania** (`portal.php`) — wspólny ekran do webmaila i panelu
  (flow: e-mail → wybór usługi → hasło) + **publiczna rejestracja kont** z limitami
  per-IP, polityką haseł i opcjonalną reCAPTCHA,
- **Panel administratora** (`panel/`) — konta (wyszukiwarka po e-mail/IP/dacie/quocie/%użycia,
  quota w jednostkach dwójkowych, dzienny limit wysyłki per konto, reset hasła,
  bany, usuwanie ze skrzynką z dysku), blokady IP (lokalne + Fail2Ban) ze skanerem
  podejrzanych IP, logi (rejestracje, logowania adminów, audyt) z uniwersalną
  wyszukiwarką, edycja Fail2Ban i limitów Postfixa,
- **Samoobsługa hasła** — plugin Roundcube `password` (SQL → `$6$` do virtual_users),
- **Dzienny limit wysyłki** — policy service Postfiksa (per konto / globalny),
- **Zabezpieczenia**: CSRF, rate-limiting, blokady brute-force, reCAPTCHA
  fail-closed, CSP bez inline-JS, utwardzone sesje, wzorzec PRG, audyt akcji.

## Szybka instalacja

Zakłada działający Roundcube 1.6+ i bazę `mailserver` (Postfix/Dovecot virtual users).
Skopiuj zawartość pakietu do katalogu Roundcube (zachowując drzewko), potem:

```bash
php install.php        # .env, import schematu, pierwszy administrator (Argon2id)
sudo bash install.sh   # skrypty, sudoers, Fail2Ban, policy service, plugin password
# na końcu: dołącz panel_config.php w config/config.inc.php (patrz krok 5)
```

Oba instalatory są **interaktywne i idempotentne** (bramka potwierdzenia per krok,
kopie zapasowe przed edycją). Szczegóły i instalacja ręczna — sekcje poniżej.

## Układ repozytorium

```
├── install.php / install.sh      # instalatory (aplikacja / system)
├── portal.php  app/  assets/     # nakładka: portal + panel (kod + szablony + CSS/JS)
├── panel/  config/panel_config.php
├── plugins/custom_auth/          # przekierowanie niezalogowanych do portalu
├── plugins/password/config.inc.php.example
├── scripts/                      # skrypty do /usr/local/bin (skaner, policy, delete-user…)
├── system/                       # szablony integracji (sudoers, fail2ban, postfix, policy.cf)
├── sql/panel_schema.sql          # schemat bazy (IF NOT EXISTS)
├── tests/run.php                 # testy (bezzależnościowy runner; dev)
├── .env.example  .gitignore  LICENSE
```

> **Sekrety poza repo:** `.env`, `plugins/password/config.inc.php`,
> `/etc/mailpanel-policy.cf` i zrzuty `all_settings/` są w `.gitignore`.
> Nigdy nie commituj realnych haseł ani kluczy DKIM.

---

## 1. Wymagania

| Składnik | Wersja / uwaga |
|---|---|
| System | **Debian 13 (trixie)** — cel instalatorów i szablonów (Dovecot 2.4, Postfix 3.10, Fail2Ban 1.1); na Windows/dev integracje systemowe same się wyłączają |
| Roundcube | 1.6+ (testowane na 1.7.2), zainstalowany i działający |
| PHP | 8.1+ z rozszerzeniami: pdo_mysql, sodium/argon2 (standard w dystrybucjach) |
| MariaDB / MySQL | baza `mailserver` (Postfix/Dovecot virtual users) |
| Apache | `mod_rewrite` + `AllowOverride All` dla katalogu Roundcube |
| HTTPS | wymagane na produkcji (sesja z flagą Secure, HSTS) |

Zakładamy, że Roundcube jest w **korzeniu domeny** (np. `/var/www/roundcube` →
`https://poczta.twojadomena.pl/`). Przy instalacji w podkatalogu popraw dwa
redirecty w `plugins/custom_auth/custom_auth.php` (`/portal.php` → pełna ścieżka).

## 2. Struktura pakietu (= struktura na serwerze)

Wszystko kopiujesz do katalogu głównego Roundcube, **zachowując drzewko**:

```
roundcube/                        ← katalog główny Twojego Roundcube
├── .htaccess                     ← PODMIEŃ (blokady app/, .env, sql_backup…)
├── .env                          ← UTWÓRZ z .env.example (sekrety, NIE z pakietu!)
├── portal.php                    ← NOWY: portal logowania/rejestracji
├── app/                          ← NOWY: cały kod aplikacji (blokowany z WWW)
│   ├── .htaccess                 ←   deny all
│   ├── bootstrap.php             ←   autoloader + .env
│   ├── web.php                   ←   kernel WWW (sesja, tryb błędów)
│   ├── src/                      ←   klasy (Auth, Panel, Portal, Security…)
│   └── templates/                ←   szablony HTML (portal + panel)
├── assets/                       ← NOWY: CSS/JS/ikony (jedyny publiczny katalog)
│   ├── design-system.css  portal.css  portal.js  panel.css  panel.js
│   └── vendor/remixicon/         ←   ikony self-hostowane (bez CDN)
├── panel/                        ← PODMIEŃ zawartość (3 pliki)
│   ├── index.php  logout.php  user_ips.php
├── config/
│   └── panel_config.php          ← NOWY/PODMIEŃ: wpięcie ustawień rc_* do RC
├── plugins/
│   └── custom_auth/              ← NOWY: przekierowanie niezalogowanych do portalu
├── sql/panel_schema.sql          ← (nie musi być na serwerze — do importu bazy)
└── tests/run.php                 ← (opcjonalnie; uruchamiaj tylko na dev)
```

Reszta Roundcube (`program/`, `skins/`, `vendor/`, `config/config.inc.php`…)
zostaje bez zmian — poza jedną linijką w kroku 5.

## 3. Krok 1 — Pliki

```bash
# z katalogu pakietu:
rsync -av --exclude README.md --exclude sql/ ./ /var/www/roundcube/
chown -R www-data:www-data /var/www/roundcube/app /var/www/roundcube/assets \
    /var/www/roundcube/portal.php /var/www/roundcube/panel /var/www/roundcube/plugins/custom_auth
```

Usuń pozostałości starych wersji panelu, jeśli istnieją (zastąpione przez `app/`):
`bootstrap.php` (w korzeniu), `panel/config.php`, `panel/_csrf.php`,
`panel/styles.css`, `panel/light-mode.css`, `panel/dark-mode.css`, `panel/style-index.css`.

## 4. Krok 2 — Baza danych

```bash
# 1) użytkownik DB dla panelu (EXECUTE potrzebne dla procedury sp_register_login)
mysql -u root -p <<'SQL'
CREATE DATABASE IF NOT EXISTS mailserver CHARACTER SET utf8mb4;
CREATE USER IF NOT EXISTS 'mailpanel'@'localhost' IDENTIFIED BY 'SILNE_HASLO';
GRANT SELECT, INSERT, UPDATE, DELETE, EXECUTE ON mailserver.* TO 'mailpanel'@'localhost';
FLUSH PRIVILEGES;
SQL

# 2) schemat (bezpieczny dla istniejących danych: IF NOT EXISTS / INSERT IGNORE)
mysql -u root -p mailserver < sql/panel_schema.sql
```

Następnie (jeśli świeża baza) dodaj domenę i pierwszego administratora:

```bash
php -r 'echo password_hash("TwojeHasloAdmina", PASSWORD_ARGON2ID), PHP_EOL;'
```

```sql
INSERT INTO virtual_domains (name) VALUES ('twojadomena.pl');
INSERT INTO panel_admins (email, pwd_hash, created_at)
VALUES ('admin@twojadomena.pl', '<HASH_Z_POWYŻSZEJ_KOMENDY>', NOW());
```

> **Dwa rodzaje haseł** (nie pomyl!):
> - `panel_admins.pwd_hash` — hasło do **panelu**, Argon2id (`$argon2id$...`),
> - `virtual_users.password` — hasło **skrzynki** (Dovecot), SHA-512 crypt (`$6$...`).
>   Konta zakładane przez rejestrację w portalu dostają je automatycznie.

## 5. Krok 3 — `.env` i wpięcie w Roundcube

```bash
cd /var/www/roundcube
cp .env.example .env && nano .env          # APP_ENV=prod + dane bazy
chown root:www-data .env && chmod 640 .env
```

W `config/config.inc.php`:

```php
// 1) dodaj pluginy do listy (password = samoobsługa zmiany hasła skrzynki):
$config['plugins'] = ['archive', 'zipdownload', 'newmail_notifier', 'custom_auth', 'password'];

// 2) na SAMYM KOŃCU pliku dołącz konfigurację panelu:
require_once __DIR__ . '/panel_config.php';
```

`panel_config.php` mapuje klucze `rc_*` z bazy (edytowalne w panelu) na opcje
Roundcube (opóźnienie wysyłki, limit odbiorców itd.) oraz wymusza
`force_https`, `ip_check`, `identities_level=3`.

### Samoobsługa zmiany hasła (plugin `password`)

Bez tego użytkownik nie zmieni hasła w webmailu. Plugin `password` jest w
komplecie Roundcube — trzeba go tylko skonfigurować (sterownik SQL → zapis
hasha `$6$` do `virtual_users`, zgodnie z Dovecotem). Instalacja skryptem:

```bash
bash scripts/install-password-plugin.sh    # tworzy usera DB roundcubepw + config
# potem ręcznie dodaj 'password' do $config['plugins'] (patrz wyżej)
```

Skrypt tworzy usera DB `roundcubepw` (tylko `SELECT, UPDATE ON
mailserver.virtual_users`) i `plugins/password/config.inc.php` (szablon:
`plugins/password/config.inc.php` w pakiecie). Kluczowe opcje: sterownik
`sql`, `password_algorithm = sha512-crypt`, pusty prefix (Dovecot trzyma `$6$`
bez `{SHA512-CRYPT}`), `password_confirm_current = true` (wymaga starego
hasła), min. długość 10 + wymagany znak niealfanumeryczny. Efekt: **Ustawienia
→ Hasło** w webmailu; admin dodatkowo resetuje hasła kont z panelu (karta
Użytkownicy → „Hasło").

> Uwaga: to hasło jest niezależne od hasła SSH konta systemowego. Zmiana hasła
> skrzynki nie dotyka `/etc/shadow` i odwrotnie.

## 6. Krok 4 — sudoers i uprawnienia (integracje systemowe)

Panel wykonuje operacje systemowe jako `www-data` przez `sudo`. Wpisz `sudo visudo`
i dodaj na końcu:

```
www-data ALL=(ALL) NOPASSWD: /usr/bin/fail2ban-client ping, /usr/bin/fail2ban-client status, /usr/bin/fail2ban-client status *, /usr/bin/fail2ban-client set * unbanip *, /usr/bin/fail2ban-client set * banip *, /usr/bin/fail2ban-client reload
www-data ALL=(ALL) NOPASSWD: /usr/bin/fail2ban-client get * banip *
www-data ALL=(ALL) NOPASSWD: /usr/bin/fail2ban-client set * bantime *
www-data ALL=(root) NOPASSWD: /usr/local/bin/update-postfix-limits.sh
www-data ALL=(root) NOPASSWD: /usr/local/bin/mailpanel-delete-user.sh
www-data ALL=(root) NOPASSWD: /usr/bin/doveadm -f flow quota get -u *
www-data ALL=(root) NOPASSWD: /usr/local/bin/mailpanel-scan.sh *
```

Co które daje (bez wpisu dana funkcja pokazuje czytelny komunikat, reszta działa):

| Wpis | Funkcja panelu |
|---|---|
| `fail2ban-client ping/status` | lista banów F2B w zakładce „Blokady IP" |
| `fail2ban-client set * unbanip *` | przycisk „Unban (F2B)" |
| `fail2ban-client set * banip *` | ręczna blokada IP + bany ze skanera |
| `fail2ban-client get * banip *` | kolumna „Wygasa" z pozostałym czasem bana |
| `fail2ban-client set * bantime *` | bany czasowe ze skanera (jail `panel-tempban`) |
| `fail2ban-client reload` | automatyczne przeładowanie po zapisie zakładki „Fail2Ban" |
| `update-postfix-limits.sh` | aplikowanie limitów Postfixa („Bezpieczeństwo") |
| `mailpanel-delete-user` | usuwanie plików skrzynki przy kasowaniu konta |
| `mailpanel-scan.sh *` | skaner podejrzanych IP (obsługuje 3. argument: `ssh`/`dovecot`/`postfix`) |

### Jaile panelu (blokady „na stałe" i czasowe)

Skaner i przycisk „Zarządzaj" używają dwóch dedykowanych jaili, których filtry
nigdy nie łapią nic automatycznie (przyjmują tylko ręczne `banip` z panelu).

> `install.sh` instaluje je jako **`/etc/fail2ban/jail.d/mailpanel.local`**
> (gotowy plik: `system/fail2ban/jail.d/mailpanel.local`) — Fail2Ban czyta
> `jail.d/*.local` automatycznie, a `jail.local` zostaje czysty dla jaili
> standardowych edytowanych z panelu. Ręcznie możesz je też dopisać do
> `jail.local` (wariant legacy) — ale nie w obu miejscach naraz:

```ini
# Jail dla blokad STAŁYCH z panelu (bantime = -1).
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

# Jail dla blokad CZASOWYCH z panelu (skaner: 1h..30 dni).
# Panel ustawia bantime bezpośrednio przed banip, więc każdy ban ma własny czas.
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

Filtry (`/etc/fail2ban/filter.d/panel-permaban.conf` i `panel-tempban.conf` —
w drugim podmień słowo `permaban` na `tempban`):

```ini
[Definition]
failregex = ^__mailpanel_permaban_never_match__ <HOST>$
ignoreregex =
```

Puste logi: `touch /var/log/mailpanel-permaban.log /var/log/mailpanel-tempban.log`,
po wszystkim `fail2ban-client reload`.

> **Fallback reloadu:** `install.sh` (sekcja 8) stawia dodatkowo timer systemd
> `mailpanel-f2b-reload` (`scripts/mailpanel-f2b-reload.php` +
> `system/systemd/*.{service,timer}`) — co minutę porównuje mtime `jail.local`
> i przeładowuje Fail2Ban, gdyby bezpośredni `sudo fail2ban-client reload`
> z panelu nie zadziałał. Stan trzyma w `/var/lib/mailpanel/f2b_state.json`.

Edycja konfiguracji w zakładce „Fail2Ban" wymaga dodatkowo prawa zapisu do pliku:

```bash
chgrp www-data /etc/fail2ban/jail.local
chmod 664 /etc/fail2ban/jail.local
```

Skrypty `/usr/local/bin/update-postfix-limits.sh` i `/usr/local/bin/mailpanel-delete-user`
to Twoje istniejące skrypty (te same, których używał stary panel). Panel rozpoznaje
ich dostępność automatycznie (sonda co 5 minut).

Uwaga: `APP_ENV=prod` w `.env` jest wymagane — w trybie `dev` integracje
systemowe są celowo wyłączone.

### Dzienny limit wysyłki (Postfix policy service)

Ustawienie „Limit wysyłki (na dzień)" oraz limity indywidualne (modal „Zmiana
Quota") egzekwuje osobny policy service Postfiksa — bez niego wartości są tylko
zapisywane w bazie. Instalacja:

```bash
# 1) skrypt polityki
install -o root -g root -m 755 scripts/mailpanel-policy-daily.php /usr/local/bin/

# 2) użytkownik DB o minimalnych uprawnieniach + licznik (tabela jest w panel_schema.sql)
mysql -u root -p <<'SQL'
CREATE USER IF NOT EXISTS 'mailpolicy'@'localhost' IDENTIFIED BY 'SILNE_HASLO_POLICY';
GRANT SELECT ON mailserver.virtual_users TO 'mailpolicy'@'localhost';
GRANT SELECT ON mailserver.app_settings  TO 'mailpolicy'@'localhost';
GRANT SELECT, INSERT, UPDATE ON mailserver.send_counters TO 'mailpolicy'@'localhost';
FLUSH PRIVILEGES;
SQL

# 3) konfiguracja (czytelna dla "nobody", pod którym działa spawn)
cat > /etc/mailpanel-policy.cf <<'CFG'
dsn = "mysql:host=127.0.0.1;dbname=mailserver;charset=utf8mb4"
user = mailpolicy
password = "SILNE_HASLO_POLICY"
timezone = Europe/Warsaw
CFG
chown root:nogroup /etc/mailpanel-policy.cf && chmod 640 /etc/mailpanel-policy.cf
```

W `/etc/postfix/main.cf`:

```
mua_data_restrictions = reject_unauth_pipelining, check_policy_service unix:private/policy-daily
```

W `/etc/postfix/master.cf` — w blokach `submission` i `submissions` dopisz
`-o smtpd_data_restrictions=$mua_data_restrictions`, a na końcu pliku dodaj usługę:

```
policy-daily unix  -       n       n       -       0       spawn
  user=nobody argv=/usr/bin/php /usr/local/bin/mailpanel-policy-daily.php
```

Po `postfix check && systemctl reload postfix` limit działa dla WSZYSTKICH
wysyłek uwierzytelnionych SASL (webmail Roundcube i klienci zewnętrzni jak
Thunderbird — oba idą przez port 587/465). Port 25 (poczta przychodząca) nie
jest dotykany. Awaria bazy = fail-open (poczta wychodzi, limit nieegzekwowany).
Semantyka limitów: konto `NULL` = globalny z panelu, `0` = bez limitu.

Nadawca odbitej wiadomości dostaje błąd `554 5.7.1` już w kliencie (webmail
pokazuje go przy „Wyślij"), a przy PIERWSZYM odbiciu danego dnia dodatkowo
grzecznościowy e-mail do własnej skrzynki (flaga `send_counters.notified`,
kopertowy nadawca `<>` — bez pętli zwrotek). Wyłączenie powiadomień:
`notify = 0` w `/etc/mailpanel-policy.cf`.

Test ręczny:

```bash
printf 'request=smtpd_access_policy\nsasl_username=user@twojadomena.pl\n\n' \
  | sudo -u nobody php /usr/local/bin/mailpanel-policy-daily.php   # -> action=DUNNO
```

## 7. Krok 5 — Apache

W vhostcie katalog Roundcube musi honorować `.htaccess`:

```apache
<Directory /var/www/roundcube>
    AllowOverride All
    Require all granted
</Directory>
```

> **Roundcube 1.7+** (webroot = `public_html/`): kod nakładki zostaje POZA
> webrootem, a do `public_html/` trafiają tylko symlinki — tworzy je
> `install.sh` (sekcja 7): `assets`, `portal.php`, `panel`, `.htaccess`.
> Dyrektywę `<Directory>` ustaw wtedy na `/var/www/roundcube/public_html`
> z `Options FollowSymLinks`.

`.htaccess` z pakietu blokuje dostęp WWW do: `app/`, `config/`, `logs/`, `temp/`,
`vendor/`, `sql_backup/`, `_legacy/`, `tests/`, plików `.env` i dokumentacji.

## 8. Weryfikacja po instalacji

| Test | Oczekiwany wynik |
|---|---|
| `https://domena/portal.php` | ekran logowania (ciemny motyw) |
| `https://domena/.env` | **403** |
| `https://domena/app/bootstrap.php` | **403** |
| `https://domena/config/panel_config.php` | **403** |
| logowanie e-mailem admina → „Panel Admina" | panel ze stylami i ikonami |
| zakładka „Blokady IP" | brak czerwonego ostrzeżenia o Fail2Ban |
| zakładka „Bezpieczeństwo" | brak ostrzeżenia o sudo dla Postfixa |
| logowanie zwykłym kontem → „Webmail" | skrzynka Roundcube |
| wejście na `https://domena/` bez sesji | redirect do portalu (plugin custom_auth) |

## 9. reCAPTCHA (opcjonalnie)

Panel → Ustawienia → Google reCAPTCHA: wklej Site Key + Secret Key (typ v2
„Nie jestem robotem") i zaznacz „Aktywne". Weryfikacja jest **fail-closed** —
błąd/timeout po stronie Google odrzuca próbę zamiast ją przepuścić.
Awaryjne wyłączenie z SQL: `UPDATE app_settings SET v='0' WHERE k='recaptcha_enabled';`

## 10. Tryb deweloperski (lokalnie, bez serwera pocztowego)

`.env`: `APP_ENV=dev` → logowanie do webmaila weryfikuje hasło względem hasha
`$6$` z `virtual_users` (bez IMAP), integracje systemowe wyłączone, pełne
komunikaty błędów. Testy: `php tests/run.php` (używa bazy z `.env`, sprząta po
sobie — uruchamiaj na dev, nie na produkcji).

## 11. Bezpieczeństwo — co jest w środku i o czym pamiętać

Wbudowane: CSRF na każdej mutacji (wszystkie mutacje przez POST + PRG),
rate-limiting logowania (konto i IP) z automatycznym banem, throttling
rejestracji per-IP, Argon2id dla adminów / SHA-512 crypt dla skrzynek,
CSP bez inline-JS (ikony self-hostowane), sesja `HttpOnly/SameSite/Secure` +
`use_strict_mode` + regeneracja ID po logowaniu + limit bezczynności 60 min,
nagłówki HSTS/X-Frame-Options/nosniff, dziennik audytowy akcji administratorów.

Twoje obowiązki:
- **kopie baz trzymaj poza webrootem** (nigdy w katalogu Roundcube),
- po migracji ze starego panelu **zrotuj** hasła DB i klucze reCAPTCHA, które
  były zaszyte w starych plikach (`panel/config.php` itd.),
- `des_key` Roundcube traktuj jak sekret (żyje w `config.inc.php`),
- aktualizuj Roundcube (`program/`, `vendor/`) niezależnie — pakiet nie
  modyfikuje rdzenia, więc aktualizacje RC nie nadpisują panelu.

## 12. Rozwiązywanie problemów

| Objaw | Przyczyna / rozwiązanie |
|---|---|
| Portal/panel bez stylów | cache przeglądarki (Ctrl+Shift+R) lub brak `assets/` na serwerze |
| Wszędzie 404/500 po wgraniu | brak `AllowOverride All` albo `.env` nieczytelny dla www-data |
| „DB Error" / błąd 500 na portalu | złe dane w `.env` (DSN/użytkownik/hasło) |
| Czerwony box „Brak dostępu do Fail2Ban" | brak wpisów sudoers albo `APP_ENV=dev` |
| „Brak uprawnień sudo" przy Postfix | brak wpisu sudoers dla `update-postfix-limits.sh` |
| Zapis Fail2Ban: „nie przeładowano usługi" | dodaj `fail2ban-client reload` do sudoers |
| Zakładka Fail2Ban: „brak uprawnień odczytu" | `chgrp www-data && chmod 664` na `jail.local` |
| Login webmail nie działa, panel działa | sprawdź `imap_host` w `config.inc.php` (to zwykłe logowanie RC) |
| Po zalogowaniu wraca do portalu | ciasteczka: wymagane HTTPS na prod (sesja ma flagę Secure) |
