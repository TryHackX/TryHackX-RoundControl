#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * Instalator warstwy aplikacyjnej panelu (CLI).
 * Generuje .env, importuje schemat bazy i zakłada pierwszego administratora.
 * Integracje systemowe (sudoers, helper Fail2Ban — od 1.0.1 jail.local należy do roota —,
 * Postfix, plugin password) robi osobny install.sh (uruchamiany jako root).
 *
 * Użycie:  php install.php
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Ten instalator działa tylko z linii poleceń (php install.php).\n");
}

$root = __DIR__;

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
    // ukryj wpisywane hasło, jeśli terminal wspiera stty
    $hasStty = @shell_exec('stty 2>/dev/null') !== null;
    if ($hasStty) { shell_exec('stty -echo'); }
    $line = rtrim((string) fgets(STDIN), "\r\n");
    if ($hasStty) { shell_exec('stty echo'); fwrite(STDOUT, "\n"); }
    return $line;
}

function askYes(string $q, bool $default = true): bool {
    $d = $default ? 'T/n' : 't/N';
    $a = strtolower(ask($q . " ($d)"));
    if ($a === '') return $default;
    return $a[0] === 't' || $a[0] === 'y';
}

/**
 * Dzieli plik SQL na pojedyncze statementy z obsługą `DELIMITER` (procedury),
 * komentarzy `--` i stringów — PDO::exec() nie radzi sobie z wieloma
 * statementami i znakiem $$ w jednym wywołaniu.
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
        // pomiń całoliniowe komentarze poza buforem statementu
        if ($buffer === '' && ($trim === '' || str_starts_with($trim, '--') || str_starts_with($trim, '#'))) {
            continue;
        }
        // zmiana delimitera (składnia klienta mysql, nie serwera)
        if (preg_match('/^DELIMITER\s+(\S+)\s*$/i', $trim, $m)) {
            $delimiter = $m[1];
            continue;
        }
        $buffer .= $line . "\n";
        // statement kończy się delimiterem na końcu linii
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
out("\033[1m  Panel Roundcube — instalator aplikacji\033[0m");
hr();

/* --- 1. Wymagania --- */
out("Sprawdzam wymagania…");
$phpOk = PHP_VERSION_ID >= 80100;
$phpOk ? ok('PHP ' . PHP_VERSION . ' (≥ 8.1)') : err('Wymagane PHP ≥ 8.1, masz ' . PHP_VERSION);
foreach (['pdo_mysql', 'mbstring'] as $ext) {
    extension_loaded($ext) ? ok("rozszerzenie $ext") : err("brak rozszerzenia $ext");
}
if (!$phpOk) { err('Przerwano — zaktualizuj PHP.'); exit(1); }

/* --- 2. .env --- */
hr();
$envPath = "$root/.env";
$examplePath = "$root/.env.example";
if (file_exists($envPath) && !askYes('.env już istnieje — nadpisać?', false)) {
    out('Zostawiam istniejący .env.');
    $env = parse_ini_file($envPath, false, INI_SCANNER_RAW) ?: [];
    $dsn  = $env['PANEL_DB_DSN'] ?? '';
    $dbUser = $env['PANEL_DB_USER'] ?? '';
    $dbPass = $env['PANEL_DB_PASS'] ?? '';
} else {
    out('Konfiguracja połączenia z bazą (schemat `mailserver`):');
    $host = ask('Host bazy', '127.0.0.1');
    $name = ask('Nazwa bazy', 'mailserver');
    $dbUser = ask('Użytkownik DB panelu', 'mailpanel');
    $dbPass = askSecret('Hasło użytkownika DB');
    $env0 = ask('APP_ENV (prod/dev)', 'prod');
    while (!in_array($env0, ['prod', 'dev'], true)) {
        err('Dozwolone wartości: prod, dev.');
        $env0 = ask('APP_ENV (prod/dev)', 'prod');
    }
    $tz = ask('Strefa czasowa', 'Europe/Warsaw');
    while (!in_array($tz, DateTimeZone::listIdentifiers(), true)) {
        err("Nieznana strefa czasowa: $tz (przykład: Europe/Warsaw, UTC).");
        $tz = ask('Strefa czasowa', 'Europe/Warsaw');
    }
    $trusted = ask('Zaufane IP skanera (po przecinku, opcjonalnie)', '');
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
    // plik z sekretem nigdy nie może powstać jako world-readable —
    // uprawnienia ustawiamy PRZED zapisem treści (touch + chmod), nie po
    $old = umask(0027);
    if (!file_exists($envPath)) { touch($envPath); }
    @chmod($envPath, 0640);
    file_put_contents($envPath, implode("\n", $lines) . "\n");
    umask($old);
    // uruchomione przez root (sudo php install.php) plik byłby root:root 640 i panel (www-data) nie mógłby go czytać
    // aż do install.sh sekcja 7 — ustawiamy grupę od razu, jeśli się da
    $chgrpDone = false;
    if (function_exists('posix_getuid') && posix_getuid() === 0 && function_exists('posix_getgrnam') && posix_getgrnam('www-data') !== false) {
        $chgrpDone = @chgrp($envPath, 'www-data');
    }
    ok('.env zapisany (0640' . ($chgrpDone ? ', grupa www-data' : '; pamiętaj: chown root:www-data .env') . ')');
    out('   Najlepiej przenieś go poza katalog aplikacji:  install -d -o root -g www-data -m 750 /etc/mailpanel && mv .env /etc/mailpanel/panel.env');
}

/* --- 3. Połączenie z bazą --- */
hr();
out('Łączę się z bazą…');
try {
    $pdo = new PDO($dsn, $dbUser, $dbPass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    ok('Połączenie OK');
} catch (Throwable $e) {
    err('Nie mogę połączyć się z bazą: ' . $e->getMessage());
    err('Utwórz użytkownika DB i nadaj granty (patrz README), potem uruchom ponownie.');
    exit(1);
}

/* --- 4. Schemat --- */
$schema = "$root/sql/panel_schema.sql";
if (file_exists($schema) && askYes('Zaimportować schemat bazy (sql/panel_schema.sql)?')) {
    $statements = splitSqlStatements((string) file_get_contents($schema));
    $done = 0; $failed = 0;
    foreach ($statements as $stmt) {
        try {
            $pdo->exec($stmt);
            $done++;
        } catch (Throwable $e) {
            $failed++;
            err('Statement #' . ($done + $failed) . ': ' . $e->getMessage());
        }
    }
    if ($failed === 0) {
        ok("Schemat zaimportowany ($done statementów; IF NOT EXISTS — istniejące dane nietknięte)");
    } else {
        err("$failed/" . ($done + $failed) . ' statementów nie przeszło — możesz też zaimportować ręcznie:');
        out('  mysql mailserver < sql/panel_schema.sql');
    }
}

/* --- 5. Pierwszy administrator --- */
hr();
if (askYes('Utworzyć / zaktualizować konto administratora panelu?')) {
    $adminEmail = strtolower(trim(ask('E-mail administratora')));
    while (!filter_var($adminEmail, FILTER_VALIDATE_EMAIL)) {
        err('Nieprawidłowy e-mail.'); $adminEmail = strtolower(trim(ask('E-mail administratora')));
    }
    // jedna polityka z panelem (Password::meetsAdminPolicy): min. 12 znaków, wielka litera, cyfra, znak specjalny
    $adminPolicy = static fn(string $p): bool => mb_strlen($p) >= 12 && preg_match('/[A-Z]/', $p) === 1
        && preg_match('/\d/', $p) === 1 && preg_match('/[^a-zA-Z0-9]/', $p) === 1;
    $p1 = askSecret('Hasło administratora (min. 12 znaków, wielka litera, cyfra, znak specjalny)');
    while (!$adminPolicy($p1)) { err('Hasło za słabe (min. 12 znaków, wielka litera, cyfra, znak specjalny).'); $p1 = askSecret('Hasło administratora'); }
    $p2 = askSecret('Powtórz hasło');
    if ($p1 !== $p2) { err('Hasła różnią się — pomijam tworzenie admina.'); }
    else {
        $hash = password_hash($p1, PASSWORD_ARGON2ID);
        $pdo->prepare(
            'INSERT INTO panel_admins (email, pwd_hash, created_at) VALUES (?, ?, NOW())
             ON DUPLICATE KEY UPDATE pwd_hash = VALUES(pwd_hash)'
        )->execute([$adminEmail, $hash]);
        ok("Administrator $adminEmail gotowy (hash Argon2id).");
    }
}

/* --- 6. Domena --- */
hr();
$domains = $pdo->query('SELECT name FROM virtual_domains')->fetchAll(PDO::FETCH_COLUMN);
if ($domains) {
    out('Domeny w bazie: ' . implode(', ', $domains));
} elseif (askYes('Brak domen — dodać pierwszą teraz?')) {
    $dom = ask('Nazwa domeny (np. example.org)');
    if ($dom !== '') {
        $pdo->prepare('INSERT IGNORE INTO virtual_domains (name) VALUES (?)')->execute([$dom]);
        ok("Domena $dom dodana.");
    }
}

/* --- Podsumowanie --- */
hr();
ok('Warstwa aplikacji gotowa.');
out('');
out('Następne kroki:');
out('  1) Integracje systemowe (jako root):   sudo bash install.sh');
out('     (sekcja 0: baza + uprawnienia per tabela z sql/panel_grants.sql; sekcje 2-3: helper Fail2Ban + sudoers z dokładnymi');
out('      argumentami, jail.local = root:root 644; sekcja 7: kod root:root, tylko temp/ i logs/ zapisywalne dla www-data,');
out('      .env 640 root:www-data; sekcja 9: wpięcie panelu w config.inc.php; sekcja 10: drop-in php-fpm ReadWritePaths)');
out('     F2B_JAIL_LOCAL w .env zostaw /etc/fail2ban/jail.local — tylko ten plik obsługuje helper.');
out('  2) Zaloguj się do panelu przez portal (admin → Panel Admina).');
out('');
out('Wskazówka (świeży serwer): jeśli user DB jeszcze nie istniał, uruchom');
out('najpierw "sudo bash install.sh" i odpowiedz TAK na sekcję 0 (baza+schemat),');
out('a potem wróć do install.php.');
out('');
