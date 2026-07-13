<?php
declare(strict_types=1);

namespace App\System;

use App\Support\Env;

/**
 * Fasada dla wywołań systemowych (Fail2Ban, Postfix, usuwanie skrzynek).
 * Aktywna wyłącznie na Linuksie poza trybem dev — na Windows/WAMP wszystkie
 * metody zwracają stan "niedostępne"/no-op, więc panel działa bez błędów.
 *
 * (W M3 zostanie rozbita na osobne interfejsy + implementacje.)
 */
final class Commands
{
    private static function enabled(): bool
    {
        return !Env::isDev()
            && PHP_OS_FAMILY !== 'Windows'
            && function_exists('exec');
    }

    private static function jailFile(): string
    {
        return (string) Env::get('F2B_JAIL_LOCAL', '/etc/fail2ban/jail.local');
    }

    /* ---------------- Fail2Ban ---------------- */

    public static function fail2banAvailable(): bool
    {
        return self::cachedProbe('f2b', 'sudo -n fail2ban-client ping 2>&1', static function (array $out, int $ret): bool {
            return $ret === 0;
        });
    }

    /** @return string[] */
    public static function fail2banJails(): array
    {
        if (!self::enabled()) {
            return [];
        }
        $out = [];
        exec('sudo fail2ban-client status | grep "Jail list:" | sed "s/.*Jail list://"', $out);
        if (!$out) {
            return [];
        }
        return preg_split('/[\s,]+/', trim(implode('', $out)), -1, PREG_SPLIT_NO_EMPTY) ?: [];
    }

    /** @return string[] */
    public static function fail2banBans(string $jail): array
    {
        if (!self::enabled()) {
            return [];
        }
        $out = [];
        exec('sudo fail2ban-client status ' . escapeshellarg($jail), $out);
        foreach ($out as $line) {
            if (preg_match('/Banned IP list:\s*(.*)/', $line, $m)) {
                $list = trim($m[1]);
                return $list === '' ? [] : (preg_split('/[\s,]+/', $list, -1, PREG_SPLIT_NO_EMPTY) ?: []);
            }
        }
        return [];
    }

    /**
     * Lista banów jaila wraz z czasem wygaśnięcia (wymaga sudoers: get * banip *).
     * Linia f2b: "IP <tab>START + BANTIME = END" (END=9999-… przy bantime=-1).
     * Gdy `get … --with-time` niedostępne, wraca do zwykłej listy bez czasów.
     *
     * @return array<int,array{ip:string,end:?int,perm:bool}>  end = epoch lub null
     */
    public static function fail2banBansWithTime(string $jail): array
    {
        if (!self::enabled()) {
            return [];
        }
        $out = [];
        $ret = 0;
        exec('sudo -n fail2ban-client get ' . escapeshellarg($jail) . ' banip --with-time 2>/dev/null', $out, $ret);
        if ($ret !== 0) {
            return array_map(
                static fn(string $ip) => ['ip' => $ip, 'end' => null, 'perm' => false],
                self::fail2banBans($jail)
            );
        }

        $bans = [];
        foreach ($out as $line) {
            if (!preg_match('/^(\S+)\s+\S+ \S+ \+ (-?\d+) = (\d{4})-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', trim($line), $m)) {
                continue;
            }
            $perm = ((int) $m[2] < 0) || $m[3] === '9999';
            $end  = null;
            if (!$perm && preg_match('/= (\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})$/', $line, $e)) {
                $ts  = strtotime($e[1]);
                $end = $ts === false ? null : $ts;
            }
            $bans[] = ['ip' => $m[1], 'end' => $end, 'perm' => $perm];
        }
        return $bans;
    }

    /** Przeładowanie Fail2Ban po zapisie konfiguracji (wymaga wpisu sudoers). */
    public static function fail2banReload(): bool
    {
        if (!self::enabled()) {
            return false;
        }
        $out = [];
        $ret = 0;
        exec('sudo -n fail2ban-client reload 2>&1', $out, $ret);
        return $ret === 0;
    }

    public static function fail2banUnban(string $jail, string $ip): bool
    {
        if (!self::enabled()) {
            return false;
        }
        $out = [];
        $ret = 0;
        exec('sudo fail2ban-client set ' . escapeshellarg($jail) . ' unbanip ' . escapeshellarg($ip), $out, $ret);
        return $ret === 0;
    }

    /** Ręczne zablokowanie IP w wybranym jailu (wymaga sudoers: set * banip *). */
    public static function fail2banBan(string $jail, string $ip): bool
    {
        if (!self::enabled()) {
            return false;
        }
        $out = [];
        $ret = 0;
        exec('sudo fail2ban-client set ' . escapeshellarg($jail) . ' banip ' . escapeshellarg($ip), $out, $ret);
        return $ret === 0;
    }

    /**
     * Zmiana bieżącego bantime jaila (wymaga sudoers: set * bantime *).
     * Ban czasowy z panelu = ustaw bantime, potem banip — f2b przypisuje
     * czas obowiązujący w momencie bana, więc każdy ban ma własny okres.
     */
    public static function fail2banSetBantime(string $jail, int $seconds): bool
    {
        if (!self::enabled() || $seconds < 60) {
            return false;
        }
        $out = [];
        $ret = 0;
        exec('sudo -n fail2ban-client set ' . escapeshellarg($jail) . ' bantime ' . $seconds . ' 2>/dev/null', $out, $ret);
        return $ret === 0;
    }

    /**
     * Skan podejrzanych IP z logów systemowych w zakresie dat.
     * $source zawęża do jednej usługi (ssh|dovecot|postfix) — panel skanuje
     * źródła sekwencyjnie, żeby nie blokować PHP jednym długim wywołaniem.
     * @return array<string,array{total:int,services:array<string,int>}>  klucz = IP
     */
    public static function scanSystemLogs(string $from, string $to, ?string $source = null): array
    {
        if (!self::enabled()) {
            return [];
        }
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) {
            return [];
        }
        $srcArg = '';
        if ($source !== null) {
            if (!in_array($source, ['ssh', 'dovecot', 'postfix'], true)) {
                return [];
            }
            $srcArg = ' ' . escapeshellarg($source);
        }
        $out = [];
        $ret = 0;
        exec(
            'sudo -n /usr/local/bin/mailpanel-scan.sh ' . escapeshellarg($from) . ' ' . escapeshellarg($to) . $srcArg . ' 2>/dev/null',
            $out,
            $ret
        );

        $result = [];
        foreach ($out as $line) {
            if (!preg_match('/^\s*(\d+)\s+(\S+)\s+(\S+)\s*$/', $line, $m)) {
                continue;
            }
            $count   = (int) $m[1];
            $service = $m[2];
            $ip      = $m[3];
            $result[$ip]['total'] = ($result[$ip]['total'] ?? 0) + $count;
            $result[$ip]['services'][$service] = ($result[$ip]['services'][$service] ?? 0) + $count;
        }
        return $result;
    }

    /**
     * Wykorzystanie quoty konta przez doveadm (STORAGE + liczba wiadomości).
     * Wartości w KB (jednostka doveadm) — front formatuje od B do GiB.
     * @return array{used_kb:int,limit_kb:int,used_mb:int,limit_mb:int,pct:int,msgs:int}|null
     */
    public static function dovecotQuota(string $email): ?array
    {
        if (!self::enabled()) {
            return null;
        }
        $out = [];
        $ret = 0;
        exec('sudo -n /usr/bin/doveadm -f flow quota get -u ' . escapeshellarg($email) . ' 2>/dev/null', $out, $ret);
        if ($ret !== 0) {
            return null;
        }
        $storage = null;
        $msgs    = 0;
        foreach ($out as $line) {
            if (str_contains($line, 'Type=STORAGE')) {
                preg_match('/Value=(\S+)/', $line, $v);
                preg_match('/Limit=(\S+)/', $line, $l);
                $usedKb  = isset($v[1]) && is_numeric($v[1]) ? (int) $v[1] : 0;
                $limitKb = isset($l[1]) && is_numeric($l[1]) ? (int) $l[1] : 0;
                $pct     = $limitKb > 0 ? min(100, (int) round($usedKb * 100 / max(1, $limitKb))) : 0;
                $storage = [
                    'used_kb'  => $usedKb,
                    'limit_kb' => $limitKb,
                    'used_mb'  => (int) round($usedKb / 1024),
                    'limit_mb' => $limitKb > 0 ? (int) round($limitKb / 1024) : 0,
                    'pct'      => $pct,
                ];
            } elseif (str_contains($line, 'Type=MESSAGE')) {
                preg_match('/Value=(\S+)/', $line, $v);
                $msgs = isset($v[1]) && is_numeric($v[1]) ? (int) $v[1] : 0;
            }
        }
        return $storage === null ? null : $storage + ['msgs' => $msgs];
    }

    /** @return array{available:bool,values:array<string,array<string,string>>} */
    public static function fail2banLoadConfig(): array
    {
        $file = self::jailFile();
        if (!is_readable($file)) {
            return ['available' => false, 'values' => []];
        }

        $sections = ['DEFAULT' => [], 'roundcube-auth' => [], 'dovecot' => [], 'postfix-sasl' => [], 'recidive' => []];
        $current  = null;

        foreach (file($file, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
            if (preg_match('/^\s*\[([^\]]+)\]/', $line, $m)) {
                $current = trim($m[1]);
                continue;
            }
            if (!isset($sections[$current])) {
                continue;
            }
            if (preg_match('/^\s*([A-Za-z0-9_.]+)\s*=\s*(.+?)\s*$/', $line, $m)) {
                $sections[$current][$m[1]] = $m[2];
            }
        }

        return ['available' => true, 'values' => $sections];
    }

    /** @throws \RuntimeException gdy plik nie jest zapisywalny */
    public static function fail2banSaveConfig(array $post): void
    {
        $file = self::jailFile();
        if (!is_writable($file)) {
            throw new \RuntimeException($file . ' nie jest zapisywalny (brak uprawnień www-data).');
        }

        $map = [
            'DEFAULT' => [
                'findtime'          => self::sanitize($post['f2b_default_findtime'] ?? '', 'time'),
                'bantime'           => self::sanitize($post['f2b_default_bantime'] ?? '', 'time'),
                'maxretry'          => self::sanitize($post['f2b_default_maxretry'] ?? '', 'int'),
                'bantime.increment' => !empty($post['f2b_default_bantime_increment']) ? 'true' : 'false',
                'bantime.factor'    => self::sanitize($post['f2b_default_bantime_factor'] ?? '', 'float'),
                'bantime.maxtime'   => self::sanitize($post['f2b_default_bantime_maxtime'] ?? '', 'time'),
                'ignoreip'          => self::sanitize($post['f2b_default_ignoreip'] ?? '', 'ip_list'),
            ],
            'roundcube-auth' => self::jailBlock($post, 'roundcube'),
            'dovecot'        => self::jailBlock($post, 'dovecot'),
            'postfix-sasl'   => self::jailBlock($post, 'postfix'),
            'recidive'       => self::jailBlock($post, 'recidive'),
        ];

        $lines   = file($file, FILE_IGNORE_NEW_LINES) ?: [];
        $current = null;
        foreach ($lines as $i => $line) {
            if (preg_match('/^\s*\[([^\]]+)\]/', $line, $m)) {
                $current = trim($m[1]);
                continue;
            }
            if (!isset($map[$current])) {
                continue;
            }
            if (preg_match('/^\s*([A-Za-z0-9_.]+)\s*=/', $line, $m)) {
                $key = $m[1];
                if (array_key_exists($key, $map[$current]) && $map[$current][$key] !== '') {
                    $lines[$i] = $key . ' = ' . $map[$current][$key];
                }
            }
        }

        @copy($file, $file . '.bak-' . date('YmdHis'));
        file_put_contents($file, implode(PHP_EOL, $lines) . PHP_EOL, LOCK_EX);
    }

    /** @return array<string,string> */
    private static function jailBlock(array $post, string $prefix): array
    {
        return [
            'enabled'  => !empty($post["f2b_{$prefix}_enabled"]) ? 'true' : 'false',
            'findtime' => self::sanitize($post["f2b_{$prefix}_findtime"] ?? '', 'time'),
            'maxretry' => self::sanitize($post["f2b_{$prefix}_maxretry"] ?? '', 'int'),
            'bantime'  => self::sanitize($post["f2b_{$prefix}_bantime"] ?? '', 'time'),
        ];
    }

    private static function sanitize(string $value, string $type): string
    {
        $value = trim(str_replace(["\r", "\n"], '', $value));

        return match ($type) {
            'int'   => (string) (int) $value,
            'float' => (string) min(10.0, max(1.0, (float) $value)),
            'time'  => self::sanitizeTime($value),
            'ip_list' => self::sanitizeIpList($value),
            default => preg_replace('/[^A-Za-z0-9.\- _]/', '', $value) ?? '',
        };
    }

    private static function sanitizeTime(string $value): string
    {
        if (preg_match('/^(\d+)([smhdw]?)$/', $value, $m)) {
            $num  = (int) $m[1];
            $unit = $m[2] ?: 's';
            $max  = ['s' => 31536000, 'm' => 525600, 'h' => 8760, 'd' => 365, 'w' => 52];
            return min($num, $max[$unit]) . $unit;
        }
        return '10m';
    }

    private static function sanitizeIpList(string $value): string
    {
        $valid = [];
        foreach (preg_split('/[\s,]+/', $value, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $ip) {
            $ip = trim($ip);
            if (filter_var($ip, FILTER_VALIDATE_IP)) {
                $valid[] = $ip;
            } elseif (str_contains($ip, '/')) {
                [$addr, $mask] = explode('/', $ip, 2);
                $isV4 = filter_var($addr, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) && (int) $mask <= 32;
                $isV6 = filter_var($addr, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) && (int) $mask <= 128;
                if ($isV4 || $isV6) {
                    $valid[] = $ip;
                }
            }
        }
        return implode(' ', $valid);
    }

    /* ---------------- Postfix ---------------- */

    public static function postfixAvailable(): bool
    {
        return self::cachedProbe('pf', 'sudo -n /usr/local/bin/update-postfix-limits.sh 2>&1', static function (array $out): bool {
            return str_contains(implode(' ', $out), 'Inputs must be integers');
        });
    }

    /** @return array{ok:bool,output:string} */
    public static function postfixApply(int $msgRate, int $connRate, int $connCount): array
    {
        if (!self::enabled()) {
            return ['ok' => false, 'output' => 'disabled'];
        }
        $out = [];
        $ret = 0;
        exec("sudo -n /usr/local/bin/update-postfix-limits.sh $msgRate $connRate $connCount 2>&1", $out, $ret);
        return ['ok' => $ret === 0, 'output' => implode(' ', $out)];
    }

    /* ---------------- Usuwanie skrzynek ---------------- */

    public static function mailboxDeleteAvailable(): bool
    {
        return self::cachedProbe('del', 'sudo -n /usr/local/bin/mailpanel-delete-user.sh 2>&1', static function (array $out): bool {
            return str_contains(implode(' ', $out), 'Invalid email format');
        });
    }

    /** @return array{ok:bool,code:int} */
    public static function mailboxDelete(string $email): array
    {
        if (!self::enabled()) {
            return ['ok' => false, 'code' => -1];
        }
        $out = [];
        $ret = 0;
        exec('sudo -n /usr/local/bin/mailpanel-delete-user.sh ' . escapeshellarg($email), $out, $ret);
        return ['ok' => $ret === 0, 'code' => $ret];
    }

    /* ---------------- Cache prób (5 min w sesji) ---------------- */

    private static function cachedProbe(string $key, string $cmd, callable $decide): bool
    {
        if (!self::enabled()) {
            return false;
        }
        $ck = "_probe_$key";
        $ts = "_probe_{$key}_ts";
        if (isset($_SESSION[$ck], $_SESSION[$ts]) && (time() - $_SESSION[$ts] < 300)) {
            return (bool) $_SESSION[$ck];
        }
        $out = [];
        $ret = 0;
        exec($cmd, $out, $ret);
        $ok = $decide($out, $ret);
        $_SESSION[$ck] = $ok;
        $_SESSION[$ts] = time();
        return $ok;
    }
}
