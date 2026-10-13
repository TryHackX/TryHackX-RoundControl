<?php
declare(strict_types=1);

/**
 * Testy HTTP 1.2.0 — prawdziwe punkty wejścia (portal.php, panel/index.php, panel/user_ips.php) przez wbudowany serwer
 * PHP (`php -S`) na CAŁKOWICIE osobnej, tymczasowej bazie (tworzona i kasowana jak w db_test.php):
 *
 *     MP_TEST_DB_DSN="mysql:host=127.0.0.1;port=3307" MP_TEST_DB_USER=root MP_TEST_DB_PASS= php tests/http_test.php
 *
 * Sprawdza: logowanie bez enumeracji (te same odpowiedzi dla nieistniejącego adresu, zwykłego konta, administratora
 * z i bez skrzynki aż do poprawnego hasła), kafelki po haśle administratora, endpointy JSON panelu (CSRF, brak sesji,
 * błędy, wartości po normalizacji, wskazówki odświeżania), zapis nieudanych potwierdzeń hasłem w dzienniku audytowym
 * i koniec sesji po limicie, usuwanie aliasu, rezerwacje nazw, render zakładki z ?tab=.
 *
 *     php tests/http_test.php --serve[=SEKUNDY]   zamiast testów: zostaw serwer z danymi testowymi do ręcznego
 *                                                 sprawdzania w przeglądarce (domyślnie 1800 s; plik STOP kończy wcześniej)
 * Bez dostępu do bazy wypisuje SKIP i kończy się kodem 0.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
if (!function_exists('curl_init')) {
    echo "SKIP: brak rozszerzenia curl\n";
    exit(0);
}

$root    = dirname(__DIR__);
$dsnBase = getenv('MP_TEST_DB_DSN') ?: 'mysql:host=127.0.0.1;port=3307';
$dbUser  = getenv('MP_TEST_DB_USER') !== false ? (string) getenv('MP_TEST_DB_USER') : 'root';
$dbPass  = getenv('MP_TEST_DB_PASS') !== false ? (string) getenv('MP_TEST_DB_PASS') : '';
$serve   = null;
foreach (array_slice($argv, 1) as $a) {
    if (preg_match('/^--serve(?:=(\d+))?$/', $a, $m)) {
        $serve = isset($m[1]) ? (int) $m[1] : 1800;
    }
}

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

/** Minimalny klient HTTP z własnymi ciasteczkami (jedna "przeglądarka"). */
final class Client
{
    private CurlHandle $h;
    public string $csrf = '';
    public function __construct(private string $base)
    {
        $this->h = curl_init();
        curl_setopt_array($this->h, [CURLOPT_COOKIEFILE => '', CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true,
                                     CURLOPT_FOLLOWLOCATION => false, CURLOPT_TIMEOUT => 60]);
    }
    /** @return array{code:int,headers:string,body:string,json:?array} */
    public function req(string $method, string $path, array $post = [], array $headers = []): array
    {
        curl_setopt($this->h, CURLOPT_URL, $this->base . $path);
        curl_setopt($this->h, CURLOPT_CUSTOMREQUEST, $method);
        curl_setopt($this->h, CURLOPT_POSTFIELDS, $method === 'POST' ? http_build_query($post) : null);
        if ($method !== 'POST') { curl_setopt($this->h, CURLOPT_HTTPGET, true); }
        curl_setopt($this->h, CURLOPT_HTTPHEADER, $headers);
        $raw  = (string) curl_exec($this->h);
        $code = (int) curl_getinfo($this->h, CURLINFO_RESPONSE_CODE);
        $hs   = (int) curl_getinfo($this->h, CURLINFO_HEADER_SIZE);
        $body = substr($raw, $hs);
        $json = json_decode($body, true);
        return ['code' => $code, 'headers' => substr($raw, 0, $hs), 'body' => $body, 'json' => is_array($json) ? $json : null];
    }
    public function get(string $path): array { return $this->req('GET', $path, [], ['X-Requested-With: fetch']); }
    public function page(string $path): array
    {
        $r = $this->req('GET', $path);
        if (preg_match('/data-csrf="([0-9a-f]{64})"/', $r['body'], $m)) { $this->csrf = $m[1]; }
        return $r;
    }
    public function portal(string $action, array $post = []): array
    {
        return $this->req('POST', '/portal.php', $post + ['ajax_action' => $action, '_csrf' => $this->csrf], ['X-Requested-With: fetch'])['json'] ?? [];
    }
    public function panel(array $post, bool $csrf = true): array
    {
        if ($csrf) { $post += ['_csrf' => $this->csrf]; }
        return $this->req('POST', '/panel/index.php', $post + ['_ajax' => '1'], ['X-Requested-With: fetch']);
    }
}

$suffix  = bin2hex(random_bytes(3));
$db      = 'mp_http_' . $suffix;
$uPanel  = 'mp_h_panel_' . $suffix;
$pw      = bin2hex(random_bytes(12));
$tmp     = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'mp_http_' . $suffix;
mkdir($tmp, 0777, true);
$server  = null;

$cleanup = static function () use ($admin, $db, $uPanel, $tmp, &$server): void {
    if (is_resource($server)) {
        $st = proc_get_status($server);
        if ($st['running']) {
            PHP_OS_FAMILY === 'Windows' ? exec('taskkill /F /T /PID ' . (int) $st['pid'] . ' >NUL 2>&1') : proc_terminate($server);
        }
        @proc_close($server);
    }
    try {
        $admin->exec("DROP DATABASE IF EXISTS `$db`");
        $admin->exec("DROP USER IF EXISTS '$uPanel'@'localhost'");
    } catch (Throwable $e) {
        fwrite(STDERR, 'cleanup: ' . $e->getMessage() . "\n");
    }
    foreach (glob($tmp . '/*') ?: [] as $f) { @unlink($f); }
    @rmdir($tmp);
};
register_shutdown_function($cleanup);

try {
    /* ------------------------------------------------------------------ baza i dane testowe */
    $admin->exec("CREATE DATABASE `$db` CHARACTER SET utf8mb4");
    $admin->exec("USE `$db`");
    runSql($admin, (string) file_get_contents($root . '/sql/panel_schema.sql'));
    $admin->exec("CREATE USER '$uPanel'@'localhost' IDENTIFIED BY '$pw'");
    runSql($admin, strtr((string) file_get_contents($root . '/sql/panel_grants.sql'),
        ["'mailpanel'@'localhost'" => "'$uPanel'@'localhost'", 'mailserver.' => "`$db`."]));

    $mailPw  = 'DevTest123!';
    $adminPw = 'Admin-Pass-12345!';
    $crypt   = static fn(string $p): string => crypt($p, '$6$' . bin2hex(random_bytes(6)) . '$');
    $admin->exec("INSERT INTO virtual_domains (name) VALUES ('example.test')");
    $dom = (int) $admin->lastInsertId();
    $iu  = $admin->prepare('INSERT INTO virtual_users (domain_id, password, email, quota_mb) VALUES (?, ?, ?, 100)');
    $iu->execute([$dom, $crypt($mailPw), 'jan@example.test']);              // zwykłe konto
    $iu->execute([$dom, $crypt($adminPw), 'boss@example.test']);            // admin + skrzynka, TO SAMO hasło
    $iu->execute([$dom, $crypt($mailPw), 'chef@example.test']);             // admin + skrzynka, INNE hasło skrzynki
    $iu->execute([$dom, '{SHA512-CRYPT}' . $crypt($adminPw), 'prefixed@example.test']);   // hash z prefiksem Dovecota
    foreach (range(1, 25) as $i) { $iu->execute([$dom, $crypt($mailPw), sprintf('user%02d@example.test', $i)]); }
    $ia = $admin->prepare('INSERT INTO panel_admins (email, pwd_hash, created_at) VALUES (?, ?, NOW())');
    foreach (['boss@example.test', 'chef@example.test', 'solo@example.test', 'prefixed@example.test'] as $a) {
        $ia->execute([$a, password_hash($adminPw, PASSWORD_ARGON2ID)]);
    }
    $admin->prepare('INSERT INTO virtual_aliases (domain_id, source, destination) VALUES (?, ?, ?)')
          ->execute([$dom, 'postmaster@example.test', 'jan@example.test']);
    // limity logowania wyżej niż liczba złych prób w testach (wszystkie żądania idą z 127.0.0.1)
    $admin->exec("REPLACE INTO app_settings (k, v) VALUES ('admin_lock_threshold', '50'), ('recaptcha_enabled', '0'),
                  ('allow_registration', '1'), ('registration_enabled', '1')");

    file_put_contents($tmp . '/app.env', "APP_ENV=dev\nAPP_TIMEZONE=UTC\nPANEL_DB_DSN=\"$dsnBase;dbname=$db;charset=utf8mb4\"\n"
        . "PANEL_DB_USER=$uPanel\nPANEL_DB_PASS=$pw\n");

    /* ------------------------------------------------------------------ serwer */
    $sock = stream_socket_server('tcp://127.0.0.1:0');
    $port = (int) substr((string) stream_socket_get_name($sock, false), strrpos((string) stream_socket_get_name($sock, false), ':') + 1);
    fclose($sock);
    $env = array_merge(getenv(), ['MAILPANEL_ENV_FILE' => $tmp . '/app.env']);
    $server = proc_open([PHP_BINARY, '-d', 'display_errors=stderr', '-S', '127.0.0.1:' . $port, '-t', $root],
        [0 => ['pipe', 'r'], 1 => ['file', $tmp . '/server.log', 'a'], 2 => ['file', $tmp . '/server.log', 'a']], $pipes, $root, $env);
    $base = 'http://127.0.0.1:' . $port;
    for ($i = 0; $i < 50; $i++) {
        $fp = @fsockopen('127.0.0.1', $port, $en, $es, 0.2);
        if ($fp) { fclose($fp); break; }
        usleep(100000);
    }

    if ($serve !== null) {
        $stop = $tmp . DIRECTORY_SEPARATOR . 'STOP';
        echo "Serwer testowy: $base/portal.php  (baza $db, kasowana po zakończeniu)\n";
        echo "  admin+skrzynka (to samo hasło): boss@example.test / $adminPw\n";
        echo "  admin+skrzynka (inne hasło):    chef@example.test / $adminPw (skrzynka: $mailPw)\n";
        echo "  admin bez skrzynki:             solo@example.test / $adminPw\n";
        echo "  zwykłe konto:                   jan@example.test / $mailPw   (postmaster@ = alias -> jan@)\n";
        echo "Zatrzymanie: utwórz plik $stop albo poczekaj $serve s.\n";
        $until = time() + $serve;
        while (time() < $until && !is_file($stop)) { sleep(1); }
        exit(0);
    }

    $count = static function (string $sql, array $p = []) use ($admin): int {
        $s = $admin->prepare($sql); $s->execute($p); return (int) $s->fetchColumn();
    };

    /* ------------------------------------------------------------------ portal: brak enumeracji */
    group('portal: do poprawnego hasła odpowiedź nie zależy od konta');
    $norm = static function (array $r, string $email): string {
        $s = json_encode($r, JSON_UNESCAPED_UNICODE);
        $s = str_replace([$email, json_encode($email)], 'X', (string) $s);
        return (string) preg_replace('~(class=\\\\"avatar\\\\"[^>]*>)[^<]*~', '$1A', $s);
    };
    $who = ['nikt-taki@example.test', 'jan@example.test', 'boss@example.test', 'solo@example.test', 'chef@example.test'];
    $id = $bad = $codes = [];
    foreach ($who as $email) {
        $c = new Client($base);
        $p = $c->page('/portal.php');
        $codes[] = $p['code'];
        $r1 = $c->req('POST', '/portal.php', ['ajax_action' => 'check_identity', 'email' => $email, '_csrf' => $c->csrf], ['X-Requested-With: fetch']);
        $id[]  = [$r1['code'], $norm($r1['json'] ?? [], $email), preg_match_all('/^Set-Cookie:/mi', $r1['headers'])];
        $r2 = $c->req('POST', '/portal.php', ['ajax_action' => 'perform_login', 'password' => 'zle-haslo-xyz', '_csrf' => $c->csrf], ['X-Requested-With: fetch']);
        $bad[] = [$r2['code'], json_encode($r2['json'], JSON_UNESCAPED_UNICODE), preg_match_all('/^Set-Cookie:/mi', $r2['headers'])];
    }
    eq(array_unique($codes), [200], 'portal.php: 200 dla każdego');
    ok(count(array_unique(array_map('serialize', $id))) === 1, 'krok "adres": identyczny kod, JSON (po podmianie adresu) i ciasteczka dla nieistniejącego / zwykłego / admina / admina bez skrzynki');
    ok(str_contains($id[0][1], 'inpPassword') && !str_contains($id[0][1], 'name=\"target\"') && !str_contains($id[0][1], 'tileAdmin'),
        'ekran hasła: tylko hasło, bez wyboru usługi');
    ok(count(array_unique(array_map('serialize', $bad))) === 1, 'złe hasło: identyczna odpowiedź dla wszystkich (' . $bad[0][1] . ')');
    ok(str_contains($bad[0][1], 'Nieprawidłowe dane logowania') && str_contains($bad[0][1], '"reset":true'), 'złe hasło: komunikat ogólny + powrót do adresu');
    eq($count("SELECT COUNT(*) FROM throttle_events WHERE kind IN ('admin','webmail') AND ip = ?", [inet_pton('127.0.0.1')]), 5, 'każda zła próba zapisana w limiterze (5 kont = 5 zdarzeń)');
    eq($count("SELECT COUNT(*) FROM admin_login_attempts WHERE success = 0"), 3, 'dziennik prób admina: tylko adresy administratorów (boss, solo, chef)');

    group('portal: poprawne hasło — kafelki tylko dla administratora ze skrzynką');
    $login = static function (string $email, string $pass) use ($base): array {
        $c = new Client($base);
        $c->page('/portal.php');
        $c->portal('check_identity', ['email' => $email]);
        return [$c, $c->portal('perform_login', ['password' => $pass])];
    };
    [, $r] = $login('jan@example.test', $mailPw);
    eq($r['redirect'] ?? '', './?_task=mail', 'zwykłe konto: prosto do webmaila');
    [$cs, $r] = $login('solo@example.test', $adminPw);
    eq($r['redirect'] ?? '', 'panel/index.php', 'administrator bez skrzynki: prosto do panelu');
    eq($cs->req('GET', '/panel/index.php')['code'], 200, '... i sesja panelu działa');
    [$cb, $r] = $login('boss@example.test', $adminPw);
    ok(($r['step'] ?? '') === 'choose' && str_contains($r['html'] ?? '', 'tileWebmail') && str_contains($r['html'] ?? '', 'tileAdmin')
        && !preg_match('/id="tileWebmail"[^>]*disabled/', $r['html'] ?? ''), 'admin ze skrzynką (to samo hasło): dwa aktywne kafelki');
    ok(strlen($r['choice_key'] ?? '') >= 40 && ($r['redirect'] ?? '') === '', '... klucz dla kafelka Webmail dostaje tylko przeglądarka; bez przekierowania');
    $r2 = $cb->portal('choose_target', ['target' => 'webmail', 'key' => 'zly-klucz']);
    ok(empty($r2['ok']) && !empty($r2['reset']), 'kafelek Webmail ze złym kluczem: odmowa i powrót do adresu');
    $r3 = $cb->portal('choose_target', ['target' => 'admin']);
    ok(empty($r3['ok']) && !empty($r3['reset']), '... a wybór po nieudanym jest już nieważny (jednorazowy)');
    // (sprawdzenie panelu na końcu: odmowa AdminGuard kasuje całą sesję, także token CSRF portalu)
    eq($cb->req('GET', '/panel/index.php')['code'], 302, '... po haśle administratora, bez wyboru kafelka, sesji panelu nie ma');
    [$cb, $r] = $login('boss@example.test', $adminPw);
    $r2 = $cb->portal('choose_target', ['target' => 'admin']);
    eq($r2['redirect'] ?? '', 'panel/index.php', 'kafelek Panel Admina: wejście do panelu');
    eq($cb->req('GET', '/panel/index.php')['code'], 200, '... sesja panelu działa');
    [$cb2, $r] = $login('boss@example.test', $adminPw);
    $r2 = $cb2->portal('choose_target', ['target' => 'webmail', 'key' => (string) $r['choice_key']]);
    eq($r2['redirect'] ?? '', './?_task=mail', 'kafelek Webmail z kluczem: wejście do webmaila');
    $r3 = $cb2->portal('choose_target', ['target' => 'webmail', 'key' => (string) $r['choice_key']]);
    ok(empty($r3['ok']) && !empty($r3['reset']), '... ponowne użycie tego samego wyboru: odmowa');
    eq($cb2->req('GET', '/panel/index.php')['code'], 302, '... bez sesji panelu (wybrano webmail)');
    [$cc, $r] = $login('chef@example.test', $adminPw);
    ok(($r['step'] ?? '') === 'choose' && preg_match('/id="tileWebmail"[^>]*disabled/', $r['html'] ?? '') === 1
        && ($r['choice_key'] ?? 'x') === '' && str_contains($r['html'] ?? '', 'choiceNote'), 'admin z INNYM hasłem skrzynki: kafelek Webmail wyłączony z wyjaśnieniem, bez klucza');
    [, $r] = $login('chef@example.test', $mailPw);
    eq($r['redirect'] ?? '', './?_task=mail', 'ten sam admin hasłem SKRZYNKI: prosto do webmaila (bez kafelków)');
    [, $r] = $login('prefixed@example.test', $adminPw);
    ok(($r['step'] ?? '') === 'choose' && strlen($r['choice_key'] ?? '') >= 40, 'hash skrzynki z prefiksem {SHA512-CRYPT}: rozpoznany, kafelek Webmail aktywny');
    $c = new Client($base);
    $c->page('/portal.php');
    $c->portal('check_identity', ['email' => 'jan@example.test']);
    $c->portal('cancel_login');
    $r = $c->portal('perform_login', ['password' => $mailPw]);
    ok(empty($r['ok']) && !empty($r['reset']), '"Wróć" zapomina rozpoczęte logowanie (hasło po nim: od nowa)');
    $r = $c->req('POST', '/portal.php', ['ajax_action' => 'check_identity', 'email' => 'jan@example.test'], ['X-Requested-With: fetch'])['json'] ?? [];
    ok(empty($r['ok']) && !empty($r['reload']), 'portal bez tokenu CSRF: odmowa');
    eq($count("SELECT COUNT(*) FROM admin_login_attempts WHERE success = 1"), 2, 'udane wejścia do PANELU zapisane (solo, boss); webmail z kafelka i hasło skrzynki — nie');

    /* ------------------------------------------------------------------ panel: brak sesji */
    group('panel: bez sesji — przekierowanie albo JSON 403 (nigdy strona portalu w odpowiedzi fetch)');
    $anon = new Client($base);
    $r = $anon->req('GET', '/panel/index.php');
    ok($r['code'] === 302 && str_contains($r['headers'], 'portal.php'), 'GET panelu: 302 do portalu');
    foreach (['/panel/index.php?ajax_stats=1', '/panel/index.php?ajax_fetch_users=1', '/panel/user_ips.php?email=jan@example.test'] as $u) {
        $r = $anon->req('GET', $u);
        ok($r['code'] === 403 && ($r['json']['auth'] ?? false) === true, "GET $u: 403 JSON {auth:true}");
    }
    $r = $anon->panel(['update_security' => '1', 'max_login_attempts' => '9'], false);
    ok($r['code'] === 403 && ($r['json']['auth'] ?? false) === true && ($r['json']['ok'] ?? true) === false, 'POST zapisu bez sesji: 403 JSON {auth:true}');

    /* ------------------------------------------------------------------ panel: CSRF i zapisy */
    group('panel: CSRF, zapisy przez fetch, wartości po normalizacji');
    [$p, $r] = $login('solo@example.test', $adminPw);
    $page = $p->page('/panel/index.php?tab=users');
    ok($page['code'] === 200 && strlen($p->csrf) === 64, 'strona panelu + token CSRF');
    ok(preg_match('/<section id="users" class="tab-content active"/', $page['body']) === 1
        && preg_match('/id="tab-users"[^>]*aria-selected="true"/', $page['body']) === 1, '?tab=users: serwer od razu renderuje właściwą zakładkę');
    ok(str_contains($page['body'], 'data-id="') && str_contains($page['body'], 'Łącznie kont: <b>29</b>'), 'pierwsza strona kont jest w HTML (bez pustej tabeli i doładowania)');
    $before = $count("SELECT COUNT(*) FROM app_settings WHERE k = 'max_login_attempts' AND v = '9'");
    $r = $p->panel(['update_security' => '1', 'max_login_attempts' => '9', 'ban_duration_minutes' => '5'], false);
    ok($r['code'] === 403 && ($r['json']['csrf'] ?? false) === true && ($r['json']['ok'] ?? true) === false, 'brak tokenu: 403 JSON {csrf:true}');
    $r = $p->panel(['update_security' => '1', 'max_login_attempts' => '9', '_csrf' => str_repeat('0', 64)], false);
    ok($r['code'] === 403 && ($r['json']['csrf'] ?? false) === true, 'zły token: 403 JSON {csrf:true}');
    eq($count("SELECT COUNT(*) FROM app_settings WHERE k = 'max_login_attempts' AND v = '9'"), $before, '... nic nie zapisano');
    $r = $p->panel(['update_security' => '1', 'max_login_attempts' => '0', 'ban_duration_minutes' => '-3']);
    $j = $r['json'] ?? [];
    ok($r['code'] === 200 && ($j['ok'] ?? false) === true && ($j['msg'] ?? '') !== '' && ($j['error'] ?? 'x') === '', 'zapis: 200 JSON ok + komunikat z serwera');
    eq($j['values'] ?? null, ['max_login_attempts' => '1', 'ban_duration_minutes' => '1'], 'values = to, co naprawdę zapisano (przycięte do minimum)');
    $r = $p->panel(['update_settings' => '1', 'deleted_name_hold_days' => '99999', 'reserved_local_parts' => "Foo, BAR\nzły", 'mailbox_quota_value' => '2048',
                    'mailbox_quota_unit' => 'MiB', 'accounts_per_ip' => '0', 'ip_window_hours' => '24', 'ip_total_limit' => '5', 'daily_send_limit' => '77']);
    $v = $r['json']['values'] ?? [];
    ok(($v['deleted_name_hold_days'] ?? '') === '3650' && ($v['reserved_local_parts'] ?? '') === 'foo,bar' && ($v['accounts_per_ip'] ?? '') === '1'
        && ($v['mailbox_quota_value'] ?? '') === '2' && ($v['mailbox_quota_unit'] ?? '') === 'GiB' && ($v['apply_quota_to_all'] ?? null) === false
        && ($v['recaptcha_secret_key'] ?? null) === '', 'ustawienia: wartości po normalizacji wracają do formularza (' . json_encode($v) . ')');
    $r = $p->panel(['cos_nieznanego' => '1']);
    ok(($r['json']['ok'] ?? true) === false && str_contains((string) ($r['json']['error'] ?? ''), 'Nieznana'), 'nieznana operacja: błąd, nie cichy sukces');
    $r = $p->req('POST', '/panel/index.php', ['update_security' => '1', 'max_login_attempts' => '6', '_csrf' => $p->csrf, '_tab' => 'security']);
    ok($r['code'] === 302 && str_contains($r['headers'], 'index.php?tab=security'), 'bez JavaScriptu (bez _ajax): dawne POST + przekierowanie na ?tab=');
    $s = $p->get('/panel/index.php?ajax_stats=1')['json'] ?? [];
    ok(($s['ok'] ?? false) === true && ($s['total_users'] ?? 0) === 29 && !isset($s['banned_ips']), 'ajax_stats: liczniki (bez fail2ban)');
    $u = $p->get('/panel/index.php?ajax_fetch_users=1&page=2&per=10')['json'] ?? [];
    ok(($u['page'] ?? 0) === 2 && substr_count((string) ($u['html'] ?? ''), '<tr data-id=') === 10 && str_contains((string) ($u['pagination'] ?? ''), 'aria-current="page"'),
        'lista kont: strona 2 po 10, paginacja z aria-current');

    group('panel: nazwy zarezerwowane, alias i wyjście z niego');
    $chk = static fn(string $local) => $p->get('/panel/index.php?ajax_check_user=1&domain=example.test&local=' . rawurlencode($local))['json'] ?? [];
    $a = $chk('postmaster');
    ok(($a['status'] ?? '') === 'alias' && ($a['override'] ?? true) === false && ($a['aliases'][0]['destination'] ?? '') === 'jan@example.test'
        && str_contains((string) ($a['detail'] ?? ''), 'zgoda'), 'postmaster@ (alias): niedostępne, zgoda nie pomaga, podany cel aliasu i wyjaśnienie');
    $b = $chk('admin');
    ok(($b['status'] ?? '') === 'reserved' && ($b['override'] ?? false) === true && str_contains((string) ($b['detail'] ?? ''), 'certyfikat'), 'admin@: zarezerwowane, zgoda możliwa, wyjaśnienie (urzędy certyfikacji)');
    $b = $chk('foo');
    ok(($b['status'] ?? '') === 'reserved' && str_contains((string) ($b['detail'] ?? ''), 'Ustawienia'), 'foo@ z listy w Ustawieniach: powód wskazuje listę');
    eq($chk('jan')['status'] ?? '', 'taken', 'jan@: istniejące konto');
    eq($chk('nowy.ktos')['status'] ?? '', 'free', 'wolna nazwa');
    ok(($chk('-zla')['ok'] ?? true) === false, 'zły kształt nazwy: ok=false');
    $new = static fn(string $local, bool $consent) => $p->panel(['create_user' => '1', 'nu_local' => $local, 'nu_domain' => 'example.test',
        'nu_password' => 'Silne-Haslo-99!', 'nu_password2' => 'Silne-Haslo-99!', 'nu_quota_value' => '10', 'nu_quota_unit' => 'MiB']
        + ($consent ? ['nu_allow_reserved' => '1'] : []))['json'] ?? [];
    $r = $new('postmaster', true);
    ok(($r['ok'] ?? true) === false && str_contains((string) ($r['error'] ?? ''), 'aliasu') && ($r['data']['name']['status'] ?? '') === 'alias',
        'załóż postmaster@ ZE zgodą: odmowa (alias), odpowiedź niesie ocenę nazwy dla okna');
    $aliasId = (int) ($a['aliases'][0]['id'] ?? 0);
    $r = $p->panel(['delete_alias' => '1', 'alias_id' => (string) $aliasId, 'alias_source' => 'inny@example.test'])['json'] ?? [];
    ok(($r['ok'] ?? true) === false && $count('SELECT COUNT(*) FROM virtual_aliases WHERE id = ?', [$aliasId]) === 1, 'usuń alias z niezgodnym adresem: odmowa, alias zostaje');
    $r = $p->panel(['delete_alias' => '1', 'alias_id' => (string) $aliasId, 'alias_source' => 'Postmaster@Example.test'])['json'] ?? [];
    ok(($r['ok'] ?? false) === true && $count('SELECT COUNT(*) FROM virtual_aliases WHERE id = ?', [$aliasId]) === 0, 'usuń alias: ok, wiersz usunięty');
    eq($count("SELECT COUNT(*) FROM admin_audit_logs WHERE action_type = 'DELETE_ALIAS' AND details LIKE '%postmaster@example.test%jan@example.test%'"), 1, '... pełny wiersz aliasu w dzienniku audytowym');
    $a = $chk('postmaster');
    ok(($a['status'] ?? '') === 'reserved' && ($a['override'] ?? false) === true, 'po usunięciu aliasu postmaster@ jest "tylko" zarezerwowane (zgoda możliwa)');
    $r = $new('postmaster', false);
    ok(($r['ok'] ?? true) === false && str_contains((string) ($r['error'] ?? ''), 'zgod'), 'bez zgody: odmowa z prośbą o zgodę');
    $r = $new('postmaster', true);
    ok(($r['ok'] ?? false) === true && in_array('users', $r['refresh'] ?? [], true) && in_array('stats', $r['refresh'] ?? [], true) && ($r['data']['id'] ?? 0) > 0,
        'ze zgodą: konto utworzone; refresh users + stats; id nowego wiersza');
    eq($count("SELECT COUNT(*) FROM admin_audit_logs WHERE action_type = 'CREATE_USER' AND details LIKE '%postmaster@example.test%za zgodą%'"), 1, '... zgoda zapisana w dzienniku');

    group('panel: błędne hasło przy potwierdzaniu — dziennik audytowy i koniec sesji po limicie');
    $victim = (int) $admin->query("SELECT id FROM virtual_users WHERE email = 'user01@example.test'")->fetchColumn();
    $del = static fn(Client $c, string $pass) => $c->panel(['delete_user_confirm' => '1', 'delete_user_id' => (string) $victim,
        'delete_user_email' => 'user01@example.test', 'admin_confirm_password' => $pass]);
    $r = $del($p, 'zle-haslo');
    ok(($r['json']['ok'] ?? true) === false && str_contains((string) ($r['json']['error'] ?? ''), 'dzienniku') && ($r['json']['values']['admin_confirm_password'] ?? null) === '',
        'złe hasło: odmowa, komunikat wspomina dziennik audytowy, pole hasła czyszczone');
    eq($count("SELECT COUNT(*) FROM admin_audit_logs WHERE action_type = 'REAUTH_FAILED' AND admin_email = 'solo@example.test' AND details LIKE '%user01@example.test%'"), 1,
        'REAUTH_FAILED w dzienniku audytowym (z kontem, którego dotyczyło)');
    eq($count('SELECT COUNT(*) FROM virtual_users WHERE id = ?', [$victim]), 1, '... konto NIE usunięte');
    $r = $p->panel(['change_password' => '1', 'current_password' => 'tez-zle', 'new_password' => 'Nowe-Haslo-Admina-77!', 'confirm_password' => 'Nowe-Haslo-Admina-77!'])['json'] ?? [];
    ok(($r['ok'] ?? true) === false && $count("SELECT COUNT(*) FROM admin_audit_logs WHERE action_type = 'REAUTH_FAILED' AND details LIKE '%zmiana hasła%'") === 1,
        'złe obecne hasło przy zmianie hasła: też REAUTH_FAILED');
    for ($i = 3; $i <= 4; $i++) { $del($p, 'zle-' . $i); }
    $r = $del($p, 'zle-5');
    ok($r['code'] === 403 && ($r['json']['auth'] ?? false) === true, 'piąty błąd w tej sesji: sesja zakończona (403 {auth:true})');
    eq($count("SELECT COUNT(*) FROM admin_audit_logs WHERE action_type = 'REAUTH_LOCKOUT'"), 1, 'REAUTH_LOCKOUT w dzienniku');
    eq($p->get('/panel/index.php?ajax_stats=1')['code'], 403, 'po zakończeniu sesji panel odpowiada 403');
    eq($count('SELECT COUNT(*) FROM virtual_users WHERE id = ?', [$victim]), 1, 'konto nadal istnieje');
    [$p2, ] = $login('solo@example.test', $adminPw);
    $p2->page('/panel/index.php');
    $r = $del($p2, $adminPw);
    $j = $r['json'] ?? [];
    ok(($j['done'] ?? false) === true && in_array('users', $j['refresh'] ?? [], true) && str_contains((string) ($j['msg'] ?? ''), 'usunięte z bazy')
        && $count('SELECT COUNT(*) FROM virtual_users WHERE id = ?', [$victim]) === 0, 'poprawne hasło: konto usunięte (done + refresh users)');
    ok(($j['ok'] ?? true) === false && str_contains((string) ($j['error'] ?? ''), 'kwarantanny'), 'tryb dev bez skryptu: widoczny błąd o plikach (nie przeniesione do kwarantanny)');
    $r = $p2->panel(['update_user_quota' => '1', 'user_id' => (string) $victim, 'user_quota_value' => '1', 'user_quota_unit' => 'GiB'])['json'] ?? [];
    ok(($r['ok'] ?? true) === false && in_array('users', $r['refresh'] ?? [], true), 'quota usuniętego konta: błąd + odśwież listę (nie cichy sukces)');
    $uid = (int) $admin->query("SELECT id FROM virtual_users WHERE email = 'user02@example.test'")->fetchColumn();
    $r = $p2->panel(['update_user_quota' => '1', 'user_id' => (string) $uid, 'user_quota_value' => '2', 'user_quota_unit' => 'GiB', 'user_send_limit' => ''])['json'] ?? [];
    ok(($r['ok'] ?? false) === true && ($r['data']['id'] ?? 0) === $uid && $count("SELECT COUNT(*) FROM admin_audit_logs WHERE action_type = 'USER_QUOTA'") === 1,
        'zmiana quoty: ok, id wiersza do podświetlenia, wpis USER_QUOTA w dzienniku');

    $log = (string) @file_get_contents($tmp . '/server.log');
    ok(!preg_match('/PHP (Warning|Notice|Deprecated|Fatal)/', $log), 'serwer: brak ostrzeżeń PHP' . (preg_match('/PHP (Warning|Notice|Deprecated|Fatal)[^\n]*/', $log, $mm) ? ' — ' . $mm[0] : ''));
} catch (Throwable $e) {
    ok(false, 'WYJĄTEK: ' . $e::class . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine());
}

echo "\n";
echo ($T['fail'] === 0 ? 'OK' : 'BŁĘDY') . ": {$T['pass']} przeszło, {$T['fail']} nie\n";
foreach ($T['fails'] as $f) { echo "  FAIL: $f\n"; }
exit($T['fail'] === 0 ? 0 : 1);
