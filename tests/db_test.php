<?php
declare(strict_types=1);

/**
 * Testy z bazą (MariaDB) 1.1.0 — w CAŁKOWICIE osobnej, tymczasowej bazie, którą tworzą i kasują:
 *
 *     MP_TEST_DB_DSN="mysql:host=127.0.0.1;port=3307" MP_TEST_DB_USER=root MP_TEST_DB_PASS= php tests/db_test.php
 *
 * (domyślnie 127.0.0.1:3307, root bez hasła — lokalny WAMP; bez dostępu do bazy test wypisuje SKIP i kończy się 0.)
 * Nie dotyka żadnej istniejącej bazy ani konta. Aplikacja łączy się jako tymczasowe konto z DOKŁADNIE tymi
 * uprawnieniami, które nadaje sql/panel_grants.sql (po podmianie nazw), więc test sprawdza także, że minimalne granty
 * wystarczają kodowi (portal, rejestracja, usuwanie, limity), a niedozwolone operacje są odrzucane.
 * Skrypty: polityka dziennego limitu (odbiorcy, atomowy licznik) uruchamiana jako osobny proces.
 */

$root = dirname(__DIR__);
$dsnBase = getenv('MP_TEST_DB_DSN') ?: 'mysql:host=127.0.0.1;port=3307';
$dbUser  = getenv('MP_TEST_DB_USER') !== false ? (string) getenv('MP_TEST_DB_USER') : 'root';
$dbPass  = getenv('MP_TEST_DB_PASS') !== false ? (string) getenv('MP_TEST_DB_PASS') : '';

$T = ['pass' => 0, 'fail' => 0, 'fails' => []];
function ok(bool $cond, string $label): void
{
    global $T;
    if ($cond) { $T['pass']++; } else { $T['fail']++; $T['fails'][] = $label; }
}
function eq(mixed $a, mixed $b, string $label): void
{
    ok($a === $b, $label . ($a === $b ? '' : ' (got=' . var_export($a, true) . ' exp=' . var_export($b, true) . ')'));
}
function group(string $name): void { echo "\n# $name\n"; }

try {
    $admin = new PDO($dsnBase, $dbUser, $dbPass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
} catch (Throwable $e) {
    echo "SKIP: brak dostępu do bazy testowej ({$e->getMessage()}) — ustaw MP_TEST_DB_DSN / MP_TEST_DB_USER / MP_TEST_DB_PASS\n";
    exit(0);
}

/** Dzieli plik SQL na instrukcje (obsługa DELIMITER, komentarzy --) — jak install.php. */
function splitSql(string $sql): array
{
    $out = []; $delim = ';'; $buf = '';
    foreach (preg_split('/\r\n|\r|\n/', $sql) as $line) {
        $t = trim($line);
        if ($buf === '' && ($t === '' || str_starts_with($t, '--') || str_starts_with($t, '#'))) { continue; }
        if (preg_match('/^DELIMITER\s+(\S+)\s*$/i', $t, $m)) { $delim = $m[1]; continue; }
        $buf .= $line . "\n";
        if ($t !== '' && substr($t, -strlen($delim)) === $delim) {
            $stmt = trim(substr(trim($buf), 0, -strlen($delim)));
            if ($stmt !== '') { $out[] = $stmt; }
            $buf = '';
        }
    }
    if (trim($buf) !== '') { $out[] = trim($buf); }
    return $out;
}
function runSql(PDO $pdo, string $sql): void { foreach (splitSql($sql) as $stmt) { $pdo->exec($stmt); } }

$suffix  = bin2hex(random_bytes(3));
$db      = 'mp_test_' . $suffix;
$uPanel  = 'mp_t_panel_' . $suffix;
$uPolicy = 'mp_t_policy_' . $suffix;
$uLogin  = 'mp_t_login_' . $suffix;
$pw      = bin2hex(random_bytes(12));
$tmp     = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'mp_db_' . $suffix;
mkdir($tmp, 0777, true);

$cleanup = static function () use ($admin, $db, $uPanel, $uPolicy, $uLogin, $tmp): void {
    try {
        $admin->exec("DROP DATABASE IF EXISTS `$db`");
        foreach ([$uPanel, $uPolicy, $uLogin] as $u) { $admin->exec("DROP USER IF EXISTS '$u'@'localhost'"); }
    } catch (Throwable $e) {
        fwrite(STDERR, "cleanup: " . $e->getMessage() . "\n");
    }
    foreach (glob($tmp . '/*') ?: [] as $f) { @unlink($f); }
    @rmdir($tmp);
};
register_shutdown_function($cleanup);

try {
    /* -------------------------------------------------------------- przygotowanie */
    $admin->exec("CREATE DATABASE `$db` CHARACTER SET utf8mb4");
    $admin->exec("USE `$db`");
    $schema = (string) file_get_contents($root . '/sql/panel_schema.sql');
    runSql($admin, $schema);

    // granty z plików repozytorium, z podmienionymi nazwami kont i bazy
    $mk = static function (string $file, array $map) use ($root): string {
        return strtr((string) file_get_contents($root . '/sql/' . $file), $map);
    };
    foreach ([$uPanel, $uPolicy, $uLogin] as $u) { $admin->exec("CREATE USER '$u'@'localhost' IDENTIFIED BY '$pw'"); }
    $names = ["'mailpanel'@'localhost'" => "'$uPanel'@'localhost'", "'mailpostlogin'@'localhost'" => "'$uLogin'@'localhost'", 'mailserver.' => "`$db`."];
    runSql($admin, $mk('panel_grants.sql', $names));
    runSql($admin, $mk('postlogin_grants.sql', $names));
    $admin->exec("GRANT SELECT ON `$db`.virtual_users TO '$uPolicy'@'localhost'");
    $admin->exec("GRANT SELECT ON `$db`.app_settings TO '$uPolicy'@'localhost'");
    $admin->exec("GRANT SELECT, INSERT, UPDATE ON `$db`.send_counters TO '$uPolicy'@'localhost'");

    // dane
    require_once $root . '/app/src/Security/Password.php';
    $adminHash = password_hash('Admin-Pass-12345!', PASSWORD_ARGON2ID);
    $userHash  = crypt('DevTest123!', '$6$' . 'saltsaltsaltsalt' . '$');
    $admin->exec("INSERT INTO virtual_domains (name) VALUES ('example.test')");
    $domId = (int) $admin->lastInsertId();
    $ins = $admin->prepare('INSERT INTO virtual_users (domain_id, password, email, quota_mb) VALUES (?, ?, ?, 50)');
    $ins->execute([$domId, $userHash, 'jan@example.test']);
    $ins->execute([$domId, $userHash, 'old.user@example.test']);
    $admin->prepare('INSERT INTO panel_admins (email, pwd_hash, created_at) VALUES (?, ?, NOW())')->execute(['boss@example.test', $adminHash]);
    // jak produkcja po aktualizacji do 1.3.1: polski zapisany jawnie (bez ustawienia obowiązuje angielski)
    $admin->exec("INSERT INTO app_settings (k, v) VALUES ('default_language', 'pl') ON DUPLICATE KEY UPDATE v = 'pl'");
    $al = $admin->prepare('INSERT INTO virtual_aliases (domain_id, source, destination) VALUES (?, ?, ?)');
    $al->execute([$domId, 'sales2@example.test', 'jan@example.test']);          // źródło aliasu = nazwa zajęta
    $al->execute([$domId, 'forward@example.test', 'ghost@example.test']);       // destynacja po skasowanym koncie
    $al->execute([$domId, 'list@example.test', 'jan@example.test, bob@elsewhere.example']);
    $al->execute([$domId, 'postmaster@example.test', 'jan@example.test']);

    file_put_contents($tmp . '/app.env', "APP_ENV=dev\nAPP_TIMEZONE=UTC\nPANEL_DB_DSN=\"$dsnBase;dbname=$db;charset=utf8mb4\"\n"
        . "PANEL_DB_USER=$uPanel\nPANEL_DB_PASS=$pw\nSCAN_TRUSTED_IPS=198.51.100.99\n");
    putenv('MAILPANEL_ENV_FILE=' . $tmp . '/app.env');
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_SERVER['REMOTE_ADDR']    = '203.0.113.50';
    $_SESSION = ['csrf' => 'TESTTOKEN'];
    require $root . '/app/bootstrap.php';

    $pdo = App\Support\Database::pdo();       // jako ograniczone konto aplikacji
    $call = static function (string $action, array $post, string $ip = '203.0.113.50'): array {
        $_SERVER['REMOTE_ADDR'] = $ip;
        $_POST = $post + ['_csrf' => 'TESTTOKEN'];
        return (new App\Portal\PortalController())->handle($action);
    };
    $count = static fn(string $sql, array $p = []): int => (function () use ($admin, $sql, $p) { $s = $admin->prepare($sql); $s->execute($p); return (int) $s->fetchColumn(); })();

    /* -------------------------------------------------------------- schemat i granty */
    group('schemat: idempotentny import, szerokość kolumny, granty');
    runSql($admin, $schema);          // drugi import nie może się wysypać
    ok(true, 'ponowny import schematu bez błędu');
    $col = $admin->query("SELECT CHARACTER_MAXIMUM_LENGTH FROM information_schema.COLUMNS WHERE TABLE_SCHEMA='$db' AND TABLE_NAME='virtual_users' AND COLUMN_NAME='password'")->fetchColumn();
    eq((int) $col, 255, 'virtual_users.password: VARCHAR(255)');
    $long = '$6$rounds=100000$' . str_repeat('a', 16) . '$' . str_repeat('b', 86);
    ok(strlen($long) > 106 && strlen($long) <= 255, 'hash z rounds= jest dłuższy niż 106 znaków (' . strlen($long) . ')');
    $admin->prepare('UPDATE virtual_users SET password = ? WHERE email = ?')->execute([$long, 'old.user@example.test']);
    $back = $admin->query("SELECT password FROM virtual_users WHERE email='old.user@example.test'")->fetchColumn();
    eq($back, $long, 'długi hash zapisany bez obcięcia');
    $admin->prepare('UPDATE virtual_users SET password = ? WHERE email = ?')->execute([$userHash, 'old.user@example.test']);

    // CREATE OR REPLACE PROCEDURE zachowuje EXECUTE (po DROP + CREATE uprawnienie przepadało i rejestracja logowań cicho
    // przestawała działać po każdym ponownym imporcie schematu) — drugi import schematu już się odbył, granty zostały
    $panelPdo = new PDO("$dsnBase;dbname=$db", $uPanel, $pw, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $panelPdo->prepare('CALL sp_register_login(?, ?)')->execute(['probe@example.test', '203.0.113.1']);
    ok(true, 'po PONOWNYM imporcie schematu mailpanel nadal może wywołać sp_register_login (granty zachowane)');
    runSql($admin, $mk('panel_grants.sql', $names));          // idempotentne: drugie nałożenie grantów nic nie psuje
    runSql($admin, $mk('postlogin_grants.sql', $names));
    $panelPdo->prepare('CALL sp_register_login(?, ?)')->execute(['probe@example.test', '203.0.113.1']);
    ok(true, 'po ponownym nałożeniu grantów (idempotentnie) nadal działa');
    $admin->exec("DELETE FROM user_login_events WHERE email='probe@example.test'");

    $denied = static function (PDO $p, string $sql, string $label): void {
        try { $p->exec($sql); ok(false, "$label: powinno być ODMOWIONE"); }
        catch (PDOException $e) { ok(in_array($e->errorInfo[1] ?? 0, [1142, 1143, 1044, 1370, 1227], true), "$label: odmowa (kod " . ($e->errorInfo[1] ?? '?') . ')'); }
    };
    $denied($panelPdo, "INSERT INTO virtual_aliases (domain_id, source, destination) VALUES ($domId, 'evil@example.test', 'x@y.z')", 'mailpanel: INSERT virtual_aliases');
    $denied($panelPdo, "UPDATE virtual_aliases SET destination='x@y.z'", 'mailpanel: UPDATE virtual_aliases');
    $denied($panelPdo, 'DELETE FROM admin_audit_logs', 'mailpanel: DELETE admin_audit_logs');
    $denied($panelPdo, 'DELETE FROM admin_login_attempts', 'mailpanel: DELETE admin_login_attempts');
    $denied($panelPdo, 'DELETE FROM registration_events', 'mailpanel: DELETE registration_events');
    $denied($panelPdo, 'DELETE FROM panel_admins', 'mailpanel: DELETE panel_admins');
    $denied($panelPdo, 'DROP TABLE virtual_users', 'mailpanel: DROP');
    $denied($panelPdo, "INSERT INTO user_login_events (email, ip, created_at) VALUES ('x', 'y', NOW())", 'mailpanel: INSERT user_login_events (tylko procedura)');

    $loginPdo = new PDO($dsnBase, $uLogin, $pw, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);   // bez dbname, jak dovecot-postlogin.sh
    $loginPdo->prepare("CALL `$db`.sp_register_login(?, ?)")->execute(['dove@example.test', '203.0.113.2']);
    eq($count('SELECT COUNT(*) FROM user_login_events WHERE email = ?', ['dove@example.test']), 1, 'mailpostlogin: CALL sp_register_login działa bez domyślnej bazy i zapisuje zdarzenie');
    $denied($loginPdo, "SELECT * FROM `$db`.virtual_users", 'mailpostlogin: SELECT virtual_users');
    $denied($loginPdo, "DELETE FROM `$db`.user_login_events", 'mailpostlogin: DELETE user_login_events');

    /* -------------------------------------------------------------- portal: brak enumeracji */
    group('portal: ten sam wynik dla admina, użytkownika i nieistniejącego adresu');
    $r1 = $call('check_identity', ['email' => 'boss@example.test']);
    $r2 = $call('check_identity', ['email' => 'jan@example.test']);
    $r3 = $call('check_identity', ['email' => 'nikt-takiego@example.test']);
    ok(!empty($r1['ok']) && !empty($r2['ok']) && !empty($r3['ok']), 'check_identity ok dla admina / użytkownika / nieznanego');
    $norm = static fn(array $r, string $e): string => (string) preg_replace('~(<div class="avatar"[^>]*>)[^<]*~', '$1A', str_replace($e, 'X', (string) $r['html']));
    eq($norm($r1, 'boss@example.test'), $norm($r3, 'nikt-takiego@example.test'), 'admin i nieznany: identyczny HTML');
    eq($norm($r2, 'jan@example.test'), $norm($r3, 'nikt-takiego@example.test'), 'użytkownik i nieznany: identyczny HTML');
    eq([$r1['focus_id'], $r2['focus_id'], $r3['focus_id']], ['inpPassword', 'inpPassword', 'inpPassword'], 'focus_id takie samo');
    $r = $call('check_identity', ['email' => 'Boss@Example.TEST']);
    ok(!empty($r['ok']) && str_contains($r['html'], 'boss@example.test'), 'adres normalizowany do małych liter');
    $r = $call('check_identity', ['email' => 'to nie jest adres']);
    ok(empty($r['ok']), 'zły format odrzucony');
    $r = $call('check_identity', ['email' => 'x@y']);
    ok(empty($r['ok']), 'adres bez TLD odrzucony');

    /* -------------------------------------------------------------- logowanie: limity */
    group('logowanie: limity (para konto+klient, klient), brak sleep, brak kasowania wierszy');
    $limiter = new App\Security\RateLimiter($pdo);
    App\Settings\Settings::set('admin_lock_threshold', '5');
    App\Settings\Settings::set('admin_lock_minutes', '15');
    $ipA = '203.0.113.60'; $ipB = '203.0.113.61';

    $t0 = microtime(true);
    for ($i = 1; $i <= 5; $i++) {
        $call('check_identity', ['email' => 'boss@example.test'], $ipA);
        $r = $call('perform_login', ['password' => 'zle-haslo-' . $i], $ipA);
        ok(empty($r['ok']) && !empty($r['reset']) && $r['msg'] === 'Nieprawidłowe dane logowania', "zła próba admina #$i: komunikat ogólny + reset");
    }
    ok(microtime(true) - $t0 < 15, 'pięć złych prób bez sleep() (czas ' . round(microtime(true) - $t0, 2) . ' s)');
    eq($count("SELECT COUNT(*) FROM admin_login_attempts WHERE username='boss@example.test' AND success=0"), 5, 'nieudane próby admina zapisane w admin_login_attempts');
    eq($count("SELECT COUNT(*) FROM throttle_events WHERE kind='admin' AND email='boss@example.test'"), 5, 'nieudane próby w throttle_events');
    $r = $call('check_identity', ['email' => 'boss@example.test'], $ipA);
    ok(empty($r['ok']) && str_contains($r['msg'], 'Zbyt wiele'), 'szósta próba z tego samego IP: szybka odmowa już w kroku identyfikacji');
    $r = $call('check_identity', ['email' => 'boss@example.test'], $ipB);
    ok(!empty($r['ok']), 'inny adres IP NIE jest blokowany dla tego samego konta (admin nie do zablokowania z zewnątrz)');
    $call('check_identity', ['email' => 'boss@example.test'], $ipB);
    $r = $call('perform_login', ['password' => 'Admin-Pass-12345!'], $ipB);
    ok(!empty($r['ok']) && $r['redirect'] === 'panel/index.php', 'poprawne hasło admina z innego IP: wejście');
    ok(!empty($_SESSION['admin_id']) && !empty($_SESSION['admin_login_at']) && !empty($_SESSION['admin_fp']), 'sesja admina: id, czas logowania, odcisk hasła');
    ok(!isset($_SESSION['csrf']), '1.4.0: token CSRF sprzed logowania unieważniony (panel wyda nowy)');
    eq($count("SELECT COUNT(*) FROM admin_login_attempts WHERE username='boss@example.test' AND success=0"), 5, 'udane logowanie nie skasowało wierszy porażek');
    unset($_SESSION['admin_id'], $_SESSION['admin_login_at'], $_SESSION['admin_fp'], $_SESSION['admin_username']);
    $_SESSION['csrf'] = 'TESTTOKEN';      // "przeglądarka" dostaje nowy token z następnej strony

    // udane logowanie IMAP ofiary (sp_register_login) nie zeruje licznika atakującego
    $before = $count("SELECT COUNT(*) FROM throttle_events WHERE kind='webmail' AND email='jan@example.test'");
    for ($i = 0; $i < 3; $i++) {
        $call('check_identity', ['email' => 'jan@example.test'], '203.0.113.70');
        $call('perform_login', ['password' => 'zle'], '203.0.113.70');
    }
    $afterFails = $count("SELECT COUNT(*) FROM throttle_events WHERE kind='webmail' AND email='jan@example.test'");
    eq($afterFails - $before, 3, 'webmail: porażki zapisane w throttle_events');
    for ($i = 0; $i < 4; $i++) {      // legalne logowania z różnych IP (każde nowe IP przycina user_login_events)
        $panelPdo->prepare('CALL sp_register_login(?, ?)')->execute(['jan@example.test', '198.18.0.' . (10 + $i)]);
    }
    eq($count("SELECT COUNT(*) FROM throttle_events WHERE kind='webmail' AND email='jan@example.test'"), $afterFails, 'udane logowania (sp_register_login) nie ruszają licznika porażek');
    $call('check_identity', ['email' => 'jan@example.test'], '203.0.113.70');
    $r = $call('perform_login', ['password' => 'DevTest123!'], '203.0.113.70');
    eq($r['redirect'] ?? '', './?_task=mail', 'poprawne hasło webmail');

    // limit na klienta (rozsiewanie haseł po kontach)
    for ($i = 0; $i < 20; $i++) { $limiter->recordLoginFailure('webmail', "victim$i@example.test", inet_pton('203.0.113.80')); }
    ok($limiter->loginBlocked('another@example.test', inet_pton('203.0.113.80')) !== null, 'klient po 20 porażkach na różne konta: zablokowany dla wszystkich kont');
    ok($limiter->loginBlocked('another@example.test', inet_pton('203.0.113.81')) === null, 'sąsiedni adres IPv4 nie jest dotknięty');
    // IPv6 /64
    for ($i = 0; $i < 5; $i++) { $limiter->recordLoginFailure('admin', 'boss@example.test', inet_pton('2001:db8:aaaa:1::' . dechex(0x10 + $i))); }
    ok($limiter->loginBlocked('boss@example.test', inet_pton('2001:db8:aaaa:1:ffff::9')) !== null, 'IPv6: porażki z jednego /64 liczą się razem');
    ok($limiter->loginBlocked('boss@example.test', inet_pton('2001:db8:aaaa:2::9')) === null, 'IPv6: inny /64 nie jest dotknięty');
    // zaufane IP
    for ($i = 0; $i < 30; $i++) { $limiter->recordLoginFailure('admin', 'boss@example.test', inet_pton('198.51.100.99')); }
    ok($limiter->loginBlocked('boss@example.test', inet_pton('198.51.100.99')) === null, 'adres z SCAN_TRUSTED_IPS nie podlega limitom');
    // 1.3.3: ani CAPTCHA przy logowaniu — droga administratora, gdy klucze CAPTCHA przestały działać
    App\Settings\Settings::set('captcha_provider', 'recaptcha');
    App\Settings\Settings::set('recaptcha_site_key', 'SITE-KEY');
    App\Settings\Settings::set('recaptcha_secret', 'SECRET-KEY');
    App\Settings\Settings::set('recaptcha_enabled', '1');
    $r = $call('check_identity', ['email' => 'boss@example.test'], '198.51.100.99');
    ok(!empty($r['ok']) && ($r['focus_id'] ?? '') === 'inpPassword', 'CAPTCHA włączona: zaufany adres przechodzi do hasła bez tokenu');
    $r = $call('check_identity', ['email' => 'boss@example.test'], '203.0.113.61');
    ok(empty($r['ok']) && str_contains((string) ($r['msg'] ?? ''), 'CAPTCHA'), 'CAPTCHA włączona: inny adres bez tokenu odrzucony (fail-closed)');
    App\Settings\Settings::set('recaptcha_enabled', '0');
    // ręczny ban lokalny (ip_blocks) działa w portalu dla WSZYSTKICH akcji
    $banRepo = new App\Panel\BanRepository($pdo);
    $banRepo->ban(inet_pton('203.0.113.90'), 30, 'test');
    $r = $call('check_identity', ['email' => 'jan@example.test'], '203.0.113.90');
    ok(empty($r['ok']) && str_contains($r['msg'], 'zablokowany'), 'ban lokalny blokuje portal (identyfikacja)');
    $r = $call('check_availability', ['local' => 'cokolwiek', 'domain' => 'example.test'], '203.0.113.90');
    ok(empty($r['ok']) && str_contains($r['msg'], 'zablokowany'), 'ban lokalny blokuje też sprawdzanie dostępności');
    $banRepo->unbanLocal(inet_pton('203.0.113.90'));
    ok($banRepo->reasonFor(inet_pton('203.0.113.90')) === null, 'unbanLocal zdejmuje blokadę');

    /* -------------------------------------------------------------- rejestracja */
    group('rejestracja: nazwy zarezerwowane, aliasy, wielkość liter, nagrobki, limity /64');
    App\Settings\Settings::set('allow_registration', '1');
    App\Settings\Settings::set('registration_enabled', '1');
    App\Settings\Settings::set('register_min_interval_seconds', '0');
    App\Settings\Settings::set('ip_max_accounts', '100');
    App\Settings\Settings::set('accounts_per_ip', '100');
    App\Settings\Settings::set('ip_total_limit', '1000');
    App\Settings\Settings::set('pwd_min_length', '10');
    App\Settings\Settings::set('pwd_require_upper', '1');
    App\Settings\Settings::set('pwd_require_digit', '1');
    App\Settings\Settings::set('pwd_require_special', '1');
    $reg = static function (string $local, string $ip = '203.0.113.100', string $pass = 'Silne-Haslo-99!') use ($call): array {
        return $call('register', ['local' => $local, 'domain' => 'example.test', 'password' => $pass, 'password2' => $pass], $ip);
    };
    foreach (['admin', 'administrator', 'hostmaster', 'postmaster', 'webmaster', 'abuse', 'security', 'root', 'noreply', 'no-reply', 'mailer-daemon', 'info', 'support', 'Admin', 'POSTMASTER', 'owner-list', 'x-request'] as $name) {
        $r = $reg($name, '203.0.113.101');
        ok(empty($r['ok']) && str_contains((string) $r['msg'], 'zarezerwowana'), "zarezerwowane [$name]: " . ($r['msg'] ?? ''));
    }
    foreach (['-rf', '--help', '-', '.x', 'x.', 'a..b', 'a b', 'a/b', '', str_repeat('a', 65), 'a@b'] as $name) {
        $r = $reg($name, '203.0.113.102');
        ok(empty($r['ok']), 'zły kształt [' . $name . '] odrzucony: ' . ($r['msg'] ?? ''));
    }
    eq($count('SELECT COUNT(*) FROM virtual_users WHERE email IN (?, ?, ?)', ['admin@example.test', '-rf@example.test', 'info@example.test']), 0, 'żadne z odrzuconych kont nie powstało');
    $r = $reg('sales2', '203.0.113.103');
    ok(empty($r['ok']) && str_contains((string) $r['msg'], 'niedostępna'), 'nazwa będąca ŹRÓDŁEM aliasu odrzucona');
    $r = $reg('ghost', '203.0.113.103');
    ok(empty($r['ok']) && str_contains((string) $r['msg'], 'niedostępna'), 'nazwa będąca CELEM wiszącego aliasu odrzucona');
    $r = $reg('bob', '203.0.113.103');
    ok(!empty($r['ok']), 'nazwa, która tylko wygląda podobnie do celu z listy (bob@elsewhere.example), jest wolna');
    $r = $reg('Test.User', '203.0.113.104');
    ok(!empty($r['ok']), 'rejestracja Test.User przyjęta');
    eq($count("SELECT COUNT(*) FROM virtual_users WHERE BINARY email = 'test.user@example.test'"), 1, 'adres zapisany MAŁYMI literami');
    eq($count("SELECT COUNT(*) FROM registration_events WHERE BINARY email = 'test.user@example.test'"), 1, 'zdarzenie rejestracji też małymi literami');
    $r = $reg('TEST.user', '203.0.113.105');
    ok(empty($r['ok']) && str_contains((string) $r['msg'], 'już istnieje'), 'ta sama nazwa wielkimi literami: "już istnieje"');
    $r = $reg('slabe', '203.0.113.106', 'slabe');
    ok(empty($r['ok']), 'za słabe hasło odrzucone');

    $r = $call('check_availability', ['local' => 'admin', 'domain' => 'example.test'], '203.0.113.107');
    eq([$r['status'] ?? '', $r['msg'] ?? ''], ['taken', 'Zarezerwowane'], 'dostępność: zarezerwowane');
    $r = $call('check_availability', ['local' => 'JAN', 'domain' => 'EXAMPLE.test'], '203.0.113.107');
    eq([$r['status'] ?? '', $r['msg'] ?? ''], ['taken', 'Zajęte'], 'dostępność: zajęte (wielkość liter bez znaczenia)');
    $r = $call('check_availability', ['local' => 'wolna.nazwa', 'domain' => 'example.test'], '203.0.113.107');
    eq($r['status'] ?? '', 'free', 'dostępność: wolne');
    for ($i = 0; $i < 40; $i++) { $r = $call('check_availability', ['local' => 'probe' . $i, 'domain' => 'example.test'], '203.0.113.108'); }
    ok(empty($r['ok']) && str_contains((string) $r['msg'], 'Zbyt częste'), 'sprawdzanie dostępności: limit na klienta (nie na sesję)');
    $_SESSION = ['csrf' => 'TESTTOKEN'];                  // "nowa sesja" nie omija limitu
    $r = $call('check_availability', ['local' => 'probe-x', 'domain' => 'example.test'], '203.0.113.108');
    ok(empty($r['ok']), 'nowa sesja nie resetuje limitu zapytań o nazwę');

    // limit rejestracji na /64
    App\Settings\Settings::set('ip_max_accounts', '3');
    App\Settings\Settings::set('accounts_per_ip', '3');
    $made = 0;
    foreach (['u1', 'u2', 'u3', 'u4'] as $i => $n) {
        $r = $reg($n, '2001:db8:bbbb:7::' . dechex($i + 1));       // różne adresy, jeden /64
        if (!empty($r['ok'])) { $made++; }
    }
    eq($made, 3, 'IPv6: trzy konta z jednego /64, czwarte (inny adres w tym samym /64) odrzucone');
    $r = $reg('u5', '2001:db8:bbbb:8::1');
    ok(!empty($r['ok']), 'IPv6: inny /64 ma własny limit');
    // 1.3.3: klient IPv4 z gniazda dwustosowego (::ffff:a.b.c.d) — wcześniej limit widział 0 jego rejestracji
    $made = 0;
    foreach (['m1', 'm2', 'm3', 'm4'] as $n) {
        $r = $reg($n, '::ffff:198.51.100.77');
        if (!empty($r['ok'])) { $made++; }
    }
    eq($made, 3, 'IPv4 jako ::ffff:…: trzy konta, czwarte odrzucone (jak zwykły IPv4)');
    $r = $reg('m5', '198.51.100.77');
    ok(empty($r['ok']), 'ten sam klient bez prefiksu ::ffff: — ten sam limit');
    ok($count('SELECT COUNT(*) FROM registration_events WHERE ip = ?', [inet_pton('198.51.100.77')]) >= 3
        && $count('SELECT COUNT(*) FROM registration_events WHERE ip = ?', [inet_pton('::ffff:198.51.100.77')]) === 0, 'zdarzenia zapisane jako adres IPv4 (4 bajty), nie ::ffff:…');

    /* -------------------------------------------------------------- usuwanie konta */
    group('usuwanie konta: aliasy, historia, licznik, nagrobek');
    App\Settings\Settings::set('deleted_name_hold_days', '365');
    $uid = (int) $admin->query("SELECT id FROM virtual_users WHERE email='jan@example.test'")->fetchColumn();
    $admin->exec("INSERT INTO send_counters (email, day, cnt) VALUES ('Jan@Example.test', CURDATE(), 7)");
    $admin->exec("INSERT INTO user_login_events (email, ip, created_at) VALUES ('jan@example.test', 0x01020304, NOW())");
    $repo = new App\Panel\UserAdminRepository($pdo);
    $res = $repo->deleteAccount($uid, 'Jan@Example.test');
    eq($res['aliases'], 2, 'usunięto aliasy kierujące dokładnie na konto (sales2, postmaster)');
    eq(count($res['lists']), 1, 'alias z LISTĄ celów zwrócony do ręcznej poprawy, nie ruszony');
    eq($count("SELECT COUNT(*) FROM virtual_aliases WHERE destination = 'jan@example.test'"), 0, 'brak aliasów z celem jan@');
    eq($count("SELECT COUNT(*) FROM virtual_aliases WHERE source = 'list@example.test'"), 1, 'alias z listą nadal istnieje');
    eq($count("SELECT COUNT(*) FROM user_login_events WHERE email = 'jan@example.test'"), 0, 'historia logowań usunięta');
    eq($count("SELECT COUNT(*) FROM send_counters WHERE email = 'jan@example.test'"), 0, 'dzisiejszy licznik wysyłki usunięty (mimo innej wielkości liter)');
    eq($count("SELECT COUNT(*) FROM virtual_users WHERE email = 'jan@example.test'"), 0, 'konto usunięte');
    eq($count("SELECT COUNT(*) FROM deleted_accounts WHERE email = 'jan@example.test'"), 1, 'nagrobek zapisany');
    $r = $reg('jan', '203.0.113.110');
    ok(empty($r['ok']) && str_contains((string) $r['msg'], 'niedostępna'), 'ponowna rejestracja usuniętej nazwy odrzucona (nagrobek)');
    App\Settings\Settings::set('deleted_name_hold_days', '0');
    $r = $reg('jan', '203.0.113.110');
    ok(empty($r['ok']) && str_contains((string) $r['msg'], 'niedostępna'), 'blokada 0 dni, ale alias z LISTĄ celów nadal wymienia jan@: nazwa wciąż niedostępna');
    $admin->exec("DELETE FROM virtual_aliases WHERE source = 'list@example.test'");
    // 1.4.0: pliki skrzynki zostały na dysku (nagrobek z files_left = 1, póki helper ich nie przeniesie) — nazwa zablokowana
    eq($count("SELECT files_left FROM deleted_accounts WHERE email = 'jan@example.test'"), 1, '1.4.0: świeży nagrobek ma files_left = 1 (do potwierdzenia przeniesienia plików)');
    $r = $reg('jan', '203.0.113.110');
    ok(empty($r['ok']) && str_contains((string) $r['msg'], 'niedostępna'), '1.4.0: blokada 0 dni, ale stare pliki skrzynki na dysku: nazwa niedostępna');
    $admin->exec("UPDATE deleted_accounts SET files_left = 0 WHERE email = 'jan@example.test'");   // jak po udanym przeniesieniu
    $r = $reg('jan', '203.0.113.110');
    ok(!empty($r['ok']), 'z blokadą wyłączoną (0 dni) i bez aliasów nazwa wraca do puli');
    App\Settings\Settings::set('deleted_name_hold_days', '365');

    // kontroler panelu: usuwanie i tworzenie z nadpisaniem zarezerwowanej nazwy (metody prywatne przez refleksję)
    $_SESSION['admin_id'] = (int) $admin->query("SELECT id FROM panel_admins LIMIT 1")->fetchColumn();
    $_SESSION['admin_username'] = 'boss@example.test';
    $panel = new App\Panel\PanelController();
    $inv = static function (string $method) use ($panel): array {
        $m = new ReflectionMethod($panel, $method);
        foreach (['success', 'error'] as $p) { $rp = new ReflectionProperty($panel, $p); $rp->setValue($panel, ''); }
        $m->invoke($panel);
        $out = [];
        foreach (['success', 'error'] as $p) { $rp = new ReflectionProperty($panel, $p); $out[$p] = $rp->getValue($panel); }
        return $out;
    };
    $_POST = ['nu_local' => 'Admin', 'nu_domain' => 'example.test', 'nu_password' => 'Silne-Haslo-99!', 'nu_password2' => 'Silne-Haslo-99!', 'nu_quota_value' => '10', 'nu_quota_unit' => 'MB'];
    $o = $inv('createUser');
    ok($o['error'] !== '' && $o['success'] === '', 'admin: zarezerwowana nazwa bez zgody odrzucona (' . $o['error'] . ')');
    $_POST['nu_allow_reserved'] = '1';
    $o = $inv('createUser');
    ok($o['error'] !== '' && $count("SELECT COUNT(*) FROM virtual_users WHERE email = 'admin@example.test'") === 0, '1.4.0: zgoda bez hasła administratora odrzucona (' . $o['error'] . ')');
    $_POST['admin_confirm_password'] = 'Admin-Pass-12345!';
    $o = $inv('createUser');
    ok($o['error'] === '' && $count("SELECT COUNT(*) FROM virtual_users WHERE BINARY email = 'admin@example.test'") === 1, 'admin: ze zgodą konto admin@ powstaje (małymi literami)');
    $_POST = ['nu_local' => 'sales2', 'nu_domain' => 'example.test', 'nu_password' => 'Silne-Haslo-99!', 'nu_password2' => 'Silne-Haslo-99!', 'nu_allow_reserved' => '1'];
    $al->execute([$domId, 'sales2@example.test', 'someone@elsewhere.example']);
    $o = $inv('createUser');
    ok($o['error'] !== '', 'admin: nazwa będąca źródłem aliasu odrzucona nawet ze zgodą');

    $victim = (int) $admin->query("SELECT id FROM virtual_users WHERE email='old.user@example.test'")->fetchColumn();
    $al->execute([$domId, 'fwd@example.test', 'old.user@example.test']);
    $_POST = ['delete_user_id' => (string) $victim, 'delete_user_email' => 'Old.User@Example.test', 'admin_confirm_password' => 'zle'];
    $o = $inv('deleteUser');
    ok($o['error'] !== '' && $count("SELECT COUNT(*) FROM virtual_users WHERE id = ?", [$victim]) === 1, 'usuwanie ze złym hasłem admina: nic się nie dzieje');
    $_POST['admin_confirm_password'] = 'Admin-Pass-12345!';
    $o = $inv('deleteUser');
    ok($count("SELECT COUNT(*) FROM virtual_users WHERE id = ?", [$victim]) === 0, 'usuwanie z poprawnym hasłem: konto usunięte');
    ok(str_contains($o['success'], 'old.user@example.test') && str_contains($o['success'], 'Usunięto aliasów'), 'komunikat używa adresu małymi literami i podaje liczbę usuniętych aliasów');
    ok($o['error'] !== '', 'tryb dev: brak skryptu usuwania plików -> widoczny BŁĄD, nie cichy sukces (' . $o['error'] . ')');
    eq($count("SELECT COUNT(*) FROM virtual_aliases WHERE destination = 'old.user@example.test'"), 0, 'alias kierujący na usunięte konto zniknął');

    /* -------------------------------------------------------------- panel: ustawienia, bany, hasła, strona */
    group('panel: ustawienia, sekret reCAPTCHA, bany, hasło admina, render strony');
    $prevHandler = set_error_handler(static function (int $no, string $str, string $file, int $line): bool {
        throw new ErrorException($str, 0, $no, $file, $line);
    });
    App\Settings\Settings::set('recaptcha_secret', 'SEKRET-ORYGINAL');
    $_POST = ['registration_enabled' => '1', 'recaptcha_site_key' => 'sk', 'recaptcha_secret_key' => '', 'mailbox_quota_value' => '1',
              'mailbox_quota_unit' => 'GB', 'daily_send_limit' => '20', 'accounts_per_ip' => '3', 'ip_window_hours' => '24',
              'ip_total_limit' => '50', 'reserved_local_parts' => "Foo, BAR\nbaz*, zły, ?!", 'deleted_name_hold_days' => '30'];
    $o = $inv('saveSettings');
    ok($o['error'] === '' && $o['success'] !== '', 'saveSettings: zapisane');
    eq(App\Settings\Settings::get('recaptcha_secret'), 'SEKRET-ORYGINAL', 'puste pole Secret Key zostawia zapisany sekret');
    eq(App\Settings\Settings::get('reserved_local_parts'), 'foo,bar,baz*', 'lista zarezerwowanych znormalizowana (małe litery, tylko poprawne wpisy)');
    eq(App\Settings\Settings::get('deleted_name_hold_days'), '30', 'dni blokady nazw zapisane');
    $_POST['recaptcha_secret_key'] = 'SEKRET-NOWY'; $_POST['deleted_name_hold_days'] = '99999';
    $inv('saveSettings');
    eq(App\Settings\Settings::get('recaptcha_secret'), 'SEKRET-NOWY', 'wpisany Secret Key zastępuje zapisany');
    eq(App\Settings\Settings::get('deleted_name_hold_days'), '3650', 'dni blokady przycięte do 3650');
    $_POST = ['rc_max_recipients' => '10', 'pf_message_rate_limit' => '15', 'pf_connection_rate_limit' => '10', 'pf_connection_count_limit' => '10'];
    $o = $inv('saveAntispam');
    ok($o['error'] === '' && str_contains($o['success'], 'zapisane'), 'saveAntispam w trybie dev: zapis do bazy, bez wyjątku (' . $o['success'] . ')');
    App\Settings\Settings::set('reserved_local_parts', 'info,support');

    // bany: czas, zaufane IP, zakres działania
    $_POST = ['ban_ip' => '203.0.113.200', 'ban_duration' => '60', 'ban_reason' => 'Spam'];
    $o = $inv('banUserIp');
    ok($o['error'] === '' && str_contains($o['success'], 'TYLKO w portalu i panelu'), 'ban IP: bez Fail2Ban komunikat mówi wprost, że to tylko portal/panel');
    ok($banRepo->reasonFor(inet_pton('203.0.113.200')) === 'Spam', 'ban IP: wpis w ip_blocks (bez znacznika F2B)');
    $o = $inv('unbanIp');   // $_POST bez 'ip' -> błąd adresu
    ok($o['error'] !== '', 'unban bez adresu: błąd');
    $_POST = ['ip' => '203.0.113.200', 'unban_type' => 'local'];
    $o = $inv('unbanIp');
    ok($o['error'] === '' && $banRepo->reasonFor(inet_pton('203.0.113.200')) === null, 'unban lokalny zdejmuje blokadę');
    foreach ([['203.0.113.201', '0'], ['203.0.113.201', '-5'], ['203.0.113.201', '999999'], ['nie-ip', '60'], ['198.51.100.99', '60'], ['127.0.0.1', '60']] as [$bip, $bdur]) {
        $_POST = ['ban_ip' => $bip, 'ban_duration' => $bdur];
        $o = $inv('banUserIp');
        ok($o['error'] !== '' && $banRepo->reasonFor((string) App\Support\Ip::toBin($bip)) === null, "ban [$bip, $bdur min] odrzucony");
    }

    // hasło administratora: polityka, odcisk w sesji
    $oldHash = (new App\Admin\AdminRepository($pdo))->findByEmail('boss@example.test')['pwd_hash'];
    $_SESSION['admin_fp'] = hash('sha256', $oldHash);
    $_POST = ['current_password' => 'Admin-Pass-12345!', 'new_password' => 'Krotkie1!', 'confirm_password' => 'Krotkie1!'];
    $o = $inv('changePassword');
    ok($o['error'] !== '' && str_contains($o['error'], '12'), 'hasło admina: krótsze niż 12 znaków odrzucone');
    $_POST = ['current_password' => 'zle', 'new_password' => 'Nowe-Haslo-Admina-77!', 'confirm_password' => 'Nowe-Haslo-Admina-77!'];
    $o = $inv('changePassword');
    ok($o['error'] !== '', 'hasło admina: złe obecne hasło');
    $_POST = ['current_password' => 'Admin-Pass-12345!', 'new_password' => 'Nowe-Haslo-Admina-77!', 'confirm_password' => 'Nowe-Haslo-Admina-77!'];
    $o = $inv('changePassword');
    $newHash = (new App\Admin\AdminRepository($pdo))->findByEmail('boss@example.test')['pwd_hash'];
    ok($o['error'] === '' && App\Security\Password::verifyAdmin('Nowe-Haslo-Admina-77!', $newHash), 'hasło admina zmienione');
    eq($_SESSION['admin_fp'], hash('sha256', $newHash), 'odcisk w bieżącej sesji odświeżony (inne sesje stają się nieważne)');
    $admin->prepare('UPDATE panel_admins SET pwd_hash = ? WHERE email = ?')->execute([$adminHash, 'boss@example.test']);
    $_SESSION['admin_fp'] = hash('sha256', $adminHash);

    // reset hasła skrzynki i quota
    $mbx = (int) $admin->query("SELECT id FROM virtual_users WHERE email='test.user@example.test'")->fetchColumn();
    $_POST = ['user_id' => (string) $mbx, 'user_email' => 'inny@example.test', 'new_password' => 'Silne-Haslo-99!', 'new_password2' => 'Silne-Haslo-99!'];
    $o = $inv('resetUserPassword');
    ok($o['error'] !== '', 'reset hasła skrzynki: niezgodny adres odrzucony');
    $_POST['user_email'] = 'TEST.user@example.test';
    $o = $inv('resetUserPassword');
    ok($o['error'] !== '' && str_contains($o['error'], 'dzienniku'), '1.4.0: reset hasła skrzynki bez hasła administratora odrzucony');
    $_POST['admin_confirm_password'] = 'Admin-Pass-12345!';
    $o = $inv('resetUserPassword');
    ok($o['error'] === '' && str_contains($o['success'], 'test.user@example.test'), 'reset hasła skrzynki: adres wielkimi literami przyjęty, komunikat małymi');
    $_POST = ['user_id' => (string) $mbx, 'user_quota_value' => '1e30', 'user_quota_unit' => 'GB', 'user_send_limit' => ''];
    $inv('updateUserQuota');
    eq($count('SELECT quota_mb FROM virtual_users WHERE id = ?', [$mbx]), App\Panel\Quota::MAX_MB, 'quota: ogromna wartość przycięta do MAX_MB (nie 0 = bez limitu)');

    // render całej strony panelu: bez notice/warning, z nowymi polami, bez sekretu
    $render = new ReflectionMethod($panel, 'renderPage');
    $_SESSION['flash_success'] = 'Konto <b>x</b>'; $_SESSION['csrf'] = 'TESTTOKEN';
    ob_start();
    try { $render->invoke($panel); $html = (string) ob_get_clean(); } catch (Throwable $e) { ob_end_clean(); $html = ''; ok(false, 'renderPage rzuca: ' . $e->getMessage()); }
    ok($html !== '' && str_contains($html, '<form method="post" action="logout.php"'), 'strona: wylogowanie jest formularzem POST');
    ok(str_contains($html, 'name="reserved_local_parts"') && str_contains($html, 'name="deleted_name_hold_days"') && str_contains($html, 'name="nu_allow_reserved"'), 'strona: pola zarezerwowanych nazw / blokady / zgody admina');
    ok(!str_contains($html, 'SEKRET-NOWY') && str_contains($html, 'type="password" name="recaptcha_secret_key" value=""'), 'strona: sekret reCAPTCHA nie jest wypisywany');
    ok(str_contains($html, 'Konto &lt;b&gt;x&lt;/b&gt;') && !str_contains($html, '&amp;lt;'), 'komunikat flash escapowany dokładnie raz');
    restore_error_handler();

    // wszystkie zapytania odczytujące, których używa panel, działają na grantach ograniczonego konta
    $lr = new App\Panel\LogRepository($pdo);
    ok($lr->countAudit() >= 4 && count($lr->auditPage(5, 0)) > 0, 'dziennik audytu: INSERT działa (wpisy z operacji powyżej) i da się go czytać');
    ok($lr->countRegistration() > 0 && count($lr->registrationPage(5, 0)) > 0, 'log rejestracji czytelny');
    ok($lr->countAdminLogin() >= 5 && count($lr->adminLoginPage(5, 0)) > 0, 'log logowań admina czytelny');
    [$urows, $utotal] = $repo->fetch('', [], 5, 0);
    ok($utotal > 0 && count($urows) > 0, 'lista kont (z joinami do rejestracji / logowań) działa na grantach');
    ok(isset($repo->ipsForEmail('test.user@example.test')['flags']), 'ipsForEmail działa na grantach');
    ok(App\Panel\Stats::collect($pdo, 0)['total_users'] > 0, 'statystyki działają na grantach');

    /* -------------------------------------------------------------- sesja admina */
    group('AdminGuard: ważność sesji');
    $guard = new ReflectionMethod(App\Admin\AdminGuard::class, 'stillValid');
    $row = (new App\Admin\AdminRepository($pdo))->findByEmail('boss@example.test');
    $sess = ['admin_id' => (int) $row['id'], 'admin_fp' => hash('sha256', $row['pwd_hash']), 'admin_login_at' => time() - 60, 'admin_last_seen' => time() - 10];
    $_SESSION = $sess;
    ok($guard->invoke(null) === true, 'świeża sesja ważna');
    $_SESSION = array_merge($sess, ['admin_fp' => hash('sha256', 'inne-haslo')]);
    ok($guard->invoke(null) === false, 'zmiana hasła (inny odcisk) unieważnia sesję');
    $_SESSION = array_merge($sess, ['admin_id' => 999999]);
    ok($guard->invoke(null) === false, 'konto admina nie istnieje -> sesja nieważna');
    $_SESSION = array_merge($sess, ['admin_login_at' => time() - 13 * 3600]);
    ok($guard->invoke(null) === false, 'sesja starsza niż 12 h -> nieważna (bezwzględny limit)');
    $_SESSION = array_merge($sess, ['admin_last_seen' => time() - 3700]);
    ok($guard->invoke(null) === false, 'bezczynność > 1 h -> nieważna');
    $_SESSION = array_diff_key($sess, ['admin_login_at' => 1]);
    ok($guard->invoke(null) === false, 'sesja bez czasu logowania (sprzed 1.1.0) -> nieważna');
    $_SESSION = ['csrf' => 'TESTTOKEN'];

    /* -------------------------------------------------------------- polityka dziennego limitu */
    group('mailpanel-policy-daily.php: limit liczy odbiorców, licznik atomowy');
    $admin->exec("INSERT INTO virtual_users (domain_id, password, email, quota_mb, daily_send_limit) VALUES ($domId, 'x', 'sender@example.test', 10, 5)");
    $admin->exec("INSERT INTO virtual_users (domain_id, password, email, quota_mb, daily_send_limit) VALUES ($domId, 'x', 'free@example.test', 10, 0)");
    $admin->exec("INSERT INTO virtual_users (domain_id, password, email, quota_mb, daily_send_limit) VALUES ($domId, 'x', 'global@example.test', 10, NULL)");
    $admin->exec("REPLACE INTO app_settings (k, v) VALUES ('daily_send_limit', '4')");
    file_put_contents($tmp . '/policy.cf', "dsn = \"$dsnBase;dbname=$db;charset=utf8mb4\"\nuser = $uPolicy\npassword = \"$pw\"\ntimezone = UTC\nnotify = 0\n");
    file_put_contents($tmp . '/policy_prepend.php', '<?php define("CONFIG_FILE", ' . var_export($tmp . '/policy.cf', true) . ');');
    $policy = static function (array $requests) use ($root, $tmp): array {
        $in = '';
        foreach ($requests as $rq) {
            foreach ($rq as $k => $v) { $in .= "$k=$v\n"; }
            $in .= "\n";
        }
        $p = proc_open(['php', '-d', 'auto_prepend_file=' . $tmp . '/policy_prepend.php', $root . '/scripts/mailpanel-policy-daily.php'],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        fwrite($pipes[0], $in); fclose($pipes[0]);
        $out = (string) stream_get_contents($pipes[1]); $err = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]); fclose($pipes[2]); proc_close($p);
        preg_match_all('/^action=(.*)$/m', $out, $m);
        return [$m[1], $err];
    };
    $rq = static fn(string $user, int $rcpt): array => ['request' => 'smtpd_access_policy', 'sasl_username' => $user, 'recipient_count' => (string) $rcpt];
    [$act, $err] = $policy([$rq('sender@example.test', 3), $rq('sender@example.test', 3), $rq('SENDER@example.test', 2), $rq('sender@example.test', 1), $rq('sender@example.test', 10)]);
    ok(str_starts_with($act[0] ?? '', 'DUNNO'), 'limit 5: 3 odbiorców przechodzi');
    ok(str_starts_with($act[1] ?? '', 'REJECT'), 'limit 5: kolejne 3 (razem 6) odrzucone');
    ok(str_starts_with($act[2] ?? '', 'DUNNO'), 'limit 5: 2 odbiorców (razem 5) przechodzi — adres bez względu na wielkość liter');
    ok(str_starts_with($act[3] ?? '', 'REJECT'), 'limit 5: szósty odbiorca odrzucony');
    ok(str_starts_with($act[4] ?? '', 'REJECT') && str_contains($act[4], 'Za wiele'), 'wiadomość z 10 odbiorcami przy limicie 5: odrzucona od razu');
    eq($count("SELECT cnt FROM send_counters WHERE email = 'sender@example.test' AND day = CURDATE()"), 5, 'licznik w bazie = 5 (odrzucone nie zwiększają)');   // 1.4.0: dzień wg zegara bazy, jak polityka
    [$act, ] = $policy([$rq('free@example.test', 500), $rq('nobody@elsewhere.test', 3), ['request' => 'smtpd_access_policy', 'sasl_username' => ''], $rq('global@example.test', 4), $rq('global@example.test', 1)]);
    ok(str_starts_with($act[0] ?? '', 'DUNNO'), 'limit 0 = bez limitu');
    ok(str_starts_with($act[1] ?? '', 'DUNNO'), 'nieznane konto: DUNNO');
    ok(str_starts_with($act[2] ?? '', 'DUNNO'), 'brak sasl_username: DUNNO');
    ok(str_starts_with($act[3] ?? '', 'DUNNO') && str_starts_with($act[4] ?? '', 'REJECT'), 'limit globalny 4: cztery przechodzą, piąty odrzucony');
    [$act, ] = $policy([['request' => 'smtpd_access_policy', 'sasl_username' => 'sender@example.test']]);
    ok(str_starts_with($act[0] ?? '', 'REJECT'), 'brak recipient_count = 1 odbiorca (limit wyczerpany)');
    ok(str_contains($act[0] ?? '', 'Dzienny limit wysylki wyczerpany (5 odbiorcow/dzien)'), 'default_language = pl: odmowa po polsku, tylko ASCII');
    $admin->exec("REPLACE INTO app_settings (k, v) VALUES ('default_language', 'en')");
    [$act, ] = $policy([$rq('sender@example.test', 1), $rq('sender@example.test', 10)]);
    ok(($act[0] ?? '') === 'REJECT Daily sending limit reached (5 recipients/day)', '1.3.0: język domyślny en — odmowa po angielsku');
    ok(str_starts_with($act[1] ?? '', 'REJECT Too many recipients'), '1.3.0: ... także odmowa zbyt wielu odbiorców');
    $admin->exec("DELETE FROM app_settings WHERE k = 'default_language'");
    [$act, ] = $policy([$rq('sender@example.test', 1)]);
    ok(($act[0] ?? '') === 'REJECT Daily sending limit reached (5 recipients/day)', '1.3.1: bez ustawienia języka — po angielsku (świeża instalacja)');
    $admin->exec("INSERT INTO app_settings (k, v) VALUES ('default_language', 'pl')");
    // dwa procesy naraz nie przekraczają limitu
    $admin->exec("DELETE FROM send_counters WHERE email = 'sender@example.test'");
    $procs = [];
    for ($i = 0; $i < 4; $i++) {
        $in = ''; for ($k = 0; $k < 5; $k++) { $in .= "request=smtpd_access_policy\nsasl_username=sender@example.test\nrecipient_count=1\n\n"; }
        $procs[$i] = proc_open(['php', '-d', 'auto_prepend_file=' . $tmp . '/policy_prepend.php', $root . '/scripts/mailpanel-policy-daily.php'],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pp[$i]);
        fwrite($pp[$i][0], $in); fclose($pp[$i][0]);
    }
    $dunno = 0;
    foreach ($procs as $i => $p) {
        $out = (string) stream_get_contents($pp[$i][1]);
        fclose($pp[$i][1]); fclose($pp[$i][2]); proc_close($p);
        $dunno += substr_count($out, 'action=DUNNO');
    }
    eq($dunno, 5, 'cztery równoległe procesy po 5 zapytań: dokładnie 5 przechodzi (limit 5), reszta odrzucona');
    eq($count("SELECT cnt FROM send_counters WHERE email = 'sender@example.test' AND day = CURDATE()"), 5, 'licznik po wyścigu = limit');

    /* -------------------------------------------------------------- migracja: małe litery */
    group('upgrade_1.1.0.sql: adresy małymi literami');
    $admin->exec("INSERT INTO virtual_users (domain_id, password, email, quota_mb) VALUES ($domId, 'x', 'Mixed.Case@example.test', 1)");
    eq($count("SELECT COUNT(*) FROM virtual_users WHERE email <> LOWER(email)"), 0, 'pułapka: zwykłe email <> LOWER(email) NIE znajduje wielkich liter (porównanie ci)');
    eq($count("SELECT COUNT(*) FROM virtual_users WHERE BINARY email <> BINARY LOWER(email)"), 1, 'BINARY znajduje konto z wielkimi literami');
    foreach (splitSql((string) file_get_contents($root . '/sql/upgrade_1.1.0.sql')) as $stmt) {
        $up = $admin->query($stmt);
        if ($up instanceof PDOStatement) { $up->fetchAll(); $up->closeCursor(); }
    }
    eq($count("SELECT COUNT(*) FROM virtual_users WHERE BINARY email = 'mixed.case@example.test'"), 1, 'po migracji adres jest małymi literami');

    // kolumna z porównaniem binarnym: dwa konta różniące się tylko wielkością liter -> migracja staje PRZED zmianą
    $admin->exec("ALTER TABLE virtual_users MODIFY email VARCHAR(100) COLLATE utf8mb4_bin NOT NULL");
    $admin->exec("INSERT INTO virtual_users (domain_id, password, email, quota_mb) VALUES ($domId, 'x', 'Dup@example.test', 1), ($domId, 'x', 'dup@example.test', 1), ($domId, 'x', 'Other@example.test', 1)");
    $sigState = '';
    try {
        foreach (splitSql((string) file_get_contents($root . '/sql/upgrade_1.1.0.sql')) as $stmt) {
            $up = $admin->query($stmt);
            if ($up instanceof PDOStatement) { $up->fetchAll(); $up->closeCursor(); }
        }
    } catch (PDOException $e) {
        $sigState = (string) $e->getCode();
    }
    eq($sigState, '45000', 'kolizja wielkości liter: migracja przerwana (SIGNAL 45000)');
    eq($count("SELECT COUNT(*) FROM virtual_users WHERE BINARY email IN ('Dup@example.test', 'dup@example.test', 'Other@example.test')"), 3, 'kolizja: NIC nie zostało zmienione (także niekolidujące konto)');
    $admin->exec("DELETE FROM virtual_users WHERE BINARY email IN ('Dup@example.test', 'dup@example.test', 'Other@example.test')");
    $admin->exec("ALTER TABLE virtual_users MODIFY email VARCHAR(100) NOT NULL");

    /* -------------------------------------------------------------- 1.4.0 */
    group('1.4.0: pliki skrzynki na dysku, rejestracja nowego właściciela, aliasy w dzienniku, zakresy, LIKE, bany zaufanych');
    $adminPass = 'Admin-Pass-12345!';
    $_SESSION['admin_id']       = (int) $admin->query("SELECT id FROM panel_admins WHERE email = 'boss@example.test'")->fetchColumn();
    $_SESSION['admin_username'] = 'boss@example.test';
    $_SESSION['reauth_fail']    = 0;
    App\Settings\Settings::set('deleted_name_hold_days', '0');
    $admin->exec("INSERT INTO virtual_users (domain_id, password, email, quota_mb) VALUES ($domId, 'x', 'anna@example.test', 10)");
    $annaId = (int) $admin->query("SELECT id FROM virtual_users WHERE email = 'anna@example.test'")->fetchColumn();
    $admin->prepare('INSERT INTO registration_events (ip, email, status, created_at) VALUES (?, ?, ?, NOW() - INTERVAL 20 DAY)')
          ->execute([inet_pton('192.0.2.10'), 'anna@example.test', 'success']);
    $al->execute([$domId, 'role@example.test', 'anna@example.test']);
    $_POST = ['delete_user_id' => (string) $annaId, 'delete_user_email' => 'anna@example.test', 'admin_confirm_password' => $adminPass];
    $o = $inv('deleteUser');
    ok($count("SELECT COUNT(*) FROM virtual_users WHERE email = 'anna@example.test'") === 0 && $o['error'] !== '', 'usunięcie bez helpera (dev): konto znika, błąd o plikach widoczny');
    eq($count("SELECT files_left FROM deleted_accounts WHERE email = 'anna@example.test'"), 1, 'nagrobek: files_left = 1 (pliki na dysku)');
    ok(str_contains($o['error'], 'anna@example.test'), 'komunikat: nazwa zablokowana do przeniesienia plików');
    eq($count("SELECT COUNT(*) FROM admin_audit_logs WHERE action_type = 'DELETE_USER' AND details LIKE '%role@example.test → anna@example.test%'"), 1,
        'dziennik: usunięty alias wymieniony (#id źródło → cel), nie tylko liczba');
    $r = $reg('anna', '203.0.113.120');
    ok(empty($r['ok']) && str_contains((string) $r['msg'], 'niedostępna'), 'portal: nazwa z plikami na dysku niedostępna (mimo blokady 0 dni)');
    $a = (new ReflectionMethod($panel, 'classifyName'))->invoke($panel, new App\Users\UserRepository(App\Support\Database::pdo()), 'anna', 'anna@example.test');
    ok($a['status'] === 'held' && $a['override'] === true && str_contains($a['detail'], 'kwarantann'), 'panel: ocena nazwy "stara skrzynka na dysku", zgoda możliwa');
    $_POST = ['nu_local' => 'anna', 'nu_domain' => 'example.test', 'nu_password' => 'Silne-Haslo-99!', 'nu_password2' => 'Silne-Haslo-99!',
              'nu_allow_reserved' => '1', 'admin_confirm_password' => $adminPass];
    $o = $inv('createUser');
    ok($o['error'] !== '' && $count("SELECT COUNT(*) FROM virtual_users WHERE email = 'anna@example.test'") === 0,
        'panel ze zgodą: plików nie da się przenieść (dev, brak helpera) -> konto NIE powstaje');
    eq($count("SELECT COUNT(*) FROM deleted_accounts WHERE email = 'anna@example.test' AND files_left = 1"), 1, '... a nagrobek nadal pamięta pliki');
    $admin->exec("UPDATE deleted_accounts SET deleted_at = NOW() - INTERVAL 5000 DAY WHERE email = 'anna@example.test'");
    $prune = $admin->query('CALL sp_prune_logs(90, 5)');
    $prune->fetchAll();
    $prune->closeCursor();
    eq($count("SELECT COUNT(*) FROM deleted_accounts WHERE email = 'anna@example.test'"), 1, 'sp_prune_logs nie usuwa nagrobka z files_left = 1 (nawet bardzo starego)');
    $admin->exec("UPDATE deleted_accounts SET files_left = 0, deleted_at = NOW() - INTERVAL 1 DAY WHERE email = 'anna@example.test'");   // jak po przeniesieniu
    $o = $inv('createUser');
    ok($o['error'] === '' && $count("SELECT COUNT(*) FROM virtual_users WHERE email = 'anna@example.test'") === 1, 'po przeniesieniu plików: konto utworzone ponownie');
    $repo2 = new App\Panel\UserAdminRepository($pdo);
    $rows2 = $repo2->rows('anna@example.test', [], 20, 0);
    ok(isset($rows2[0]) && $rows2[0]['reg_ip'] === null && $rows2[0]['created'] === '-', 'lista kont: bez daty i IP rejestracji poprzedniej właścicielki');
    $flags2 = $repo2->ipsForEmail('anna@example.test')['flags'];
    ok(array_key_exists('reg_ip', $flags2) && $flags2['reg_ip'] === null, 'okno banowania: bez IP rejestracji poprzedniej właścicielki');
    eq($repo2->count('192.0.2.10'), 0, 'wyszukiwanie po IP rejestracji poprzedniej właścicielki nie wiąże nowego konta');
    App\Settings\Settings::set('deleted_name_hold_days', '365');

    // "_" i "%" w wyszukiwaniu to zwykłe znaki
    $admin->exec("INSERT INTO virtual_users (domain_id, password, email, quota_mb) VALUES ($domId, 'x', 'under_score@example.test', 10)");
    eq($repo2->count('_'), $count("SELECT COUNT(*) FROM virtual_users WHERE LOCATE('_', email) > 0"), 'konta: "_" znajduje tylko adresy z podkreśleniem');
    $logs2 = new App\Panel\LogRepository($pdo);
    eq($logs2->countAudit('%'), $count("SELECT COUNT(*) FROM admin_audit_logs WHERE LOCATE('%', CONCAT_WS(' ', admin_email, action_type, details)) > 0"),
        'dziennik: "%" znajduje tylko wpisy z procentem');

    // limit wysyłki i quota sprawdzone PRZED zapisem; puste pole quoty = domyślna; 0 = świadomie bez limitu (∞)
    $_POST = ['nu_local' => 'limit.test', 'nu_domain' => 'example.test', 'nu_password' => 'Silne-Haslo-99!', 'nu_password2' => 'Silne-Haslo-99!', 'nu_send_limit' => '3000000000'];
    $o = $inv('createUser');
    ok($o['error'] !== '' && $count("SELECT COUNT(*) FROM virtual_users WHERE email = 'limit.test@example.test'") === 0, 'zbyt duży limit wysyłki: odmowa, konto NIE powstaje');
    $_POST['nu_send_limit'] = '25';
    $o = $inv('createUser');
    eq([$count("SELECT daily_send_limit FROM virtual_users WHERE email = 'limit.test@example.test'"), $count("SELECT quota_mb FROM virtual_users WHERE email = 'limit.test@example.test'")],
       [25, App\Settings\Settings::int('mailbox_default_quota_mb', 1024)], 'limit w tym samym INSERT; puste pole quoty = domyślna');
    $lt = (int) $admin->query("SELECT id FROM virtual_users WHERE email = 'limit.test@example.test'")->fetchColumn();
    $before = $count("SELECT quota_mb FROM virtual_users WHERE id = $lt");
    $_POST = ['user_id' => (string) $lt, 'user_quota_value' => '-5', 'user_quota_unit' => 'MiB', 'user_send_limit' => ''];
    $o = $inv('updateUserQuota');
    ok($o['error'] !== '' && $count("SELECT quota_mb FROM virtual_users WHERE id = $lt") === $before, 'quota -5: odmowa, nic nie zmienione (wcześniej 0 = bez limitu)');
    $_POST['user_quota_value'] = '500';
    $_POST['user_quota_unit']  = 'KiB';
    $inv('updateUserQuota');
    eq($count("SELECT quota_mb FROM virtual_users WHERE id = $lt"), 1, '500 KiB -> 1 MiB (wcześniej 0 = bez limitu)');
    $_POST['user_quota_value'] = '0';
    $o = $inv('updateUserQuota');
    ok($count("SELECT quota_mb FROM virtual_users WHERE id = $lt") === 0 && str_contains($o['success'], '∞'), 'quota 0 = świadomie bez limitu, pokazane jako ∞');
    $_POST = ['user_id' => (string) $lt, 'user_quota_value' => '10', 'user_quota_unit' => 'MiB', 'user_send_limit' => '3000000000'];
    $o = $inv('updateUserQuota');
    ok($o['error'] !== '' && $count("SELECT quota_mb FROM virtual_users WHERE id = $lt") === 0, 'okno quoty: zbyt duży limit -> odmowa, quota też NIE zmieniona');

    // ustawienia: puste / ujemne pola zostawiają zapisane wartości, za duże są przycinane
    App\Settings\Settings::set('daily_send_limit', '50');
    App\Settings\Settings::set('mailbox_default_quota_mb', '1024');
    App\Settings\Settings::set('ip_total_limit', '15');
    $_POST = ['registration_enabled' => '1', 'mailbox_quota_value' => '', 'mailbox_quota_unit' => 'MiB', 'daily_send_limit' => '', 'deleted_name_hold_days' => '',
              'accounts_per_ip' => '0', 'ip_window_hours' => '99999999', 'ip_total_limit' => '-4'];
    $inv('saveSettings');
    eq([App\Settings\Settings::int('daily_send_limit', -1), App\Settings\Settings::int('deleted_name_hold_days', -1), App\Settings\Settings::int('mailbox_default_quota_mb', -1),
        App\Settings\Settings::int('ip_window_hours', -1), App\Settings\Settings::int('ip_total_limit', -1)],
       [50, 365, 1024, 8760, 1], 'ustawienia: puste pola bez zmian (nie 0 = bez limitu), za duże przycięte, ujemne do minimum (1)');
    $zeroBefore = $count('SELECT COUNT(*) FROM virtual_users WHERE quota_mb = 0');
    $_POST = ['mailbox_quota_value' => '-3', 'mailbox_quota_unit' => 'GiB', 'apply_quota_to_all' => '1'];
    $o = $inv('saveSettings');
    ok($o['error'] !== '' && App\Settings\Settings::int('mailbox_default_quota_mb', -1) === 1024 && $count('SELECT COUNT(*) FROM virtual_users WHERE quota_mb = 0') === $zeroBefore,
        'domyślna quota ujemna: odmowa, "zastosuj do wszystkich" NIE uruchomione');
    $_POST = ['rc_max_recipients' => '-5', 'rc_login_rate_limit' => '', 'pf_message_rate_limit' => '1e30'];
    $inv('saveAntispam');
    eq([App\Settings\Settings::int('rc_max_recipients', -1), App\Settings\Settings::int('pf_message_rate_limit', -1)], [10, 999999],
       'antyspam: ujemna wartość bez zmian (nie 0 = bez limitu w Roundcube), 1e30 przycięte');

    // ręczny ban i przeniesienie bana nie przyjmują adresów zaufanych (127.0.0.1, ::1, SCAN_TRUSTED_IPS i ich postaci ::ffff:)
    foreach (['127.0.0.1', '::1', '198.51.100.99', '::ffff:198.51.100.99'] as $tip) {
        $_POST = ['f2b_ip' => $tip, 'f2b_jail' => 'recidive'];
        $o1 = $inv('fail2banBanIp');
        $_POST = ['ip' => $tip, 'from_jail' => '', 'to_jail' => 'recidive'];
        $o2 = $inv('moveBan');
        ok(str_contains($o1['error'], 'SCAN_TRUSTED_IPS') && str_contains($o2['error'], 'SCAN_TRUSTED_IPS'), "ręczny ban i przeniesienie: $tip odrzucony jako zaufany");
    }

    /* -------------------------------------------------------------- bany czasowe */
    group('1.4.0: bany czasowe — koniec każdego bana w f2b_tempbans, panel zdejmuje je sam (TempBans)');
    // Fail2Ban na niby: ban/unban/lista jak fail2ban-client (unban adresu, który nie jest zbanowany = błąd)
    $f2b = ['banned' => [], 'calls' => [], 'failBan' => false, 'failUnban' => [], 'listNull' => false];
    $tb = new App\Panel\TempBans(
        App\Support\Database::pdo(),
        static function (string $jail, string $ip) use (&$f2b): bool {
            $f2b['calls'][] = "ban $jail $ip";
            if ($f2b['failBan']) { return false; }
            $f2b['banned'][$ip] = true;
            return true;
        },
        static function (string $jail, string $ip) use (&$f2b): bool {
            $f2b['calls'][] = "unban $jail $ip";
            if (in_array($ip, $f2b['failUnban'], true) || !isset($f2b['banned'][$ip])) { return false; }
            unset($f2b['banned'][$ip]);
            return true;
        },
        static function (string $jail) use (&$f2b): ?array {
            $f2b['calls'][] = "list $jail";
            return $f2b['listNull'] ? null : array_map('strval', array_keys($f2b['banned']));
        }
    );
    $left = static function (string $ip) use ($admin): ?int {
        $s = $admin->prepare('SELECT TIMESTAMPDIFF(SECOND, NOW(), until_ts) FROM f2b_tempbans WHERE ip = ?');
        $s->execute([App\Support\Ip::toBin($ip)]);
        $v = $s->fetchColumn();
        return $v === false ? null : (int) $v;
    };
    $due = static fn(string $ip) => $admin->prepare('UPDATE f2b_tempbans SET until_ts = NOW() - INTERVAL 1 SECOND WHERE ip = ?')->execute([App\Support\Ip::toBin($ip)]);

    ok($tb->ban('203.0.113.5', 3600) && isset($f2b['banned']['203.0.113.5']), 'ban na godzinę: adres w jailu');
    ok(abs((int) $left('203.0.113.5') - 3600) <= 5, 'koniec bana zapisany: za ~3600 s (' . var_export($left('203.0.113.5'), true) . ')');
    ok($tb->ban('2001:DB8::5', 86400) && in_array('ban panel-tempban 2001:db8::5', $f2b['calls'], true), 'IPv6 trafia do Fail2Ban w postaci kanonicznej (2001:db8::5)');
    ok($tb->ban('::ffff:203.0.113.9', 600) && in_array('ban panel-tempban 203.0.113.9', $f2b['calls'], true), '::ffff:a.b.c.d = ten sam klient IPv4');
    ok($tb->ban('203.0.113.5', 7200) && abs((int) $left('203.0.113.5') - 7200) <= 5, 'ponowny ban tego samego adresu: nowy koniec');
    eq($count('SELECT COUNT(*) FROM f2b_tempbans WHERE ip = ?', [App\Support\Ip::toBin('203.0.113.5')]), 1, 'jeden wiersz na adres');
    $f2b['failBan'] = true;
    ok(!$tb->ban('203.0.113.6', 600) && $left('203.0.113.6') === null, 'ban nieudany: wiersz cofnięty');
    ok(!$tb->ban('203.0.113.5', 60) && abs((int) $left('203.0.113.5') - 7200) <= 5, 'ban nieudany przy istniejącym wierszu: poprzedni koniec zostaje');
    $f2b['failBan'] = false;
    $before = count($f2b['calls']);
    ok(!$tb->ban('203.0.113.7', 59) && !$tb->ban('203.0.113.7', 7776001) && !$tb->ban('nie-adres', 600), 'czas spoza 60 s .. 90 dni albo zły adres: odmowa');
    eq(count($f2b['calls']), $before, '...bez wołania Fail2Ban');
    $ends = $tb->ends();
    ok(isset($ends['203.0.113.5'], $ends['2001:db8::5']) && abs($ends['2001:db8::5'] - time() - 86400) <= 5, 'ends(): adres => koniec (epoch) dla listy banów');

    $due('203.0.113.5');
    $f2b['calls'] = [];
    eq($tb->expire(), ['203.0.113.5'], 'expire(): zdejmuje tylko bany po czasie');
    ok(!isset($f2b['banned']['203.0.113.5']) && $left('203.0.113.5') === null && $left('2001:db8::5') !== null, '...ban zdjęty w Fail2Ban, wiersz usunięty, reszta nietknięta');
    eq($tb->expire(), [], 'expire() drugi raz: nic do zrobienia');
    $tb->ban('203.0.113.7', 600);
    $due('203.0.113.7');
    $f2b['failUnban'] = ['203.0.113.7'];
    eq($tb->expire(), [], 'unban się nie udał, adres nadal zbanowany: wiersz zostaje (następny przebieg spróbuje znowu)');
    ok($left('203.0.113.7') !== null, '...wiersz jest');
    $tb->ban('203.0.113.8', 600);
    $due('203.0.113.8');
    unset($f2b['banned']['203.0.113.8']);                       // zdjęty ręcznie (fail2ban-client) przed czasem
    eq($tb->expire(), ['203.0.113.8'], 'adresu już nie ma w jailu: wiersz usunięty bez ponawiania');
    $f2b['listNull'] = true;
    $f2b['calls'] = [];
    eq($tb->expire(), [], 'Fail2Ban nie odpowiada: nic nie usunięte');
    ok($left('203.0.113.7') !== null && $f2b['calls'] === ['unban panel-tempban 203.0.113.7', 'list panel-tempban'], '...i przebieg przerwany po pierwszym adresie: ' . implode(' | ', $f2b['calls']));
    $f2b['listNull'] = false;
    $f2b['failUnban'] = [];
    eq($tb->expire(), ['203.0.113.7'], 'Fail2Ban znowu działa: zaległy ban zdjęty');
    $tb->forget('2001:DB8::5');
    ok($left('2001:db8::5') === null, 'forget(): ban zdjęty ręcznie albo przeniesiony — koniec zapomniany');

    // skrypt timera: jako osobny proces z tym samym .env (tryb dev: Commands nic nie robi, więc nic nie zdejmuje)
    $tb->ban('203.0.113.9', 600);
    $due('203.0.113.9');
    $p = proc_open(['php', $root . '/app/cli/tempban-expire.php'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    $cliOut = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]);
    $cliCode = proc_close($p);
    ok($cliCode === 0 && $cliOut === '' && $left('203.0.113.9') !== null, 'app/cli/tempban-expire.php: kod 0, cisza, bez Fail2Ban wiersz zostaje (' . $cliCode . ' ' . trim($cliOut) . ')');
    $admin->exec('DELETE FROM f2b_tempbans');

    /* -------------------------------------------------------------- panel_config.php */
    group('config/panel_config.php: tylko dozwolone klucze rc_*');
    $admin->exec("REPLACE INTO app_settings (k, v) VALUES ('rc_imap_host', 'evil.example'), ('rc_smtp_host', 'evil.example'), ('rc_max_recipients', '12'), ('rc_sendmail_delay', 'abc'), ('rc_login_rate_limit', '999999'), ('rc_des_key', 'x'), ('rcx', 'y')");
    file_put_contents($tmp . '/pc.php', '<?php $config = []; require ' . var_export($root . '/config/panel_config.php', true) . '; echo json_encode($config);');
    $p = proc_open(['php', $tmp . '/pc.php'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pp2, null, array_merge(getenv(), ['MAILPANEL_ENV_FILE' => $tmp . '/app.env']));
    $json = (string) stream_get_contents($pp2[1]); fclose($pp2[1]); fclose($pp2[2]); proc_close($p);
    $cfg = json_decode($json, true) ?: [];
    ok(!isset($cfg['imap_host']) && !isset($cfg['smtp_host']) && !isset($cfg['des_key']) && !isset($cfg['x']), 'obce klucze rc_* (imap_host, smtp_host, des_key) NIE trafiają do konfiguracji Roundcube');
    eq($cfg['max_recipients'] ?? null, 12, 'rc_max_recipients zaimportowany');
    ok(!isset($cfg['sendmail_delay']), 'wartość nienumeryczna odrzucona');
    eq($cfg['login_rate_limit'] ?? null, 1000, 'wartość poza zakresem przycięta');
    eq($cfg['force_https'] ?? null, true, 'stałe ustawienia panelu zachowane');

    /* -------------------------------------------------------------- 1.3.2: przechowywanie logów */
    group('1.3.2: przechowywanie logów — sp_prune_logs przez konto panelu, reguły per tabela, "bez limitu", raz na dobę');
    $schemaProc = static function (string $file) use ($root): string {
        preg_match('/CREATE OR REPLACE PROCEDURE sp_prune_logs.*?END\$\$/s', (string) file_get_contents($root . '/sql/' . $file), $m);
        return (string) preg_replace('/\s+/', ' ', $m[0] ?? '');
    };
    ok($schemaProc('panel_schema.sql') !== '' && $schemaProc('panel_schema.sql') === $schemaProc('upgrade_1.4.0.sql'), 'procedura w panel_schema.sql = ta z upgrade_1.4.0.sql (najnowszy plik, który ją zmienia)');
    $ago = static fn(int $d): string => date('Y-m-d H:i:s', time() - $d * 86400);
    $admin->exec('DELETE FROM admin_audit_logs'); $admin->exec('DELETE FROM admin_login_attempts'); $admin->exec('DELETE FROM registration_events');
    $admin->exec('DELETE FROM user_login_events'); $admin->exec('DELETE FROM send_counters'); $admin->exec('DELETE FROM deleted_accounts');
    $ip = inet_pton('203.0.113.9');
    $ia = $admin->prepare("INSERT INTO admin_audit_logs (admin_email, ip, action_type, details, created_at) VALUES ('boss@example.test', ?, ?, '', ?)");
    $ia->execute([$ip, 'OLD', $ago(400)]); $ia->execute([$ip, 'RECENT', $ago(10)]);
    $il = $admin->prepare("INSERT INTO admin_login_attempts (username, success, details, ip, created_at) VALUES ('boss@example.test', 0, '', ?, ?)");
    $il->execute([$ip, $ago(400)]); $il->execute([$ip, $ago(10)]);
    $ir = $admin->prepare('INSERT INTO registration_events (ip, email, status, reason, created_at) VALUES (?, ?, ?, \'\', ?)');
    $ir->execute([$ip, 'jan@example.test', 'success', $ago(400)]);        // istniejące konto: zostaje (data / IP rejestracji)
    $ir->execute([$ip, 'gone@example.test', 'success', $ago(400)]);       // konta już nie ma: usuwane
    $ir->execute([$ip, 'x@example.test', 'failed', $ago(400)]);           // nieudana, stara: usuwana
    $ir->execute([$ip, 'y@example.test', 'failed', $ago(10)]);            // świeża: zostaje
    $iu = $admin->prepare('INSERT INTO user_login_events (email, ip, created_at) VALUES (?, ?, ?)');
    $iu->execute(['jan@example.test', $ip, $ago(500)]); $iu->execute(['jan@example.test', $ip, $ago(400)]); $iu->execute(['jan@example.test', $ip, $ago(5)]);
    $admin->prepare('INSERT INTO virtual_users (domain_id, password, email, quota_mb) VALUES (?, ?, ?, 10)')->execute([$domId, 'x', 'quiet@example.test']);
    $iu->execute(['quiet@example.test', $ip, $ago(400)]);                 // jedyne (ostatnie) logowanie istniejącego konta: zostaje
    $iu->execute(['ghost2@example.test', $ip, $ago(400)]);                // konta nie ma: usuwane
    $is = $admin->prepare('INSERT INTO send_counters (email, day, cnt) VALUES (?, ?, 1)');
    $is->execute(['jan@example.test', date('Y-m-d', time() - 400 * 86400)]); $is->execute(['jan@example.test', date('Y-m-d')]);
    $id = $admin->prepare('INSERT INTO deleted_accounts (email, deleted_at) VALUES (?, ?)');
    $id->execute(['del1@example.test', $ago(400)]); $id->execute(['del2@example.test', $ago(200)]);
    $admin->exec("REPLACE INTO app_settings (k, v) VALUES ('log_retention_days', '365'), ('deleted_name_hold_days', '365'), ('log_retention_last_run', '0')");
    App\Settings\Settings::flush();
    eq(App\Admin\LogRetention::days(), 365, 'okno z ustawień: 365 dni');
    $_SESSION['admin_username'] = 'boss@example.test';
    $n = App\Admin\LogRetention::run($pdo, false);
    eq($n, ['audit' => 1, 'admin_logins' => 1, 'registrations' => 2, 'user_logins' => 3, 'send_counters' => 1, 'deleted_accounts' => 1],
        '365 dni: usunięte dokładnie stare wpisy (audyt 1, logowania admina 1, rejestracje 2, logowania kont 3, liczniki 1, blokady 1)');
    eq($count("SELECT COUNT(*) FROM admin_audit_logs WHERE action_type = 'RECENT'"), 1, 'świeży wpis audytu zostaje');
    eq($count("SELECT COUNT(*) FROM registration_events WHERE email = 'jan@example.test' AND status = 'success'"), 1, 'udana rejestracja istniejącego konta zostaje (mimo wieku)');
    eq($count("SELECT COUNT(*) FROM user_login_events WHERE email = 'jan@example.test'"), 1, 'jan: zostaje tylko ostatnie (świeże) logowanie');
    eq($count("SELECT COUNT(*) FROM user_login_events WHERE email = 'quiet@example.test'"), 1, 'konto logujące się dawno: ostatnie logowanie zostaje (kolumna "ostatnie logowanie")');
    eq($count('SELECT COUNT(*) FROM send_counters WHERE day = ?', [date('Y-m-d')]), 1, 'licznik dzisiejszy zostaje');   // dzień wstawiony wyżej (strefa testu), nie CURDATE() bazy
    eq($count("SELECT COUNT(*) FROM deleted_accounts WHERE email = 'del2@example.test'"), 1, 'blokada nazwy młodsza niż okno zostaje');
    eq($count("SELECT COUNT(*) FROM admin_audit_logs WHERE action_type = 'LOG_RETENTION' AND admin_email = 'system'"), 1, 'jeden zbiorczy wpis audytu na przebieg (automatyczny: "system")');
    $n = App\Admin\LogRetention::run($pdo, false);
    ok(array_sum($n) === 0 && $count("SELECT COUNT(*) FROM admin_audit_logs WHERE action_type = 'LOG_RETENTION'") === 1, 'przebieg bez niczego do usunięcia: zero i bez nowego wpisu audytu');
    // blokada nazw dłuższa niż okno chroni wpis
    $id->execute(['del3@example.test', $ago(400)]);
    $admin->exec("REPLACE INTO app_settings (k, v) VALUES ('deleted_name_hold_days', '3650')");
    App\Admin\LogRetention::run($pdo, false);
    eq($count("SELECT COUNT(*) FROM deleted_accounts WHERE email = 'del3@example.test'"), 1, 'blokada nazw 3650 dni: wpis z 400 dni temu zostaje (ochrona przed ponowną rejestracją)');
    $admin->exec("REPLACE INTO app_settings (k, v) VALUES ('deleted_name_hold_days', '365')");
    // "bez limitu"
    $ia->execute([$ip, 'OLD2', $ago(800)]);
    $admin->exec("REPLACE INTO app_settings (k, v) VALUES ('log_retention_days', '0')");
    App\Settings\Settings::flush();
    $n = App\Admin\LogRetention::run($pdo, false);
    ok(array_sum($n) === 0 && $count("SELECT COUNT(*) FROM admin_audit_logs WHERE action_type = 'OLD2'") === 1, '"bez limitu": nic nie jest usuwane (wpis sprzed 800 dni zostaje)');
    ok(!App\Admin\LogRetention::due(time()), '"bez limitu": automatyczne sprzątanie nie jest nawet planowane');
    // dolna granica 90 dni pilnowana przez procedurę (także przy bezpośrednim wywołaniu przez konto panelu)
    $ia->execute([$ip, 'D50', $ago(50)]); $ia->execute([$ip, 'D100', $ago(100)]);
    $callProc = static function (PDO $p, int $days): array {
        $st = $p->query('CALL sp_prune_logs(' . $days . ', 5)');
        $row = $st->fetch(PDO::FETCH_ASSOC) ?: [];
        while ($st->nextRowset()) { /* status CALL */ }
        $st->closeCursor();
        return $row;
    };
    $callProc($panelPdo, 10);
    ok($count("SELECT COUNT(*) FROM admin_audit_logs WHERE action_type = 'D50'") === 1 && $count("SELECT COUNT(*) FROM admin_audit_logs WHERE action_type = 'D100'") === 0,
        'procedura z oknem 10 dni: działa jak 90 (wpis sprzed 50 dni zostaje, sprzed 100 usunięty)');
    $row = $callProc($panelPdo, 0);
    ok($row !== [] && array_sum(array_map('intval', $row)) === 0 && $count("SELECT COUNT(*) FROM admin_audit_logs WHERE action_type = 'D50'") === 1, 'procedura z 0 dni: nic');
    // aktualizacja 1.3.1 -> 1.3.2 na istniejącej bazie: procedury i uprawnienia nie ma, ustawienie ustawione wcześniej zostaje
    $admin->exec('DROP PROCEDURE IF EXISTS sp_prune_logs');
    $admin->exec("REPLACE INTO app_settings (k, v) VALUES ('log_retention_days', '180')");
    runSql($admin, $mk('upgrade_1.3.2.sql', $names));
    App\Settings\Settings::flush();
    ok($callProc($panelPdo, 365) !== [], 'upgrade_1.3.2.sql: procedura odtworzona, konto panelu ma EXECUTE');
    eq($count("SELECT COUNT(*) FROM app_settings WHERE k = 'log_retention_days' AND v = '180'"), 1, 'upgrade_1.3.2.sql: wcześniej wybrany okres zostaje (INSERT IGNORE)');
    $admin->exec("REPLACE INTO app_settings (k, v) VALUES ('log_retention_days', '365')");
    runSql($admin, $mk('upgrade_1.3.2.sql', $names));
    ok($callProc($panelPdo, 365) !== [], 'upgrade_1.3.2.sql: drugie uruchomienie bez błędów (idempotentny)');
    $denied($panelPdo, 'DELETE FROM admin_audit_logs', 'mailpanel nadal bez DELETE na admin_audit_logs (tylko procedura)');
    $denied($panelPdo, 'DELETE FROM deleted_accounts', 'mailpanel bez DELETE na deleted_accounts');
    // 1.4.0: upgrade_1.4.0.sql na bazie w stanie 1.3.2 — bez files_left i indeksu, tabela panelu w innej kolacji (MariaDB 11.5+)
    $admin->exec('ALTER TABLE deleted_accounts DROP COLUMN files_left');
    $admin->exec('DROP INDEX idx_source ON virtual_aliases');
    // stan 1.3.2: bez tabeli i bez jej grantu (DROP TABLE grantu NIE odbiera — osobny REVOKE)
    $admin->exec("REVOKE SELECT, INSERT, UPDATE, DELETE ON `$db`.f2b_tempbans FROM '$uPanel'@'localhost'");
    $admin->exec('DROP TABLE f2b_tempbans');
    $admin->exec('ALTER TABLE throttle_events CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_uca1400_ai_ci');
    $mailColl = (string) $admin->query("SELECT REPLACE(COLLATION_NAME, 'utf8mb3_', 'utf8mb4_') FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'virtual_users' AND COLUMN_NAME = 'email'")->fetchColumn();
    runSql($admin, $mk('upgrade_1.4.0.sql', $names));
    eq($count("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'deleted_accounts' AND COLUMN_NAME = 'files_left'"), 1,
        'upgrade_1.4.0.sql: kolumna deleted_accounts.files_left');
    eq($count("SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'virtual_aliases' AND INDEX_NAME = 'idx_source'"), 1,
        'upgrade_1.4.0.sql: indeks virtual_aliases.source');
    eq((string) $admin->query("SELECT TABLE_COLLATION FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'throttle_events'")->fetchColumn(), $mailColl,
        'upgrade_1.4.0.sql: tabela panelu dostaje kolację virtual_users.email');
    ok($callProc($panelPdo, 365) !== [], 'upgrade_1.4.0.sql: procedura odtworzona, konto panelu ma EXECUTE');
    $tbUp = new App\Panel\TempBans($panelPdo, static fn() => true, static fn() => true, static fn() => []);
    ok($tbUp->ban('203.0.113.77', 600) && $tbUp->ends() !== [], 'upgrade_1.4.0.sql: tabela f2b_tempbans i grant konta panelu (zapis, odczyt)');
    $tbUp->forget('203.0.113.77');
    runSql($admin, $mk('upgrade_1.4.0.sql', $names));
    ok($callProc($panelPdo, 365) !== [], 'upgrade_1.4.0.sql: drugie uruchomienie bez błędów (idempotentny)');
    // raz na dobę
    $admin->exec("REPLACE INTO app_settings (k, v) VALUES ('log_retention_days', '365'), ('log_retention_last_run', '0')");
    App\Settings\Settings::flush();
    $now = time();
    ok(App\Admin\LogRetention::due($now), 'po dobie (znacznik 0): sprzątanie należne');
    ok(App\Admin\LogRetention::claim($pdo, $now), 'pierwsze żądanie zajmuje przebieg');
    ok(!App\Admin\LogRetention::claim($pdo, $now), 'drugie (równoległe) żądanie tej samej doby: odpuszcza');
    ok(!App\Admin\LogRetention::claim($pdo, $now + 3600), '... i godzinę później też');
    ok(!App\Admin\LogRetention::due($now + 3600), 'due(): nie przed upływem doby');
    ok(App\Admin\LogRetention::due($now + 86400), 'due(): znowu po dobie');
    ok(App\Admin\LogRetention::claim($pdo, $now + 86400), 'po dobie przebieg można zająć ponownie');
    $n = App\Admin\LogRetention::run($pdo, true, $now + 86400);
    ok($count("SELECT COUNT(*) FROM admin_audit_logs WHERE action_type = 'LOG_RETENTION' AND admin_email = 'boss@example.test'") === 1, 'ręczny przebieg: wpis audytu z administratorem (także gdy nic nie usunął)');
    App\Settings\Settings::flush();
    eq(App\Admin\LogRetention::lastRun(), $now + 86400, 'ręczny przebieg przesuwa znacznik ostatniego sprzątania');
    unset($_SESSION['admin_username']);
} catch (Throwable $e) {
    ok(false, 'WYJĄTEK: ' . $e::class . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine());
}

echo "\n";
echo ($T['fail'] === 0 ? 'OK' : 'BŁĘDY') . ": {$T['pass']} przeszło, {$T['fail']} nie\n";
foreach ($T['fails'] as $f) { echo "  FAIL: $f\n"; }
exit($T['fail'] === 0 ? 0 : 1);
