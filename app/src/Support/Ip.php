<?php
declare(strict_types=1);

namespace App\Support;

final class Ip
{
    public static function raw(): string
    {
        return $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
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
}
