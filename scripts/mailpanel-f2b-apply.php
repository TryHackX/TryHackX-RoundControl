#!/usr/bin/env php
<?php
// mailpanel-f2b-apply (2026-10-13) — installs the mail panel's PROPOSED /etc/fail2ban/jail.local, after checking that
// it differs from the current file ONLY in the values of the options the panel edits, each one well-formed.
//
// Why: jail.local used to be writable by www-data, and fail2ban runs as root — a jail can name any action, so any code
// running as www-data (any PHP site on this box) could become root. Now the file is root's; the panel writes its
// proposal to /var/lib/mailpanel-f2b/jail.local.proposed and runs this through sudo (no arguments).
// Exit 0 = installed (or nothing to change); 1 = refused, nothing changed; 2 = installed but the reload failed.
const LIVE = '/etc/fail2ban/jail.local';
const PROP = '/var/lib/mailpanel-f2b/jail.local.proposed';
const BAK  = '/var/backups/mailpanel-f2b';

$sections = ['DEFAULT', 'roundcube-auth', 'dovecot', 'postfix-sasl', 'recidive'];
$time = '/^(-1|\d{1,9}|(\d{1,6}[smhdw])+)$/';
$rules = [
    'findtime' => $time, 'bantime' => $time, 'bantime.maxtime' => $time,
    'maxretry' => '/^\d{1,6}$/', 'bantime.factor' => '/^\d{1,3}(\.\d{1,3})?$/',
    'bantime.increment' => '/^(true|false)$/', 'enabled' => '/^(true|false)$/',
    'ignoreip' => 'ip_list',
];

function refuse(string $m): void { fwrite(STDOUT, "odrzucone: $m\n"); exit(1); }
function ipOk(string $t): bool {
    $p = explode('/', $t, 2);
    if (!filter_var($p[0], FILTER_VALIDATE_IP)) return false;
    if (isset($p[1])) {
        if (!ctype_digit($p[1])) return false;
        if ((int)$p[1] > (strpos($p[0], ':') !== false ? 128 : 32)) return false;
    }
    return true;
}

clearstatcache();
if (!is_file(PROP) || is_link(PROP)) refuse('brak propozycji ' . PROP);
$st = stat(PROP);
if ($st === false || $st['size'] > 65536) refuse('propozycja za duża');
$wwwUid = function_exists('posix_getpwnam') ? (posix_getpwnam('www-data')['uid'] ?? 33) : 33;
if ($st['uid'] !== $wwwUid) refuse('propozycja nie należy do www-data');
$cur = file(LIVE, FILE_IGNORE_NEW_LINES);
$new = file(PROP, FILE_IGNORE_NEW_LINES);
if ($cur === false || $new === false) refuse('nie mogę przeczytać plików');
if (count($cur) !== count($new)) refuse('zmieniła się liczba linii');

$sec = null;
$changed = 0;
foreach ($cur as $i => $c) {
    $n = $new[$i];
    $line = $i + 1;
    if (preg_match('/^\s*\[([^\]]+)\]/', $c, $m)) {
        $sec = trim($m[1]);
        if ($n !== $c) refuse("zmieniony nagłówek sekcji (linia $line)");
        continue;
    }
    if ($n === $c) continue;
    if (!in_array($sec, $sections, true)) refuse("zmiana w sekcji [$sec] (linia $line)");
    if (!preg_match('/^\s*([A-Za-z0-9_.]+)\s*=/', $c, $mc) || !preg_match('/^([A-Za-z0-9_.]+) = (.*)$/', $n, $mn) || $mc[1] !== $mn[1]) {
        refuse("linia $line nie jest tą samą opcją");
    }
    [$k, $v] = [$mn[1], $mn[2]];
    if (!isset($rules[$k])) refuse("opcja $k nie jest edytowalna z panelu");
    if ($rules[$k] === 'ip_list') {
        $toks = preg_split('/\s+/', trim($v), -1, PREG_SPLIT_NO_EMPTY);
        if (!$toks || count($toks) > 200) refuse('ignoreip: pusta lub za długa lista');
        foreach ($toks as $t) if (!ipOk($t)) refuse("ignoreip: $t");
    } elseif (!preg_match($rules[$k], $v)) {
        refuse("$k = $v");
    }
    $changed++;
}
if ($changed === 0) { @unlink(PROP); echo "bez zmian\n"; exit(0); }

if (!is_dir(BAK)) mkdir(BAK, 0700, true);
$bak = BAK . '/jail.local.' . date('YmdHis');
copy(LIVE, $bak);
chmod($bak, 0600);
$tmp = LIVE . '.new';
file_put_contents($tmp, implode("\n", $new) . "\n");
chown($tmp, 'root'); chgrp($tmp, 'root'); chmod($tmp, 0644);
rename($tmp, LIVE);
exec('/usr/bin/fail2ban-client -t 2>&1', $o, $r);
if ($r !== 0) {
    copy($bak, LIVE);
    refuse('fail2ban odrzucił konfigurację: ' . implode(' ', array_slice($o, -3)));
}
exec('/usr/bin/fail2ban-client reload 2>&1', $o2, $r2);
@unlink(PROP);
echo $r2 === 0 ? "zapisane i przeładowane ($changed zmian)\n" : 'zapisane, reload nie powiódł się: ' . implode(' ', $o2) . "\n";
exit($r2 === 0 ? 0 : 2);
