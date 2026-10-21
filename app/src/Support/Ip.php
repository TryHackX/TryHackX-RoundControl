<?php
declare(strict_types=1);

namespace App\Support;

final class Ip
{
    /**
     * The client's address. 1.3.3: an IPv4 client that a dual-stack socket reports as ::ffff:a.b.c.d is returned as
     * a.b.c.d, so one client has one form everywhere — in the stored rows (the registration limit counted 0 of such a
     * client's own registrations), in the trusted-address list and in the Fail2Ban log line (nftables bans the IPv4).
     */
    public static function raw(): string
    {
        $ip  = (string) ($_SERVER['REMOTE_ADDR'] ?? '127.0.0.1');
        $bin = @inet_pton($ip);
        if ($bin !== false && strlen($bin) === 16 && substr($bin, 0, 12) === "\0\0\0\0\0\0\0\0\0\0\xff\xff") {
            return (string) inet_ntop(substr($bin, 12));
        }
        return $ip;
    }

    /** Adres klienta jako VARBINARY(16) do zapisu w bazie. */
    public static function bin(): string
    {
        $bin = @inet_pton(self::raw());
        return $bin !== false ? $bin : inet_pton('::');
    }

    public static function toBin(string $ip): ?string
    {
        $bin = @inet_pton($ip);
        return $bin === false ? null : $bin;
    }

    public static function toStr(string $bin): ?string
    {
        $str = @inet_ntop($bin);
        return $str === false ? null : $str;
    }

    /**
     * Throttling key (VARBINARY(16)): an IPv4 address as it is, an IPv6 address reduced to its /64 prefix
     * (padded with zeros to 16 bytes), IPv4-mapped IPv6 (::ffff:a.b.c.d) as the IPv4 address. One IPv6 /64 is what a
     * single subscriber typically owns, so per-address limits are meaningless for IPv6.
     */
    public static function key(string $bin): string
    {
        if (strlen($bin) === 16) {
            if (substr($bin, 0, 12) === "\0\0\0\0\0\0\0\0\0\0\xff\xff") {
                return substr($bin, 12);
            }
            return substr($bin, 0, 8) . str_repeat("\0", 8);
        }
        return $bin;
    }

    /**
     * Inclusive [low, high] range of VARBINARY(16) values that belong to the same throttling key as $bin — for a
     * BETWEEN query against columns that hold full addresses (registration_events.ip). IPv4 is a single value.
     *
     * @return array{0:string,1:string}
     */
    public static function keyRange(string $bin): array
    {
        $k = self::key($bin);
        if (strlen($k) === 16) {
            return [substr($k, 0, 8) . str_repeat("\0", 8), substr($k, 0, 8) . str_repeat("\xff", 8)];
        }
        return [$k, $k];
    }

    /** Does $ip (text) fall inside $cidr (text, "1.2.3.4", "1.2.3.0/24", "2001:db8::/32")? */
    public static function inCidr(string $ip, string $cidr): bool
    {
        $addr = self::toBin($ip);
        if ($addr === null) {
            return false;
        }
        $parts = explode('/', $cidr, 2);
        $net   = self::toBin(trim($parts[0]));
        if ($net === null || strlen($net) !== strlen($addr)) {
            return false;
        }
        $bits = strlen($net) * 8;
        if (isset($parts[1])) {
            if (!ctype_digit($parts[1])) {
                return false;
            }
            $bits = min((int) $parts[1], $bits);
        }
        $full = intdiv($bits, 8);
        if ($full > 0 && substr($addr, 0, $full) !== substr($net, 0, $full)) {
            return false;
        }
        $rest = $bits % 8;
        if ($rest === 0) {
            return true;
        }
        $mask = (0xFF << (8 - $rest)) & 0xFF;
        return ((ord($addr[$full]) ^ ord($net[$full])) & $mask) === 0;
    }

    /**
     * Addresses that are never rate-limited by the portal: SCAN_TRUSTED_IPS from the environment file (exact
     * addresses and CIDR ranges, comma separated). Deliberately WITHOUT loopback: behind a local reverse proxy every
     * client would look like 127.0.0.1.
     *
     * @return string[]
     */
    public static function trustedFromEnv(): array
    {
        return array_values(array_filter(array_map('trim', explode(',', (string) Env::get('SCAN_TRUSTED_IPS', '')))));
    }

    public static function isTrusted(string $ip): bool
    {
        foreach (self::trustedFromEnv() as $entry) {
            if (self::inCidr($ip, $entry)) {
                return true;
            }
        }
        return false;
    }
}
