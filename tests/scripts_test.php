<?php
declare(strict_types=1);

/**
 * Testy skryptów systemowych 1.1.0 — bez bazy, bez roota, bez sudo; działają na Linuksie i na Windows (Git Bash):
 *   php tests/scripts_test.php
 *
 *  - mailpanel-f2b-apply.php  (zapis W MIEJSCU, kopie, odrzucanie, przywracanie, brak fałszywego sukcesu),
 *  - mailpanel-delete-user.sh (walidacja, kod "nie znaleziono", odmowa dla żywego konta, kwarantanna),
 *  - update-postfix-limits.sh (odczyt zwrotny, brak fałszywego sukcesu przy read-only / cichym braku zmiany),
 *  - mailpanel-sudoers-cleanup.sh (stare reguły z wildcardami),
 *  - funkcje pomocnicze install.sh (file_kv, render_template, valid_secret),
 *  - kontrole statyczne (php -l, bash -n, brak sleep, strażnicy CLI, brak czytania .env przez root).
 * Ścieżki w skryptach (BASE_DIR, LIVE, ...) są podmieniane na katalog tymczasowy; polecenia systemowe — na atrapy.
 *
 * Skipped checks are counted one by one: "SKIP (N checks)" says how many checks did not run, and the summary adds them
 * up. A block skipped as a whole (no bash) declares its size; whenever the block runs, blockSize() checks that it still
 * runs exactly that many checks, so the count stays true when checks are added. The symbolic-link checks run only where
 * symlink() works (Linux; Windows needs the privilege). On Linux run this suite as root (CI: sudo): the Fail2Ban helper
 * insists on a root-owned jail.local, also the scratch one; as root the read-only check is the one that is skipped.
 * Ctrl+C / SIGTERM still remove the temporary directory.
 */

$root = dirname(__DIR__);
$T = ['pass' => 0, 'fail' => 0, 'fails' => [], 'skip' => 0];
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
/** $checks = how many checks this skip leaves out (they are counted as skipped, never as passed). */
function skip(string $why, int $checks): void
{
    global $T;
    $T['skip'] += $checks;
    echo "  SKIP ($checks " . ($checks === 1 ? 'check' : 'checks') . "): $why\n";
}
/** Checks seen so far: passed, failed and skipped. */
function seen(): int { global $T; return $T['pass'] + $T['fail'] + $T['skip']; }
/** A block that skip() may leave out as a whole must still run exactly as many checks as its skip() counts. */
function blockSize(int $start, int $declared, string $block): void
{
    $ran = seen() - $start;
    if ($ran !== $declared) { ok(false, "$block: ran $ran checks but its skip() counts $declared — update the count"); }
}
/** Ctrl+C, SIGTERM and SIGHUP (pcntl) or Ctrl+C / Ctrl+Break (Windows) end the run through exit(): the cleanup runs. */
function cleanupOnInterrupt(): void
{
    if (function_exists('pcntl_async_signals') && function_exists('pcntl_signal')) {
        pcntl_async_signals(true);
        foreach ([SIGINT, SIGTERM, SIGHUP] as $sig) {
            pcntl_signal($sig, static function (int $signo): void { exit(128 + $signo); });
        }
    } elseif (function_exists('sapi_windows_set_ctrl_handler')) {
        sapi_windows_set_ctrl_handler(static function (int $event): void { exit(130); });
    }
}

function toPosix(string $p): string
{
    if (preg_match('~^([A-Za-z]):[\\\\/](.*)$~', $p, $m) === 1) {
        return '/' . strtolower($m[1]) . '/' . str_replace('\\', '/', $m[2]);
    }
    return str_replace('\\', '/', $p);
}
function findBash(): ?string
{
    if (PHP_OS_FAMILY !== 'Windows') { return 'bash'; }
    foreach (['C:\\Program Files\\Git\\bin\\bash.exe', 'C:\\Program Files\\Git\\usr\\bin\\bash.exe', 'C:\\Program Files (x86)\\Git\\bin\\bash.exe'] as $b) {
        if (is_file($b)) { return $b; }
    }
    return null;
}
/** @return array{0:int,1:string} [kod wyjścia, stdout+stderr] */
function runProc(array $cmd, string $stdin = '', ?array $env = null): array
{
    $p = proc_open($cmd, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $env);
    if (!is_resource($p)) { return [-1, 'proc_open failed']; }
    @fwrite($pipes[0], $stdin);
    fclose($pipes[0]);
    $out = (string) stream_get_contents($pipes[1]);
    $err = (string) stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]);
    return [proc_close($p), trim($out . $err)];
}
function rrmdir(string $d): void
{
    if (is_link($d) || is_file($d)) { @unlink($d); return; }
    if (!is_dir($d)) { return; }
    foreach (scandir($d) ?: [] as $f) {
        if ($f !== '.' && $f !== '..') { @chmod("$d/$f", 0777); rrmdir("$d/$f"); }
    }
    @rmdir($d);
}
function write(string $path, string $content): void
{
    @mkdir(dirname($path), 0777, true);
    file_put_contents($path, $content);
}

$work = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'mp_scripts_' . bin2hex(random_bytes(4));
mkdir($work, 0777, true);
register_shutdown_function(static function () use ($work): void { rrmdir($work); });
cleanupOnInterrupt();
$bash = findBash();

/* ====================================================================== mailpanel-f2b-apply.php */
group('mailpanel-f2b-apply.php: zapis w miejscu');

$h = $work . '/f2b';
mkdir($h, 0777, true);
$live = $h . '/jail.local';
$bak  = $h . '/backups';
$fake = $h . '/fake_f2b.php';
write($fake, '<?php
$d = ' . var_export($h, true) . ';
file_put_contents("$d/calls.log", implode(" ", array_slice($argv, 1)) . "\n", FILE_APPEND);
$cmd = $argv[1] ?? "";
$rcFile = "$d/" . ($cmd === "-t" ? "t_rc" : "reload_rc");
$rc = is_file($rcFile) ? (int) trim((string) file_get_contents($rcFile)) : 0;
echo $rc === 0 ? "OK" : "ERROR: simulated failure of $cmd";
exit($rc);
');
write($h . '/prepend.php', '<?php
define("LIVE", ' . var_export($live, true) . ');
define("BAK", ' . var_export($bak, true) . ');
define("F2B", "php " . ' . var_export('"' . $fake . '"', true) . ');
define("KEEP_BACKUPS", 3);
');

$base = "[DEFAULT]\nbantime = 10m\nfindtime = 10m\nmaxretry = 5\nignoreip = 127.0.0.1/8 ::1 203.0.113.9\nbantime.increment = true\naction = %(action_)s\n\n"
      . "[sshd]\nenabled = true\nmaxretry = 3\n\n[dovecot]\nenabled = true\nfindtime = 10m\nmaxretry = 5\nbantime = 1h\n";
function helper(string $proposal, string ...$args): array
{
    global $root, $h;
    return runProc(['php', '-d', 'auto_prepend_file=' . $h . '/prepend.php', $root . '/scripts/mailpanel-f2b-apply.php', ...$args], $proposal);
}
function reset_state(string $content): void
{
    global $live, $bak, $h;
    @chmod($live, 0666);
    file_put_contents($live, $content);
    rrmdir($bak);
    @unlink($h . '/calls.log'); @unlink($h . '/t_rc'); @unlink($h . '/reload_rc');
}
function replaceLine(string $content, string $from, string $to): string
{
    $n = str_replace($from, $to, $content, $cnt);
    if ($cnt !== 1) { throw new RuntimeException("fixture: '$from' x$cnt"); }
    return $n;
}
function backups(): array { global $bak; return glob($bak . '/jail.local.*') ?: []; }

reset_state($base);
[$rc, $out] = helper($base);
eq([$rc, $out], [0, 'unchanged'], 'niezmieniona propozycja -> "unchanged", exit 0');
eq(file_get_contents($live), $base, 'bez zmian: plik nietknięty');
eq(backups(), [], 'bez zmian: bez kopii zapasowej');
ok(!is_file($h . '/calls.log'), 'bez zmian: fail2ban-client nie wywołany');

// ta sama opcja i wartość, inny odstęp = nie zmiana semantyczna
[$rc, $out] = helper(replaceLine($base, "maxretry = 3\n", "maxretry =   3\n"));
eq([$rc, $out], [0, 'unchanged'], 'zmiana samego odstępu -> "unchanged"');

// prawidłowa zmiana
$new = replaceLine($base, "[dovecot]\nenabled = true\nfindtime = 10m\nmaxretry = 5", "[dovecot]\nenabled = true\nfindtime = 10m\nmaxretry = 7");
reset_state($base);
[$rc, $out] = helper($new);
eq($rc, 0, 'prawidłowa zmiana: exit 0');
ok(str_starts_with($out, 'saved and reloaded (1 changes)'), 'prawidłowa zmiana: komunikat ("' . $out . '")');
eq(file_get_contents($live), $new, 'plik w miejscu == propozycja');
eq(count(backups()), 1, 'powstała jedna kopia zapasowa');
eq(file_get_contents(backups()[0] ?? ''), $base, 'kopia zapasowa == oryginał');
$calls = file($h . '/calls.log', FILE_IGNORE_NEW_LINES) ?: [];
eq($calls, ['-t', 'reload'], 'fail2ban-client: najpierw -t, potem reload');

// plik KRÓTSZY po zmianie: nie może zostać ogon starej treści
$shorter = replaceLine($base, 'ignoreip = 127.0.0.1/8 ::1 203.0.113.9', 'ignoreip = 127.0.0.1/8');
reset_state($base);
[$rc, $out] = helper($shorter);
eq($rc, 0, 'krótszy plik: exit 0');
eq(file_get_contents($live), $shorter, 'krótszy plik: brak ogona starej treści (ftruncate)');
ok(filesize($live) === strlen($shorter), 'krótszy plik: rozmiar == długość propozycji');

// DŁUŻSZY plik
$longer = replaceLine($base, 'ignoreip = 127.0.0.1/8 ::1 203.0.113.9', 'ignoreip = 127.0.0.1/8 ::1 203.0.113.9 198.51.100.0/24 2001:db8::/32');
reset_state($base);
[$rc, ] = helper($longer);
eq([$rc, file_get_contents($live)], [0, $longer], 'dłuższy plik zapisany w całości');

// odrzucenia: nic się nie zmienia
$refusals = [
    'opcja spoza listy sekcji [sshd]'         => replaceLine($base, "[sshd]\nenabled = true\nmaxretry = 3", "[sshd]\nenabled = true\nmaxretry = 1"),
    'opcja [DEFAULT] action'                  => replaceLine($base, 'action = %(action_)s', 'action = nftables-allports[name=x]'),
    'zła wartość maxretry'                    => replaceLine($base, "findtime = 10m\nmaxretry = 5\nbantime = 1h", "findtime = 10m\nmaxretry = abc\nbantime = 1h"),
    'wstrzyknięcie w bantime'                 => replaceLine($base, "maxretry = 5\nbantime = 1h", "maxretry = 5\nbantime = 1h; touch /tmp/x"),
    'zły ignoreip (maska /99)'                => replaceLine($base, '203.0.113.9', '203.0.113.9/99'),
    'ignoreip z poleceniem'                   => replaceLine($base, '203.0.113.9', '`id`'),
    'enabled = true w [DEFAULT] (linia nowa)' => replaceLine($base, 'action = %(action_)s', 'enabled = true'),
    'nowy nagłówek zamiast linii'             => replaceLine($base, 'action = %(action_)s', '[evil]'),
    'zmieniony nagłówek sekcji'               => replaceLine($base, '[sshd]', '[dovecot]'),
    'inna liczba linii'                       => $base . "extra = 1\n",
    'inna opcja w tej samej linii'            => replaceLine($base, 'findtime = 10m' . "\n" . 'maxretry = 5' . "\n" . 'ignoreip', 'findtime = 10m' . "\n" . 'bantime = 5' . "\n" . 'ignoreip'),
    // 1.4.0: poprawny zapis, ale jail, który nigdy nie banuje ([DEFAULT] dziedziczy sshd i recidive)
    'ignoreip 0.0.0.0/0 (cały internet)'      => replaceLine($base, '203.0.113.9', '0.0.0.0/0'),
    'ignoreip ::/0'                           => replaceLine($base, '203.0.113.9', '::/0'),
    'ignoreip 10.0.0.0/4 (szersza niż /8)'    => replaceLine($base, '203.0.113.9', '10.0.0.0/4'),
    'ignoreip 2001::/16 (szersza niż /32)'    => replaceLine($base, '203.0.113.9', '2001::/16'),
    'ignoreip zła nazwa hosta'                => replaceLine($base, '203.0.113.9', 'bad_name..example'),
    'maxretry 0'                              => replaceLine($base, "findtime = 10m\nmaxretry = 5\nbantime = 1h", "findtime = 10m\nmaxretry = 0\nbantime = 1h"),
    'maxretry 101'                            => replaceLine($base, "findtime = 10m\nmaxretry = 5\nbantime = 1h", "findtime = 10m\nmaxretry = 101\nbantime = 1h"),
    'maxretry 999999'                         => replaceLine($base, "bantime = 10m\nfindtime = 10m\nmaxretry = 5", "bantime = 10m\nfindtime = 10m\nmaxretry = 999999"),
    'bantime 1 (sekunda)'                     => replaceLine($base, "maxretry = 5\nbantime = 1h", "maxretry = 5\nbantime = 1"),
    'findtime 30s w [DEFAULT]'                => replaceLine($base, "bantime = 10m\nfindtime = 10m", "bantime = 10m\nfindtime = 30s"),
    'findtime -1'                             => replaceLine($base, "bantime = 10m\nfindtime = 10m", "bantime = 10m\nfindtime = -1"),
    'bantime ponad rok'                       => replaceLine($base, "maxretry = 5\nbantime = 1h", "maxretry = 5\nbantime = 53w"),
];
foreach ($refusals as $label => $prop) {
    reset_state($base);
    [$rc, $out] = helper($prop);
    ok($rc === 1 && str_starts_with($out, 'refused:'), "odrzucone ($label): exit 1, komunikat [" . substr($out, 0, 70) . ']');
    eq(file_get_contents($live), $base, "odrzucone ($label): plik nietknięty");
    ok(!is_file($h . '/calls.log'), "odrzucone ($label): fail2ban-client nie wywołany");
}

// 1.4.0: granice zakresów, które panel sam wpisuje — przyjęte
$accepted = [
    'ignoreip 10.0.0.0/8 i 2001:db8::/32'     => replaceLine($base, '203.0.113.9', '10.0.0.0/8 2001:db8::/32'),
    'ignoreip z nazwą hosta (dynamiczny DNS)' => replaceLine($base, '203.0.113.9', '203.0.113.9 home.example.net'),
    'maxretry 1'                              => replaceLine($base, "findtime = 10m\nmaxretry = 5\nbantime = 1h", "findtime = 10m\nmaxretry = 1\nbantime = 1h"),
    'maxretry 100'                            => replaceLine($base, "findtime = 10m\nmaxretry = 5\nbantime = 1h", "findtime = 10m\nmaxretry = 100\nbantime = 1h"),
    'bantime -1 (na stałe)'                   => replaceLine($base, "maxretry = 5\nbantime = 1h", "maxretry = 5\nbantime = -1"),
    'bantime 1h30m'                           => replaceLine($base, "maxretry = 5\nbantime = 1h", "maxretry = 5\nbantime = 1h30m"),
    'findtime 60 i 365d'                      => replaceLine($base, "bantime = 10m\nfindtime = 10m", "bantime = 365d\nfindtime = 60"),
];
foreach ($accepted as $label => $prop) {
    reset_state($base);
    [$rc, $out] = helper($prop);
    ok($rc === 0 && str_starts_with($out, 'saved and reloaded'), "przyjęte ($label): exit 0 [" . substr($out, 0, 70) . ']');
    eq(file_get_contents($live), $prop, "przyjęte ($label): plik == propozycja");
}

reset_state($base);
[$rc, $out] = helper('');
ok($rc === 1 && str_contains($out, 'empty'), 'pusta propozycja -> odrzucone');
[$rc, $out] = helper(str_repeat("# x\n", 20000));
ok($rc === 1 && str_contains($out, 'too large'), 'propozycja > 64 KiB -> odrzucone');
[$rc, $out] = helper($base . "\0");
ok($rc === 1, 'bajt zerowy -> odrzucone');

// fail2ban-client -t odrzuca konfigurację: plik wraca do oryginału, kod 1
reset_state($base);
file_put_contents($h . '/t_rc', '1');
[$rc, $out] = helper($new);
ok($rc === 1 && str_contains($out, 'restored'), 'błąd fail2ban -t: exit 1 i "przywrócono" (' . substr($out, 0, 90) . ')');
eq(file_get_contents($live), $base, 'błąd fail2ban -t: plik == oryginał (przywrócony w miejscu)');
eq(count(backups()), 1, 'błąd fail2ban -t: kopia zapasowa zostaje');
eq(file($h . '/calls.log', FILE_IGNORE_NEW_LINES), ['-t'], 'błąd fail2ban -t: reload nie wywołany');

// reload nie działa: zapisane i sprawdzone, ale kod 2 (nie sukces)
reset_state($base);
file_put_contents($h . '/reload_rc', '1');
[$rc, $out] = helper($new);
ok($rc === 2 && str_contains($out, 'the reload failed'), 'błąd reload: exit 2 (' . substr($out, 0, 90) . ')');
ok(!str_contains($out, 'saved and reloaded'), 'błąd reload: NIE raportuje sukcesu');
eq(file_get_contents($live), $new, 'błąd reload: nowa treść zostaje (przeszła -t)');

// jail.local niezapisywalny (read-only): zapis się nie udaje => odmowa, nie "sukces"
$isRoot = function_exists('posix_geteuid') && posix_geteuid() === 0;
if ($isRoot) {
    skip('uruchomiono jako root — test read-only pominięty', 2);
} else {
    reset_state($base);
    chmod($live, 0444);
    [$rc, $out] = helper($new);
    ok($rc !== 0 && !str_contains($out, 'saved and reloaded'), 'jail.local read-only: exit != 0 i brak fałszywego sukcesu (' . substr($out, 0, 80) . ')');
    eq(file_get_contents($live), $base, 'jail.local read-only: plik nietknięty');
    chmod($live, 0666);
}

// brak pliku / dowiązanie: helper nie tworzy jail.local przez fopen('c+')
@unlink($live);
[$rc, $out] = helper($new);
ok($rc === 1 && !is_file($live), 'brak jail.local: odmowa i plik NIE został utworzony');

// katalog kopii niedostępny: odmowa przed zapisem
reset_state($base);
write($h . '/afile', 'x');
write($h . '/prepend_badbak.php', '<?php define("LIVE", ' . var_export($live, true) . '); define("BAK", ' . var_export($h . '/afile/sub', true) . ');'
    . ' define("F2B", "php " . ' . var_export('"' . $fake . '"', true) . ');');
[$rc, $out] = runProc(['php', '-d', 'auto_prepend_file=' . $h . '/prepend_badbak.php', $root . '/scripts/mailpanel-f2b-apply.php'], $new);
ok($rc === 1 && str_starts_with($out, 'refused:'), 'katalog kopii niedostępny: odmowa (' . substr($out, 0, 70) . ')');
eq(file_get_contents($live), $base, 'katalog kopii niedostępny: plik nietknięty');

// przycinanie kopii: zostają najnowsze KEEP_BACKUPS (=3)
reset_state($base);
mkdir($bak, 0777, true);
for ($i = 1; $i <= 6; $i++) { file_put_contents($bak . '/jail.local.2020010100000' . $i . '-1', "old$i"); }
[$rc, ] = helper($new);
eq($rc, 0, 'przycinanie kopii: zapis OK');
eq(count(backups()), 3, 'przycinanie kopii: zostały 3 najnowsze');

// selftest
reset_state($base);
[$rc, $out] = helper('', '--selftest');
eq([$rc, $out], [0, 'selftest ok'], '--selftest: ok');
eq(file_get_contents($live), $base, '--selftest: nic nie zmienia');

$src = (string) file_get_contents($root . '/scripts/mailpanel-f2b-apply.php');
ok(str_contains($src, "PHP_SAPI !== 'cli'"), 'helper: strażnik CLI');
ok(!preg_match('/\brename\(|LIVE \. \'\.new\'/', $src), 'helper: brak tmp + rename (nie działa pod ProtectSystem=full)');
ok(str_contains($src, "fopen(LIVE, 'c+')") && str_contains($src, 'LOCK_EX') && str_contains($src, 'ftruncate'), 'helper: fopen c+ / flock / ftruncate');

/* ====================================================================== mailpanel-delete-user.sh */
group('mailpanel-delete-user.sh');

$size = 49;     // checks in this block and the purge block below (blockSize() verifies it whenever bash is there)
if ($bash === null) {
    skip('brak bash — testy skryptów powłoki pominięte', $size);
} else {
    $start = seen();
    $d = $work . '/del';
    $mail = $d . '/vhosts';
    $quar = $d . '/deleted';
    mkdir($mail . '/example.org/jan.kowalski/cur', 0777, true);
    file_put_contents($mail . '/example.org/jan.kowalski/cur/1', 'mail one');
    mkdir($mail . '/example.org/ala', 0777, true);
    mkdir($mail . '/other.net/bob', 0777, true);
    file_put_contents($mail . '/other.net/bob/keep', 'bob mail');
    $doveadm = $d . '/doveadm';
    write($doveadm, "#!/bin/bash\n[ -f " . toPosix($d) . "/doveadm_out ] && cat " . toPosix($d) . "/doveadm_out\nexit \$(cat " . toPosix($d) . "/doveadm_rc)\n");
    chmod($doveadm, 0755);
    file_put_contents($d . '/doveadm_rc', '67');

    $script = str_replace("\r\n", "\n", (string) file_get_contents($root . '/scripts/mailpanel-delete-user.sh'));   // CRLF (autocrlf) -> LF, zanim podmienimy ścieżki
    $script = preg_replace(
        ['/^BASE_DIR=".*"$/m', '/^QUARANTINE_DIR=".*"$/m', '/^DOVEADM=".*"$/m'],
        ['BASE_DIR="' . toPosix($mail) . '"', 'QUARANTINE_DIR="' . toPosix($quar) . '"', 'DOVEADM="' . toPosix($doveadm) . '"'],
        $script
    );
    $script = str_replace("\r\n", "\n", (string) $script);
    $sp = $d . '/delete-user.sh';
    file_put_contents($sp, $script);
    // NTFS nie ma trybów POSIX: chmod 700 na katalogu kończy się tam błędem, więc na Windows podstawiamy atrapę chmod
    // (przez BASH_ENV); na Linuksie skrypt używa prawdziwego chmod.
    $envFile = $d . '/win_env.sh';
    file_put_contents($envFile, "chmod() { :; }\n");
    $delEnv = PHP_OS_FAMILY === 'Windows' ? array_merge(getenv(), ['BASH_ENV' => toPosix($envFile)]) : null;
    $del = static function (string ...$args) use ($bash, $sp, $delEnv): array { return runProc([$bash, toPosix($sp), ...$args], '', $delEnv); };
    $stat = static fn(string $p): bool => is_dir($p);

    [$rc, $out] = $del();
    ok($rc === 1 && str_contains($out, 'Invalid email format'), 'bez argumentów: 1 + "Invalid email format" (sonda panelu)');
    [$rc, ] = $del('');
    eq($rc, 1, 'pusty argument: 1');
    [$rc, ] = $del('a@example.org', 'b@example.org');
    eq($rc, 1, 'dwa argumenty: 1');
    foreach (['.', '..', '...', '../x@example.org', 'a/b@example.org', 'a\\b@example.org', '@example.org', 'jan@', 'x@y@z.org', 'a b@example.org',
              '.@example.org', '..@example.org', '-rf@example.org', 'jan.@example.org', '.jan@example.org', 'jan..kowalski@example.org',
              'jan@..', 'jan@example..org', 'jan@/etc.org', 'jan@example.org/..', 'jan@-example.org', 'jan@example', "a\nb@example.org", 'jan%2f..@example.org'] as $bad) {
        [$rc, $out] = $del($bad);
        ok(in_array($rc, [1, 2], true), 'odrzucone [' . str_replace("\n", '\\n', $bad) . ']: kod ' . $rc . ' ' . substr($out, 0, 40));
    }
    ok($stat($mail . '/example.org/jan.kowalski') && $stat($mail . '/other.net/bob') && !is_dir($quar), 'po odrzuceniach nic nie ruszone');

    [$rc, $out] = $del('nikt@example.org');
    ok($rc === 4 && str_contains($out, 'not found'), 'brak katalogu: exit 4 (nie 0) -> ' . $rc);

    file_put_contents($d . '/doveadm_rc', '0');
    [$rc, $out] = $del('jan.kowalski@example.org');
    ok($rc === 6 && $stat($mail . '/example.org/jan.kowalski'), 'konto nadal w Dovecot: exit 6, skrzynka nietknięta');
    file_put_contents($d . '/doveadm_rc', '1');
    [$rc, ] = $del('jan.kowalski@example.org');
    ok($rc === 7 && $stat($mail . '/example.org/jan.kowalski'), 'doveadm zwrócił błąd: exit 7 (odmowa), skrzynka nietknięta');
    file_put_contents($d . '/doveadm_out', 'doveadm(jan.kowalski@example.org): Error: net_connect_unix(/run/dovecot/auth-userdb) failed: Connection refused');
    [$rc, ] = $del('jan.kowalski@example.org');
    ok($rc === 7 && $stat($mail . '/example.org/jan.kowalski'), 'doveadm: socket autoryzacji niedostępny (exit 1 + inny komunikat): odmowa 7, skrzynka nietknięta');
    rename($doveadm, $doveadm . '.off');
    [$rc, ] = $del('jan.kowalski@example.org');
    ok($rc === 7 && $stat($mail . '/example.org/jan.kowalski'), 'brak doveadm: exit 7 (odmowa)');
    rename($doveadm . '.off', $doveadm);
    @unlink($d . '/doveadm_out');
    file_put_contents($d . '/doveadm_rc', '67');

    // wielkie litery w argumencie (panel dawniej podawał zapisany adres z wielkimi literami)
    [$rc, $out] = $del('Jan.Kowalski@Example.ORG');
    ok($rc === 0 && str_contains($out, 'Success'), 'adres z wielkimi literami -> małe, skrzynka znaleziona i przeniesiona: ' . $rc . ' ' . substr($out, 0, 80));
    ok(!is_dir($mail . '/example.org/jan.kowalski'), 'skrzynka zniknęła z vhosts');
    $moved = glob($quar . '/example.org__jan.kowalski__*') ?: [];
    ok(count($moved) === 1 && is_file($moved[0] . '/cur/1') && file_get_contents($moved[0] . '/cur/1') === 'mail one', 'poczta w kwarantannie (nie skasowana)');
    ok($stat($mail . '/example.org/ala') && $stat($mail . '/other.net/bob'), 'inne skrzynki i domeny nietknięte');

    [$rc, ] = $del('jan.kowalski@example.org');
    eq($rc, 4, 'drugie usunięcie tej samej skrzynki: exit 4');

    // dowiązanie symboliczne i zwykły plik w miejscu skrzynki
    file_put_contents($mail . '/example.org/plik', 'x');
    [$rc, ] = $del('plik@example.org');
    eq($rc, 3, 'zwykły plik zamiast katalogu: exit 3');
    $linked = @symlink($mail . '/other.net/bob', $mail . '/example.org/link');
    if ($linked) {
        [$rc, ] = $del('link@example.org');
        ok($rc === 3 && is_dir($mail . '/other.net/bob'), 'dowiązanie symboliczne: exit 3, cel nietknięty');
        @unlink($mail . '/example.org/link');
        mkdir($d . '/outside/zed', 0777, true);
        @symlink($d . '/outside', $mail . '/linked.org');
        [$rc, ] = $del('zed@linked.org');
        ok($rc === 3 && is_dir($d . '/outside/zed'), 'domena będąca dowiązaniem poza vhosts: exit 3, katalog poza nietknięty');
    } else {
        skip('symlink() niedostępny (Windows bez uprawnień) — testy dowiązań pominięte', 2);
    }

    // kwarantanna będąca dowiązaniem
    if ($linked) {
        rrmdir($quar);
        @symlink($d . '/outside', $quar);
        [$rc, ] = $del('ala@example.org');
        ok($rc === 8 && is_dir($mail . '/example.org/ala'), 'kwarantanna jako dowiązanie: exit 8, skrzynka nietknięta');
        @unlink($quar);
    } else {
        skip('symlink() unavailable — the symlinked quarantine (exit 8) not tested', 1);
    }
    [$rc, $out] = $del('ala@example.org');
    ok($rc === 0 && !is_dir($mail . '/example.org/ala'), 'kolejna skrzynka: przeniesiona (kwarantanna odtworzona)');

    /* ------------------------------------------------------------------ mailpanel-purge-deleted */
    group('mailpanel-purge-deleted: wiek = czas usunięcia z nazwy, nie mtime');
    $pq = str_replace("\r\n", "\n", (string) file_get_contents($root . '/scripts/mailpanel-purge-deleted'));
    $pq = preg_replace('/^QUARANTINE_DIR=".*"$/m', 'QUARANTINE_DIR="' . toPosix($quar) . '"', $pq);
    file_put_contents($d . '/purge.sh', (string) $pq);
    $purge = static fn(): array => runProc([$bash, toPosix($d . '/purge.sh')]);

    $justMoved = glob($quar . '/example.org__*') ?: [];
    // with symlinks the test above removed the quarantine (and jan.kowalski's entry in it) for the symlink case
    ok(count($justMoved) >= ($linked ? 1 : 2), 'skrzynki przeniesione przez delete-user są w kwarantannie (' . count($justMoved) . ')');
    // skrzynka nieużywanego od lat konta: stary mtime katalogu, ale usunięta TERAZ (nazwa) -> musi zostać
    foreach ($justMoved as $jm) { @touch($jm, time() - 400 * 86400); }
    mkdir($quar . '/example.org__stary__20000101000000/cur', 0777, true);
    mkdir($quar . '/example.org__starypid__20000101000000-4242', 0777, true);
    mkdir($quar . '/reczny-katalog', 0777, true);
    @touch($quar . '/reczny-katalog', time() - 400 * 86400);
    [$rc, $out] = $purge();
    eq($rc, 0, 'purge: exit 0');
    ok(!is_dir($quar . '/example.org__stary__20000101000000') && !is_dir($quar . '/example.org__starypid__20000101000000-4242'), 'wpisy usunięte dawno (data w nazwie) skasowane');
    $still = array_filter($justMoved, 'is_dir');
    ok(count($still) === count($justMoved), 'świeżo usunięte konta zostają mimo starego mtime (mv zachowuje mtime) — to był błąd -mtime +30');
    ok(is_dir($quar . '/reczny-katalog') && str_contains($out, 'kept (no deletion timestamp'), 'katalog bez daty w nazwie: nigdy kasowany, wypisany');
    $pq0 = preg_replace('/^RETENTION_DAYS=.*$/m', 'RETENTION_DAYS=0', (string) $pq);
    file_put_contents($d . '/purge0.sh', (string) $pq0);
    mkdir($quar . '/example.org__zero__20000101000000', 0777, true);
    [$rc, ] = runProc([$bash, toPosix($d . '/purge0.sh')]);
    ok($rc === 0 && is_dir($quar . '/example.org__zero__20000101000000'), 'RETENTION_DAYS=0: nic nie jest kasowane');
    blockSize($start, $size, 'mailpanel-delete-user.sh + mailpanel-purge-deleted');
}

/* ====================================================================== update-postfix-limits.sh */
group('update-postfix-limits.sh');

$size = 19;
if ($bash === null) {
    skip('brak bash', $size);
} else {
    $start = seen();
    $p = $work . '/pf';
    mkdir($p . '/state', 0777, true);
    $sdir = toPosix($p . '/state');
    $reset = static function () use ($p): void {
        foreach (glob($p . '/state/*') ?: [] as $f) { @unlink($f); }
        file_put_contents($p . '/state/smtpd_client_message_rate_limit', "15\n");
        file_put_contents($p . '/state/smtpd_client_connection_rate_limit', "10\n");
        file_put_contents($p . '/state/smtpd_client_connection_count_limit', "10\n");
    };
    write($p . '/postconf', "#!/bin/bash\nS=$sdir\n" . <<<'SH'
if [ "$1" = "-h" ]; then cat "$S/$2" 2>/dev/null | tr -d '\n'; echo; exit 0; fi
if [ "$1" = "-e" ]; then
  shift
  [ -e "$S/ro" ] && { echo "postconf: fatal: open /etc/postfix/main.cf.tmp: Read-only file system" >&2; exit 1; }
  for kv in "$@"; do
    [ -e "$S/stuck" ] || { k=${kv%%=*}; v=${kv#*=}; echo "$v" > "$S/$k"; }
  done
  exit 0
fi
exit 2
SH);
    write($p . '/postfix', "#!/bin/bash\nexit \$(cat $sdir/check_rc 2>/dev/null || echo 0)\n");
    write($p . '/systemctl', "#!/bin/bash\nexit \$(cat $sdir/reload_rc 2>/dev/null || echo 0)\n");
    foreach (['postconf', 'postfix', 'systemctl'] as $f) { chmod($p . '/' . $f, 0755); }
    $sc = str_replace("\r\n", "\n", (string) file_get_contents($root . '/scripts/update-postfix-limits.sh'));
    $sc = preg_replace(
        ['/^POSTCONF=".*"$/m', '/^POSTFIX=".*"$/m', '/^SYSTEMCTL=".*"$/m'],
        ['POSTCONF="' . toPosix($p) . '/postconf"', 'POSTFIX="' . toPosix($p) . '/postfix"', 'SYSTEMCTL="' . toPosix($p) . '/systemctl"'],
        $sc
    );
    file_put_contents($p . '/upl.sh', str_replace("\r\n", "\n", (string) $sc));
    $upl = static function (string ...$args) use ($bash, $p): array { return runProc([$bash, toPosix($p . '/upl.sh'), ...$args]); };
    $val = static fn(string $k): string => trim((string) file_get_contents($GLOBALS['p'] . '/state/' . $k));

    $reset();
    [$rc, $out] = $upl();
    ok($rc === 1 && str_contains($out, 'Inputs must be integers'), 'bez argumentów: 1 + "Inputs must be integers" (sonda panelu)');
    foreach ([['1', '2'], ['a', '1', '1'], ['-1', '1', '1'], ['1.5', '1', '1'], ['1', '1', '1', '1'], ['', '1', '1'], ['1234567', '1', '1'], ['1; id', '1', '1']] as $bad) {
        [$rc, ] = $upl(...$bad);
        ok($rc === 1, 'złe argumenty [' . implode(' ', $bad) . ']: exit 1');
    }
    eq($val('smtpd_client_message_rate_limit'), '15', 'złe argumenty nie zmieniły konfiguracji');

    [$rc, $out] = $upl('20', '5', '7');
    ok($rc === 0 && str_starts_with($out, 'Postfix limits updated'), 'poprawne wartości: exit 0 + komunikat (' . $out . ')');
    eq([$val('smtpd_client_message_rate_limit'), $val('smtpd_client_connection_rate_limit'), $val('smtpd_client_connection_count_limit')], ['20', '5', '7'], 'wartości zapisane');
    [$rc, ] = $upl('007', '0', '1');
    eq([$rc, $val('smtpd_client_message_rate_limit')], [0, '7'], 'wiodące zera normalizowane (007 -> 7, nie ósemkowo)');

    $reset();
    touch($p . '/state/ro');
    [$rc, $out] = $upl('20', '5', '7');
    ok($rc === 3 && !str_contains($out, 'updated'), 'main.cf tylko do odczytu (ProtectSystem): exit 3, BEZ fałszywego sukcesu (' . substr($out, 0, 80) . ')');
    ok(str_contains($out, 'ReadWritePaths=/etc/postfix'), 'komunikat podpowiada ReadWritePaths=/etc/postfix');
    eq($val('smtpd_client_message_rate_limit'), '15', 'read-only: stara wartość');

    $reset();
    touch($p . '/state/stuck');            // postconf -e kończy się 0, ale nic nie zapisuje
    [$rc, $out] = $upl('20', '5', '7');
    ok($rc === 3 && !str_contains($out, 'updated'), 'ciche niezapisanie: odczyt zwrotny wykrywa, exit 3 (' . substr($out, 0, 80) . ')');

    $reset();
    file_put_contents($p . '/state/check_rc', '1');
    [$rc, $out] = $upl('20', '5', '7');
    ok($rc === 4 && $val('smtpd_client_message_rate_limit') === '15', 'postfix check nie przechodzi: exit 4 i wartości przywrócone');

    $reset();
    file_put_contents($p . '/state/reload_rc', '1');
    [$rc, $out] = $upl('20', '5', '7');
    ok($rc === 5 && !str_contains($out, 'Postfix limits updated'), 'reload nie działa: exit 5, bez sukcesu');
    blockSize($start, $size, 'update-postfix-limits.sh');
}

/* ====================================================================== mailpanel-sudoers-cleanup.sh */
group('mailpanel-sudoers-cleanup.sh');

$size = 29;
if ($bash === null) {
    skip('brak bash', $size);
} else {
    $start = seen();
    $s = $work . '/sudo';
    $dir = $s . '/sudoers.d';
    mkdir($dir, 0777, true);
    $visudo = $s . '/visudo';
    // atrapa visudo: -cf PLIK odrzuca plik ze słowem BROKEN; samo -c (cała konfiguracja) zawodzi, gdy istnieje global_fail
    write($visudo, "#!/bin/bash\nf=\"\${2:-}\"\n[ \"\$1\" = \"-c\" ] && [ -e " . toPosix($s) . "/global_fail ] && exit 1\n[ -n \"\$f\" ] && grep -q BROKEN \"\$f\" && exit 1\nexit 0\n");
    chmod($visudo, 0755);
    $sc = str_replace("\r\n", "\n", (string) file_get_contents($root . '/scripts/mailpanel-sudoers-cleanup.sh'));
    $sc = preg_replace(
        ['/^SUDOERS_MAIN=".*"$/m', '/^SUDOERS_DIR=".*"$/m', '/^VISUDO=".*"$/m', '/^BACKUP_ROOT=".*"$/m'],
        ['SUDOERS_MAIN="' . toPosix($s) . '/sudoers"', 'SUDOERS_DIR="' . toPosix($dir) . '"', 'VISUDO="' . toPosix($visudo) . '"', 'BACKUP_ROOT="' . toPosix($s) . '"'],
        $sc
    );
    file_put_contents($s . '/cleanup.sh', str_replace("\r\n", "\n", (string) $sc));
    $clean = static function (string ...$args) use ($bash, $s): array { return runProc([$bash, toPosix($s . '/cleanup.sh'), ...$args]); };

    file_put_contents($s . '/sudoers', "Defaults env_reset\nroot ALL=(ALL:ALL) ALL\n"
        . "# mailpanel (1.0)\nwww-data ALL=(root) NOPASSWD: /usr/bin/fail2ban-client set * banip *\n"
        . "www-data ALL=(root) NOPASSWD: /usr/bin/doveadm -f flow quota get -u *\n"
        . "dominik ALL=(ALL) NOPASSWD: /usr/bin/fail2ban-client status\n");
    file_put_contents($dir . '/mailpanel', "www-data ALL=(root) NOPASSWD: /usr/local/sbin/mailpanel-f2b-apply \"\"\n");   // własny plik: pomijany
    file_put_contents($dir . '/mailpanel-quota', "www-data ALL=(root) NOPASSWD: /usr/bin/doveadm -f flow quota get -u *\n");
    file_put_contents($dir . '/mailpanel-scan', "# scan\nwww-data ALL=(root) NOPASSWD: /usr/local/bin/mailpanel-scan.sh *\n");
    file_put_contents($dir . '/oldalias', "Cmnd_Alias MAILPANEL = /usr/bin/fail2ban-client set * banip *, /usr/bin/fail2ban-client status *\nwww-data ALL=(root) NOPASSWD: MAILPANEL\n");
    file_put_contents($dir . '/unrelated', "www-data ALL=(root) NOPASSWD: /usr/bin/apt-get update\n");
    file_put_contents($dir . '/ignored.bak', "www-data ALL=(root) NOPASSWD: /usr/bin/fail2ban-client set * banip *\n");   // sudo ignoruje pliki z kropką
    file_put_contents($dir . '/multi', "www-data ALL=(root) NOPASSWD: /usr/bin/fail2ban-client set * banip *, /usr/bin/systemctl restart nginx\n");
    // jak na produkcji (1.0.1 zainstalowane ręcznie pod inną nazwą): wieloliniowy Cmnd_Alias z tą samą nazwą aliasu
    $prodF2b = "# 1.0.1\nCmnd_Alias MAILPANEL_F2B = /usr/bin/fail2ban-client ping, \\\n    /usr/bin/fail2ban-client ^set [A-Za-z0-9_-]+ bantime -?[0-9]+\$, \\\n"
        . "    /usr/local/sbin/mailpanel-f2b-apply\nwww-data ALL=(root) NOPASSWD: MAILPANEL_F2B\n";
    file_put_contents($dir . '/mailpanel-f2b', $prodF2b);
    // reguła łącząca alias panelu z CUDZYM aliasem: tylko ręcznie
    $aliasMix = "Cmnd_Alias PANELX = /usr/bin/doveadm -f flow quota get -u *\nCmnd_Alias OTHERX = /usr/bin/apt-get update\nwww-data ALL=(root) NOPASSWD: PANELX, OTHERX\n";
    file_put_contents($dir . '/aliasmix', $aliasMix);

    [$rc, $out] = $clean();
    eq($rc, 1, 'tryb próbny: exit 1 (są stare reguły)');
    foreach (['sudoers:', 'mailpanel-quota:', 'mailpanel-scan:', 'oldalias:', 'multi:', 'mailpanel-f2b:2-4:', 'mailpanel-f2b:5:', 'aliasmix:3:'] as $needle) {
        ok(str_contains($out, $needle), "tryb próbny wymienia $needle");
    }
    ok(!str_contains($out, 'unrelated') && !str_contains($out, 'ignored.bak') && !str_contains($out, 'dominik'), 'tryb próbny pomija cudze reguły, pliki z kropką i własny plik');
    ok(str_contains($out, 'also grants other commands'), 'wiersz z innym poleceniem oznaczony do ręcznej edycji');
    ok(str_contains((string) file_get_contents($dir . '/mailpanel-quota'), 'quota get -u *'), 'tryb próbny nic nie zmienia');

    [$rc, $out] = $clean('--apply');
    eq($rc, 2, '--apply: exit 2 (plik "multi" wymaga ręcznej edycji)');
    $quota = (string) file_get_contents($dir . '/mailpanel-quota');
    ok(str_starts_with($quota, '# [RoundControl ') && str_contains($quota, 'removed]'), 'mailpanel-quota: reguła zakomentowana');
    $main = (string) file_get_contents($s . '/sudoers');
    ok(str_contains($main, '# [RoundControl') && str_contains($main, "\ndominik ALL=(ALL) NOPASSWD: /usr/bin/fail2ban-client status\n"), 'sudoers: stare reguły www-data zakomentowane, reguła innego użytkownika nietknięta');
    $alias = (string) file_get_contents($dir . '/oldalias');
    ok(substr_count($alias, '# [RoundControl') === 2, 'alias i reguła, która go używa, zakomentowane razem');
    eq((string) file_get_contents($dir . '/unrelated'), "www-data ALL=(root) NOPASSWD: /usr/bin/apt-get update\n", 'niezwiązana reguła nietknięta');
    ok(str_contains((string) file_get_contents($dir . '/multi'), "fail2ban-client set * banip *, /usr/bin/systemctl"), 'wiersz z wieloma poleceniami nietknięty');
    ok(count(glob($s . '/sudoers-backup-*/mailpanel-quota') ?: []) === 1, 'kopia oryginału w sudoers-backup-*');
    ok(!is_file($dir . '/mailpanel-quota.new'), 'brak plików tymczasowych *.new');
    $f2bLines = array_filter(explode("\n", (string) file_get_contents($dir . '/mailpanel-f2b')), static fn(string $l): bool => $l !== '');
    ok(array_filter($f2bLines, static fn(string $l): bool => $l[0] !== '#') === [], 'wieloliniowy alias (MAILPANEL_F2B): KAŻDA linia zakomentowana, także kontynuacje');
    ok(array_filter($f2bLines, static fn(string $l): bool => (bool) preg_match('/\\\\\s*$/', $l)) === [], 'zakomentowane linie nie kończą się "\\" (nic nie przechodzi do następnej linii)');
    eq((string) file_get_contents($dir . '/aliasmix'), $aliasMix, 'reguła z cudzym aliasem: nietknięta (do ręcznej edycji)');

    // visudo odrzuca zmieniony plik -> plik NIE jest podmieniany
    file_put_contents($dir . '/broken', "www-data ALL=(root) NOPASSWD: /usr/bin/doveadm -f flow quota get -u *\n# BROKEN\n");
    $before = (string) file_get_contents($dir . '/broken');
    [$rc, ] = $clean('--apply');
    eq((string) file_get_contents($dir . '/broken'), $before, 'visudo odrzuca zmianę: oryginał zostaje');
    eq($rc, 2, 'visudo odrzuca zmianę: exit 2');

    unlink($dir . '/broken');
    unlink($dir . '/multi');
    unlink($dir . '/aliasmix');

    // cała konfiguracja nie przechodzi visudo -c po zmianach -> pliki tego przebiegu wracają z kopii
    $late = "www-data ALL=(root) NOPASSWD: /usr/local/bin/mailpanel-scan.sh *\n";
    file_put_contents($dir . '/late', $late);
    touch($s . '/global_fail');
    [$rc, $out] = $clean('--apply');
    ok($rc === 2 && str_contains($out, 'restored'), 'visudo -c (całość) zawodzi: exit 2 i przywrócenie (' . substr($out, -80) . ')');
    eq((string) file_get_contents($dir . '/late'), $late, 'visudo -c (całość) zawodzi: plik wrócił do oryginału');
    unlink($s . '/global_fail');
    unlink($dir . '/late');

    [$rc, $out] = $clean();
    eq($rc, 0, 'po sprzątaniu: exit 0');
    ok(str_contains($out, 'No leftover'), 'po sprzątaniu: komunikat "No leftover"');
    blockSize($start, $size, 'mailpanel-sudoers-cleanup.sh');
}

/* ====================================================================== funkcje pomocnicze install.sh */
group('install.sh: file_kv / render_template / valid_secret');

$size = 24;
if ($bash === null) {
    skip('brak bash', $size);
} else {
    $start = seen();
    $inst = str_replace("\r\n", "\n", (string) file_get_contents($root . '/install.sh'));
    ok(preg_match('/# --- helpers begin.*?\n(.*?)\n# --- helpers end/s', $inst, $hm) === 1, 'blok funkcji pomocniczych znaleziony');
    $i = $work . '/inst';
    mkdir($i, 0777, true);
    file_put_contents($i . '/helpers.sh', $hm[1] . "\n");
    // Wartości testowe przekazujemy ZMIENNĄ ŚRODOWISKOWĄ (V), nie przez cytowanie w wierszu poleceń — escapeshellarg
    // na Windows zmienia znaki ' " %, a o to właśnie chodzi w tych testach.
    $call = static function (string $body, array $extraEnv = []) use ($bash, $i): array {
        $env = array_merge(getenv(), $extraEnv);
        return runProc([$bash, '-c', 'set -uo pipefail; . ' . toPosix($i . '/helpers.sh') . "\n" . $body], '', $env);
    };

    foreach (['0123456789abcdef0123456789abcdef', 'aB3._~+-aB3._~+-aB3', str_repeat('a', 128)] as $good) {
        [$rc, ] = $call('valid_secret "$V"', ['V' => $good]);
        eq($rc, 0, "valid_secret przyjmuje [$good]");
    }
    foreach (["x';\\! id #xxxxxxxxxxxxxxxx", 'short', str_repeat('a', 129), 'abc def abc def abc def', 'a/b/c/d/e/f/g/h/i/j/k', 'a&b&c&d&e&f&g&h&i&j&k', 'a\\b\\c\\d\\e\\f\\g\\h\\i', '$(id)$(id)$(id)$(id)', "abc\ndef012345678901234", '', 'a"b"c"d"e"f"g"h"i"j"'] as $bad) {
        [$rc, ] = $call('valid_secret "$V"', ['V' => $bad]);
        ok($rc !== 0, 'valid_secret odrzuca [' . str_replace("\n", '\\n', $bad) . ']');
    }

    // file_kv: dane, nie kod
    file_put_contents($i . '/a.cf', "# comment password = no\nuser = mailuser\r\npassword = \"s3cretvalue0123456789\"\r\nhosts = 127.0.0.1\n");
    [$rc, $out] = $call('file_kv ' . escapeshellarg(toPosix($i . '/a.cf')) . ' password');
    eq([$rc, $out], [0, 's3cretvalue0123456789'], 'file_kv: wartość w cudzysłowie, CRLF, komentarz pominięty');
    [$rc, $out] = $call('file_kv ' . escapeshellarg(toPosix($i . '/a.cf')) . ' user');
    eq([$rc, $out], [0, 'mailuser'], 'file_kv: wartość bez cudzysłowu, CRLF zdjęty');
    [$rc, ] = $call('file_kv ' . escapeshellarg(toPosix($i . '/a.cf')) . ' nokey');
    ok($rc !== 0, 'file_kv: brak klucza -> błąd');
    file_put_contents($i . '/evil.env', "PANEL_DB_PASS=x';\\! touch " . toPosix($i) . "/PWNED #\nPANEL_DB_PASS2=\$(touch " . toPosix($i) . "/PWNED2)\n");
    [$rc, $out] = $call('v=$(file_kv ' . escapeshellarg(toPosix($i . '/evil.env')) . ' PANEL_DB_PASS2); valid_secret "$v" && echo accepted || echo rejected; printf "%s" "$v"');
    ok(str_starts_with($out, 'rejected') && !is_file($i . '/PWNED') && !is_file($i . '/PWNED2'), 'file_kv: wrogi .env jest tylko tekstem (nic nie wykonane), valid_secret go odrzuca');
    $lnk = @symlink($i . '/a.cf', $i . '/link.cf');
    if ($lnk) {
        [$rc, ] = $call('file_kv ' . escapeshellarg(toPosix($i . '/link.cf')) . ' password');
        ok($rc !== 0, 'file_kv: dowiązanie symboliczne odrzucone');
    } else {
        skip('symlink() unavailable — the file_kv refusal of a symbolic link not tested', 1);
    }

    // render_template: wartości dosłowne
    file_put_contents($i . '/t.tpl', "password = \"HASLO_X\"\nagain=HASLO_X HASLO_X\n");
    foreach (['plain0123456789abc', 'a&b&c\\1\\&/x/y', "q'q\"q\$HOME\$(id)`id`", 'x/y\\z&&&'] as $val) {
        [$rc, $out] = $call('render_template ' . escapeshellarg(toPosix($i . '/t.tpl')) . ' HASLO_X "$V"', ['V' => $val]);
        eq($out, "password = \"$val\"\nagain=$val $val", "render_template wstawia dosłownie [$val]");
    }
    blockSize($start, $size, 'install.sh: file_kv / render_template / valid_secret');
}

/* ====================================================================== install.sh: osobna pula PHP-FPM (sekcja 1b) */
group('install.sh: pula PHP-FPM — log_dir, logrotate, vhosty Apache, szablon puli');

$size = 21;
if ($bash === null) {
    skip('brak bash', $size);
} else {
    $start = seen();
    $inst = str_replace("\r\n", "\n", (string) file_get_contents($root . '/install.sh'));
    ok(preg_match('/# --- helpers begin.*?\n(.*?)\n# --- helpers end/s', $inst, $hm1) === 1
        && preg_match('/# --- pool helpers begin.*?\n(.*?)\n# --- pool helpers end/s', $inst, $hm2) === 1, 'bloki funkcji install.sh znalezione');
    $pw = $work . '/pool';
    foreach (['sites', 'avail', 'logrotate.d', 'backup', 'rc/config', 'rc/temp', 'rc/logs', 'log/roundcube'] as $sub) { mkdir($pw . '/' . $sub, 0777, true); }
    file_put_contents($pw . '/helpers.sh', $hm1[1] . "\n" . $hm2[1] . "\n");
    $TP = toPosix($pw);
    $prelude = 'set -uo pipefail; ok() { echo "OK: $*"; }; warn() { echo "WARN: $*"; }; chown() { :; }; chmod() { :; }' . "\n"
        . 'apache2ctl() { [ -e "$T/apache_bad" ] && return 1; return 0; }; systemctl() { echo "systemctl $*" >> "$T/calls"; return 0; }' . "\n"
        . 'logrotate() { [ -e "$T/logrotate_bad" ] && return 1; return 0; }' . "\n"
        . '. "$T/helpers.sh"' . "\n"
        . 'APACHE_SITES="$T/sites"; LOGROTATE_D="$T/logrotate.d"; RC_BACKUP_ROOT="$T/backup"; RC_DIR="$T/rc"; SRC="$R"' . "\n"
        . 'PANEL_USER=mailweb; APACHE_USER=www-data; PHP_VER=8.5' . "\n";
    $pool = static function (string $body) use ($bash, $TP, $root, $prelude): array {
        return runProc([$bash, '-c', $prelude . $body], '', array_merge(getenv(), ['T' => $TP, 'R' => toPosix($root)]));
    };

    // log_dir z config.inc.php (dane, nie kod)
    $cfgFile = $pw . '/rc/config/config.inc.php';
    file_put_contents($cfgFile, "<?php\n\$config['log_dir'] = 'logs/';\n\$config['log_dir'] = '$TP/log/roundcube/';\n");
    [, $out] = $pool('rc_log_dir');
    eq($out, "$TP/log/roundcube", 'rc_log_dir: ostatnie przypisanie, ścieżka bezwzględna poza drzewem, bez końcowego /');
    file_put_contents($cfgFile, "<?php\n\$config[\"log_dir\"] = \"/var/log/\";\n");
    [, $out] = $pool('v=$(rc_log_dir 2>/dev/null); printf "[%s]" "$v"');
    eq($out, '[]', 'rc_log_dir: /var/log (cudzy katalog) NIE jest zwracany — nie oddamy /var/log użytkownikowi PHP');
    file_put_contents($cfgFile, "<?php\n\$config['log_dir'] = '$TP/rc/logs';\n");
    [, $out] = $pool('v=$(rc_log_dir 2>/dev/null); printf "[%s]" "$v"');
    eq($out, '[]', 'rc_log_dir: katalog w drzewie Roundcube obsługuje runtime_ownership (logs/)');
    file_put_contents($cfgFile, "<?php\n\$config['log_dir'] = '/var/log/roundcube/../../etc';\n");
    [, $out] = $pool('v=$(rc_log_dir 2>/dev/null); printf "[%s]" "$v"');
    eq($out, '[]', 'rc_log_dir: ścieżka z .. odrzucona');

    // logrotate: create … www-data www-data -> mailweb
    $lr = "$TP/log/roundcube/userlogins.log {\n  weekly\n  create 0640 www-data www-data\n  postrotate\n    true\n  endscript\n}\n";
    file_put_contents($pw . '/logrotate.d/roundcube-userlogins', $lr);
    file_put_contents($pw . '/logrotate.d/other', "/var/log/other.log {\n  create 0640 www-data adm\n}\n");
    [$rc, $out] = $pool('logrotate_user "$T/log/roundcube" mailweb');
    ok(str_contains((string) file_get_contents($pw . '/logrotate.d/roundcube-userlogins'), "  create 0640 mailweb mailweb\n"), 'logrotate: logi Roundcube odtwarzane dla mailweb');
    ok(str_contains((string) file_get_contents($pw . '/logrotate.d/other'), 'create 0640 www-data adm'), 'logrotate: plik innego logu nietknięty');
    ok(count(glob($pw . '/backup/roundcontrol-logrotate-backup-*/roundcube-userlogins') ?: []) === 1, 'logrotate: kopia oryginału');
    file_put_contents($pw . '/logrotate.d/roundcube-userlogins', $lr);
    touch($pw . '/logrotate_bad');
    [, $out] = $pool('logrotate_user "$T/log/roundcube" mailweb');
    eq((string) file_get_contents($pw . '/logrotate.d/roundcube-userlogins'), $lr, 'logrotate -d odrzuca zmianę: plik przywrócony');
    unlink($pw . '/logrotate_bad');

    // vhosty: blok tylko w <VirtualHost> z DocumentRoot Roundcube (także w <IfModule>), idempotentnie, z przywróceniem
    $vh443 = "<IfModule mod_ssl.c>\n<VirtualHost *:443>\n    ServerName mail.example.org\n    DocumentRoot $TP/rc/public_html\n    RewriteEngine on\n</VirtualHost>\n</IfModule>\n";
    $vh80  = "<VirtualHost *:80>\n    ServerName mail.example.org\n    DocumentRoot \"$TP/rc/public_html/\"\n</VirtualHost>\n";
    $other = "<VirtualHost *:443>\n    ServerName forum.example.org\n    DocumentRoot /var/www/forum\n</VirtualHost>\n";
    file_put_contents($pw . '/avail/mail-ssl.conf', $vh443);
    file_put_contents($pw . '/sites/mail.conf', $vh80);
    file_put_contents($pw . '/sites/forum.conf', $other);
    $linkOk = @symlink($pw . '/avail/mail-ssl.conf', $pw . '/sites/mail-ssl.conf');
    if (!$linkOk) { copy($pw . '/avail/mail-ssl.conf', $pw . '/sites/mail-ssl.conf'); }
    $sslFile = $linkOk ? $pw . '/avail/mail-ssl.conf' : $pw . '/sites/mail-ssl.conf';
    $sock = '/run/php/php8.5-fpm-mailweb.sock';
    [$rc, $out] = $pool('switch_vhosts ' . $sock);
    $s443 = (string) file_get_contents($sslFile);
    $s80  = (string) file_get_contents($pw . '/sites/mail.conf');
    ok($rc === 0 && str_contains($s443, 'SetHandler "proxy:unix:' . $sock . '|fcgi://localhost"'), 'vhost 443: handler puli wstawiony' . ($linkOk ? ' (do celu dowiązania)' : ''));
    ok((bool) preg_match('~SetHandler[^\n]*\n\s*</FilesMatch>\n\s*</Directory>\n</VirtualHost>\n</IfModule>~', $s443), 'vhost 443: blok tuż przed </VirtualHost>');
    // zagnieżdżony w <Directory RC_DIR>: aliasy globalne (np. /phpmyadmin) na tym hoście zostają w puli [www]
    ok(str_contains($s443, '<Directory "' . $TP . '/rc">' . "\n" . '        <FilesMatch "\.ph(?:ar|p|tml)$">'), 'vhost 443: handler tylko dla plików pod RC_DIR (<Directory> w vhoście)');
    ok(str_contains($s80, $sock) && str_contains($s80, '<FilesMatch "\.ph(?:ar|p|tml)$">'), 'vhost 80: handler wstawiony (ten sam wzorzec co conf-enabled/php*-fpm.conf)');
    ok(!preg_match('/@[A-Z_]+@/', $s443 . $s80), 'vhost: brak niewypełnionych znaczników');
    eq((string) file_get_contents($pw . '/sites/forum.conf'), $other, 'vhost innej strony nietknięty (zostaje w puli [www])');
    [$rc, $out] = $pool('switch_vhosts ' . $sock);
    ok($rc === 0 && (string) file_get_contents($sslFile) === $s443 && substr_count((string) file_get_contents($pw . '/sites/mail.conf'), $sock) === 1, 'drugie uruchomienie: bez zmian (idempotentne)');
    file_put_contents($sslFile, $vh443);
    file_put_contents($pw . '/sites/mail.conf', $vh80);
    touch($pw . '/apache_bad');
    [$rc, $out] = $pool('switch_vhosts ' . $sock);
    ok($rc !== 0 && (string) file_get_contents($sslFile) === $vh443 && (string) file_get_contents($pw . '/sites/mail.conf') === $vh80, 'configtest nie przechodzi: vhosty przywrócone, kod != 0');
    unlink($pw . '/apache_bad');

    // szablon puli
    [$rc, $out] = $pool('render_template "$R/system/php-fpm/roundcontrol-pool.conf" @POOL@ mailweb | render_template - @PANEL_USER@ mailweb'
        . ' | render_template - @APACHE_USER@ www-data | render_template - @SOCKET@ ' . $sock . ' | render_template - @SESSION_DIR@ /var/lib/php/sessions-mailweb');
    ok(!str_contains($out, '@') && str_contains($out, "[mailweb]\nuser = mailweb\ngroup = mailweb"), 'szablon puli: wszystkie znaczniki wypełnione, [mailweb] user/group');
    ok(str_contains($out, "listen = $sock\nlisten.owner = www-data\nlisten.group = www-data\nlisten.mode = 0660"), 'szablon puli: gniazdo tylko dla Apache (0660 www-data)');
    ok(str_contains($out, 'php_admin_value[session.save_path] = /var/lib/php/sessions-mailweb') && str_contains($out, 'php_value[session.gc_probability] = 1'), 'szablon puli: własny katalog sesji + własne GC (phpsessionclean go nie czyści)');
    ok((bool) preg_match('/^pm = ondemand$/m', $out) && (bool) preg_match('/^pm\.max_children = \d+$/m', $out), 'szablon puli: pm ondemand z limitem procesów');
    blockSize($start, $size, 'install.sh: pool helpers');
}

/* ====================================================================== kontrole statyczne */
group('kontrole statyczne');

$phpFiles = [];
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
foreach ($it as $f) {
    $path = str_replace('\\', '/', (string) $f);
    if (str_ends_with($path, '.php') && !str_contains($path, '/.git/') && !str_contains($path, '/vendor/')) { $phpFiles[] = $path; }
}
$bad = [];
foreach ($phpFiles as $f) {
    [$rc, $out] = runProc(['php', '-l', $f]);
    if ($rc !== 0) { $bad[] = $f . ': ' . $out; }
}
ok($bad === [], 'php -l: ' . count($phpFiles) . ' plików' . ($bad ? ' — ' . implode('; ', $bad) : ''));

if ($bash !== null) {
    $shFiles = array_merge(glob($root . '/scripts/*.sh') ?: [], [$root . '/install.sh', $root . '/scripts/mailpanel-purge-deleted']);
    $badSh = [];
    foreach ($shFiles as $f) {
        [$rc, $out] = runProc([$bash, '-n', toPosix($f)]);
        if ($rc !== 0) { $badSh[] = basename($f) . ': ' . $out; }
    }
    ok($badSh === [], 'bash -n: ' . count($shFiles) . ' skryptów' . ($badSh ? ' — ' . implode('; ', $badSh) : ''));
} else {
    skip('no bash — bash -n of the shell scripts not run', 1);
}

$appCode = '';   // kod aplikacji bez komentarzy (komentarze wolno wspominać o dawnych rozwiązaniach)
foreach ($phpFiles as $f) {
    if (str_contains($f, '/app/') || str_contains($f, '/panel/') || str_ends_with($f, '/portal.php')) {
        foreach (token_get_all((string) file_get_contents($f)) as $tok) {
            if (is_array($tok) && in_array($tok[0], [T_COMMENT, T_DOC_COMMENT], true)) { continue; }
            $appCode .= is_array($tok) ? $tok[1] : $tok;
        }
        $appCode .= "\n";
    }
}
ok(!preg_match('/\b(u?sleep)\s*\(/', $appCode), 'kod aplikacji: brak sleep()/usleep() (nie wiesza wątków FPM)');
ok(!str_contains($appCode, 'secureSleep'), 'kod aplikacji: brak secureSleep');
ok(!preg_match('/DELETE FROM admin_login_attempts/', $appCode), 'kod aplikacji: nie kasuje wierszy admin_login_attempts');
foreach (['scripts/mailpanel-f2b-apply.php', 'scripts/mailpanel-policy-daily.php'] as $g) {
    ok(str_contains((string) file_get_contents($root . '/' . $g), "PHP_SAPI !== 'cli'"), "$g: strażnik CLI");
}
$instLines = array_filter(explode("\n", str_replace("\r\n", "\n", (string) file_get_contents($root . '/install.sh'))), static fn($l) => !preg_match('/^\s*#/', $l));
$instCode = implode("\n", $instLines);
ok(!preg_match('/^\s*(\.|source)\s+["$]?[^\s]*\.env/m', $instCode), 'install.sh: nie wykonuje (source) żadnego .env');
ok(!preg_match('/(grep|sed|awk|cat|file_kv)[^\n]*\.env\b/', $instCode), 'install.sh: nie czyta pliku .env aplikacji');
ok(!preg_match('/sed\s+["\'][^\n]*\$\{?(PW|MU_PW|MP_PW|PANEL_PW|PLPW)/', $instCode), 'install.sh: żadnego sed z sekretem w wyrażeniu');
ok(!preg_match('/ALTER USER[^\n]*mailuser/', $instCode), 'install.sh: nie robi ALTER USER dla mailuser (sekcja 0 idempotentna)');
ok(str_contains($instCode, 'ReadWritePaths=-/etc/fail2ban/jail.local -/etc/postfix'), 'install.sh: drop-in php-fpm z ReadWritePaths (jail.local + /etc/postfix)');
ok(!str_contains($instCode, 'chown -R "$WEB_USER":"$WEB_USER" "$RC_DIR/app"'), 'install.sh: kod nie jest oddawany www-data');
ok(!preg_match('#install[^\n]*/etc/cron\.daily#', $instCode) && str_contains($instCode, 'enable --now mailpanel-purge-deleted.timer'),
    'install.sh: czyszczenie kwarantanny przez timer systemd, nic do /etc/cron.daily (serwer bez crona)');
ok(!str_contains((string) file_get_contents($root . '/scripts/mailpanel-purge-deleted'), '-mtime'), 'purge: wiek z nazwy, nie z mtime (mv zachowuje mtime skrzynki)');
ok(str_contains($instCode, 'render_template "$SRC/system/sudoers.d/mailpanel" "User_Alias ROUNDCONTROL_USER = mailweb" "User_Alias ROUNDCONTROL_USER = $RUN_USER"'),
    'install.sh: reguły sudo dla użytkownika, który NAPRAWDĘ wykonuje PHP Roundcube (RUN_USER: pula mailweb albo www-data)');
ok(str_contains($instCode, '"php-fpm$PHP_VER" -t') && str_contains($instCode, 'apache2ctl configtest') && str_contains($instCode, 'runtime_ownership "$APACHE_USER"'),
    'install.sh: pula sprawdzana php-fpm -t, vhosty apache2ctl configtest, przy błędzie własność wraca do Apache');
ok(!preg_match('/\$WEB_USER\b/', $instCode) && !preg_match('/\$\{WEB_USER\}/', $instCode), 'install.sh: brak gołego $WEB_USER (set -u; nazwa z 1.0.x tylko jako ${WEB_USER:-…})');
ok(str_contains($instCode, 'install_sudoers_file') && preg_match('/mv -f "\$stage" "\$dst"[^\n]*\n\s*if visudo -c /', $instCode) === 1,
    'install.sh: po wstawieniu pliku sudoers sprawdzana jest CAŁA konfiguracja (visudo -c), z przywróceniem');
$htaccess = (string) file_get_contents($root . '/.htaccess');
foreach (['scripts', 'sql_backup', '_legacy', 'tests', 'install\\.(php|sh)', '\\.env'] as $needle) {
    ok(str_contains($htaccess, $needle), ".htaccess blokuje $needle");
}

/* ====================================================================== install.sh: sections 1b, 3, 4, 6, 7, 9, 11b */
// The functions run as install.sh runs them (the three marked blocks, sourced), with the system commands as shell
// functions: fail2ban-client, doveconf, systemctl, apache2ctl, sudo, id, python3, timedatectl; install copies (owner and
// mode need root). The behaviour of each fake is switched by a flag file in the scratch directory.
group('install.sh section 9: custom_auth and password in $config[\'plugins\'] — added once, either quote style');

$size = 52;
if ($bash === null) {
    skip('no bash — the install.sh section tests not run', $size);
} else {
    $start = seen();
    $inst = str_replace("\r\n", "\n", (string) file_get_contents($root . '/install.sh'));
    $blocks = [];
    foreach (['helpers', 'pool helpers', 'section helpers'] as $b) {
        $found = preg_match('/# --- ' . $b . ' begin[^\n]*\n(.*?)\n# --- ' . $b . ' end/s', $inst, $bm) === 1;
        ok($found, "install.sh: the \"$b\" block is there");
        $blocks[] = $found ? $bm[1] : '';
    }
    $sx = $work . '/sections';
    foreach (['rc/config', 'rc/logs', 'rc/plugins/custom_auth', 'conf.d', 'zoneinfo/Europe', 'zoneinfo/Asia'] as $sub) { mkdir($sx . '/' . $sub, 0777, true); }
    foreach (['Europe/Warsaw', 'Asia/Tokyo'] as $z) { touch($sx . '/zoneinfo/' . $z); }
    file_put_contents($sx . '/helpers.sh', implode("\n", $blocks) . "\n");
    $SX = toPosix($sx);
    $fakes = <<<'SH'
set -uo pipefail
ok() { echo "OK: $*"; }; warn() { echo "WARN: $*"; }; chown() { :; }; chmod() { :; }
install() { local a=(); while [ $# -gt 0 ]; do case "$1" in -o|-g|-m) shift 2 ;; -d) shift ;; *) a+=("$1"); shift ;; esac; done; cp -- "${a[0]}" "${a[1]}"; }
fail2ban-client() {
  echo "fail2ban-client $*" >> "$T/calls"
  [ "${1:-}" = "-t" ] || return 0
  [ -e "$T/f2b_bad" ] && { echo "ERROR  broken already"; return 1; }
  [ -e "$T/f2b_rejects_new" ] && [ -e "$JAIL_LOCAL" ] && { echo "ERROR  Have not found any log file for roundcube-auth jail"; return 1; }
  echo "OK: configuration test is successful"
}
python3() { [ ! -e "$T/no_pysystemd" ]; }
doveconf() {
  [ -e "$T/dove_bad" ] && { echo "doveconf: Fatal: Error in configuration file /etc/dovecot/dovecot.conf line 1"; return 1; }
  echo "protocols = imap pop3"
  [ -e "$T/dove_manual" ] && echo "  executable = script-login $POSTLOGIN_SCRIPT"
  [ -e "$T/dove_noinclude" ] && return 0
  local f
  for f in "$T"/conf.d/*.conf; do
    [ -f "$f" ] || continue
    [ -e "$T/dove_rejects_new" ] && { echo "doveconf: Fatal: Error in configuration file $f line 12"; return 1; }
    sed -e '/^[[:space:]]*#/d' -e "s#/usr/local/bin/dovecot-postlogin.sh#$POSTLOGIN_SCRIPT#" "$f"
  done
}
systemctl() { echo "systemctl $*" >> "$T/calls"; [ ! -e "$T/reload_bad" ]; }
sudo() { [ "${1:-}" = "-u" ] && shift 2; "$@"; }
id() { [ "${1:-}" = "-u" ] && [ "${2:-}" = "vmail" ] && [ ! -e "$T/no_vmail" ] && echo 5000; }
apache2ctl() {
  [ "${1:-}" = "-M" ] || return 0
  [ -e "$T/apache_M_bad" ] && return 1
  echo "Loaded Modules:"; echo " core_module (static)"
  [ -e "$T/no_proxy" ] || echo " proxy_module (shared)"
  [ -e "$T/no_proxy_fcgi" ] || echo " proxy_fcgi_module (shared)"
}
timedatectl() { [ -e "$T/timedatectl_out" ] && cat "$T/timedatectl_out"; return 0; }
useradd() { echo "useradd $*" >> "$T/calls"; }
. "$T/helpers.sh"
SRC="$R"; RC_DIR="$T/rc"; RUN_USER=mailweb; PANEL_USER=mailweb; APACHE_USER=www-data; PHP_VER=8.5
JAIL_LOCAL="$T/jail.local"; MAIL_LOG="$T/mail.log"; LOCALTIME_LINK="$T/localtime"; TIMEZONE_FILE="$T/timezone"
ZONEINFO_DIR="$T/zoneinfo"; DOVECOT_CONF_D="$T/conf.d"; POSTLOGIN_CONF="$T/conf.d/99-roundcontrol-postlogin.conf"
POSTLOGIN_SCRIPT="$T/dovecot-postlogin.sh"; POSTLOGIN_CNF="$T/mysql-mailpanel.cnf"

SH;
    $sh = static function (string $body) use ($bash, $SX, $root, $fakes): array {
        return runProc([$bash, '-c', $fakes . $body], '', array_merge(getenv(), ['T' => $SX, 'R' => toPosix($root)]));
    };
    $flags = static function (array $on) use ($sx): void {
        foreach (['f2b_bad', 'f2b_rejects_new', 'no_pysystemd', 'dove_bad', 'dove_manual', 'dove_noinclude', 'dove_rejects_new', 'reload_bad',
                  'no_vmail', 'apache_M_bad', 'no_proxy', 'no_proxy_fcgi', 'timedatectl_out', 'calls'] as $f) { @unlink($sx . '/' . $f); }
        foreach ($on as $f) { touch($sx . '/' . $f); }
    };
    $calls = static fn(): string => (string) @file_get_contents($sx . '/calls');

    // section 9
    $cfg = $sx . '/rc/config/config.inc.php';
    $plugins = static function (?string $php) use ($cfg, $sh): array {     // null = run again on the file as it is
        if ($php !== null) { file_put_contents($cfg, $php); }
        [$rc, $out] = $sh('rc_add_plugins "$RC_DIR/config/config.inc.php" custom_auth password');
        return [$rc, $out, (string) file_get_contents($cfg)];
    };
    [$rc, $out, $txt] = $plugins("<?php\n\$config['plugins'] = ['archive', 'zipdownload'];\n");
    ok($rc === 0 && $out === 'custom_auth password' && str_contains($txt, "\$config['plugins'] = ['custom_auth', 'password', 'archive', 'zipdownload'];"), 'stock list: both plugins added in front (' . $out . ')');
    [$rc, , $txt2] = $plugins(null);
    ok($rc === 2 && $txt2 === $txt, 'second run: exit 2, file unchanged (idempotent)');
    [$rc, $out, $txt] = $plugins("<?php\n// \$config['plugins'] = ['custom_auth'];\n\$config[\"plugins\"] = array(\"password\", \"archive\");\n");
    ok($rc === 0 && $out === 'custom_auth' && str_contains($txt, "\$config[\"plugins\"] = array('custom_auth', \"password\", \"archive\");"), 'double quotes and array(): only the missing one; a commented-out list does not count');
    [$rc, , $txt] = $plugins("<?php\n\$config['plugins'] = [\n    'archive',   // (archive folder)\n    'zipdownload',\n];\n");
    [$lrc, ] = runProc(['php', '-l', $cfg]);
    ok($rc === 0 && $lrc === 0 && substr_count($txt, "'custom_auth'") === 1, 'a list over several lines with a comment: added, the file still passes php -l');
    $noList = "<?php\n\$config['des_key'] = 'x';\n";
    [$rc, , $txt] = $plugins($noList);
    ok($rc === 1 && $txt === $noList, 'no plugins list: exit 1, file unchanged');
    ok(str_contains($inst, 'PLUGINS=(custom_auth password)') && str_contains($inst, 'rc_add_plugins "$CFG" "${PLUGINS[@]}"'), 'section 9 asks rc_add_plugins for custom_auth and password');

    group('install.sh section 3: jail.local from system/fail2ban/jail.local.example — never over an existing one');
    $tpl = (string) file_get_contents($root . '/system/fail2ban/jail.local.example');
    file_put_contents($cfg, "<?php\n\$config['plugins'] = [];\n");
    $flags([]);
    [$rc, $out] = $sh('create_jail_local');
    $jl = (string) @file_get_contents($sx . '/jail.local');
    ok($rc === 0 && $jl !== '' && !preg_match('/@[A-Z_]+@/', $jl), 'created, every placeholder filled (' . substr($out, -90) . ')');
    ok(str_contains($jl, "[roundcube-auth]\nenabled = true\nbackend = polling\nlogpath = $SX/rc/logs/errors.log\n"), 'roundcube-auth: polling on Roundcube\'s errors.log (logs/ by default)');
    ok(is_file($sx . '/rc/logs/errors.log'), 'the log it watches exists (fail2ban-client -t refuses a jail without its log)');
    ok(str_contains($jl, "[dovecot]\nenabled = true\nbackend = systemd\n") && str_contains($jl, "[postfix-sasl]\nenabled = true\nbackend = systemd\n"), 'dovecot and postfix-sasl: the journal (Debian 13 has no mail.log)');
    eq(substr_count($calls(), 'fail2ban-client -t'), 2, 'fail2ban-client -t before and after');
    file_put_contents($sx . '/jail.local', "[DEFAULT]\nbantime = 5m\n");
    [$rc, $out] = $sh('create_jail_local');
    ok($rc === 0 && (string) file_get_contents($sx . '/jail.local') === "[DEFAULT]\nbantime = 5m\n", 'an existing jail.local is left as it is');
    unlink($sx . '/jail.local');
    $flags(['f2b_bad']);
    [$rc, ] = $sh('create_jail_local');
    ok($rc === 1 && !is_file($sx . '/jail.local'), 'Fail2Ban configuration broken before: nothing created');
    $flags(['f2b_rejects_new']);
    [$rc, $out] = $sh('create_jail_local');
    ok($rc === 1 && !is_file($sx . '/jail.local') && str_contains($out, 'removed again'), 'fail2ban-client -t rejects the new file: removed again');
    file_put_contents($cfg, "<?php\n\$config['log_driver'] = 'syslog';\n\$config['log_dir'] = '/var/log/roundcube/';\n");
    $flags(['no_pysystemd']);
    [$rc, $out] = $sh('create_jail_local');
    $jl2 = (string) @file_get_contents($sx . '/jail.local');
    ok($rc === 0 && str_contains($jl2, "backend = systemd\nlogpath = /var/log/roundcube/errors.log\n") && str_contains($out, 'apt install python3-systemd'),
        "log_driver 'syslog': roundcube-auth on the journal; log_dir from config.inc.php; missing python3-systemd is named");
    unlink($sx . '/jail.local');
    touch($sx . '/mail.log');
    [$rc, ] = $sh('create_jail_local');
    ok($rc === 0 && str_contains((string) @file_get_contents($sx . '/jail.local'), "[dovecot]\nenabled = true\nbackend = auto\n"), 'without python3-systemd but with mail.log: the mail jails read the file (auto)');
    unlink($sx . '/jail.local'); unlink($sx . '/mail.log');
    $flags([]);
    file_put_contents($cfg, "<?php\n");
    $sh('create_jail_local');

    group('install.sh section 3: the template is exactly what the Fail2Ban tab and its root helper work with');
    // the panel side: the real Commands::fail2banLoadConfig() and PanelController::f2bFields() on the created file
    file_put_contents($work . '/f2b.env', "APP_ENV=prod\nF2B_JAIL_LOCAL=" . $sx . "/jail.local\n");
    putenv('MAILPANEL_ENV_FILE=' . $work . '/f2b.env');
    require_once $root . '/app/bootstrap.php';
    $loaded = App\System\Commands::fail2banLoadConfig();
    $fields = App\Panel\PanelController::f2bFields();
    $missing = [];
    foreach ($fields as $name => [$sec, $key]) {
        if (!isset($loaded['values'][$sec][$key])) { $missing[] = "[$sec] $key"; }
    }
    ok($loaded['available'] === true && $missing === [], 'every field of the tab (' . count($fields) . ') has its line in the file — the tab only rewrites lines that exist' . ($missing ? ': missing ' . implode(', ', $missing) : ''));
    $san = new ReflectionMethod(App\System\Commands::class, 'sanitize');
    $types = ['findtime' => 'time', 'bantime' => 'bantime', 'maxretry' => 'retry', 'bantime.factor' => 'float', 'bantime.maxtime' => 'time', 'ignoreip' => 'ip_list'];
    $drift = [];
    foreach ($fields as $name => [$sec, $key, , $kind]) {
        $v = (string) ($loaded['values'][$sec][$key] ?? '');
        $back = $kind === 'check' ? ($v === 'true' ? 'true' : 'false') : (string) $san->invoke(null, $v, $types[$key] ?? 'text');
        if ($back !== $v) { $drift[] = "[$sec] $key = $v -> $back"; }
    }
    ok($drift === [], 'saving the tab as rendered changes nothing (every value comes back from the sanitizer as it is)' . ($drift ? ': ' . implode('; ', $drift) : ''));
    // the root side: the real helper, against the created file — every editable value changed to another valid one
    $hDir = $work . '/f2bt';
    @mkdir($hDir, 0777, true);
    $hLive = $hDir . '/jail.local';
    file_put_contents($hDir . '/prepend.php', '<?php define("LIVE", ' . var_export($hLive, true) . '); define("BAK", ' . var_export($hDir . '/bak', true)
        . '); define("F2B", "php " . ' . var_export('"' . $fake . '"', true) . '); define("KEEP_BACKUPS", 3);');
    $other = ['findtime' => '15m', 'bantime' => '2h', 'maxretry' => '7', 'bantime.factor' => '2', 'bantime.maxtime' => '2w', 'ignoreip' => '127.0.0.1/8 ::1 203.0.113.9',
              'bantime.increment' => 'false', 'enabled' => 'false'];
    $editable = array_unique(array_map(static fn(array $f): string => $f[0] . '|' . $f[1], array_values($fields)));
    $shifted = []; $sec = null;
    foreach (explode("\n", $jl) as $line) {
        if (preg_match('/^\[([^\]]+)\]/', $line, $mm)) { $sec = $mm[1]; }
        if (preg_match('/^([A-Za-z0-9_.]+) = /', $line, $mm) && in_array($sec . '|' . $mm[1], $editable, true)) {
            $line = $mm[1] . ' = ' . ($sec === 'recidive' && $mm[1] === 'findtime' ? '2d' : $other[$mm[1]]);
        }
        $shifted[] = $line;
    }
    $shifted = implode("\n", $shifted);
    $runHelper = static function (string $liveContent, string $proposal) use ($root, $hDir, $hLive): array {
        file_put_contents($hLive, $liveContent);
        foreach (glob($hDir . '/bak/*') ?: [] as $b) { @unlink($b); }
        return runProc(['php', '-d', 'auto_prepend_file=' . $hDir . '/prepend.php', $root . '/scripts/mailpanel-f2b-apply.php'], $proposal);
    };
    [$rc, $out] = $runHelper($jl, $shifted);
    ok($rc === 0 && str_contains($out, 'saved and reloaded (' . count($editable) . ' changes)'), 'helper: all ' . count($editable) . ' editable lines of the template are the options it lets the panel change (' . $out . ')');
    [$rc, $out] = $runHelper($shifted, $jl);
    ok($rc === 0 && str_starts_with($out, 'saved and reloaded'), 'helper: the template\'s own values are in the ranges it accepts (' . $out . ')');
    ok(!preg_match('/^enabled\s*=/m', (string) preg_replace('/\n\[(?!DEFAULT\]).*$/s', '', $tpl)) && str_contains($tpl, "[recidive]\nenabled = true\nbackend = polling\nlogpath = /var/log/fail2ban.log\n"),
        'no "enabled" under [DEFAULT] (the helper refuses it there); recidive reads fail2ban.log');

    group('install.sh section 3 (1.4.0): [panel-tempban] bantime = 90d, written in place, then a restart of that jail');
    $tbf = $sx . '/tempban.local';
    $legacy = "[DEFAULT]\nbantime = 10m\n\n[panel-permaban]\nbantime = -1\n\n[panel-tempban]\nenabled = true\nbantime = 24h\nbantime.increment = false\n\n[recidive]\nbantime = 1w\n";
    file_put_contents($tbf, $legacy);
    [$rc, $out] = $sh('tempban_bantime "$T/tempban.local"');
    ok($rc === 0 && $out === '24h', 'tempban_bantime reads [panel-tempban] only (' . $out . ')');
    $hl = $tbf . '.hardlink';
    $linked = @link($tbf, $hl);
    [$rc, ] = $sh('tempban_set_90d "$T/tempban.local"');
    $want = str_replace("enabled = true\nbantime = 24h\n", "enabled = true\nbantime = 90d\n", $legacy);
    ok($rc === 0 && (string) file_get_contents($tbf) === $want, 'only that line changes (bantime.increment, [DEFAULT], permaban and recidive untouched)');
    if ($linked) {
        ok((string) file_get_contents($hl) === $want, 'written in place: a hard link to the file sees the new content (php-fpm holds jail.local as a bind mount)');
    } else {
        skip('link() not available here', 1);
    }
    ok(count(glob($tbf . '.bak-*') ?: []) === 1 && (string) file_get_contents((glob($tbf . '.bak-*') ?: [''])[0]) === $legacy, 'a copy .bak-<time> of the previous file first');
    file_put_contents($tbf, "[panel-tempban]\nenabled = true\n\n[recidive]\nenabled = true\n");
    [$rc, ] = $sh('tempban_set_90d "$T/tempban.local"');
    eq((string) file_get_contents($tbf), "[panel-tempban]\nbantime = 90d\nenabled = true\n\n[recidive]\nenabled = true\n", 'no bantime line: added right under the section header');
    file_put_contents($tbf, "[recidive]\nbantime = 1w\n");
    [$rc, ] = $sh('tempban_set_90d "$T/tempban.local"');
    ok($rc === 1 && (string) file_get_contents($tbf) === "[recidive]\nbantime = 1w\n", 'no [panel-tempban]: exit 1, file unchanged');
    foreach (array_merge(glob($tbf . '*') ?: []) as $f) { @unlink($f); }
    ok(str_contains($inst, 'tempban_set_90d "$TEMPBAN_FILE"') && str_contains($inst, 'fail2ban-client reload --restart panel-tempban'),
        'section 3 sets 90d in a legacy jail.local and restarts panel-tempban when its bantime changed');
    ok(str_contains($inst, 'systemctl enable --now mailpanel-tempban-expire.timer') && str_contains($inst, '"s#^User=mailweb\$#User=${RUN_USER}#"'),
        'section 11b installs the timer with the pool user and the Roundcube path written in');

    group('install.sh section 4: the policy file gets the server\'s time zone');
    $tz = static function (array $files) use ($sh, $sx): string {
        foreach (['timezone', 'timedatectl_out'] as $f) { @unlink($sx . '/' . $f); }
        foreach ($files as $f => $c) { file_put_contents($sx . '/' . $f, $c); }
        return $sh('system_tz')[1];
    };
    eq([$tz(['timezone' => "Europe/Warsaw\n"]), $tz(['timezone' => "Etc/UTC\n"]), $tz(['timezone' => "Mars/Olympus\n"]), $tz(['timezone' => "../../etc/passwd\n"]),
        $tz(['timedatectl_out' => "Asia/Tokyo\n"]), $tz([])],
        ['Europe/Warsaw', 'UTC', 'UTC', 'UTC', 'Asia/Tokyo', 'UTC'], '/etc/timezone, Etc/UTC -> UTC, unknown and hostile names -> UTC, timedatectl, nothing -> UTC');
    if (@symlink($sx . '/zoneinfo/Europe/Warsaw', $sx . '/localtime')) {
        eq($tz(['timezone' => "Asia/Tokyo\n"]), 'Europe/Warsaw', 'the /etc/localtime link wins over /etc/timezone');
        unlink($sx . '/localtime');
    } else {
        skip('symlink() unavailable — the /etc/localtime link not tested', 1);
    }
    [$rc, $out] = $sh('render_template "$R/system/mailpanel-policy.cf.example" HASLO_MAILPOLICY 0123456789abcdef0123 | render_template - @TIMEZONE@ "Europe/Warsaw"');
    file_put_contents($sx . '/policy.cf', $out);
    $ini = @parse_ini_file($sx . '/policy.cf');
    ok(is_array($ini) && ($ini['timezone'] ?? '') === 'Europe/Warsaw' && ($ini['password'] ?? '') === '0123456789abcdef0123' && substr_count($out, '0123456789abcdef0123') === 1,
        'rendered policy.cf: the policy\'s own parser reads the zone and the password (the password only on its line)');
    ok(str_contains($inst, 'render_template - @TIMEZONE@ "$(system_tz)"'), 'section 4 renders the server zone into the policy file');

    group('install.sh section 6: Dovecot post-login wired as conf.d/99-roundcontrol-postlogin.conf, removed again on any error');
    file_put_contents($sx . '/dovecot-postlogin.sh', "#!/bin/bash\nexec \"\$@\"\n");
    chmod($sx . '/dovecot-postlogin.sh', 0755);
    file_put_contents($sx . '/mysql-mailpanel.cnf', "[client]\n");
    $snippet = (string) file_get_contents($root . '/system/dovecot/postlogin.conf.snippet');
    $pconf = $sx . '/conf.d/99-roundcontrol-postlogin.conf';
    $flags([]);
    [$rc, $out] = $sh('dovecot_postlogin_active && echo ACTIVE; wire_dovecot_postlogin; echo "rc=$?"; dovecot_postlogin_active && echo ACTIVE2');
    ok(!str_starts_with($out, 'ACTIVE') && str_contains($out, 'rc=0') && str_contains($out, 'ACTIVE2') && (string) @file_get_contents($pconf) === $snippet, 'wired: the snippet installed as is, doveconf -n shows the script');
    ok(str_contains($calls(), 'systemctl reload dovecot'), 'Dovecot reloaded');
    $flags([]);
    [$rc, $out] = $sh('wire_dovecot_postlogin');
    ok($rc === 0 && str_contains($out, 'already runs') && $calls() === '', 'second run: "already runs", no reload (idempotent)');
    unlink($pconf);
    $scenarios = [
        'doveconf -n rejects the new file' => [['dove_rejects_new'], 'removed again'],
        'dovecot.conf does not read conf.d/' => [['dove_noinclude'], 'does not include conf.d'],
        'the reload fails'                   => [['reload_bad'], 'reload dovecot failed'],
        'Dovecot already broken before'      => [['dove_bad'], 'BEFORE any change'],
        'no vmail user'                      => [['no_vmail'], 'vmail does not exist'],
    ];
    foreach ($scenarios as $label => [$on, $needle]) {
        $flags($on);
        [$rc, $out] = $sh('wire_dovecot_postlogin');
        ok($rc === 1 && !is_file($pconf) && str_contains($out, $needle), "$label: exit 1, no file left (" . $needle . ')');
    }
    $flags(['dove_manual']);
    [$rc, $out] = $sh('wire_dovecot_postlogin');
    ok($rc === 0 && !is_file($pconf) && str_contains($out, 'already runs'), 'wired by hand in dovecot.conf: nothing added');
    $flags([]);
    unlink($sx . '/dovecot-postlogin.sh');
    [$rc, $out] = $sh('wire_dovecot_postlogin');
    ok($rc === 1 && !is_file($pconf) && str_contains($out, 'run section 1 first'), 'script missing: nothing wired (every IMAP login would fail)');
    ok(str_contains($snippet, 'executable = script-login /usr/local/bin/dovecot-postlogin.sh') && preg_match('/^POSTLOGIN_SCRIPT=\/usr\/local\/bin\/dovecot-postlogin\.sh$/m', $inst) === 1
        && str_contains($inst, '"$SRC/scripts/dovecot-postlogin.sh" /usr/local/bin/'), 'the snippet, the check and section 1 name the same script path');

    group('install.sh section 1b: the pool handler only with mod_proxy + mod_proxy_fcgi; section 7: *.pre-* copies are secrets');
    $mods = static function (array $on) use ($sh, $flags): int { $flags($on); return $sh('apache_proxy_fcgi')[0]; };
    eq([$mods([]), $mods(['no_proxy_fcgi']), $mods(['no_proxy']), $mods(['apache_M_bad'])], [0, 1, 1, 1], 'both modules: yes; without proxy_fcgi, without proxy, apache2ctl -M failing: no');
    $flags(['no_proxy_fcgi']);
    [$rc, $out] = $sh('php-fpm8.5() { :; }; setup_pool');
    ok($rc === 1 && str_contains($out, 'a2enmod proxy_fcgi') && !str_contains($calls(), 'useradd'), 'setup_pool stops before changing anything and says how to enable the module');
    ok(preg_match('/(SECRET_COPY_NAMES=\(.*?\)\nsecure_secret_copies\(\) \{.*?\n\})/s', $inst, $sm) === 1, 'install.sh: secure_secret_copies found');
    foreach (['rc/config.inc.php.pre-password', 'rc/config/config.inc.php.pre-password', 'rc/config/config.inc.php.bak-1', 'rc/config/config.inc.php', 'rc/.env.example', 'rc/.env.pre-1.4', 'rc/config/defaults.inc.php'] as $f) {
        file_put_contents($sx . '/' . $f, 'x');
    }
    file_put_contents($sx . '/secrets.sh', ($sm[1] ?? '') . "\n");
    [$rc, $out] = $sh('chown() { echo "chown $*" >> "$T/calls"; }; . "$T/secrets.sh"; secure_secret_copies');
    $sec = $calls();
    ok(str_contains($sec, 'config.inc.php.pre-password') && str_contains($sec, '.env.pre-1.4') && str_contains($sec, 'config.inc.php.bak-1') && str_contains($out, '4 file(s)'),
        '*.pre-* (config.inc.php.pre-password left by the old password-plugin installer), .env.*, *.bak*: root:root 600 (' . $out . ')');
    ok(!preg_match('#/config\.inc\.php$|\.env\.example|defaults\.inc\.php#m', $sec), 'the live config, .env.example and Roundcube\'s own files are not touched');
    blockSize($start, $size, 'install.sh sections 1b, 3, 4, 6, 7, 9, 11b');
}

echo "\n";
echo ($T['fail'] === 0 ? 'OK' : 'BŁĘDY') . ": {$T['pass']} przeszło, {$T['fail']} nie, {$T['skip']} pominięto\n";
foreach ($T['fails'] as $f) { echo "  FAIL: $f\n"; }
exit($T['fail'] === 0 ? 0 : 1);
