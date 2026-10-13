#!/usr/bin/php
<?php
declare(strict_types=1);

// mailpanel-f2b-apply (1.1.0) — installs the mail panel's PROPOSED /etc/fail2ban/jail.local, after checking that it
// differs from the current file ONLY in the values of the options the panel edits, each one well-formed.
//
// Why a helper: jail.local is root's (fail2ban runs as root and a jail may name any action, so a file the web user
// could write would be a way to root). The panel computes the new file and pipes it to this program on STANDARD INPUT
// (`sudo -n /usr/local/sbin/mailpanel-f2b-apply`, no arguments). No proposal file lives anywhere www-data could swap it.
//
// Why "in place": php-fpm runs with ProtectSystem=full, and a process started through sudo from PHP-FPM INHERITS that
// sandbox — /etc is read-only for the root helper too, except the paths listed in the drop-in's ReadWritePaths
// (/etc/fail2ban/jail.local, a single file, bind-mounted read-write). Creating a temporary file next to it and
// renaming it over the target (what 1.0.x did) cannot work in that namespace: you cannot create files in a read-only
// directory or rename over a bind mount. So the helper opens jail.local itself (fopen 'c+'), takes an exclusive flock,
// rewrites it from offset 0 and truncates to the new length, then re-reads the file and compares. Backups go to
// /var/backups/mailpanel-f2b (writable under ProtectSystem=full). File ownership / mode are left as they are (an
// in-place rewrite does not change them); if they are not root:root without group/other write, the helper repairs them
// or refuses.
//
// EVERY write is checked. The helper never prints a success line it has not verified: the new content must read back
// byte for byte, `fail2ban-client -t` must pass, and `reload` must exit 0.
//
// Exit codes: 0 = installed and reloaded (or nothing to change); 1 = refused, nothing changed;
//             2 = installed and tested, but the reload failed; 3 = FAILED and the file may be inconsistent
//             (the message names the backup).

if (PHP_SAPI !== 'cli') {      // never reachable over HTTP, even if the web server hands the file out
    http_response_code(404);
    exit;
}

// Overridable ONLY by something that predefines the constants (the tests, via auto_prepend_file).
defined('LIVE') || define('LIVE', '/etc/fail2ban/jail.local');
defined('BAK') || define('BAK', '/var/backups/mailpanel-f2b');
defined('F2B') || define('F2B', '/usr/bin/fail2ban-client');
defined('KEEP_BACKUPS') || define('KEEP_BACKUPS', 30);
const MAX_BYTES = 65536;

// Sections the "Fail2Ban" tab edits, and per section the options it may change (Commands::fail2banSaveConfig()).
$allowed = [
    'DEFAULT'        => ['findtime', 'bantime', 'bantime.maxtime', 'maxretry', 'bantime.factor', 'bantime.increment', 'ignoreip'],
    'roundcube-auth' => ['enabled', 'findtime', 'maxretry', 'bantime'],
    'dovecot'        => ['enabled', 'findtime', 'maxretry', 'bantime'],
    'postfix-sasl'   => ['enabled', 'findtime', 'maxretry', 'bantime'],
    'recidive'       => ['enabled', 'findtime', 'maxretry', 'bantime'],
];
$time = '/^(-1|\d{1,9}|(\d{1,6}[smhdw])+)$/D';
$rules = [
    'findtime' => $time, 'bantime' => $time, 'bantime.maxtime' => $time,
    'maxretry' => '/^\d{1,6}$/D', 'bantime.factor' => '/^\d{1,3}(\.\d{1,3})?$/D',
    'bantime.increment' => '/^(true|false)$/D', 'enabled' => '/^(true|false)$/D',
    'ignoreip' => 'ip_list',
];

function say(string $m): void
{
    fwrite(STDOUT, $m . "\n");
}

/** Refused; nothing was changed. */
function refuse(string $m): never
{
    say("odrzucone: $m");
    exit(1);
}

/** Failed in a way that may leave the file in an unknown state. */
function fail(string $m): never
{
    say("BŁĄD: $m");
    exit(3);
}

function ipOk(string $t): bool
{
    $p = explode('/', $t, 2);
    if (!filter_var($p[0], FILTER_VALIDATE_IP)) {
        return false;
    }
    if (isset($p[1])) {
        if (!ctype_digit($p[1])) {
            return false;
        }
        if ((int) $p[1] > (str_contains($p[0], ':') ? 128 : 32)) {
            return false;
        }
    }
    return true;
}

/** Lines of a config file the way file(FILE_IGNORE_NEW_LINES) sees them (one trailing newline dropped, CRLF folded). */
function splitLines(string $s): array
{
    $s = str_replace("\r\n", "\n", $s);
    if (str_ends_with($s, "\n")) {
        $s = substr($s, 0, -1);
    }
    return explode("\n", $s);
}

/** @return ?array{0:string,1:string} [key, value with whitespace folded] */
function parseOpt(string $line): ?array
{
    if (preg_match('/^\s*([A-Za-z0-9_.]+)\s*=\s*(.*?)\s*$/', $line, $m) !== 1) {
        return null;
    }
    return [$m[1], (string) preg_replace('/\s+/', ' ', $m[2])];
}

/**
 * Rewrite an open file IN PLACE: write at offset 0, flush, truncate to the new length, flush to disk.
 * The new content is written BEFORE truncating, so there is never a moment with an empty file.
 *
 * @throws RuntimeException on any short or failed write
 */
function writeInPlace($fh, string $content): void
{
    if (fseek($fh, 0) !== 0) {
        throw new RuntimeException('fseek nie powiodło się');
    }
    $len = strlen($content);
    $off = 0;
    while ($off < $len) {
        $w = fwrite($fh, substr($content, $off));
        if ($w === false || $w === 0) {
            throw new RuntimeException("zapis przerwany po $off z $len bajtów");
        }
        $off += $w;
    }
    if (!fflush($fh)) {
        throw new RuntimeException('fflush nie powiodło się');
    }
    if (!ftruncate($fh, $len)) {
        throw new RuntimeException('ftruncate nie powiodło się');
    }
    if (!fflush($fh)) {
        throw new RuntimeException('fflush po ftruncate nie powiodło się');
    }
    if (function_exists('fsync') && !fsync($fh)) {
        throw new RuntimeException('fsync nie powiodło się');
    }
}

/**
 * Does the file, read back from the start through the SAME handle (after the writes were flushed), equal $content?
 * Same handle on purpose: we hold an exclusive flock, which is mandatory on some platforms (a second handle could not
 * read the file), and the size from stat() must agree as well — a stale tail would show up there.
 */
function readsBack($fh, string $content): bool
{
    clearstatcache(true, LIVE);
    if (@filesize(LIVE) !== strlen($content)) {
        return false;
    }
    if (fseek($fh, 0) !== 0) {
        return false;
    }
    $back = stream_get_contents($fh);
    return $back !== false && hash_equals(hash('sha256', $content), hash('sha256', $back));
}

/** @return array{0:int,1:string} [exit code, output] */
function run(string $cmd): array
{
    $out = [];
    $code = 0;
    exec($cmd . ' 2>&1', $out, $code);
    return [$code, trim(implode(' ', array_slice($out, -3)))];
}

function timeoutPrefix(): string
{
    return @is_executable('/usr/bin/timeout') ? '/usr/bin/timeout 120 ' : '';
}

/** Open jail.local read-write and lock it exclusively (waits up to 10 s for another helper run). */
function openLive()
{
    clearstatcache(true, LIVE);
    if (!is_file(LIVE) || is_link(LIVE)) {     // 'c+' would CREATE a missing file / write through a symlink
        refuse(LIVE . ' nie istnieje albo jest dowiązaniem symbolicznym');
    }
    $fh = @fopen(LIVE, 'c+');
    if ($fh === false) {
        refuse('nie mogę otworzyć ' . LIVE . ' do zapisu — pod ProtectSystem=full w php-fpm potrzebny jest drop-in '
            . 'ReadWritePaths=/etc/fail2ban/jail.local (README: "php-fpm sandbox")');
    }
    $locked = false;
    for ($i = 0; $i < 20 && !$locked; $i++) {
        $locked = flock($fh, LOCK_EX | LOCK_NB);
        if (!$locked) {
            usleep(500000);
        }
    }
    if (!$locked) {
        refuse('inny zapis jail.local jest w toku (blokada)');
    }
    return $fh;
}

/** jail.local must be root's and not writable by group/others; repair it if it is not, refuse if that fails. */
function checkOwnership($fh): void
{
    if (PHP_OS_FAMILY === 'Windows') {
        return;
    }
    $st = fstat($fh);
    if ($st === false) {
        refuse('fstat ' . LIVE . ' nie powiodło się');
    }
    if ($st['uid'] === 0 && ($st['mode'] & 0022) === 0) {
        return;
    }
    if (!chown(LIVE, 'root') || !chgrp(LIVE, 'root') || !chmod(LIVE, 0644)) {
        refuse(LIVE . ' nie należy do root:root albo jest zapisywalny dla grupy/innych i nie udało się tego naprawić');
    }
    $st = fstat($fh);
    if ($st === false || $st['uid'] !== 0 || ($st['mode'] & 0022) !== 0) {
        refuse(LIVE . ' nadal nie ma właściciela root i trybu 644 po próbie naprawy');
    }
}

/* ------------------------------------------------------------------ selftest */
// `mailpanel-f2b-apply --selftest` (run by install.sh as root, optionally inside the same sandbox php-fpm has):
// proves that jail.local can be rewritten in place and that the backup directory is writable. Changes nothing.
if (($argv[1] ?? '') === '--selftest') {
    $fh = openLive();
    checkOwnership($fh);
    $cur = stream_get_contents($fh);
    if ($cur === false || $cur === '') {
        refuse('nie mogę odczytać ' . LIVE);
    }
    try {
        writeInPlace($fh, $cur);
    } catch (RuntimeException $e) {
        fail('zapis w miejscu nie działa: ' . $e->getMessage());
    }
    if (!readsBack($fh, $cur)) {
        fail('po zapisie w miejscu zawartość się różni');
    }
    if (!is_dir(BAK) && !@mkdir(BAK, 0700, true)) {
        refuse('nie mogę utworzyć ' . BAK);
    }
    $probe = BAK . '/.selftest-' . getmypid();
    if (@file_put_contents($probe, 'x') !== 1 || !@unlink($probe)) {
        refuse(BAK . ' nie jest zapisywalny');
    }
    say('selftest ok');
    exit(0);
}

/* ------------------------------------------------------------------ the proposal (stdin) */
$in = stream_get_contents(STDIN, MAX_BYTES + 1);
if ($in === false || $in === '') {
    refuse('pusta propozycja (oczekiwana na standardowym wejściu)');
}
if (strlen($in) > MAX_BYTES) {
    refuse('propozycja za duża');
}
if (str_contains($in, "\0")) {
    refuse('propozycja zawiera bajt zerowy');
}

$fh = openLive();
checkOwnership($fh);

$curRaw = stream_get_contents($fh);
if ($curRaw === false) {
    refuse('nie mogę przeczytać ' . LIVE);
}
$cur = splitLines($curRaw);
$new = splitLines($in);
if (count($cur) !== count($new)) {
    refuse('zmieniła się liczba linii');
}

$sec = null;
$changed = 0;
$cosmetic = 0;
foreach ($cur as $i => $c) {
    $n = $new[$i];
    $line = $i + 1;
    if (preg_match('/^\s*\[([^\]]+)\]/', $c, $m) === 1) {
        $sec = trim($m[1]);
        if ($n !== $c) {
            refuse("zmieniony nagłówek sekcji (linia $line)");
        }
        continue;
    }
    if ($n === $c) {
        continue;
    }
    if (preg_match('/^\s*\[/', $n) === 1) {
        refuse("nowy nagłówek sekcji w linii $line");
    }
    $oc = parseOpt($c);
    $on = parseOpt($n);
    if ($oc === null || $on === null || $oc[0] !== $on[0] || preg_match('/^[A-Za-z0-9_.]+ = /', $n) !== 1) {
        refuse("linia $line nie jest tą samą opcją w postaci 'opcja = wartość'");
    }
    if ($oc[1] === $on[1]) {       // ta sama opcja i wartość, inny odstęp: bez znaczenia
        $cosmetic++;
        continue;
    }
    [$k, $v] = [$on[0], $on[1]];
    if ($sec === null || !isset($allowed[$sec])) {
        refuse("zmiana w sekcji [" . ($sec ?? '') . "] (linia $line)");
    }
    if (!in_array($k, $allowed[$sec], true)) {
        refuse("opcja $k nie jest edytowalna z panelu w sekcji [$sec]");
    }
    if (strlen($v) > 2000) {
        refuse("$k: wartość za długa");
    }
    if ($rules[$k] === 'ip_list') {
        $toks = preg_split('/\s+/', trim($v), -1, PREG_SPLIT_NO_EMPTY);
        if (!$toks || count($toks) > 200) {
            refuse('ignoreip: pusta lub za długa lista');
        }
        foreach ($toks as $t) {
            if (!ipOk($t)) {
                refuse("ignoreip: $t");
            }
        }
    } elseif (preg_match($rules[$k], $v) !== 1) {
        refuse("$k = $v");
    }
    $changed++;
}
if ($changed === 0) {
    say('bez zmian');
    exit(0);
}

/* ------------------------------------------------------------------ backup */
if (!is_dir(BAK) && !@mkdir(BAK, 0700, true)) {
    refuse('nie mogę utworzyć katalogu kopii ' . BAK);
}
$bak = BAK . '/jail.local.' . date('YmdHis') . '-' . getmypid();
$written = @file_put_contents($bak, $curRaw);
if ($written === false || $written !== strlen($curRaw)) {
    @unlink($bak);
    refuse('nie mogę zapisać kopii zapasowej ' . $bak);
}
if (!@chmod($bak, 0600) && PHP_OS_FAMILY !== 'Windows') {
    @unlink($bak);
    refuse('nie mogę ustawić uprawnień kopii zapasowej ' . $bak);
}
if (@hash_file('sha256', $bak) !== hash('sha256', $curRaw)) {
    @unlink($bak);
    refuse('kopia zapasowa nie zgadza się z oryginałem');
}
$old = glob(BAK . '/jail.local.*') ?: [];
sort($old);
foreach (array_slice($old, 0, max(0, count($old) - (int) KEEP_BACKUPS)) as $stale) {
    @unlink($stale);            // best effort; a stale backup that stays is harmless
}

/* ------------------------------------------------------------------ install (in place), verify, test, reload */
$newRaw = implode("\n", $new) . "\n";
$restore = static function () use ($fh, $curRaw, $bak): void {
    try {
        writeInPlace($fh, $curRaw);
    } catch (RuntimeException $e) {
        fail('NIE UDAŁO SIĘ PRZYWRÓCIĆ ' . LIVE . ' (' . $e->getMessage() . ') — oryginał jest w ' . $bak);
    }
    if (!readsBack($fh, $curRaw)) {
        fail(LIVE . ' po przywróceniu różni się od oryginału — oryginał jest w ' . $bak);
    }
};

try {
    writeInPlace($fh, $newRaw);
} catch (RuntimeException $e) {
    $restore();
    refuse('zapis ' . LIVE . ' nie powiódł się (' . $e->getMessage() . '); przywrócono poprzednią zawartość');
}
if (!readsBack($fh, $newRaw)) {
    $restore();
    refuse('po zapisie ' . LIVE . ' nie zawiera tego, co zapisano; przywrócono poprzednią zawartość');
}

[$rt, $tout] = run(timeoutPrefix() . F2B . ' -t');
if ($rt !== 0) {
    $restore();
    refuse('fail2ban odrzucił konfigurację (przywrócono poprzednią): ' . $tout);
}
[$rr, $rout] = run(timeoutPrefix() . F2B . ' reload');
if ($rr !== 0) {
    say('zapisane i sprawdzone, ale reload nie powiódł się: ' . $rout);
    exit(2);
}
say("zapisane i przeładowane ($changed zmian)");
exit(0);
