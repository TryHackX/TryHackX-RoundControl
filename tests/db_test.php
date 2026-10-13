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
        $r = $call('perform_login', ['password' => 'zle-haslo-' . $i, 'target' => 'admin'], $ipA);
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
    $r = $call('perform_login', ['password' => 'Admin-Pass-12345!', 'target' => 'admin'], $ipB);
    ok(!empty($r['ok']) && $r['redirect'] === 'panel/index.php', 'poprawne hasło admina z innego IP: wejście');
    ok(!empty($_SESSION['admin_id']) && !empty($_SESSION['admin_login_at']) && !empty($_SESSION['admin_fp']), 'sesja admina: id, czas logowania, odcisk hasła');
    eq($count("SELECT COUNT(*) FROM admin_login_attempts WHERE username='boss@example.test' AND success=0"), 5, 'udane logowanie nie skasowało wierszy porażek');
    unset($_SESSION['admin_id'], $_SESSION['admin_login_at'], $_SESSION['admin_fp'], $_SESSION['admin_username']);

    // udane logowanie IMAP ofiary (sp_register_login) nie zeruje licznika atakującego
    $before = $count("SELECT COUNT(*) FROM throttle_events WHERE kind='webmail' AND email='jan@example.test'");
    for ($i = 0; $i < 3; $i++) {
        $call('check_identity', ['email' => 'jan@example.test'], '203.0.113.70');
        $call('perform_login', ['password' => 'zle', 'target' => 'webmail'], '203.0.113.70');
    }
    $afterFails = $count("SELECT COUNT(*) FROM throttle_events WHERE kind='webmail' AND email='jan@example.test'");
    eq($afterFails - $before, 3, 'webmail: porażki zapisane w throttle_events');
    for ($i = 0; $i < 4; $i++) {      // legalne logowania z różnych IP (każde nowe IP przycina user_login_events)
        $panelPdo->prepare('CALL sp_register_login(?, ?)')->execute(['jan@example.test', '198.18.0.' . (10 + $i)]);
    }
    eq($count("SELECT COUNT(*) FROM throttle_events WHERE kind='webmail' AND email='jan@example.test'"), $afterFails, 'udane logowania (sp_register_login) nie ruszają licznika porażek');
    $call('check_identity', ['email' => 'jan@example.test'], '203.0.113.70');
    $r = $call('perform_login', ['password' => 'DevTest123!', 'target' => 'webmail'], '203.0.113.70');
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
    $r = $reg('jan', '203.0.113.110');
    ok(!empty($r['ok']), 'z blokadą wyłączoną (0 dni) i bez aliasów nazwa wraca do puli');
    App\Settings\Settings::set('deleted_name_hold_days', '365');

    // kontroler panelu: usuwanie i tworzenie z nadpisaniem zarezerwowanej nazwy (metody prywatne przez refleksję)
    $_SESSION['admin_id'] = (int) $admin->query("SELECT id FROM panel_admins LIMIT 1")->fetchColumn();
    $_SESSION['admin_username'] = 'boss@example.test';
    $panel = new App\Panel\PanelController();
    $inv = static function (string $method) use ($panel): array {
        $m = new ReflectionMethod($panel, $method);
        $m->setAccessible(true);
        foreach (['success', 'error'] as $p) { $rp = new ReflectionProperty($panel, $p); $rp->setAccessible(true); $rp->setValue($panel, ''); }
        $m->invoke($panel);
        $out = [];
        foreach (['success', 'error'] as $p) { $rp = new ReflectionProperty($panel, $p); $rp->setAccessible(true); $out[$p] = $rp->getValue($panel); }
        return $out;
    };
    $_POST = ['nu_local' => 'Admin', 'nu_domain' => 'example.test', 'nu_password' => 'Silne-Haslo-99!', 'nu_password2' => 'Silne-Haslo-99!', 'nu_quota_value' => '10', 'nu_quota_unit' => 'MB'];
    $o = $inv('createUser');
    ok($o['error'] !== '' && $o['success'] === '', 'admin: zarezerwowana nazwa bez zgody odrzucona (' . $o['error'] . ')');
    $_POST['nu_allow_reserved'] = '1';
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
    ok($o['error'] === '' && str_contains($o['success'], 'test.user@example.test'), 'reset hasła skrzynki: adres wielkimi literami przyjęty, komunikat małymi');
    $_POST = ['user_id' => (string) $mbx, 'user_quota_value' => '1e30', 'user_quota_unit' => 'GB', 'user_send_limit' => ''];
    $inv('updateUserQuota');
    eq($count('SELECT quota_mb FROM virtual_users WHERE id = ?', [$mbx]), App\Panel\Quota::MAX_MB, 'quota: ogromna wartość przycięta do MAX_MB (nie 0 = bez limitu)');

    // render całej strony panelu: bez notice/warning, z nowymi polami, bez sekretu
    $render = new ReflectionMethod($panel, 'renderPage');
    $render->setAccessible(true);
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
    $guard->setAccessible(true);
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
    eq($count("SELECT cnt FROM send_counters WHERE email = 'sender@example.test' AND day = CURDATE()"), 5, 'licznik w bazie = 5 (odrzucone nie zwiększają)');
    [$act, ] = $policy([$rq('free@example.test', 500), $rq('nobody@elsewhere.test', 3), ['request' => 'smtpd_access_policy', 'sasl_username' => ''], $rq('global@example.test', 4), $rq('global@example.test', 1)]);
    ok(str_starts_with($act[0] ?? '', 'DUNNO'), 'limit 0 = bez limitu');
    ok(str_starts_with($act[1] ?? '', 'DUNNO'), 'nieznane konto: DUNNO');
    ok(str_starts_with($act[2] ?? '', 'DUNNO'), 'brak sasl_username: DUNNO');
    ok(str_starts_with($act[3] ?? '', 'DUNNO') && str_starts_with($act[4] ?? '', 'REJECT'), 'limit globalny 4: cztery przechodzą, piąty odrzucony');
    [$act, ] = $policy([['request' => 'smtpd_access_policy', 'sasl_username' => 'sender@example.test']]);
    ok(str_starts_with($act[0] ?? '', 'REJECT'), 'brak recipient_count = 1 odbiorca (limit wyczerpany)');
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
} catch (Throwable $e) {
    ok(false, 'WYJĄTEK: ' . $e::class . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine());
}

echo "\n";
echo ($T['fail'] === 0 ? 'OK' : 'BŁĘDY') . ": {$T['pass']} przeszło, {$T['fail']} nie\n";
foreach ($T['fails'] as $f) { echo "  FAIL: $f\n"; }
exit($T['fail'] === 0 ? 0 : 1);
