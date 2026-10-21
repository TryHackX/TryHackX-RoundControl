#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * Application-layer installer of TryHackX RoundControl (CLI).
 * Writes .env, imports the database schema, stores the default interface language and creates the first administrator.
 * System integrations (sudoers, the Fail2Ban helper — jail.local belongs to root since 1.0.1 —, Postfix, the password
 * plugin) are done by install.sh (run as root).
 *
 * Order on a fresh server: `sudo bash install.sh` first — its section 0 creates the database and the accounts (it
 * prints the mailpanel password once), imports the schema and sets the per-table grants as root — then
 * `sudo php install.php` from the Roundcube directory. The panel account has no CREATE privilege and may not add
 * domains, so this installer imports the schema only when the account can, and otherwise prints the commands for the
 * database administrator. It tests the connection before it writes anything, and says ".env written" only after the
 * file was written.
 *
 * Usage:  php install.php [--lang=en|pl]
 *   --lang      language of THIS installer (English by default; without the flag an interactive terminal is asked first)
 *   --help      this text
 *
 * The installer's texts live in the small catalogue below (English + Polish), not in app/lang: the installer runs as
 * root on the command line before .env and the database exist, and its texts are not part of the interface language
 * packs that administrators translate and upload in the panel.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("This installer runs only from the command line (php install.php).\n");
}

$root = __DIR__;

// catalogue:begin — installer texts; keys identical in both languages (tests/i18n_test.php checks it)
$TXT = [
    'en' => [
        'usage'           => "Usage: php install.php [--lang=en|pl]\n  --lang   language of this installer (default: en)\n  --help   this text",
        'bad_lang'        => 'Unknown language ":lang" — use --lang=en or --lang=pl.',
        'title'           => 'TryHackX RoundControl — application installer',
        'req_check'       => 'Checking requirements…',
        'php_ok'          => 'PHP :v (≥ 8.1)',
        'php_old'         => 'PHP ≥ 8.1 is required, you have :v',
        'ext_ok'          => 'extension :ext',
        'ext_missing'     => 'missing extension :ext',
        'aborted_php'     => 'Aborted — upgrade PHP.',
        'aborted_ext'     => 'Aborted — install the missing PHP extensions first (Debian: apt install php-mysql php-mbstring).',
        'input_ended'     => 'Standard input ended before the installer got all its answers — it stops here.',
        'env_target'      => 'Configuration file: :path',
        'env_not_rc'      => ':dir does not look like the Roundcube directory (no program/include/rcmail.php), but the panel reads /etc/mailpanel/panel.env or <Roundcube directory>/.env — run this installer from the Roundcube directory, or move the file there.',
        'env_exists'      => '.env already exists — overwrite it?',
        'env_keep'        => 'Keeping the existing .env.',
        'db_conf'         => 'Database connection (schema `mailserver`):',
        'db_host'         => 'Database host (a port after a colon, e.g. 127.0.0.1:3307)',
        'db_host_bad'     => 'Expected a host name or an address, optionally followed by a colon and a port (e.g. 127.0.0.1 or db.example.org:3307).',
        'db_name'         => 'Database name',
        'db_name_bad'     => 'Letters, digits and _ only.',
        'db_user'         => 'Database user of the panel',
        'db_user_bad'     => 'Letters, digits, dots, - and _ only.',
        'db_pass'         => 'Password of the database user',
        'app_env'         => 'APP_ENV (prod/dev)',
        'app_env_bad'     => 'Allowed values: prod, dev.',
        'tz'              => 'Time zone',
        'tz_note'         => 'The default is the time zone of this server. Keep APP_TIMEZONE equal to "timezone" in /etc/mailpanel-policy.cf (install.sh section 4 writes the server zone there too), or the panel and the daily send limit disagree about "today".',
        'tz_bad'          => 'Unknown time zone: :tz (examples: Europe/London, UTC).',
        'trusted'         => 'Trusted IPs for the scanner, the limits and the sign-in CAPTCHA (comma separated, optional)',
        'env_saved_group' => '.env written (0640, group :g)',
        'env_saved_nogrp' => '.env written (0640; remember: chown root:<PHP pool user of the panel> .env)',
        'env_write_failed'=> 'Could not write :path (:e) — nothing was saved. Run the installer as root: sudo php install.php',
        'env_move'        => '   Better move it out of the application directory:  install -d -o root -g :g -m 750 /etc/mailpanel && mv .env /etc/mailpanel/panel.env',
        'db_connecting'   => 'Connecting to the database…',
        'db_ok'           => 'Connection OK',
        'db_fail'         => 'Cannot connect to the database: :e',
        'db_fail_hint'    => 'Nothing was written. Check the host, the database name, the user and the password.',
        'hint_1'          => 'Fresh server? Run "sudo bash install.sh" first and answer y to section 0: it creates the database and the',
        'hint_2'          => 'accounts (it prints the mailpanel password once), imports the schema and sets the grants. Then run this',
        'hint_3'          => 'installer again and enter that password.',
        'db_nodb'         => 'The connection names no database (dbname=… in PANEL_DB_DSN).',
        'schema_q'        => 'Import the database schema (sql/panel_schema.sql)?',
        'schema_stmt_err' => 'Statement #:n: :e',
        'schema_ok'       => 'Schema imported (:n statements; IF NOT EXISTS — existing data untouched)',
        'schema_fail'     => ':failed of :total statements failed — you can also import it by hand:',
        'schema_present'  => 'Schema present. :user has no CREATE privilege, as sql/panel_grants.sql intends; schema updates run as the database administrator (install.sh section 0 does it):',
        'schema_missing'  => 'The panel tables are missing in :db and :user may not create them (no CREATE privilege). As the database administrator (install.sh section 0 does the same):',
        'schema_rerun'    => 'Then run this installer again.',
        'ui_lang_q'       => 'Default interface language of the portal and the panel (en/pl)',
        'ui_lang_bad'     => 'Allowed values: en, pl.',
        'ui_lang_saved'   => 'Default interface language: :name (visitors can switch; change it later in Settings → Interface languages)',
        'ui_lang_failed'  => 'Could not store the default language (:e) — set it later in Settings → Interface languages.',
        'admin_q'         => 'Create / update the panel administrator account?',
        'admin_email'     => 'Administrator e-mail',
        'admin_email_bad' => 'Invalid e-mail address.',
        'admin_pass'      => 'Administrator password (min. 12 characters, an upper-case letter, a digit, a special character)',
        'admin_pass_weak' => 'Password too weak (min. 12 characters, an upper-case letter, a digit, a special character).',
        'admin_pass_again'=> 'Administrator password',
        'admin_pass2'     => 'Repeat the password',
        'admin_mismatch'  => 'The passwords differ — skipping the administrator.',
        'admin_ok'        => 'Administrator :email ready (Argon2id hash).',
        'admin_failed'    => 'Could not store the administrator (:e). As the database administrator, in: mysql :db',
        'domains_list'    => 'Domains in the database: :list',
        'domains_failed'  => 'Could not read the domains (:e).',
        'domain_q'        => 'No domains yet — add the first one now?',
        'domain_name'     => 'Domain name (e.g. example.org; an IDN in its xn-- form)',
        'domain_bad'      => 'Not a mail domain name: letters, digits, - and dots, with at least one dot, at most :max characters.',
        'domain_ok'       => 'Domain :d added.',
        'domain_denied'   => ':user may not add domains (:e), as sql/panel_grants.sql intends. As the database administrator, in: mysql :db',
        'done'            => 'Application layer ready.',
        'next'            => 'Next steps:',
        'next_1'          => '  1) System integrations (as root), if not done yet: sudo bash install.sh — in any order with this installer,',
        'next_1a'         => '     except section 0 (database, accounts, schema, grants), which comes first; running it again is safe.',
        'next_1b'         => '     Sections: 1b own PHP-FPM pool [mailweb]; 2-3 the Fail2Ban helper, sudoers with exact arguments, jail.local;',
        'next_1c'         => '     7 code root:root, only temp/ and logs/ writable for mailweb, .env 640 root:mailweb; 9 hooks the panel into',
        'next_1d'         => '     config.inc.php (panel_config.php, the plugins custom_auth and password); 10 php-fpm drop-in ReadWritePaths;',
        'next_1e'         => '     12 /var/lib/roundcontrol/lang for languages added in the panel.',
        'next_1f'         => '     Keep F2B_JAIL_LOCAL in .env at /etc/fail2ban/jail.local — that is the only file the helper handles.',
        'next_2'          => '  2) Log in to the panel through the portal (admin → Admin Panel).',
        'yes_no_y'        => 'Y/n',
        'yes_no_n'        => 'y/N',
        'lang_name_en'    => 'English',
        'lang_name_pl'    => 'Polish',
    ],
    'pl' => [
        'usage'           => "Użycie: php install.php [--lang=en|pl]\n  --lang   język tego instalatora (domyślnie: en)\n  --help   ten tekst",
        'bad_lang'        => 'Nieznany język „:lang” — użyj --lang=en albo --lang=pl.',
        'title'           => 'TryHackX RoundControl — instalator aplikacji',
        'req_check'       => 'Sprawdzam wymagania…',
        'php_ok'          => 'PHP :v (≥ 8.1)',
        'php_old'         => 'Wymagane PHP ≥ 8.1, masz :v',
        'ext_ok'          => 'rozszerzenie :ext',
        'ext_missing'     => 'brak rozszerzenia :ext',
        'aborted_php'     => 'Przerwano — zaktualizuj PHP.',
        'aborted_ext'     => 'Przerwano — najpierw zainstaluj brakujące rozszerzenia PHP (Debian: apt install php-mysql php-mbstring).',
        'input_ended'     => 'Standardowe wejście skończyło się, zanim instalator dostał wszystkie odpowiedzi — kończy pracę.',
        'env_target'      => 'Plik konfiguracji: :path',
        'env_not_rc'      => ':dir nie wygląda na katalog Roundcube (brak program/include/rcmail.php), a panel czyta /etc/mailpanel/panel.env albo <katalog Roundcube>/.env — uruchom instalator z katalogu Roundcube albo przenieś tam plik.',
        'env_exists'      => '.env już istnieje — nadpisać?',
        'env_keep'        => 'Zostawiam istniejący .env.',
        'db_conf'         => 'Konfiguracja połączenia z bazą (schemat `mailserver`):',
        'db_host'         => 'Host bazy (port po dwukropku, np. 127.0.0.1:3307)',
        'db_host_bad'     => 'Oczekiwano nazwy hosta albo adresu, opcjonalnie z dwukropkiem i portem (np. 127.0.0.1 albo db.example.org:3307).',
        'db_name'         => 'Nazwa bazy',
        'db_name_bad'     => 'Tylko litery, cyfry i _.',
        'db_user'         => 'Użytkownik DB panelu',
        'db_user_bad'     => 'Tylko litery, cyfry, kropki, - i _.',
        'db_pass'         => 'Hasło użytkownika DB',
        'app_env'         => 'APP_ENV (prod/dev)',
        'app_env_bad'     => 'Dozwolone wartości: prod, dev.',
        'tz'              => 'Strefa czasowa',
        'tz_note'         => 'Domyślnie strefa czasowa tego serwera. Trzymaj APP_TIMEZONE równe "timezone" w /etc/mailpanel-policy.cf (install.sh sekcja 4 też wpisuje tam strefę serwera), inaczej panel i dzienny limit wysyłki różnie liczą "dziś".',
        'tz_bad'          => 'Nieznana strefa czasowa: :tz (przykład: Europe/Warsaw, UTC).',
        'trusted'         => 'Zaufane IP skanera, limitów i CAPTCHA przy logowaniu (po przecinku, opcjonalnie)',
        'env_saved_group' => '.env zapisany (0640, grupa :g)',
        'env_saved_nogrp' => '.env zapisany (0640; pamiętaj: chown root:<użytkownik puli PHP panelu> .env)',
        'env_write_failed'=> 'Nie mogę zapisać :path (:e) — nic nie zapisano. Uruchom instalator jako root: sudo php install.php',
        'env_move'        => '   Najlepiej przenieś go poza katalog aplikacji:  install -d -o root -g :g -m 750 /etc/mailpanel && mv .env /etc/mailpanel/panel.env',
        'db_connecting'   => 'Łączę się z bazą…',
        'db_ok'           => 'Połączenie OK',
        'db_fail'         => 'Nie mogę połączyć się z bazą: :e',
        'db_fail_hint'    => 'Nic nie zapisano. Sprawdź host, nazwę bazy, użytkownika i hasło.',
        'hint_1'          => 'Świeży serwer? Uruchom najpierw "sudo bash install.sh" i odpowiedz y na sekcję 0: tworzy bazę i konta',
        'hint_2'          => '(hasło mailpanel wypisuje jeden raz), importuje schemat i nadaje uprawnienia. Potem uruchom ten instalator',
        'hint_3'          => 'ponownie i wpisz to hasło.',
        'db_nodb'         => 'Połączenie nie wskazuje bazy (dbname=… w PANEL_DB_DSN).',
        'schema_q'        => 'Zaimportować schemat bazy (sql/panel_schema.sql)?',
        'schema_stmt_err' => 'Statement #:n: :e',
        'schema_ok'       => 'Schemat zaimportowany (:n statementów; IF NOT EXISTS — istniejące dane nietknięte)',
        'schema_fail'     => ':failed z :total statementów nie przeszło — możesz też zaimportować ręcznie:',
        'schema_present'  => 'Schemat jest. :user nie ma uprawnienia CREATE, zgodnie z sql/panel_grants.sql; aktualizacje schematu robi administrator bazy (install.sh sekcja 0):',
        'schema_missing'  => 'W bazie :db brak tabel panelu, a :user nie może ich utworzyć (brak uprawnienia CREATE). Jako administrator bazy (to samo robi install.sh sekcja 0):',
        'schema_rerun'    => 'Potem uruchom ten instalator ponownie.',
        'ui_lang_q'       => 'Domyślny język interfejsu portalu i panelu (en/pl)',
        'ui_lang_bad'     => 'Dozwolone wartości: en, pl.',
        'ui_lang_saved'   => 'Domyślny język interfejsu: :name (odwiedzający mogą go zmienić; później: Ustawienia → Języki interfejsu)',
        'ui_lang_failed'  => 'Nie udało się zapisać języka domyślnego (:e) — ustaw go później w Ustawienia → Języki interfejsu.',
        'admin_q'         => 'Utworzyć / zaktualizować konto administratora panelu?',
        'admin_email'     => 'E-mail administratora',
        'admin_email_bad' => 'Nieprawidłowy e-mail.',
        'admin_pass'      => 'Hasło administratora (min. 12 znaków, wielka litera, cyfra, znak specjalny)',
        'admin_pass_weak' => 'Hasło za słabe (min. 12 znaków, wielka litera, cyfra, znak specjalny).',
        'admin_pass_again'=> 'Hasło administratora',
        'admin_pass2'     => 'Powtórz hasło',
        'admin_mismatch'  => 'Hasła różnią się — pomijam tworzenie admina.',
        'admin_ok'        => 'Administrator :email gotowy (hash Argon2id).',
        'admin_failed'    => 'Nie udało się zapisać administratora (:e). Jako administrator bazy, w: mysql :db',
        'domains_list'    => 'Domeny w bazie: :list',
        'domains_failed'  => 'Nie udało się odczytać domen (:e).',
        'domain_q'        => 'Brak domen — dodać pierwszą teraz?',
        'domain_name'     => 'Nazwa domeny (np. example.org; domena IDN w postaci xn--)',
        'domain_bad'      => 'To nie jest nazwa domeny pocztowej: litery, cyfry, - i kropki, co najmniej jedna kropka, najwyżej :max znaków.',
        'domain_ok'       => 'Domena :d dodana.',
        'domain_denied'   => ':user nie może dodawać domen (:e), zgodnie z sql/panel_grants.sql. Jako administrator bazy, w: mysql :db',
        'done'            => 'Warstwa aplikacji gotowa.',
        'next'            => 'Następne kroki:',
        'next_1'          => '  1) Integracje systemowe (jako root), jeśli jeszcze nie: sudo bash install.sh — w dowolnej kolejności z tym instalatorem,',
        'next_1a'         => '     poza sekcją 0 (baza, konta, schemat, uprawnienia), która idzie pierwsza; ponowne uruchomienie jest bezpieczne.',
        'next_1b'         => '     Sekcje: 1b osobna pula PHP-FPM [mailweb]; 2-3 helper Fail2Ban, sudoers z dokładnymi argumentami, jail.local;',
        'next_1c'         => '     7 kod root:root, tylko temp/ i logs/ zapisywalne dla mailweb, .env 640 root:mailweb; 9 wpięcie panelu w',
        'next_1d'         => '     config.inc.php (panel_config.php, wtyczki custom_auth i password); 10 drop-in php-fpm ReadWritePaths;',
        'next_1e'         => '     12 /var/lib/roundcontrol/lang na języki dodane w panelu.',
        'next_1f'         => '     F2B_JAIL_LOCAL w .env zostaw /etc/fail2ban/jail.local — tylko ten plik obsługuje helper.',
        'next_2'          => '  2) Zaloguj się do panelu przez portal (admin → Panel Admina).',
        'yes_no_y'        => 'T/n',
        'yes_no_n'        => 't/N',
        'lang_name_en'    => 'angielski',
        'lang_name_pl'    => 'polski',
    ],
];
// catalogue:end

/* ------------------------------------------------------------------ arguments and installer language */
$LANG = 'en';
$langGiven = false;
foreach (array_slice($argv, 1) as $arg) {
    if ($arg === '--help' || $arg === '-h') {
        fwrite(STDOUT, $TXT['en']['usage'] . "\n");
        exit(0);
    }
    if ($arg === '--dump-catalogue') {   // for tests/i18n_test.php
        fwrite(STDOUT, (string) json_encode($TXT, JSON_UNESCAPED_UNICODE) . "\n");
        exit(0);
    }
    if (preg_match('/^--lang=(.*)$/', $arg, $m)) {
        if (!isset($TXT[$m[1]])) {
            fwrite(STDERR, strtr($TXT['en']['bad_lang'], [':lang' => $m[1]]) . "\n");
            exit(2);
        }
        $LANG = $m[1];
        $langGiven = true;
    }
}

function L(string $key, array $p = []): string
{
    global $TXT, $LANG;
    $s = $TXT[$LANG][$key] ?? $TXT['en'][$key] ?? $key;
    $r = [];
    foreach ($p as $k => $v) {
        $r[':' . $k] = (string) $v;
    }
    return strtr($s, $r);
}

function out(string $s): void { fwrite(STDOUT, $s . "\n"); }
function err(string $s): void { fwrite(STDERR, "\033[31m" . $s . "\033[0m\n"); }
function ok(string $s): void  { fwrite(STDOUT, "\033[32m✓\033[0m " . $s . "\n"); }
function hr(): void { out(str_repeat('─', 60)); }

/** One line of standard input; when the input has ended (a pipe ran out of answers) the installer stops. */
function inputLine(): string {
    $line = fgets(STDIN);
    if ($line === false) {
        fwrite(STDOUT, "\n");
        err(L('input_ended'));
        exit(1);
    }
    return rtrim($line, "\r\n");
}

function ask(string $q, string $default = ''): string {
    fwrite(STDOUT, $q . ($default !== '' ? " [$default]" : '') . ': ');
    $line = inputLine();
    return $line === '' ? $default : $line;
}

function askSecret(string $q): string {
    fwrite(STDOUT, $q . ': ');
    // hide the typed password when the terminal supports stty (not on Windows: cmd.exe has no stty and no /dev/null)
    $hasStty = PHP_OS_FAMILY !== 'Windows' && @shell_exec('stty 2>/dev/null') !== null;
    if ($hasStty) { shell_exec('stty -echo'); }
    $line = fgets(STDIN);
    if ($hasStty) { shell_exec('stty echo'); fwrite(STDOUT, "\n"); }
    if ($line === false) {
        fwrite(STDOUT, "\n");
        err(L('input_ended'));
        exit(1);
    }
    return rtrim($line, "\r\n");
}

/** Yes / no: y, yes, t, tak (both languages) — Enter = the default. */
function askYes(string $q, bool $default = true): bool {
    $a = strtolower(ask($q . ' (' . L($default ? 'yes_no_y' : 'yes_no_n') . ')'));
    if ($a === '') return $default;
    return $a[0] === 'y' || $a[0] === 't';
}

// Without --lang an interactive terminal is asked first (bilingual question); a script / pipe gets English.
$tty = function_exists('stream_isatty') ? @stream_isatty(STDIN) : false;
if (!$langGiven && $tty) {
    $a = strtolower(trim(ask('Language / Język (en/pl)', 'en')));   // bilingual question (before the choice)
    $LANG = isset($TXT[$a]) ? $a : 'en';
}

/**
 * Splits an SQL file into statements, handling `DELIMITER` (procedures), `--` comments and strings — PDO::exec()
 * cannot run several statements and $$ in one call.
 *
 * @return string[]
 */
function splitSqlStatements(string $sql): array
{
    $statements = [];
    $delimiter  = ';';
    $buffer     = '';

    foreach (preg_split('/\r\n|\r|\n/', $sql) as $line) {
        $trim = trim($line);
        // whole-line comments outside a statement
        if ($buffer === '' && ($trim === '' || str_starts_with($trim, '--') || str_starts_with($trim, '#'))) {
            continue;
        }
        // delimiter change (mysql client syntax, not the server's)
        if (preg_match('/^DELIMITER\s+(\S+)\s*$/i', $trim, $m)) {
            $delimiter = $m[1];
            continue;
        }
        $buffer .= $line . "\n";
        // a statement ends with the delimiter at the end of a line
        if ($trim !== '' && substr($trim, -strlen($delimiter)) === $delimiter) {
            $stmt = trim(substr(trim($buffer), 0, -strlen($delimiter)));
            if ($stmt !== '') {
                $statements[] = $stmt;
            }
            $buffer = '';
        }
    }
    if (trim($buffer) !== '') {
        $statements[] = trim($buffer);
    }
    return $statements;
}

/** A string literal for the SQL this installer prints for the database administrator. */
function sqlStr(string $s): string
{
    return "'" . str_replace(['\\', "'"], ['\\\\', "''"], $s) . "'";
}

/**
 * The time zone of this server: the /etc/localtime link, /etc/timezone or timedatectl; UTC when none of them names a
 * known zone. install.sh section 4 renders the same zone into /etc/mailpanel-policy.cf, so the panel ("sent today")
 * and the policy service (the daily counter) agree about the day.
 */
function systemTimezone(): string
{
    $cands = [];
    $link = @readlink('/etc/localtime');
    if (is_string($link) && preg_match('~zoneinfo/(?:posix/|right/)?(.+)$~', $link, $m) === 1) {
        $cands[] = $m[1];
    }
    if (@is_file('/etc/timezone')) {
        $cands[] = trim((string) @file_get_contents('/etc/timezone'));
    }
    if (PHP_OS_FAMILY !== 'Windows' && function_exists('shell_exec') && (@is_executable('/usr/bin/timedatectl') || @is_executable('/bin/timedatectl'))) {
        $cands[] = trim((string) @shell_exec('timedatectl show -p Timezone --value 2>/dev/null'));
    }
    foreach ($cands as $z) {
        if (in_array($z, ['Etc/UTC', 'Etc/UCT', 'Etc/Universal', 'Etc/Zulu', 'Etc/GMT', 'Etc/GMT0', 'UCT', 'Universal', 'Zulu', 'GMT'], true)) {
            return 'UTC';
        }
        if (in_array($z, DateTimeZone::listIdentifiers(), true)) {
            return $z;
        }
    }
    return 'UTC';
}

/** "host", "host:port" or "[IPv6]:port" -> the host part of a PDO DSN; null when the input is none of those. */
function dsnHost(string $in): ?string
{
    if (preg_match('/^\[([0-9A-Fa-f:.]+)\](?::(\d{1,5}))?$/D', $in, $m) !== 1
        && preg_match('/^([A-Za-z0-9._-]+)(?::(\d{1,5}))?$/D', $in, $m) !== 1) {
        return null;
    }
    $port = $m[2] ?? '';
    if ($port !== '' && ((int) $port < 1 || (int) $port > 65535)) {
        return null;
    }
    return 'host=' . $m[1] . ($port !== '' ? ';port=' . (int) $port : '');
}

/** A mail domain the portal can create addresses in, short enough for virtual_domains.name (VARCHAR(50)). */
function validDomain(string $d): bool
{
    return strlen($d) <= 50 && preg_match('/^[a-z0-9-]+(\.[a-z0-9-]+)+$/D', $d) === 1
        && filter_var($d, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) !== false
        && filter_var('postmaster@' . $d, FILTER_VALIDATE_EMAIL) !== false;
}

/** May the connected account create tables in the current database? (information_schema lists its own privileges.) */
function canCreateTables(PDO $pdo): bool
{
    try {
        $cu = (string) $pdo->query('SELECT CURRENT_USER()')->fetchColumn();
        $at = strrpos($cu, '@');
        if ($at === false) {
            return false;
        }
        $grantee = "'" . substr($cu, 0, $at) . "'@'" . substr($cu, $at + 1) . "'";
        $st = $pdo->prepare("SELECT COUNT(*) FROM information_schema.USER_PRIVILEGES WHERE GRANTEE = ? AND PRIVILEGE_TYPE = 'CREATE'");
        $st->execute([$grantee]);
        if ((int) $st->fetchColumn() > 0) {
            return true;
        }
        $st = $pdo->prepare("SELECT COUNT(*) FROM information_schema.SCHEMA_PRIVILEGES
                             WHERE GRANTEE = ? AND PRIVILEGE_TYPE = 'CREATE' AND DATABASE() LIKE TABLE_SCHEMA");
        $st->execute([$grantee]);
        return (int) $st->fetchColumn() > 0;
    } catch (Throwable) {
        return false;
    }
}

/** Are the tables this installer writes (settings, administrators, domains) in the current database? */
function panelTablesPresent(PDO $pdo): bool
{
    try {
        $n = $pdo->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()
                          AND TABLE_NAME IN ('app_settings', 'panel_admins', 'virtual_domains')")->fetchColumn();
        return (int) $n === 3;
    } catch (Throwable) {
        return false;
    }
}

/** The commands that import the schema and set the grants as the database administrator (install.sh section 0). */
function rootSchemaCommands(string $root, string $db): void
{
    out('  mysql ' . $db . ' < ' . $root . '/sql/panel_schema.sql');
    out('  mysql < ' . $root . '/sql/panel_grants.sql');
    out('  mysql < ' . $root . '/sql/postlogin_grants.sql');
}

out("");
out("\033[1m  " . L('title') . "\033[0m");
hr();

/* --- 1. Requirements --- */
out(L('req_check'));
$phpOk = PHP_VERSION_ID >= 80100;
$phpOk ? ok(L('php_ok', ['v' => PHP_VERSION])) : err(L('php_old', ['v' => PHP_VERSION]));
$extOk = true;
foreach (['pdo_mysql', 'mbstring'] as $ext) {
    extension_loaded($ext) ? ok(L('ext_ok', ['ext' => $ext])) : err(L('ext_missing', ['ext' => $ext]));
    $extOk = $extOk && extension_loaded($ext);
}
if (!$phpOk) { err(L('aborted_php')); exit(1); }
if (!$extOk) { err(L('aborted_ext')); exit(1); }

/* --- 2. .env: read the existing one, or ask (written only after the connection works, step 3) ---
   The panel reads /etc/mailpanel/panel.env when it exists (Env::defaultPath), otherwise <Roundcube directory>/.env;
   a re-run edits the file the panel really reads. The existing file is read by the panel's own parser. */
hr();
$envPath = is_file('/etc/mailpanel/panel.env') ? '/etc/mailpanel/panel.env' : "$root/.env";
out(L('env_target', ['path' => $envPath]));
if ($envPath === "$root/.env" && !is_file("$root/program/include/rcmail.php")) {
    err(L('env_not_rc', ['dir' => $root]));
}
$newEnv = null;
if (file_exists($envPath) && !askYes(L('env_exists'), false)) {
    out(L('env_keep'));
    require_once $root . '/app/src/Support/Env.php';
    \App\Support\Env::load($envPath);
    $dsn    = (string) \App\Support\Env::get('PANEL_DB_DSN', '');
    $dbUser = (string) \App\Support\Env::get('PANEL_DB_USER', '');
    $dbPass = (string) \App\Support\Env::get('PANEL_DB_PASS', '');
} else {
    out(L('db_conf'));
    $host = trim(ask(L('db_host'), '127.0.0.1'));
    while (($hostPart = dsnHost($host)) === null) {
        err(L('db_host_bad'));
        $host = trim(ask(L('db_host'), '127.0.0.1'));
    }
    $name = trim(ask(L('db_name'), 'mailserver'));
    while (preg_match('/^[A-Za-z0-9_]{1,64}$/D', $name) !== 1) {
        err(L('db_name_bad'));
        $name = trim(ask(L('db_name'), 'mailserver'));
    }
    $dbUser = trim(ask(L('db_user'), 'mailpanel'));
    while (preg_match('/^[A-Za-z0-9._-]{1,80}$/D', $dbUser) !== 1) {
        err(L('db_user_bad'));
        $dbUser = trim(ask(L('db_user'), 'mailpanel'));
    }
    $dbPass = askSecret(L('db_pass'));
    $env0 = ask(L('app_env'), 'prod');
    while (!in_array($env0, ['prod', 'dev'], true)) {
        err(L('app_env_bad'));
        $env0 = ask(L('app_env'), 'prod');
    }
    out(L('tz_note'));
    $tzDefault = systemTimezone();
    $tz = ask(L('tz'), $tzDefault);
    while (!in_array($tz, DateTimeZone::listIdentifiers(), true)) {
        err(L('tz_bad', ['tz' => $tz]));
        $tz = ask(L('tz'), $tzDefault);
    }
    $trusted = ask(L('trusted'), '');
    $dsn = "mysql:$hostPart;dbname=$name;charset=utf8mb4";

    $newEnv = implode("\n", [
        'APP_ENV=' . $env0,
        'APP_TIMEZONE=' . $tz,
        'PANEL_DB_DSN="' . $dsn . '"',
        'PANEL_DB_USER=' . $dbUser,
        'PANEL_DB_PASS=' . $dbPass,
        'F2B_JAIL_LOCAL=/etc/fail2ban/jail.local',
        'SCAN_TRUSTED_IPS=' . $trusted,
    ]) . "\n";
}

/* --- 3. Database connection — before anything is written: a wrong password must not end up in .env --- */
hr();
out(L('db_connecting'));
try {
    $pdo = new PDO($dsn, $dbUser, $dbPass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    ok(L('db_ok'));
} catch (Throwable $e) {
    err(L('db_fail', ['e' => $e->getMessage()]));
    err(L('db_fail_hint'));
    out('');
    out(L('hint_1'));
    out(L('hint_2'));
    out(L('hint_3'));
    exit(1);
}
$dbName = (string) $pdo->query('SELECT DATABASE()')->fetchColumn();
if ($dbName === '') {
    err(L('db_nodb'));
    exit(1);
}

/* --- 4. Write .env (now that the values work) --- */
if ($newEnv !== null) {
    // a file with a secret must never be created world-readable — the mode is set BEFORE the content is written,
    // and ".env written" is printed only for a file that holds exactly the new content
    $old = umask(0027);
    $written = false;
    if ((is_file($envPath) || @touch($envPath)) && is_file($envPath)
        && (@chmod($envPath, 0640) || PHP_OS_FAMILY === 'Windows')) {
        $written = @file_put_contents($envPath, $newEnv) === strlen($newEnv);
    }
    umask($old);
    if (!$written) {
        err(L('env_write_failed', ['path' => $envPath, 'e' => error_get_last()['message'] ?? '?']));
        exit(1);
    }
    // run as root (sudo php install.php) the file would be root:root 640 and unreadable for the panel until install.sh —
    // set the group now: the PHP pool user of the panel (PANEL_USER, mailweb by default from install.sh section 1b),
    // or www-data while that user does not exist yet (install.sh sections 1b/7 fix the group anyway)
    $envGroup = (string) (getenv('PANEL_USER') ?: 'mailweb');
    $chgrpDone = false;
    if (function_exists('posix_getuid') && posix_getuid() === 0 && function_exists('posix_getgrnam')) {
        if (posix_getgrnam($envGroup) === false) {
            $envGroup = 'www-data';
        }
        if (posix_getgrnam($envGroup) !== false) {
            $chgrpDone = @chgrp($envPath, $envGroup);
        }
    }
    ok($chgrpDone ? L('env_saved_group', ['g' => $envGroup]) : L('env_saved_nogrp'));
    if ($envPath === "$root/.env") {
        out(L('env_move', ['g' => $envGroup]));
    }
}

/* --- 5. Schema ---
   install.sh section 0 imports it as root; the panel account (sql/panel_grants.sql) may not CREATE or ALTER anything,
   so the import runs here only for an account that can create tables. Otherwise: the commands for the administrator. */
hr();
$schema = "$root/sql/panel_schema.sql";
if (!canCreateTables($pdo)) {
    if (panelTablesPresent($pdo)) {
        out(L('schema_present', ['user' => $dbUser]));
        rootSchemaCommands($root, $dbName);
    } else {
        err(L('schema_missing', ['db' => $dbName, 'user' => $dbUser]));
        rootSchemaCommands($root, $dbName);
        out(L('schema_rerun'));
        exit(1);
    }
} elseif (file_exists($schema) && askYes(L('schema_q'))) {
    $statements = splitSqlStatements((string) file_get_contents($schema));
    $done = 0; $failed = 0;
    foreach ($statements as $stmt) {
        try {
            $pdo->exec($stmt);
            $done++;
        } catch (Throwable $e) {
            $failed++;
            err(L('schema_stmt_err', ['n' => $done + $failed, 'e' => $e->getMessage()]));
        }
    }
    if ($failed === 0) {
        ok(L('schema_ok', ['n' => $done]));
    } else {
        err(L('schema_fail', ['failed' => $failed, 'total' => $done + $failed]));
        rootSchemaCommands($root, $dbName);
    }
}
if (!panelTablesPresent($pdo)) {
    err(L('schema_missing', ['db' => $dbName, 'user' => $dbUser]));
    rootSchemaCommands($root, $dbName);
    out(L('schema_rerun'));
    exit(1);
}

/* --- 6. Default interface language (1.3.1) ---
   Stored explicitly in app_settings.default_language. A re-run on an existing installation offers the stored value,
   so it never flips a running site's language; without any stored value the code default is English. */
hr();
try {
    $cur = $pdo->query("SELECT v FROM app_settings WHERE k = 'default_language'")->fetchColumn();
    $uiDefault = in_array($cur, ['en', 'pl'], true) ? (string) $cur : 'en';
    $ui = strtolower(trim(ask(L('ui_lang_q'), $uiDefault)));
    while (!in_array($ui, ['en', 'pl'], true)) {
        err(L('ui_lang_bad'));
        $ui = strtolower(trim(ask(L('ui_lang_q'), $uiDefault)));
    }
    $pdo->prepare("INSERT INTO app_settings (k, v) VALUES ('default_language', ?) ON DUPLICATE KEY UPDATE v = VALUES(v)")->execute([$ui]);
    ok(L('ui_lang_saved', ['name' => L('lang_name_' . $ui)]));
} catch (Throwable $e) {
    err(L('ui_lang_failed', ['e' => $e->getMessage()]));
}

/* --- 7. First administrator --- */
hr();
if (askYes(L('admin_q'))) {
    $adminEmail = strtolower(trim(ask(L('admin_email'))));
    while (!filter_var($adminEmail, FILTER_VALIDATE_EMAIL)) {
        err(L('admin_email_bad')); $adminEmail = strtolower(trim(ask(L('admin_email'))));
    }
    // one policy with the panel (Password::meetsAdminPolicy): min. 12 characters, upper-case letter, digit, special character
    $adminPolicy = static fn(string $p): bool => mb_strlen($p) >= 12 && preg_match('/[A-Z]/', $p) === 1
        && preg_match('/\d/', $p) === 1 && preg_match('/[^a-zA-Z0-9]/', $p) === 1;
    $p1 = askSecret(L('admin_pass'));
    while (!$adminPolicy($p1)) { err(L('admin_pass_weak')); $p1 = askSecret(L('admin_pass_again')); }
    $p2 = askSecret(L('admin_pass2'));
    if ($p1 !== $p2) { err(L('admin_mismatch')); }
    else {
        $hash = password_hash($p1, PASSWORD_ARGON2ID);
        $sql = 'INSERT INTO panel_admins (email, pwd_hash, created_at) VALUES (?, ?, NOW())
             ON DUPLICATE KEY UPDATE pwd_hash = VALUES(pwd_hash)';
        try {
            $pdo->prepare($sql)->execute([$adminEmail, $hash]);
            ok(L('admin_ok', ['email' => $adminEmail]));
        } catch (Throwable $e) {
            err(L('admin_failed', ['e' => $e->getMessage(), 'db' => $dbName]));
            out('  INSERT INTO panel_admins (email, pwd_hash, created_at) VALUES (' . sqlStr($adminEmail) . ', ' . sqlStr($hash)
                . ', NOW()) ON DUPLICATE KEY UPDATE pwd_hash = VALUES(pwd_hash);');
        }
    }
}

/* --- 8. Domain ---
   The panel account may only read virtual_domains (sql/panel_grants.sql): without an account that may write it, the
   statement for the database administrator is printed instead. Checked here: a host name with a dot (the portal's
   addresses must pass FILTER_VALIDATE_EMAIL), lower case, at most 50 characters (the column). */
hr();
try {
    $domains = $pdo->query('SELECT name FROM virtual_domains')->fetchAll(PDO::FETCH_COLUMN);
} catch (Throwable $e) {
    $domains = null;
    err(L('domains_failed', ['e' => $e->getMessage()]));
}
if ($domains) {
    out(L('domains_list', ['list' => implode(', ', $domains)]));
} elseif ($domains === [] && askYes(L('domain_q'))) {
    $dom = strtolower(trim(ask(L('domain_name'))));
    while ($dom !== '' && !validDomain($dom)) {
        err(L('domain_bad', ['max' => 50]));
        $dom = strtolower(trim(ask(L('domain_name'))));
    }
    if ($dom !== '') {
        try {
            $pdo->prepare('INSERT INTO virtual_domains (name) VALUES (?)')->execute([$dom]);
            ok(L('domain_ok', ['d' => $dom]));
        } catch (Throwable $e) {
            out(L('domain_denied', ['user' => $dbUser, 'e' => $e->getMessage(), 'db' => $dbName]));
            out('  INSERT INTO virtual_domains (name) VALUES (' . sqlStr($dom) . ');');
        }
    }
}

/* --- Summary --- */
hr();
ok(L('done'));
out('');
out(L('next'));
foreach (['next_1', 'next_1a', 'next_1b', 'next_1c', 'next_1d', 'next_1e', 'next_1f', 'next_2'] as $k) {
    out(L($k));
}
out('');
