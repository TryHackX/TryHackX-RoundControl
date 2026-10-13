<?php
declare(strict_types=1);

/**
 * Powtarzalny, bezzależnościowy runner testów panelu.
 * Uruchom:  php tests/run.php
 *
 * Testy integracyjne używają lokalnej bazy (mailserver) i sprzątają po sobie
 * oraz przywracają zmienione ustawienia. Wymaga APP_ENV=dev.
 *
 * 1.1.0: odmawia pracy poza CLI i poza APP_ENV=dev (zapisuje do bazy z .env — na produkcji tego nie wolno).
 * Samowystarczalne testy 1.1.0 (własna, tymczasowa baza): tests/db_test.php; bez bazy: security_test.php,
 * scripts_test.php, sudoers_test.php.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require __DIR__ . '/../app/bootstrap.php';

if (!\App\Support\Env::isDev()) {
    fwrite(STDERR, "tests/run.php pisze do bazy z konfiguracji — uruchamiaj tylko z APP_ENV=dev (dev, nie produkcja).\n");
    exit(2);
}

use App\Panel\Pagination;
use App\Panel\Quota;
use App\Panel\UserAdminRepository;
use App\Panel\BanRepository;
use App\Panel\LogRepository;
use App\Panel\Stats;
use App\Portal\PortalController;
use App\Security\Password;
use App\Security\Recaptcha;
use App\Settings\Settings;
use App\Support\Database;
use App\Users\UserRepository;

$T = ['pass' => 0, 'fail' => 0, 'fails' => []];
function ok(bool $cond, string $label): void
{
    global $T;
    if ($cond) { $T['pass']++; }
    else { $T['fail']++; $T['fails'][] = $label; }
}
function eq(mixed $a, mixed $b, string $label): void
{
    ok($a === $b, $label . ($a === $b ? '' : ' (got=' . var_export($a, true) . ' exp=' . var_export($b, true) . ')'));
}
function group(string $name): void { echo "\n# $name\n"; }

$_SERVER['REQUEST_METHOD'] = 'POST';
$_SERVER['REMOTE_ADDR']    = '127.0.0.1';
$_SESSION = ['csrf' => 'TESTTOKEN'];

$pdo = Database::pdo();

/* ============ UNIT ============ */

group('Password');
$h = Password::dovecotHash('DevTest123!');
ok(str_starts_with($h, '$6$'), 'dovecotHash -> $6$');
ok(Password::verifyDovecot('DevTest123!', $h), 'verifyDovecot poprawne');
ok(!Password::verifyDovecot('złe', $h), 'verifyDovecot błędne');
$a = Password::adminHash('Zaq12wsx!');
ok(Password::verifyAdmin('Zaq12wsx!', $a) && !Password::verifyAdmin('x', $a), 'admin argon2 verify');
// polityka: tymczasowo znane wymogi
$origMin = Settings::get('pwd_min_length');
Settings::set('pwd_min_length', '10');
Settings::set('pwd_require_upper', '1');
Settings::set('pwd_require_digit', '1');
Settings::set('pwd_require_special', '1');
ok(Password::meetsPolicy('Abcdef1!xx'), 'polityka: spełnia');
ok(!Password::meetsPolicy('short1!'), 'polityka: za krótkie');
ok(!Password::meetsPolicy('abcdefghij1!'), 'polityka: bez dużej litery');
Settings::set('pwd_min_length', $origMin ?? '10');

group('Quota');
eq(Quota::toMb(2, 'GB'), 2048, 'toMb GB');
eq(Quota::toMb(50, 'MB'), 50, 'toMb MB');
eq(Quota::toBest(2048), [2, 'GB'], 'toBest GB');
eq(Quota::toBest(50), [50, 'MB'], 'toBest MB');
eq(Quota::display(1536), '1.5 GB', 'display GB');
eq(Quota::normalizeUnit('XX'), 'MiB', 'normalizeUnit fallback');

group('Pagination');
$p = Pagination::render(2, 20, 100, 'users');
ok(str_contains($p, 'data-nav="users"') && str_contains($p, 'data-page="3"'), 'render data-attrs');
ok(str_contains($p, 'active'), 'render active');
eq(Pagination::perOrDefault(50), 50, 'perOrDefault whitelist');
eq(Pagination::perOrDefault(7), 20, 'perOrDefault fallback');
eq(Pagination::clamp(99, 100, 20), 5, 'clamp do ostatniej strony');

group('Settings (cache)');
Settings::set('__test_key', 'abc');
eq(Settings::get('__test_key'), 'abc', 'get po set');
eq(Settings::int('__test_key', 0), 0, 'int z nie-liczby');
$pdo->prepare('DELETE FROM app_settings WHERE k=?')->execute(['__test_key']);

group('UserAdminRepository::parseSort');
$ur = new UserAdminRepository($pdo);
eq($ur->parseSort('email:desc,quota:asc,evil:desc'), ['email' => 'DESC', 'quota' => 'ASC'], 'whitelist kolumn');

/* ============ INTEGRACJA ============ */
// Dane środowiskowe wykrywane z żywej bazy (bez zaszytej domeny/kont).
$TEST_DOMAIN = (new UserRepository($pdo))->domains()[0]
    ?? throw new RuntimeException('Brak domen w virtual_domains — nie da się uruchomić testów integracyjnych.');
$anyUser = (string) ($pdo->query('SELECT email FROM virtual_users ORDER BY id LIMIT 1')->fetchColumn() ?: '');
$TEST_LOCAL = $anyUser !== '' ? explode('@', $anyUser)[0] : '';
$TEST_ADMIN = (string) ($pdo->query('SELECT email FROM panel_admins ORDER BY id LIMIT 1')->fetchColumn() ?: '');

group('Repozytoria (żywa baza)');
[$rows, $total] = $ur->fetch('', $ur->parseSort('id:desc'), 5, 0);
ok($total >= 10 && count($rows) <= 5 && count($rows) > 0, 'fetch total + LIMIT');
ok(array_key_exists('reg_ip', $rows[0]), 'fetch kolumny IP');
[$r2, $t2] = $ur->fetch($TEST_LOCAL, [], 20, 0);
ok($t2 >= 1 && str_contains($r2[0]['email'], $TEST_LOCAL), 'search email');
ok(is_int(($ur->fetch('185.', [], 20, 0))[1]), 'search IP nie wywala');
ok(isset($ur->ipsForEmail($anyUser)['flags']), 'ipsForEmail');
ok(is_array((new BanRepository($pdo))->activeLocal()), 'BanRepository::activeLocal');
$lr = new LogRepository($pdo);
ok($lr->countRegistration() >= 4 && count($lr->registrationPage(3, 0)) <= 3, 'LogRepository reg');
ok(Stats::collect($pdo, 3)['banned_ips'] === 3, 'Stats::collect');

group('Portal (kontroler, temp user)');
$origRc = Settings::get('recaptcha_enabled', '0');
Settings::set('recaptcha_enabled', '0');
$email = 'z_test_run@' . $TEST_DOMAIN;
$users = new UserRepository($pdo);
try {
    $pdo->prepare('DELETE FROM virtual_users WHERE email=?')->execute([$email]);
    $pdo->prepare('DELETE FROM user_login_events WHERE email=?')->execute([$email]);
    $users->create((int) $users->domainIdForEmail($email), Password::dovecotHash('DevTest123!'), $email, 50);

    $call = static function (string $action, array $post) {
        $post['_csrf'] = 'TESTTOKEN';
        $_POST = $post;
        return (new PortalController())->handle($action);
    };

    $r = $call('check_availability', ['local' => $TEST_LOCAL, 'domain' => $TEST_DOMAIN]);
    eq($r['status'] ?? '', 'taken', 'availability taken');
    $r = $call('check_availability', ['local' => 'zzz_' . time(), 'domain' => $TEST_DOMAIN]);
    eq($r['status'] ?? '', 'free', 'availability free');

    if ($TEST_ADMIN !== '') {
        $r = $call('check_identity', ['email' => $TEST_ADMIN]);
        ok(!empty($r['ok']), 'identity admin');
    }

    $r = $call('check_identity', ['email' => $email]);
    ok(!empty($r['ok']) && ($r['focus_id'] ?? '') === 'inpPassword', 'identity user -> hasło');

    $r = $call('perform_login', ['password' => 'DevTest123!', 'target' => 'webmail']);
    eq($r['redirect'] ?? '', './?_task=mail', 'login webmail OK');

    $call('check_identity', ['email' => $email]);
    $r = $call('perform_login', ['password' => 'ZLE', 'target' => 'webmail']);
    ok(empty($r['ok']) && !empty($r['reset']), 'login webmail złe hasło -> reset');

    $r = $call('check_identity', ['email' => 'niema_' . time() . '@' . $TEST_DOMAIN]);
    ok(!empty($r['ok']) && ($r['focus_id'] ?? '') === 'inpPassword', 'identity nieznany: ten sam ekran co dla istniejącego (brak enumeracji)');

    $_POST = ['email' => $email]; // brak _csrf
    $r = (new PortalController())->handle('check_identity');
    ok(empty($r['ok']) && !empty($r['reload']), 'CSRF brak -> reload');

    // reCAPTCHA fail-closed: włączona, pusty token
    Settings::set('recaptcha_enabled', '1');
    ok(Recaptcha::verify('') === false, 'reCAPTCHA fail-closed (pusty token)');
} finally {
    $pdo->prepare('DELETE FROM virtual_users WHERE email=?')->execute([$email]);
    $pdo->prepare('DELETE FROM user_login_events WHERE email=?')->execute([$email]);
    Settings::set('recaptcha_enabled', $origRc);
}

/* ============ PODSUMOWANIE ============ */

echo "\n" . str_repeat('=', 46) . "\n";
foreach ($T['fails'] as $f) {
    echo "FAIL: $f\n";
}
echo "PASS={$T['pass']}  FAIL={$T['fail']}\n";
exit($T['fail'] > 0 ? 1 : 0);
