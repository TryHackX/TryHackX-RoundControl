<?php
declare(strict_types=1);

/**
 * Test reguł sudoers panelu + spójności z helperem Fail2Ban. Bez bazy, bez sudo/visudo —
 * działa też na Windows:  php tests/sudoers_test.php
 *
 * Czyta system/sudoers.d/mailpanel i odtwarza semantykę sudoers: argumenty ^regex$ dopasowywane do
 * CAŁEGO ciągu argumentów (sekwencje \, i \: to ucieczki sudoers, nie regex), argumenty "" = brak argumentów,
 * argumenty ze znakiem * przez fnmatch (a w sudoers * pasuje także do spacji — dlatego reguły z poleceniami roota
 * nie mogą ich zawierać), brak argumentów w regule = dowolne argumenty.
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

/** Dzieli listę poleceń sudoers po przecinkach, ale nie po przecinkach poprzedzonych backslashem. */
function splitCommands(string $s): array
{
    $s = str_replace('\\,', "\x01", $s);
    $out = [];
    foreach (preg_split('/\s*,\s*/', trim($s)) ?: [] as $part) {
        $out[] = str_replace("\x01", '\\,', $part);
    }
    return $out;
}

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
        foreach (splitCommands($m[3]) as $e) {
            $list = isset($aliases[$e]) ? splitCommands($aliases[$e]) : [$e];
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
        if ($rargs === '""') {                                            // "" = dokładnie zero argumentów
            if ($args === '') { return true; }
            continue;
        }
        if (strlen($rargs) > 1 && $rargs[0] === '^' && substr($rargs, -1) === '$') {
            $re = str_replace(['\\:', '\\,'], [':', ','], $rargs);           // \: i \, to ucieczki sudoers, nie regex
            if (preg_match('~' . str_replace('~', '\\~', $re) . '~D', $args) === 1) { return true; }   // D: $ = koniec ciągu
            continue;
        }
        if (fnmatch($rargs, $args)) { return true; }
    }
    return false;
}

[$rules, $entries] = parseSudoers($root . '/system/sudoers.d/mailpanel');
$F2B = '/usr/bin/fail2ban-client';

group('sudoers: brak wildcardów w regułach z poleceniami roota');
$f2bRules = array_values(array_filter($rules, static fn(array $r): bool => $r[0] === $F2B));
ok(count($f2bRules) >= 5, 'reguły fail2ban-client wczytane (' . count($f2bRules) . ')');
foreach ($f2bRules as [$p, $a]) {
    if ($a === null) { ok(false, "fail2ban-client bez argumentów = dowolne argumenty"); continue; }
    $isRegex = $a[0] === '^' && substr($a, -1) === '$';
    ok($isRegex || preg_match('/^[A-Za-z0-9_ -]+$/', $a) === 1, "argumenty to ^regex\$ albo dosłowny tekst: $a");
    ok(!$isRegex ? !str_contains($a, '*') && !str_contains($a, '?') : true, "bez * i ? w dosłownych argumentach: $a");
}
$joined = preg_replace('/\\\\\n\s*/', ' ', str_replace("\r\n", "\n", (string) file_get_contents($root . '/system/sudoers.d/mailpanel'))) ?? '';
foreach (explode("\n", $joined) as $line) {
    $line = trim($line);
    if ($line === '' || $line[0] === '#') { continue; }
    ok(!str_contains($line, '*'), 'żadna reguła sudoers nie zawiera znaku *: ' . substr($line, 0, 60));
}
foreach ($rules as [$p, $a]) {
    $name = basename($p);
    if (in_array($name, ['doveadm', 'mailpanel-scan.sh', 'mailpanel-f2b-apply', 'update-postfix-limits.sh', 'mailpanel-delete-user.sh'], true)) {
        ok($a !== null, "$name ma ograniczone argumenty (^regex\$ albo \"\")");
    }
}
ok(!in_array('/usr/bin/doveadm -f flow quota get -u *', $entries, true), 'doveadm bez wildcardu');
ok(!in_array('/usr/local/bin/mailpanel-scan.sh *', $entries, true), 'mailpanel-scan.sh bez wildcardu');
// dwa pliki definiujące ten sam alias = błąd składni sudoers (cały sudo przestaje działać); 1.0.x miało MAILPANEL_F2B
ok(preg_match('/^Cmnd_Alias\s+MAILPANEL_F2B\b/m', $joined) !== 1 && preg_match('/^Cmnd_Alias\s+ROUNDCONTROL_F2B\b/m', $joined) === 1,
    'alias Fail2Ban ma unikalną nazwę ROUNDCONTROL_F2B (nie koliduje z MAILPANEL_F2B ze starszych instalacji)');
// osobna pula: reguły dla użytkownika puli (User_Alias, install.sh podmienia tę JEDNĄ linię), nigdy dla www-data
ok(preg_match('/^User_Alias ROUNDCONTROL_USER = mailweb$/m', $joined) === 1, 'User_Alias ROUNDCONTROL_USER = mailweb (domyślny użytkownik osobnej puli; install.sh wstawia właściwego)');
$ruleUsers = [];
foreach (explode("\n", $joined) as $line) {
    if (preg_match('/^(\S+)\s+ALL=/', trim($line), $mu)) { $ruleUsers[$mu[1]] = true; }
}
ok(array_keys($ruleUsers) === ['ROUNDCONTROL_USER'], 'każda reguła nazywa ROUNDCONTROL_USER (żadnej dla www-data): ' . implode(',', array_keys($ruleUsers)));

group('sudoers: polecenia panelu są dozwolone');
foreach ([
    "$F2B ping", "$F2B status", "$F2B status dovecot", "$F2B status panel-tempban", "$F2B status roundcube-auth",
    "$F2B set dovecot banip 203.0.113.7", "$F2B set panel-permaban banip 2001:db8::7",
    "$F2B set recidive unbanip 203.0.113.7", "$F2B set dovecot unbanip 2001:db8::7",
    "$F2B set panel-tempban bantime 86400", "$F2B set panel-tempban bantime 60", "$F2B set panel-tempban bantime 7776000",
    "$F2B get dovecot banip --with-time",
    '/usr/local/sbin/mailpanel-f2b-apply',
    '/usr/bin/doveadm -f flow quota get -u user.name+tag@example.com',
    '/usr/local/bin/mailpanel-scan.sh 2026-01-01 2026-01-31', '/usr/local/bin/mailpanel-scan.sh 2026-01-01 2026-01-31 ssh',
    '/usr/local/bin/mailpanel-scan.sh 2026-01-01 2026-01-31 dovecot', '/usr/local/bin/mailpanel-scan.sh 2026-01-01 2026-01-31 postfix',
    '/usr/local/bin/update-postfix-limits.sh 100 10 5', '/usr/local/bin/update-postfix-limits.sh',
    '/usr/local/bin/mailpanel-delete-user.sh user@example.com', '/usr/local/bin/mailpanel-delete-user.sh',
    '/usr/local/bin/mailpanel-delete-user.sh a@example.com', '/usr/local/bin/mailpanel-delete-user.sh a.b-c_d+e@sub.example.org',
] as $c) {
    ok(allowed($rules, $c), "dozwolone: $c");
}

group('sudoers: wrogie formy są odrzucone');
foreach ([
    "$F2B set dovecot action nftables actionban touch banip 1.2.3.4",   // atak: podmiana actionban jaila (root)
    "$F2B set dovecot banip 1.2.3.4 extra", "$F2B set dovecot banip 1.2.3.4 ; id", "$F2B set dovecot banip",
    "$F2B set dovecot banip 1.2.3.4\n", "$F2B set dovecot addaction x", "$F2B set dovecot addlogpath /etc/shadow",
    "$F2B set dovecot unbanip 1.2.3.4 extra", "$F2B set panel-tempban bantime 60 extra", "$F2B set panel-tempban bantime abc",
    "$F2B set panel-tempban bantime", "$F2B get dovecot banip", "$F2B get dovecot banip --with-time extra",
    // bantime TYLKO dla panel-tempban: inaczej www-data wyłączyłby ochronę sshd / recidive ustawiając 1 s
    "$F2B set sshd bantime 1", "$F2B set sshd bantime 3600", "$F2B set recidive bantime 10", "$F2B set dovecot bantime 60",
    "$F2B set panel-permaban bantime 3600", "$F2B set panel-tempban bantime -1", "$F2B set panel-tempban bantime 1",
    "$F2B set panel-tempban bantime 77760000", "$F2B set panel-tempban bantime 99999999999",
    "$F2B status dovecot extra", "$F2B status ../x", "$F2B status 'a b'", "$F2B ping extra",
    "$F2B reload", "$F2B reload now",                                    // reload robi helper, reguły już nie ma
    "$F2B start", "$F2B stop", "$F2B restart", "$F2B unban --all", "$F2B -c /tmp/evil status", "$F2B set loglevel 4",
    "$F2B set dovecot banip 1.2.3.4 banip 5.6.7.8",
    '/usr/local/sbin/mailpanel-f2b-apply --selftest', '/usr/local/sbin/mailpanel-f2b-apply x',   // helper bez argumentów
    '/usr/bin/doveadm -f flow quota get -u a@b.c -x', '/usr/bin/doveadm -f flow quota get -u a b',
    '/usr/bin/doveadm -f flow quota recalc -u a@b.c', '/usr/bin/doveadm -f json quota get -u a@b.c',
    '/usr/bin/doveadm -f flow quota get -u', '/usr/bin/doveadm -f flow quota get -u a@b.c;id',
    '/usr/bin/doveadm -f flow quota get -u a@b.c|id', '/usr/bin/doveadm -f flow quota get -u -A',
    '/usr/bin/doveadm -f flow quota get -u -all', '/usr/bin/doveadm', '/usr/bin/doveadm stop',
    '/usr/local/bin/mailpanel-scan.sh', '/usr/local/bin/mailpanel-scan.sh 2026-01-01', '/usr/local/bin/mailpanel-scan.sh *',
    '/usr/local/bin/mailpanel-scan.sh 2026-01-01 2026-01-31 all', '/usr/local/bin/mailpanel-scan.sh 2026-01-01 2026-01-31 ssh extra',
    '/usr/local/bin/mailpanel-scan.sh 2026-01-01 2026-01-31 ssh; id', '/usr/local/bin/mailpanel-scan.sh 2026-01-01 $(id)',
    '/usr/local/bin/mailpanel-scan.sh 2026-01-01 2026-01-31 ../../x', '/usr/local/bin/mailpanel-scan.sh -x 2026-01-31',
    '/usr/local/sbin/mailpanel-f2b-reload.php', '/bin/sh -c id', '/usr/bin/fail2ban-client',
    // update-postfix-limits.sh: exactly three integers (or nothing - the probe); mailpanel-delete-user.sh: one lower-case address
    '/usr/local/bin/update-postfix-limits.sh 1 2', '/usr/local/bin/update-postfix-limits.sh 1 2 3 4', '/usr/local/bin/update-postfix-limits.sh -1 2 3',
    '/usr/local/bin/update-postfix-limits.sh a b c', '/usr/local/bin/update-postfix-limits.sh 1 2 3; id', '/usr/local/bin/update-postfix-limits.sh 1  2 3',
    '/usr/local/bin/mailpanel-delete-user.sh ../x@example.com', '/usr/local/bin/mailpanel-delete-user.sh a@example.com b@example.com',
    '/usr/local/bin/mailpanel-delete-user.sh -rf', '/usr/local/bin/mailpanel-delete-user.sh User@Example.com', '/usr/local/bin/mailpanel-delete-user.sh .@example.com',
    '/usr/local/bin/mailpanel-delete-user.sh a@example.com/..', '/usr/local/bin/mailpanel-delete-user.sh a@-example.com', '/usr/local/bin/mailpanel-delete-user.sh a@example.com;id',
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
$code = '';   // kod bez komentarzy (komentarze wolno wspominać o dawnych rozwiązaniach)
foreach (token_get_all($cmd) as $tok) {
    if (is_array($tok) && in_array($tok[0], [T_COMMENT, T_DOC_COMMENT], true)) { continue; }
    $code .= is_array($tok) ? $tok[1] : $tok;
}
ok(str_contains($code, "sudo -n /usr/local/sbin/mailpanel-f2b-apply"), 'zapis Fail2Ban idzie przez helper mailpanel-f2b-apply');
ok(str_contains($code, 'pipeTo('), 'propozycja jail.local idzie do helpera na stdin (pipeTo)');
ok(!str_contains($code, '/var/lib/mailpanel-f2b'), 'brak pliku propozycji w katalogu www-data');
ok(!preg_match('/file_put_contents\(\$file\b/', $code), 'Commands.php nie zapisuje jail.local bezpośrednio');
ok(!str_contains($code, 'is_writable($file)'), 'brak sprawdzania zapisywalności jail.local (należy do roota)');
ok(!str_contains($code, 'fail2banReload') && !str_contains($code, 'fail2ban-client reload'), 'brak wywołań fail2ban-client reload (reload robi helper)');
ok(preg_match("/sudo -n fail2ban-client set panel-tempban bantime/", $cmd) === 1, 'bantime ustawiany tylko dla panel-tempban');

group('helper vs Commands.php: te same sekcje i opcje (osobno dla każdej sekcji)');
$helper = (string) file_get_contents($root . '/scripts/mailpanel-f2b-apply.php');
$hAllowed = [];
if (preg_match('/\$allowed = \[(.*?)\n\];/s', $helper, $ha)) {
    preg_match_all("/'([^']+)'\\s*=>\\s*\\[([^\\]]*)\\]/", $ha[1], $rows, PREG_SET_ORDER);
    foreach ($rows as $r) {
        preg_match_all("/'([^']+)'/", $r[2], $ks);
        $keys = $ks[1];
        sort($keys);
        $hAllowed[$r[1]] = $keys;
    }
}
ok($hAllowed !== [], 'helper: lista sekcji i opcji ($allowed)');

$cAllowed = [];
ok(preg_match("/'DEFAULT' => \\[\\n(.*?)\\],\\n\\s+'roundcube-auth'/s", $cmd, $cd) === 1, 'Commands: opcje DEFAULT');
$defKeys = preg_match_all("/'([A-Za-z0-9_.]+)'\\s*=>/", $cd[1] ?? '', $w) ? $w[1] : [];
sort($defKeys);
$cAllowed['DEFAULT'] = $defKeys;
ok(preg_match('/function jailBlock.*?return \[(.*?)\];/s', $cmd, $cj) === 1, 'Commands: opcje jaili');
$jailKeys = preg_match_all("/'([A-Za-z0-9_.]+)'\\s*=>/", $cj[1] ?? '', $w2) ? $w2[1] : [];
sort($jailKeys);
preg_match_all("/'([^']+)'\\s*=>\\s*self::jailBlock/", $cmd, $js);
foreach ($js[1] as $jail) { $cAllowed[$jail] = $jailKeys; }
ksort($hAllowed); ksort($cAllowed);
ok($hAllowed === $cAllowed && $hAllowed !== [], 'sekcje i opcje: helper == Commands ('
    . implode('; ', array_map(static fn($k, $v) => "$k: " . implode(',', $v), array_keys($hAllowed), $hAllowed)) . ')');
ok(!in_array('enabled', $hAllowed['DEFAULT'] ?? ['enabled'], true), 'helper: "enabled" w [DEFAULT] niedozwolone (włączałoby każdy jail)');

echo "\n";
echo ($T['fail'] === 0 ? 'OK' : 'BŁĘDY') . ": {$T['pass']} przeszło, {$T['fail']} nie\n";
foreach ($T['fails'] as $f) { echo "  FAIL: $f\n"; }
exit($T['fail'] === 0 ? 0 : 1);
