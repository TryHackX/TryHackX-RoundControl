<?php
declare(strict_types=1);

/**
 * Test reguł sudoers panelu + spójności z helperem Fail2Ban (1.0.1). Bez bazy, bez sudo/visudo —
 * działa też na Windows:  php tests/sudoers_test.php
 *
 * Czyta system/sudoers.d/mailpanel i odtwarza semantykę sudoers: argumenty ^regex$ dopasowywane do
 * CAŁEGO ciągu argumentów, argumenty ze znakiem * przez fnmatch (a w sudoers * pasuje także do spacji —
 * dlatego reguły fail2ban-client nie mogą ich zawierać), brak argumentów w regule = dowolne argumenty.
 * Sprawdza, że każde polecenie wywoływane przez panel jest dozwolone, a wrogie formy — nie.
 * Prawdziwe visudo -cf robi install.sh.
 */

$root = dirname(__DIR__);
$T = ['pass' => 0, 'fail' => 0, 'fails' => []];
function ok(bool $cond, string $label): void
{
    global $T;
    if ($cond) { $T['pass']++; } else { $T['fail']++; $T['fails'][] = $label; }
}
function group(string $name): void { echo "\n# $name\n"; }

/** @return array{0:list<array{0:string,1:?string}>,1:list<string>} [reguły [ścieżka, argumenty|null], surowe wpisy] */
function parseSudoers(string $file): array
{
    $raw = str_replace("\r\n", "\n", (string) file_get_contents($file));
    $raw = preg_replace('/\\\\\n\s*/', ' ', $raw) ?? '';
    $aliases = [];
    $rules = [];
    $entries = [];
    foreach (explode("\n", $raw) as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#') { continue; }
        if (preg_match('/^Cmnd_Alias\s+(\w+)\s*=\s*(.+)$/', $line, $m)) {
            $aliases[$m[1]] = $m[2];
        }
    }
    foreach (explode("\n", $raw) as $line) {
        $line = trim($line);
        if (!preg_match('/^(\S+)\s+ALL=\((\w+)\)\s+NOPASSWD:\s*(.+)$/', $line, $m)) { continue; }
        foreach (preg_split('/\s*,\s*/', trim($m[3])) ?: [] as $e) {
            $list = isset($aliases[$e]) ? (preg_split('/\s*,\s*/', $aliases[$e]) ?: []) : [$e];
            foreach ($list as $c) {
                $entries[] = $c;
                if (!preg_match('~^(/\S+)(?:\s+(.*))?$~', $c, $p)) { continue; }
                $rules[] = [$p[1], isset($p[2]) && $p[2] !== '' ? $p[2] : null];
            }
        }
    }
    return [$rules, $entries];
}

function allowed(array $rules, string $cmdline): bool
{
    $sp   = strpos($cmdline, ' ');
    $path = $sp === false ? $cmdline : substr($cmdline, 0, $sp);
    $args = $sp === false ? '' : substr($cmdline, $sp + 1);
    foreach ($rules as [$rpath, $rargs]) {
        if ($rpath !== $path) { continue; }
        if ($rargs === null) { return true; }
        if (strlen($rargs) > 1 && $rargs[0] === '^' && substr($rargs, -1) === '$') {
            $re = str_replace('\\:', ':', $rargs);                       // \: to ucieczka sudoers, nie regex
            if (preg_match('~' . str_replace('~', '\\~', $re) . '~D', $args) === 1) { return true; }   // D: $ = koniec ciągu
            continue;
        }
        if (fnmatch($rargs, $args)) { return true; }
    }
    return false;
}

[$rules, $entries] = parseSudoers($root . '/system/sudoers.d/mailpanel');
$F2B = '/usr/bin/fail2ban-client';

group('sudoers: brak wildcardów w regułach fail2ban-client');
$f2bRules = array_values(array_filter($rules, static fn(array $r): bool => $r[0] === $F2B));
ok(count($f2bRules) >= 7, 'reguły fail2ban-client wczytane (' . count($f2bRules) . ')');
foreach ($f2bRules as [$p, $a]) {
    if ($a === null) { ok(false, "fail2ban-client bez argumentów = dowolne argumenty"); continue; }
    $isRegex = $a[0] === '^' && substr($a, -1) === '$';
    ok($isRegex || preg_match('/^[A-Za-z0-9_ -]+$/', $a) === 1, "argumenty to ^regex\$ albo dosłowny tekst: $a");
    ok(!$isRegex ? !str_contains($a, '*') && !str_contains($a, '?') : true, "bez * i ? w dosłownych argumentach: $a");
}
$joined = preg_replace('/\\\\\n\s*/', ' ', str_replace("\r\n", "\n", (string) file_get_contents($root . '/system/sudoers.d/mailpanel'))) ?? '';
foreach (explode("\n", $joined) as $line) {
    $line = trim($line);
    if ($line === '' || $line[0] === '#' || !str_contains($line, 'fail2ban-client')) { continue; }
    ok(!str_contains($line, '*'), 'linia z fail2ban-client bez znaku *');
}
ok(!in_array('/usr/bin/doveadm -f flow quota get -u *', $entries, true), 'doveadm bez wildcardu');

group('sudoers: polecenia panelu są dozwolone');
foreach ([
    "$F2B ping", "$F2B status", "$F2B status dovecot", "$F2B status panel-tempban", "$F2B status roundcube-auth",
    "$F2B set dovecot banip 203.0.113.7", "$F2B set panel-permaban banip 2001:db8::7",
    "$F2B set recidive unbanip 203.0.113.7", "$F2B set dovecot unbanip 2001:db8::7",
    "$F2B set panel-tempban bantime 86400", "$F2B set panel-tempban bantime -1",
    "$F2B get dovecot banip --with-time", "$F2B reload",
    '/usr/local/sbin/mailpanel-f2b-apply',
    '/usr/bin/doveadm -f flow quota get -u user.name+tag@example.com',
    '/usr/local/bin/mailpanel-scan.sh 2026-01-01 2026-01-31', '/usr/local/bin/mailpanel-scan.sh 2026-01-01 2026-01-31 ssh',
    '/usr/local/bin/update-postfix-limits.sh 100 10 5', '/usr/local/bin/update-postfix-limits.sh',
    '/usr/local/bin/mailpanel-delete-user.sh user@example.com', '/usr/local/bin/mailpanel-delete-user.sh',
] as $c) {
    ok(allowed($rules, $c), "dozwolone: $c");
}

group('sudoers: wrogie formy są odrzucone');
foreach ([
    "$F2B set dovecot action nftables actionban touch banip 1.2.3.4",   // atak: podmiana actionban jaila (root)
    "$F2B set dovecot banip 1.2.3.4 extra", "$F2B set dovecot banip 1.2.3.4 ; id", "$F2B set dovecot banip",
    "$F2B set dovecot banip 1.2.3.4\n", "$F2B set dovecot addaction x", "$F2B set dovecot addlogpath /etc/shadow",
    "$F2B set dovecot unbanip 1.2.3.4 extra", "$F2B set dovecot bantime 60 extra", "$F2B set dovecot bantime abc",
    "$F2B set dovecot bantime", "$F2B get dovecot banip", "$F2B get dovecot banip --with-time extra",
    "$F2B status dovecot extra", "$F2B status ../x", "$F2B status 'a b'", "$F2B ping extra", "$F2B reload now",
    "$F2B start", "$F2B stop", "$F2B restart", "$F2B unban --all", "$F2B -c /tmp/evil status", "$F2B set loglevel 4",
    "$F2B set dovecot banip 1.2.3.4 banip 5.6.7.8",
    '/usr/bin/doveadm -f flow quota get -u a@b.c -x', '/usr/bin/doveadm -f flow quota get -u a b',
    '/usr/bin/doveadm -f flow quota recalc -u a@b.c', '/usr/bin/doveadm -f json quota get -u a@b.c',
    '/usr/bin/doveadm -f flow quota get -u', '/usr/bin/doveadm -f flow quota get -u a@b.c;id',
    '/usr/bin/doveadm -f flow quota get -u a@b.c|id', '/usr/bin/doveadm', '/usr/bin/doveadm stop',
    '/usr/local/sbin/mailpanel-f2b-reload.php', '/bin/sh -c id', '/usr/bin/fail2ban-client',
] as $c) {
    ok(!allowed($rules, $c), 'odrzucone: ' . str_replace("\n", '\\n', $c));
}

group('Commands.php: każde sudo celuje w polecenie z reguł');
$cmd = (string) file_get_contents($root . '/app/src/System/Commands.php');
preg_match_all('~sudo(?: -n)? (/\S+|fail2ban-client)~', $cmd, $mm);
ok(count($mm[1]) >= 10, 'wywołania sudo znalezione (' . count($mm[1]) . ')');
$paths = array_map(static fn(array $r): string => $r[0], $rules);
foreach (array_unique($mm[1]) as $bin) {
    $bin = $bin === 'fail2ban-client' ? $F2B : $bin;
    ok(in_array($bin, $paths, true), "reguła sudoers istnieje dla $bin");
}
ok(str_contains($cmd, 'mailpanel-f2b-apply'), 'zapis Fail2Ban idzie przez helper mailpanel-f2b-apply');
ok(!preg_match('/file_put_contents\(\$file\b/', $cmd), 'Commands.php nie zapisuje jail.local bezpośrednio');
ok(!str_contains($cmd, 'is_writable($file)'), 'brak sprawdzania zapisywalności jail.local (należy do roota)');

group('helper vs Commands.php: te same sekcje i opcje');
$helper = (string) file_get_contents($root . '/scripts/mailpanel-f2b-apply.php');
ok(preg_match('/\$sections = \[([^\]]*)\];/', $helper, $hs) === 1, 'helper: lista sekcji');
$hSections = preg_match_all("/'([^']+)'/", $hs[1] ?? '', $x) ? $x[1] : [];
ok(preg_match("/\\\$sections = \\['DEFAULT' => \\[\\], (.*?)\\];/s", $cmd, $cs) === 1, 'Commands: lista sekcji');
$cSections = array_merge(['DEFAULT'], preg_match_all("/'([^']+)' => \\[\\]/", $cs[1] ?? '', $y) ? $y[1] : []);
sort($hSections); sort($cSections);
ok($hSections === $cSections && $hSections !== [], 'sekcje: helper == Commands (' . implode(',', $hSections) . ')');
ok(preg_match('/\$rules = \[(.*?)\n\];/s', $helper, $hr) === 1, 'helper: reguły opcji');
$hKeys = preg_match_all("/'([A-Za-z0-9_.]+)' =>/", $hr[1] ?? '', $z) ? $z[1] : [];
ok(preg_match("/'DEFAULT' => \\[\\n(.*?)\\],\\n\\s+'roundcube-auth'/s", $cmd, $cd) === 1, 'Commands: opcje DEFAULT');
$cKeys = preg_match_all("/'([A-Za-z0-9_.]+)'\\s*=>/", $cd[1] ?? '', $w) ? $w[1] : [];
ok(preg_match('/function jailBlock.*?return \[(.*?)\];/s', $cmd, $cj) === 1, 'Commands: opcje jaili');
$cKeys = array_merge($cKeys, preg_match_all("/'([A-Za-z0-9_.]+)'\\s*=>/", $cj[1] ?? '', $w2) ? $w2[1] : []);
$hKeys = array_unique($hKeys); $cKeys = array_unique($cKeys);
sort($hKeys); sort($cKeys);
ok($hKeys === $cKeys && $hKeys !== [], 'opcje: helper == Commands (' . implode(',', $hKeys) . ')');

echo "\n";
echo ($T['fail'] === 0 ? 'OK' : 'BŁĘDY') . ": {$T['pass']} przeszło, {$T['fail']} nie\n";
foreach ($T['fails'] as $f) { echo "  FAIL: $f\n"; }
exit($T['fail'] === 0 ? 0 : 1);
