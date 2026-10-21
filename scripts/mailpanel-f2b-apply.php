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
// Output (1.3.1: English; the panel translates by exit code and keys on these first words): exactly "unchanged",
// "saved and reloaded (N changes)", "refused: …", "saved and checked, but the reload failed: …", "FAILED: …",
// "selftest ok".

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
// 1.4.0: well-formed is not enough. A jail with `maxretry = 999999`, `findtime = 1` or `bantime = 1` never bans anyone,
// and [DEFAULT] is inherited by sshd and recidive. The panel clamps to the same ranges (Commands::sanitize), so a value
// outside them means the proposal did not come from the panel's form.
const MIN_SECONDS = 60;
const MAX_SECONDS = 31536000;      // one year: the panel's own maximum
const MAX_RETRY = 100;

function say(string $m): void
{
    fwrite(STDOUT, $m . "\n");
}

/** Refused; nothing was changed. */
function refuse(string $m): never
{
    say("refused: $m");
    exit(1);
}

/** Failed in a way that may leave the file in an unknown state. */
function fail(string $m): never
{
    say("FAILED: $m");
    exit(3);
}

/**
 * One ignoreip entry: an address, a network no broader than IPv4 /8 or IPv6 /32 (1.4.0: `0.0.0.0/0 ::/0` used to pass
 * and exempted every source from every jail, sshd included), or a host name (Fail2Ban resolves it; a dynamic-DNS name
 * of the administrator's home is the usual case — the panel used to drop such names on every save).
 */
function ipOk(string $t): bool
{
    if (preg_match('/^(?=.{1,253}$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/iD', $t) === 1) {
        return true;
    }
    $p = explode('/', $t, 2);
    if (!filter_var($p[0], FILTER_VALIDATE_IP)) {
        return false;
    }
    if (isset($p[1])) {
        $v6 = str_contains($p[0], ':');
        if (!ctype_digit($p[1]) || (int) $p[1] > ($v6 ? 128 : 32) || (int) $p[1] < ($v6 ? 32 : 8)) {
            return false;
        }
    }
    return true;
}

/** Seconds of a Fail2Ban time value ("-1", "600", "10m", "1h30m"); null when it is none of those. */
function seconds(string $v): ?int
{
    if ($v === '-1') {
        return -1;
    }
    if (preg_match('/^\d{1,9}$/D', $v) === 1) {
        return (int) $v;
    }
    if (preg_match('/^(\d{1,6}[smhdw])+$/D', $v) !== 1) {
        return null;
    }
    preg_match_all('/(\d{1,6})([smhdw])/', $v, $m, PREG_SET_ORDER);
    $unit = ['s' => 1, 'm' => 60, 'h' => 3600, 'd' => 86400, 'w' => 604800];
    $s = 0;
    foreach ($m as $part) {
        $s += (int) $part[1] * $unit[$part[2]];
    }
    return $s;
}

/** The value of an already well-formed option lies in the range the panel itself allows. */
function inRange(string $k, string $v): bool
{
    if ($k === 'maxretry') {
        return (int) $v >= 1 && (int) $v <= MAX_RETRY;
    }
    if ($k === 'bantime.factor') {
        return (float) $v >= 1.0 && (float) $v <= 10.0;
    }
    if (in_array($k, ['findtime', 'bantime', 'bantime.maxtime'], true)) {
        $s = seconds($v);
        return $s !== null && (($k === 'bantime' && $s === -1) || ($s >= MIN_SECONDS && $s <= MAX_SECONDS));
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
        throw new RuntimeException('fseek failed');
    }
    $len = strlen($content);
    $off = 0;
    while ($off < $len) {
        $w = fwrite($fh, substr($content, $off));
        if ($w === false || $w === 0) {
            throw new RuntimeException("write stopped after $off of $len bytes");
        }
        $off += $w;
    }
    if (!fflush($fh)) {
        throw new RuntimeException('fflush failed');
    }
    if (!ftruncate($fh, $len)) {
        throw new RuntimeException('ftruncate failed');
    }
    if (!fflush($fh)) {
        throw new RuntimeException('fflush after ftruncate failed');
    }
    if (function_exists('fsync') && !fsync($fh)) {
        throw new RuntimeException('fsync failed');
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
        refuse(LIVE . ' does not exist or is a symbolic link');
    }
    $fh = @fopen(LIVE, 'c+');
    if ($fh === false) {
        refuse('cannot open ' . LIVE . ' for writing — under ProtectSystem=full php-fpm needs the drop-in '
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
        refuse('another write of jail.local is in progress (lock)');
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
        refuse('fstat ' . LIVE . ' failed');
    }
    if ($st['uid'] === 0 && ($st['mode'] & 0022) === 0) {
        return;
    }
    if (!chown(LIVE, 'root') || !chgrp(LIVE, 'root') || !chmod(LIVE, 0644)) {
        refuse(LIVE . ' is not root:root or is writable by group/others, and that could not be repaired');
    }
    $st = fstat($fh);
    if ($st === false || $st['uid'] !== 0 || ($st['mode'] & 0022) !== 0) {
        refuse(LIVE . ' still is not root-owned with mode 644 after the repair attempt');
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
        refuse('cannot read ' . LIVE);
    }
    try {
        writeInPlace($fh, $cur);
    } catch (RuntimeException $e) {
        fail('in-place write does not work: ' . $e->getMessage());
    }
    if (!readsBack($fh, $cur)) {
        fail('the content differs after the in-place write');
    }
    if (!is_dir(BAK) && !@mkdir(BAK, 0700, true)) {
        refuse('cannot create ' . BAK);
    }
    $probe = BAK . '/.selftest-' . getmypid();
    if (@file_put_contents($probe, 'x') !== 1 || !@unlink($probe)) {
        refuse(BAK . ' is not writable');
    }
    say('selftest ok');
    exit(0);
}

/* ------------------------------------------------------------------ the proposal (stdin) */
$in = stream_get_contents(STDIN, MAX_BYTES + 1);
if ($in === false || $in === '') {
    refuse('empty proposal (expected on standard input)');
}
if (strlen($in) > MAX_BYTES) {
    refuse('proposal too large');
}
if (str_contains($in, "\0")) {
    refuse('proposal contains a NUL byte');
}

$fh = openLive();
checkOwnership($fh);

$curRaw = stream_get_contents($fh);
if ($curRaw === false) {
    refuse('cannot read ' . LIVE . ' (current file)');
}
$cur = splitLines($curRaw);
$new = splitLines($in);
if (count($cur) !== count($new)) {
    refuse('the number of lines changed');
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
            refuse("changed section header (line $line)");
        }
        continue;
    }
    if ($n === $c) {
        continue;
    }
    if (preg_match('/^\s*\[/', $n) === 1) {
        refuse("new section header in line $line");
    }
    $oc = parseOpt($c);
    $on = parseOpt($n);
    if ($oc === null || $on === null || $oc[0] !== $on[0] || preg_match('/^[A-Za-z0-9_.]+ = /', $n) !== 1) {
        refuse("line $line is not the same option in the form 'option = value'");
    }
    if ($oc[1] === $on[1]) {       // ta sama opcja i wartość, inny odstęp: bez znaczenia
        $cosmetic++;
        continue;
    }
    [$k, $v] = [$on[0], $on[1]];
    if ($sec === null || !isset($allowed[$sec])) {
        refuse("change in section [" . ($sec ?? '') . "] (line $line)");
    }
    if (!in_array($k, $allowed[$sec], true)) {
        refuse("option $k is not editable from the panel in section [$sec]");
    }
    if (strlen($v) > 2000) {
        refuse("$k: value too long");
    }
    if ($rules[$k] === 'ip_list') {
        $toks = preg_split('/\s+/', trim($v), -1, PREG_SPLIT_NO_EMPTY);
        if (!$toks || count($toks) > 200) {
            refuse('ignoreip: empty or too long list');
        }
        foreach ($toks as $t) {
            if (!ipOk($t)) {
                refuse("ignoreip: $t");
            }
        }
    } elseif (preg_match($rules[$k], $v) !== 1 || !inRange($k, $v)) {
        refuse("$k = $v");
    }
    $changed++;
}
if ($changed === 0) {
    say('unchanged');
    exit(0);
}

/* ------------------------------------------------------------------ backup */
if (!is_dir(BAK) && !@mkdir(BAK, 0700, true)) {
    refuse('cannot create the backup directory ' . BAK);
}
$bak = BAK . '/jail.local.' . date('YmdHis') . '-' . getmypid();
$written = @file_put_contents($bak, $curRaw);
if ($written === false || $written !== strlen($curRaw)) {
    @unlink($bak);
    refuse('cannot write the backup ' . $bak);
}
if (!@chmod($bak, 0600) && PHP_OS_FAMILY !== 'Windows') {
    @unlink($bak);
    refuse('cannot set the mode of the backup ' . $bak);
}
if (@hash_file('sha256', $bak) !== hash('sha256', $curRaw)) {
    @unlink($bak);
    refuse('the backup does not match the original');
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
        fail('COULD NOT RESTORE ' . LIVE . ' (' . $e->getMessage() . ') — the original is in ' . $bak);
    }
    if (!readsBack($fh, $curRaw)) {
        fail(LIVE . ' differs from the original after the restore — the original is in ' . $bak);
    }
};

try {
    writeInPlace($fh, $newRaw);
} catch (RuntimeException $e) {
    $restore();
    refuse('writing ' . LIVE . ' failed (' . $e->getMessage() . '); the previous content was restored');
}
if (!readsBack($fh, $newRaw)) {
    $restore();
    refuse('after the write ' . LIVE . ' does not contain what was written; the previous content was restored');
}

[$rt, $tout] = run(timeoutPrefix() . F2B . ' -t');
if ($rt !== 0) {
    $restore();
    refuse('fail2ban rejected the configuration (the previous one was restored): ' . $tout);
}
[$rr, $rout] = run(timeoutPrefix() . F2B . ' reload');
if ($rr !== 0) {
    say('saved and checked, but the reload failed: ' . $rout);
    exit(2);
}
say("saved and reloaded ($changed changes)");
exit(0);
