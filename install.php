#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * Application-layer installer of TryHackX RoundControl (CLI).
 * Writes .env, imports the database schema, stores the default interface language and creates the first administrator.
 * System integrations (sudoers, the Fail2Ban helper — jail.local belongs to root since 1.0.1 —, Postfix, the password
 * plugin) are done by install.sh (run as root).
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
        'env_exists'      => '.env already exists — overwrite it?',
        'env_keep'        => 'Keeping the existing .env.',
        'db_conf'         => 'Database connection (schema `mailserver`):',
        'db_host'         => 'Database host',
        'db_name'         => 'Database name',
        'db_user'         => 'Database user of the panel',
        'db_pass'         => 'Password of the database user',
        'app_env'         => 'APP_ENV (prod/dev)',
        'app_env_bad'     => 'Allowed values: prod, dev.',
        'tz'              => 'Time zone',
        'tz_bad'          => 'Unknown time zone: :tz (examples: Europe/London, UTC).',
        'trusted'         => 'Trusted IPs for the scanner and the limits (comma separated, optional)',
        'env_saved_group' => '.env written (0640, group :g)',
        'env_saved_nogrp' => '.env written (0640; remember: chown root:<PHP pool user of the panel> .env)',
        'env_move'        => '   Better move it out of the application directory:  install -d -o root -g :g -m 750 /etc/mailpanel && mv .env /etc/mailpanel/panel.env',
        'db_connecting'   => 'Connecting to the database…',
        'db_ok'           => 'Connection OK',
        'db_fail'         => 'Cannot connect to the database: :e',
        'db_fail_hint'    => 'Create the database user and grant the privileges (see README), then run this again.',
        'schema_q'        => 'Import the database schema (sql/panel_schema.sql)?',
        'schema_stmt_err' => 'Statement #:n: :e',
        'schema_ok'       => 'Schema imported (:n statements; IF NOT EXISTS — existing data untouched)',
        'schema_fail'     => ':failed of :total statements failed — you can also import it by hand:',
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
        'domains_list'    => 'Domains in the database: :list',
        'domain_q'        => 'No domains yet — add the first one now?',
        'domain_name'     => 'Domain name (e.g. example.org)',
        'domain_ok'       => 'Domain :d added.',
        'done'            => 'Application layer ready.',
        'next'            => 'Next steps:',
        'next_1'          => '  1) System integrations (as root):   sudo bash install.sh',
        'next_1a'         => '     (section 0: database + per-table grants from sql/panel_grants.sql; section 1b: a SEPARATE PHP-FPM pool [mailweb]',
        'next_1b'         => '      for Roundcube and the panel; sections 2-3: the Fail2Ban helper + sudoers with exact arguments for mailweb,',
        'next_1c'         => '      jail.local = root:root 644; section 7: code root:root, only temp/ and logs/ writable for mailweb, .env 640',
        'next_1d'         => '      root:mailweb; section 9: wiring the panel into config.inc.php; section 10: php-fpm drop-in ReadWritePaths;',
        'next_1e'         => '      section 12: /var/lib/roundcontrol/lang for languages added in the panel)',
        'next_1f'         => '     Keep F2B_JAIL_LOCAL in .env at /etc/fail2ban/jail.local — that is the only file the helper handles.',
        'next_2'          => '  2) Log in to the panel through the portal (admin → Admin Panel).',
        'hint_1'          => 'Tip (fresh server): if the database user did not exist yet, run',
        'hint_2'          => '"sudo bash install.sh" first and answer yes to section 0 (database + schema),',
        'hint_3'          => 'then come back to install.php.',
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
        'env_exists'      => '.env już istnieje — nadpisać?',
        'env_keep'        => 'Zostawiam istniejący .env.',
        'db_conf'         => 'Konfiguracja połączenia z bazą (schemat `mailserver`):',
        'db_host'         => 'Host bazy',
        'db_name'         => 'Nazwa bazy',
        'db_user'         => 'Użytkownik DB panelu',
        'db_pass'         => 'Hasło użytkownika DB',
        'app_env'         => 'APP_ENV (prod/dev)',
        'app_env_bad'     => 'Dozwolone wartości: prod, dev.',
        'tz'              => 'Strefa czasowa',
        'tz_bad'          => 'Nieznana strefa czasowa: :tz (przykład: Europe/Warsaw, UTC).',
        'trusted'         => 'Zaufane IP skanera i limitów (po przecinku, opcjonalnie)',
        'env_saved_group' => '.env zapisany (0640, grupa :g)',
        'env_saved_nogrp' => '.env zapisany (0640; pamiętaj: chown root:<użytkownik puli PHP panelu> .env)',
        'env_move'        => '   Najlepiej przenieś go poza katalog aplikacji:  install -d -o root -g :g -m 750 /etc/mailpanel && mv .env /etc/mailpanel/panel.env',
        'db_connecting'   => 'Łączę się z bazą…',
        'db_ok'           => 'Połączenie OK',
        'db_fail'         => 'Nie mogę połączyć się z bazą: :e',
        'db_fail_hint'    => 'Utwórz użytkownika DB i nadaj granty (patrz README), potem uruchom ponownie.',
        'schema_q'        => 'Zaimportować schemat bazy (sql/panel_schema.sql)?',
        'schema_stmt_err' => 'Statement #:n: :e',
        'schema_ok'       => 'Schemat zaimportowany (:n statementów; IF NOT EXISTS — istniejące dane nietknięte)',
        'schema_fail'     => ':failed z :total statementów nie przeszło — możesz też zaimportować ręcznie:',
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
        'domains_list'    => 'Domeny w bazie: :list',
        'domain_q'        => 'Brak domen — dodać pierwszą teraz?',
        'domain_name'     => 'Nazwa domeny (np. example.org)',
        'domain_ok'       => 'Domena :d dodana.',
        'done'            => 'Warstwa aplikacji gotowa.',
        'next'            => 'Następne kroki:',
        'next_1'          => '  1) Integracje systemowe (jako root):   sudo bash install.sh',
        'next_1a'         => '     (sekcja 0: baza + uprawnienia per tabela z sql/panel_grants.sql; sekcja 1b: OSOBNA pula PHP-FPM [mailweb]',
        'next_1b'         => '      dla Roundcube i panelu; sekcje 2-3: helper Fail2Ban + sudoers z dokładnymi argumentami dla mailweb,',
        'next_1c'         => '      jail.local = root:root 644; sekcja 7: kod root:root, tylko temp/ i logs/ zapisywalne dla mailweb, .env 640',
        'next_1d'         => '      root:mailweb; sekcja 9: wpięcie panelu w config.inc.php; sekcja 10: drop-in php-fpm ReadWritePaths;',
        'next_1e'         => '      sekcja 12: /var/lib/roundcontrol/lang na języki dodane w panelu)',
        'next_1f'         => '     F2B_JAIL_LOCAL w .env zostaw /etc/fail2ban/jail.local — tylko ten plik obsługuje helper.',
        'next_2'          => '  2) Zaloguj się do panelu przez portal (admin → Panel Admina).',
        'hint_1'          => 'Wskazówka (świeży serwer): jeśli użytkownik DB jeszcze nie istniał, uruchom',
        'hint_2'          => 'najpierw "sudo bash install.sh" i odpowiedz tak na sekcję 0 (baza + schemat),',
        'hint_3'          => 'a potem wróć do install.php.',
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

function ask(string $q, string $default = ''): string {
    fwrite(STDOUT, $q . ($default !== '' ? " [$default]" : '') . ': ');
    $line = rtrim((string) fgets(STDIN), "\r\n");
    return $line === '' ? $default : $line;
}

function askSecret(string $q): string {
    fwrite(STDOUT, $q . ': ');
    // hide the typed password when the terminal supports stty
    $hasStty = @shell_exec('stty 2>/dev/null') !== null;
    if ($hasStty) { shell_exec('stty -echo'); }
    $line = rtrim((string) fgets(STDIN), "\r\n");
    if ($hasStty) { shell_exec('stty echo'); fwrite(STDOUT, "\n"); }
    return $line;
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

out("");
out("\033[1m  " . L('title') . "\033[0m");
hr();

/* --- 1. Requirements --- */
out(L('req_check'));
$phpOk = PHP_VERSION_ID >= 80100;
$phpOk ? ok(L('php_ok', ['v' => PHP_VERSION])) : err(L('php_old', ['v' => PHP_VERSION]));
foreach (['pdo_mysql', 'mbstring'] as $ext) {
    extension_loaded($ext) ? ok(L('ext_ok', ['ext' => $ext])) : err(L('ext_missing', ['ext' => $ext]));
}
if (!$phpOk) { err(L('aborted_php')); exit(1); }

/* --- 2. .env --- */
hr();
$envPath = "$root/.env";
if (file_exists($envPath) && !askYes(L('env_exists'), false)) {
    out(L('env_keep'));
    $env = parse_ini_file($envPath, false, INI_SCANNER_RAW) ?: [];
    $dsn  = $env['PANEL_DB_DSN'] ?? '';
    $dbUser = $env['PANEL_DB_USER'] ?? '';
    $dbPass = $env['PANEL_DB_PASS'] ?? '';
} else {
    out(L('db_conf'));
    $host = ask(L('db_host'), '127.0.0.1');
    $name = ask(L('db_name'), 'mailserver');
    $dbUser = ask(L('db_user'), 'mailpanel');
    $dbPass = askSecret(L('db_pass'));
    $env0 = ask(L('app_env'), 'prod');
    while (!in_array($env0, ['prod', 'dev'], true)) {
        err(L('app_env_bad'));
        $env0 = ask(L('app_env'), 'prod');
    }
    $tzDefault = $LANG === 'pl' ? 'Europe/Warsaw' : 'UTC';
    $tz = ask(L('tz'), $tzDefault);
    while (!in_array($tz, DateTimeZone::listIdentifiers(), true)) {
        err(L('tz_bad', ['tz' => $tz]));
        $tz = ask(L('tz'), $tzDefault);
    }
    $trusted = ask(L('trusted'), '');
    $dsn = "mysql:host=$host;dbname=$name;charset=utf8mb4";

    $lines = [
        'APP_ENV=' . $env0,
        'APP_TIMEZONE=' . $tz,
        'PANEL_DB_DSN="' . $dsn . '"',
        'PANEL_DB_USER=' . $dbUser,
        'PANEL_DB_PASS=' . $dbPass,
        'F2B_JAIL_LOCAL=/etc/fail2ban/jail.local',
        'SCAN_TRUSTED_IPS=' . $trusted,
    ];
    // a file with a secret must never be created world-readable — the mode is set BEFORE the content is written
    $old = umask(0027);
    if (!file_exists($envPath)) { touch($envPath); }
    @chmod($envPath, 0640);
    file_put_contents($envPath, implode("\n", $lines) . "\n");
    umask($old);
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
    out(L('env_move', ['g' => $envGroup]));
}

/* --- 3. Database connection --- */
hr();
out(L('db_connecting'));
try {
    $pdo = new PDO($dsn, $dbUser, $dbPass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    ok(L('db_ok'));
} catch (Throwable $e) {
    err(L('db_fail', ['e' => $e->getMessage()]));
    err(L('db_fail_hint'));
    exit(1);
}

/* --- 4. Schema --- */
$schema = "$root/sql/panel_schema.sql";
if (file_exists($schema) && askYes(L('schema_q'))) {
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
        out('  mysql mailserver < sql/panel_schema.sql');
    }
}

/* --- 5. Default interface language (1.3.1) ---
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

/* --- 6. First administrator --- */
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
        $pdo->prepare(
            'INSERT INTO panel_admins (email, pwd_hash, created_at) VALUES (?, ?, NOW())
             ON DUPLICATE KEY UPDATE pwd_hash = VALUES(pwd_hash)'
        )->execute([$adminEmail, $hash]);
        ok(L('admin_ok', ['email' => $adminEmail]));
    }
}

/* --- 7. Domain --- */
hr();
$domains = $pdo->query('SELECT name FROM virtual_domains')->fetchAll(PDO::FETCH_COLUMN);
if ($domains) {
    out(L('domains_list', ['list' => implode(', ', $domains)]));
} elseif (askYes(L('domain_q'))) {
    $dom = ask(L('domain_name'));
    if ($dom !== '') {
        $pdo->prepare('INSERT IGNORE INTO virtual_domains (name) VALUES (?)')->execute([$dom]);
        ok(L('domain_ok', ['d' => $dom]));
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
out(L('hint_1'));
out(L('hint_2'));
out(L('hint_3'));
out('');
