<?php
declare(strict_types=1);

namespace App\Auth;

use App\Support\Ip;

/**
 * Stan wieloetapowego logowania trzymany w sesji: identyfikacja (email) -> hasło (+ wybór usługi).
 * Wiązany z IP i wygasający po AUTH_TIMEOUT sekundach bezczynności.
 *
 * 1.1.0: flow NIE wie, czy konto istnieje ani czy jest administratorem — serwer odpowiada na każdy poprawnie
 * zapisany adres tak samo, żeby nie dało się wyliczać kont ani ról.
 */
final class AuthFlow
{
    private const KEY     = 'portal_auth_flow';
    private const TIMEOUT = 120;

    public function start(string $email): void
    {
        $_SESSION[self::KEY] = [
            'email'   => $email,
            'ip'      => Ip::raw(),
            'expires' => time() + self::TIMEOUT,
        ];
    }

    public function valid(): bool
    {
        if (empty($_SESSION[self::KEY])) {
            return false;
        }
        $flow = $_SESSION[self::KEY];
        if (time() > $flow['expires'] || $flow['ip'] !== Ip::raw()) {
            $this->clear();
            return false;
        }
        $_SESSION[self::KEY]['expires'] = time() + self::TIMEOUT;
        return true;
    }

    public function email(): string
    {
        return $_SESSION[self::KEY]['email'] ?? '';
    }

    public function clear(): void
    {
        unset($_SESSION[self::KEY]);
    }
}
