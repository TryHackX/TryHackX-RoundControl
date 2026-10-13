<?php
declare(strict_types=1);

namespace App\System;

use App\Support\Env;

use function App\I18n\t;

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

    // 1.1.0: fail2banReload() usunięte — reload robi helper mailpanel-f2b-apply po `fail2ban-client -t` i zapisie,
    // a reguła sudoers `fail2ban-client reload` przestała być potrzebna (mniej uprawnień dla www-data).

    /** Nazwa jaila i adres IP w postaci, którą przepuszcza reguła sudoers (to samo sprawdza sudo — tu wcześniej i czytelniej). */
    private static function jailArgsOk(string $jail, string $ip): bool
    {
        return preg_match('/^[A-Za-z0-9_-]+$/', $jail) === 1 && filter_var($ip, FILTER_VALIDATE_IP) !== false;
    }

    public static function fail2banUnban(string $jail, string $ip): bool
    {
        if (!self::enabled() || !self::jailArgsOk($jail, $ip)) {
            return false;
        }
        $out = [];
        $ret = 0;
        exec('sudo fail2ban-client set ' . escapeshellarg($jail) . ' unbanip ' . escapeshellarg($ip), $out, $ret);
        return $ret === 0;
    }

    /** Ręczne zablokowanie IP w wybranym jailu (reguła sudoers: set <jail> banip <ip>, dokładne argumenty). */
    public static function fail2banBan(string $jail, string $ip): bool
    {
        if (!self::enabled() || !self::jailArgsOk($jail, $ip)) {
            return false;
        }
        $out = [];
        $ret = 0;
        exec('sudo fail2ban-client set ' . escapeshellarg($jail) . ' banip ' . escapeshellarg($ip), $out, $ret);
        return $ret === 0;
    }

    /**
     * Zmiana bieżącego bantime jaila panelu `panel-tempban` (reguła sudoers: set panel-tempban bantime <sekundy>).
     * Ban czasowy z panelu = ustaw bantime, potem banip — f2b przypisuje czas obowiązujący w momencie bana, więc każdy
     * ban ma własny okres. Wyłącznie ten jail: reguła sudoers nie pozwala zmieniać bantime sshd / recidive itd.
     */
    public static function fail2banSetBantime(string $jail, int $seconds): bool
    {
        if (!self::enabled() || $jail !== 'panel-tempban' || $seconds < 60 || $seconds > 7776000) {
            return false;
        }
        $out = [];
        $ret = 0;
        exec('sudo -n fail2ban-client set panel-tempban bantime ' . $seconds . ' 2>/dev/null', $out, $ret);
        return $ret === 0;
    }

    /**
     * Uruchamia polecenie i podaje $stdin na jego standardowe wejście (dane nie przechodzą przez plik ani przez
     * linię poleceń). Polecenie jest STAŁE — nigdy nie zawiera danych użytkownika. stderr dołącz sam w poleceniu
     * (`2>&1`); wyjście jest czytane po zamknięciu wejścia, a proponowany plik jest mniejszy niż bufor potoku,
     * więc zapis nie blokuje się na dziecku, które jeszcze czyta.
     *
     * @return array{code:int,out:string}  code = -1, gdy nie udało się uruchomić
     */
    public static function pipeTo(string $cmd, string $stdin): array
    {
        $proc = @proc_open($cmd, [0 => ['pipe', 'r'], 1 => ['pipe', 'w']], $pipes);
        if (!is_resource($proc)) {
            return ['code' => -1, 'out' => t('cmd.proc_open_failed')];
        }
        $written = @fwrite($pipes[0], $stdin);
        fclose($pipes[0]);
        $out = (string) stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        $code = proc_close($proc);
        if ($written === false || $written < strlen($stdin)) {
            return ['code' => $code === 0 ? -1 : $code, 'out' => trim($out . ' (' . t('cmd.proposal_incomplete') . ')')];
        }
        return ['code' => $code, 'out' => $out];
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

    /**
     * @return string komunikat helpera ("unchanged" albo "saved and reloaded (N changes)")
     * @throws \RuntimeException gdy helper odrzucił propozycję albo nie udało się zapisać / sprawdzić / przeładować
     */
    public static function fail2banSaveConfig(array $post): string
    {
        $file = self::jailFile();

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

        // 2026-10-13: jail.local belongs to root. www-data writing it was a way to root (fail2ban runs as root and a
        // jail may name any action). The panel PROPOSES the file; mailpanel-f2b-apply (root, through sudo) checks that
        // only the values of the options edited here changed, each well-formed, installs it, tests it and reloads fail2ban.
        //
        // 1.1.0: the proposal travels on the helper's STANDARD INPUT (no proposal file, no /var/lib/mailpanel-f2b
        // directory that www-data could swap under the helper), and the helper writes jail.local IN PLACE — see the
        // header of scripts/mailpanel-f2b-apply.php. Exit 0 = installed (or nothing to change); non-zero = the helper's
        // own message says what it refused or what failed; it never reports success it did not verify.
        $r = self::pipeTo('sudo -n /usr/local/sbin/mailpanel-f2b-apply 2>&1', implode("\n", $lines) . "\n");
        if ($r['code'] !== 0) {
            // kod wyjścia helpera -> przetłumaczony opis; szczegół techniczny helpera (po angielsku) dołączony
            $key = match ($r['code']) { 1 => 'cmd.f2b_refused', 2 => 'cmd.f2b_reload_failed', 3 => 'cmd.f2b_write_failed', default => '' };
            throw new \RuntimeException($key !== ''
                ? t($key, ['out' => trim($r['out'])])
                : ($r['code'] > 0
                    ? t('cmd.f2b_failed', ['out' => trim($r['out']), 'code' => $r['code']])
                    : t('cmd.f2b_failed_nocode', ['out' => trim($r['out'])])));
        }
        return trim($r['out']);
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
            'int'   => (string) max(0, min(999999, (int) $value)),
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

    /**
     * Kody wyjścia skryptu mailpanel-delete-user.sh:
     * 0 = przeniesiono do kwarantanny, 1 = zły format argumentu, 2 = niedozwolone znaki, 3 = ścieżka poza katalogiem
     * poczty, 4 = nie ma takiego katalogu poczty, 5 = przeniesienie się nie powiodło, 6 = konto nadal istnieje
     * w Dovecot (odmowa), 7 = nie można tego sprawdzić (odmowa), 8 = nie można przygotować kwarantanny.
     *
     * @return array{ok:bool,code:int,out:string}
     */
    public static function mailboxDelete(string $email): array
    {
        if (!self::enabled()) {
            return ['ok' => false, 'code' => -1, 'out' => ''];
        }
        $out = [];
        $ret = 0;
        exec('sudo -n /usr/local/bin/mailpanel-delete-user.sh ' . escapeshellarg(strtolower(trim($email))) . ' 2>&1', $out, $ret);
        return ['ok' => $ret === 0, 'code' => $ret, 'out' => implode(' ', $out)];
    }

    /**
     * Czytelny opis kodu wyjścia mailboxDelete() dla administratora. Od 1.1.0 skrzynka NIE jest kasowana od razu:
     * trafia do kwarantanny /var/mail/deleted/<domena>__<nazwa>__<RRRRmmddGGMMSS>, którą timer
     * mailpanel-purge-deleted czyści po 30 dniach (do tego czasu katalog da się odzyskać ręcznie).
     */
    public static function mailboxDeleteMessage(int $code): string
    {
        return match ($code) {
            0       => t('cmd.mailbox_moved'),
            4       => t('cmd.mailbox_no_dir'),
            6       => t('cmd.mailbox_still_in_dovecot'),
            7       => t('cmd.mailbox_dovecot_unchecked'),
            -1      => t('cmd.mailbox_not_moved_no_sudo'),
            default => t('cmd.mailbox_not_moved', ['code' => $code]),
        };
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
