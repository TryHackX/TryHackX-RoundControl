<?php
declare(strict_types=1);

/**
 * Testy jednostkowe 1.1.0 / 1.2.0 bez bazy danych:  php tests/security_test.php
 * Accounts (normalizacja, kształt nazwy, nazwy zarezerwowane i skąd), Ip (klucz /64, zakresy, CIDR, lista zaufanych),
 * Quota (przycinanie zamiast przepełnienia), Env (ścieżka konfiguracji), Screens (ten sam ekran hasła dla każdego
 * adresu; kafelki dopiero po haśle administratora), AuthFlow (hasło dla kafelka Webmail zaszyfrowane kluczem
 * z przeglądarki), AuthLog vs. wyrażenie z filtra Fail2Ban (auth-failure liczone, reauth-failure nie).
 */

$tmp = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'mp_sec_' . bin2hex(random_bytes(4));
mkdir($tmp, 0777, true);
register_shutdown_function(static function () use ($tmp): void { foreach (glob($tmp . '/*') ?: [] as $f) { @unlink($f); } @rmdir($tmp); });
file_put_contents($tmp . '/test.env', "APP_ENV=dev\nSCAN_TRUSTED_IPS=203.0.113.7, 2001:db8:1::/48 ,198.51.100.0/24\n");
putenv('MAILPANEL_ENV_FILE=' . $tmp . '/test.env');

require __DIR__ . '/../app/bootstrap.php';

use App\Panel\Quota;
use App\Portal\Screens;
use App\Support\Env;
use App\Support\Ip;
use App\Users\Accounts;

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

group('Env: ścieżka konfiguracji');
eq(Env::defaultPath('/x/y'), $tmp . '/test.env', 'MAILPANEL_ENV_FILE (tylko CLI) wskazuje plik konfiguracji');
eq(Env::get('APP_ENV'), 'dev', 'wartości z pliku wczytane');

group('Accounts::normalize');
eq(Accounts::normalize("  Jan.KOWALSKI@Example.ORG \n"), 'jan.kowalski@example.org', 'trim + małe litery');
eq(Accounts::normalize(''), '', 'pusty');

group('Accounts::validLocal');
foreach (['a', 'ab', 'jan.kowalski', 'a-b_c', 'a1', 'x.y.z', '0', str_repeat('a', 64), 'a' . str_repeat('b', 62) . 'c'] as $good) {
    ok(Accounts::validLocal($good), "dozwolone [$good]");
}
foreach (['', '.', '..', '-', '--help', '-rf', '_x', 'a.', '.a', 'a-', 'a..b', 'a b', 'a/b', 'a\\b', 'A', 'Jan', str_repeat('a', 65), 'a+b', 'ą', "a\n", "a\0b", 'a%2fb', 'a@b'] as $bad) {
    ok(!Accounts::validLocal($bad), 'odrzucone [' . str_replace(["\n", "\0"], ['\\n', '\\0'], $bad) . ']');
}

group('Accounts::isReserved');
foreach (['admin', 'administrator', 'hostmaster', 'postmaster', 'webmaster', 'abuse', 'security', 'noc', 'root', 'ssl-admin',
          'nobody', 'daemon', 'mailer-daemon', 'noreply', 'no-reply', 'donotreply', 'dmarc', 'www', 'ftp',
          'owner-list', 'list-request', 'list-owner', 'list-bounces'] as $r) {
    ok(Accounts::isReserved($r, ''), "zawsze zarezerwowane (nawet z pustą listą): $r");
}
foreach (['info', 'support', 'contact', 'sales', 'billing', 'help'] as $r) {
    ok(Accounts::isReserved($r, null), "domyślna lista konfigurowalna: $r");
    ok(!Accounts::isReserved($r, ''), "po wyczyszczeniu listy konfigurowalnej wolne: $r");
}
foreach (['jan', 'adm', 'admins', 'administrators', 'admin1', 'myadmin', 'post', 'roots', 'ala-ma-kota', 'abuse2'] as $f) {
    ok(!Accounts::isReserved($f, null), "nie zarezerwowane: $f");
}
ok(Accounts::isReserved('foobar', 'foo*'), 'wildcard z listy konfigurowalnej: foo* -> foobar');
ok(Accounts::isReserved('ab', 'a*b'), 'wildcard a*b -> ab');
ok(!Accounts::isReserved('xfoo', 'foo*'), 'wildcard foo* nie łapie xfoo');
ok(Accounts::isReserved('cool', 'cool, other'), 'lista rozdzielona przecinkami');
eq(Accounts::parseList(" Info, SUPPORT;\nfoo* , bad name, ĄĆ, a, info "), ['info', 'support', 'foo*', 'bad', 'name', 'a'], 'parseList: małe litery, unikalne, tylko [a-z0-9*._-]');
eq(Accounts::parseList(''), [], 'parseList: pusta');
ok(count(Accounts::parseList(implode(',', array_map(static fn($n) => "n$n", range(1, 400))))) === 300, 'parseList: limit 300 wpisów');
ok(Accounts::listContains('A@x.org, b@x.org', 'b@x.org'), 'listContains: element listy aliasu');
ok(!Accounts::listContains('ab@x.org', 'b@x.org'), 'listContains: podciąg to nie element');

group('Ip::key / keyRange (IPv6 /64)');
$v4 = inet_pton('203.0.113.9');
eq(Ip::key($v4), $v4, 'IPv4: klucz = adres');
$a = inet_pton('2001:db8:1:2:3:4:5:6');
$b = inet_pton('2001:db8:1:2::ffff');
$c = inet_pton('2001:db8:1:3::1');
eq(Ip::key($a), Ip::key($b), 'IPv6: ten sam /64 -> ten sam klucz');
ok(Ip::key($a) !== Ip::key($c), 'IPv6: inny /64 -> inny klucz');
eq(strlen(Ip::key($a)), 16, 'IPv6: klucz ma 16 bajtów (VARBINARY(16))');
eq(Ip::key(inet_pton('::ffff:198.51.100.5')), inet_pton('198.51.100.5'), 'IPv4 zmapowany w IPv6 -> IPv4');
[$lo, $hi] = Ip::keyRange($a);
ok($lo <= $a && $a <= $hi && $lo <= $b && $b <= $hi && !($lo <= $c && $c <= $hi), 'keyRange: obejmuje cały /64 i nic poza nim');
ok(!($lo <= $v4 && $v4 <= $hi), 'keyRange: adres IPv4 nie wpada w zakres IPv6');
eq(Ip::keyRange($v4), [$v4, $v4], 'keyRange IPv4: pojedyncza wartość');

group('Ip::inCidr / Ip::isTrusted (SCAN_TRUSTED_IPS)');
ok(Ip::inCidr('203.0.113.7', '203.0.113.7'), 'dokładny adres');
ok(Ip::inCidr('203.0.113.200', '203.0.113.0/24'), '/24 zawiera');
ok(!Ip::inCidr('203.0.114.1', '203.0.113.0/24'), '/24 nie zawiera sąsiada');
ok(Ip::inCidr('10.0.0.5', '10.0.0.0/20') && !Ip::inCidr('10.0.16.5', '10.0.0.0/20'), 'maska niebajtowa /20');
ok(Ip::inCidr('2001:db8:1:ffff::1', '2001:db8:1::/48'), 'IPv6 /48 zawiera');
ok(!Ip::inCidr('2001:db8:2::1', '2001:db8:1::/48'), 'IPv6 /48 nie zawiera innego');
ok(!Ip::inCidr('203.0.113.7', '2001:db8::/32') && !Ip::inCidr('2001:db8::1', '203.0.113.0/24'), 'różne rodziny adresów');
ok(Ip::inCidr('1.2.3.4', '0.0.0.0/0'), '/0 zawiera wszystko tej rodziny');
ok(!Ip::inCidr('not-an-ip', '203.0.113.0/24') && !Ip::inCidr('1.2.3.4', 'garbage/24') && !Ip::inCidr('1.2.3.4', '1.2.3.0/x'), 'śmieci -> false');
ok(Ip::isTrusted('203.0.113.7') && Ip::isTrusted('198.51.100.77') && Ip::isTrusted('2001:db8:1:9::1'), 'zaufane z pliku konfiguracji (adres, CIDR v4, CIDR v6)');
ok(!Ip::isTrusted('203.0.113.8') && !Ip::isTrusted('127.0.0.1') && !Ip::isTrusted('::1'), 'inne i loopback NIE są zaufane dla limitów logowania');

group('Quota: przycięcie zamiast przepełnienia');
eq(Quota::toMb(2, 'GB'), 2048, 'toMb 2 GB');
eq(Quota::toMb(50, 'MB'), 50, 'toMb 50 MB');
eq(Quota::toMb(1e30, 'GB'), Quota::MAX_MB, 'ogromna wartość GB -> MAX_MB (nie 0 = bez limitu)');
eq(Quota::toMb(1e30, 'MB'), Quota::MAX_MB, 'ogromna wartość MB -> MAX_MB');
eq(Quota::toMb(INF, 'MB'), Quota::MAX_MB, 'INF -> MAX_MB');
eq(Quota::toMb(NAN, 'MB'), 0, 'NaN -> 0');
eq(Quota::toMb(-5, 'GB'), 0, 'ujemna -> 0');
ok(Quota::MAX_MB < 2147483647, 'MAX_MB mieści się w INT');
eq(Quota::toBest(2048), [2, 'GiB'], 'toBest GiB');
eq(Quota::normalizeUnit('XX'), 'MiB', 'normalizeUnit fallback');

group('Commands::pipeTo: propozycja na stdin, kod wyjścia i komunikat wracają');
$r = App\System\Commands::pipeTo('php -r "echo strtoupper(stream_get_contents(STDIN));"', "abc\n");
eq([$r['code'], trim($r['out'])], [0, 'ABC'], 'stdin dociera do procesu, stdout wraca');
$r = App\System\Commands::pipeTo('php -r "exit(3);"', 'x');
eq($r['code'], 3, 'kod wyjścia procesu jest zwracany (nie sukces)');
$r = App\System\Commands::pipeTo('php -r "fwrite(STDERR, \'boom\'); exit(1);" 2>&1', '');
ok($r['code'] === 1 && str_contains($r['out'], 'boom'), 'stderr dołączony przez 2>&1 wraca w komunikacie');
$big = str_repeat("# linia jail.local\n", 3000);   // ~54 KB: mieści się w buforze potoku, helper dopuszcza do 64 KiB
$r = App\System\Commands::pipeTo('php -r "echo strlen(stream_get_contents(STDIN));"', $big);
eq([$r['code'], trim($r['out'])], [0, (string) strlen($big)], 'duża propozycja (54 KB) przekazana w całości');

group('Screens::password (1.2.0): sam ekran hasła, ten sam dla każdego adresu');
$s1 = Screens::password('admin@example.org');
$s2 = Screens::password('nikt@example.org');
eq(str_replace('admin@example.org', 'X', str_replace('>A<', '>N<', $s1)), str_replace('nikt@example.org', 'X', $s2), 'ekran nie zależy od tego, czy adres to admin / istniejące konto');
ok(!str_contains($s1, 'name="target"') && !str_contains($s1, '<select') && !str_contains($s1, 'service-card'), 'przed hasłem: żadnego wyboru usługi (ani select, ani kafelków)');
ok(str_contains($s1, 'autocomplete="current-password"') && str_contains($s1, 'autocomplete="username"'), 'menedżer haseł: pole hasła + ukryty login');
ok(!str_contains(Screens::password('"><script>@x.org'), '<script>'), 'adres w ekranie jest escapowany');

group('Screens::choice (1.2.0): kafelki po poprawnym haśle administratora');
$c = Screens::choice('boss@example.org', 'admin', true);
ok(substr_count($c, 'class="service-card"') === 2 && str_contains($c, 'data-target="webmail"') && str_contains($c, 'data-target="admin"'), 'dwa kafelki: Webmail i Panel Admina');
ok(!preg_match('/id="tileWebmail"[^>]*disabled/', $c) && !str_contains($c, 'choiceNote'), 'to samo hasło do skrzynki: oba kafelki aktywne');
$c = Screens::choice('boss@example.org', 'webmail', false);
ok(preg_match('/id="tileWebmail"[^>]*disabled/', $c) === 1 && str_contains($c, 'id="choiceNote"'), 'inne hasło skrzynki: kafelek Webmail wyłączony z wyjaśnieniem');
eq([Screens::choiceFocus('webmail', true), Screens::choiceFocus('webmail', false), Screens::choiceFocus('admin', true)],
   ['tileWebmail', 'tileAdmin', 'tileAdmin'], 'fokus na ostatnio wybranym kafelku (o ile aktywny)');
ok(!str_contains(Screens::choice('"><script>@x.org', 'admin', false), '<script>'), 'adres w kafelkach escapowany');

group('AuthFlow (1.2.0): hasło dla kafelka Webmail zaszyfrowane kluczem z przeglądarki');
$_SERVER['REMOTE_ADDR'] = '203.0.113.5';
$_SESSION = [];
$flow = new App\Auth\AuthFlow();
$flow->start('boss@example.org');
ok($flow->valid('password') && !$flow->valid('choose'), 'po adresie: etap "password"');
$key = $flow->offerChoice(7, '$argon2id$x', 'Tajne-Haslo-1!');
ok(strlen($key) >= 40 && $flow->valid('choose') && !str_contains(serialize($_SESSION), 'Tajne-Haslo-1!'), 'w sesji nie ma hasła jawnym tekstem; klucz tylko dla przeglądarki');
eq([$flow->adminId(), $flow->adminFp()], [7, hash('sha256', '$argon2id$x')], 'w sesji: id administratora i odcisk hasha');
$copy = $_SESSION;
eq($flow->takePassword(strrev($key)), null, 'zły klucz: brak hasła');
eq($flow->takePassword($key), null, '... i szyfrogram jest już zużyty (jeden raz)');
$_SESSION = $copy;
eq($flow->takePassword($key), 'Tajne-Haslo-1!', 'dobry klucz: hasło odszyfrowane');
$_SESSION = $copy;
$_SESSION['portal_auth_flow']['email'] = 'inny@example.org';
eq($flow->takePassword($key), null, 'szyfrogram przeniesiony do innego logowania (inny adres) się nie odszyfruje');
$_SESSION = $copy;
$_SERVER['REMOTE_ADDR'] = '203.0.113.6';
ok(!$flow->valid('choose'), 'inny adres IP klienta: wybór nieważny (i wyczyszczony)');
eq($_SESSION['portal_auth_flow'] ?? null, null, '... stan logowania usunięty');
$_SERVER['REMOTE_ADDR'] = '203.0.113.5';
$flow->start('a@b.org');
eq($flow->offerChoice(1, 'h', null), '', 'kafelek Webmail niedostępny: nic nie jest szyfrowane');
$_SESSION = [];

group('AuthLog + filtr Fail2Ban (1.2.0): auth-failure liczone, reauth-failure nie');
eq(App\Support\AuthLog::line('auth-failure', 'admin', 'Jan@x.org', '203.0.113.7'), 'auth-failure ip=203.0.113.7 kind=admin user=Jan%40x.org', 'linia nieudanego logowania');
eq(App\Support\AuthLog::line('reauth-failure', 'admin', 'jan@x.org', '203.0.113.7'), 'reauth-failure ip=203.0.113.7 kind=admin user=jan%40x.org', 'linia nieudanego potwierdzenia hasłem');
$conf = (string) file_get_contents(dirname(__DIR__) . '/system/fail2ban/filter.d/mailpanel-portal.conf');
preg_match('/^failregex\s*=\s*(.+)$/m', $conf, $fm);
$re = '~' . str_replace(['~', '<HOST>'], ['\~', '(?P<host>[0-9a-fA-F:.]+)'], trim($fm[1] ?? '')) . '~';
$hit = static function (string $line) use ($re): ?string { return preg_match($re, $line, $m) === 1 ? $m['host'] : null; };
// fail2ban dopasowuje linię "<host> <identyfikator>[pid]: <wiadomość>" (data odcięta; po odcięciu bywa spacja na początku)
eq($hit('mail mailpanel[123]: ' . App\Support\AuthLog::line('auth-failure', 'admin', 'a@b.org', '203.0.113.7')), '203.0.113.7', 'filtr łapie auth-failure (journal: host + identyfikator[pid])');
eq($hit(' mail mailpanel[9]: ' . App\Support\AuthLog::line('auth-failure', 'webmail', 'a@b.org', '2001:db8::7')), '2001:db8::7', 'filtr łapie auth-failure z IPv6 i spacją po odciętej dacie');
eq($hit('mailpanel[9]: ' . App\Support\AuthLog::line('auth-failure', 'admin', '', '198.51.100.1')), '198.51.100.1', 'filtr łapie linię bez nazwy hosta');
eq($hit('mail mailpanel[123]: ' . App\Support\AuthLog::line('reauth-failure', 'admin', 'a@b.org', '203.0.113.8')), null, 'filtr NIE łapie reauth-failure (literówka zalogowanego administratora)');
eq($hit('mail mailpanel[1]: ' . App\Support\AuthLog::line('auth-failure', 'webmail', 'x ip=198.51.100.66 kind=admin', '203.0.113.9')), '203.0.113.9', 'wstrzyknięte "ip=" w loginie nie zmienia banowanego adresu');
eq($hit('mail other[1]: x mailpanel[1]: auth-failure ip=203.0.113.11 kind=admin user=x'), null, 'linia innego programu udającego mailpanel nie pasuje');
eq($hit('auth-failure ip=203.0.113.7 kind=admin user=x'), null, '(1.1.0 dopasowywał tylko gołą wiadomość — fail2ban jej nigdy tak nie podaje)');

group('Accounts::reservedBy (1.2.0): skąd rezerwacja');
eq(Accounts::reservedBy('postmaster', ''), ['base', 'postmaster'], 'postmaster: lista wbudowana');
eq(Accounts::reservedBy('list-request', ''), ['base', '*-request'], 'wzorzec z listy wbudowanej');
eq(Accounts::reservedBy('info', null), ['list', 'info'], 'info: lista z ustawień (domyślna)');
eq(Accounts::reservedBy('jan', null), null, 'zwykła nazwa: brak rezerwacji');

echo "\n";
echo ($T['fail'] === 0 ? 'OK' : 'BŁĘDY') . ": {$T['pass']} przeszło, {$T['fail']} nie\n";
foreach ($T['fails'] as $f) { echo "  FAIL: $f\n"; }
exit($T['fail'] === 0 ? 0 : 1);
