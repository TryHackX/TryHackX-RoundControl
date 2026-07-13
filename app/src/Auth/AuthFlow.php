<?php
declare(strict_types=1);

namespace App\Auth;

use App\Support\Ip;

/**
 * Stan wieloetapowego logowania trzymany w sesji:
 * identyfikacja (email) -> wybór usługi -> hasło.
 * Wiązany z IP i wygasający po AUTH_TIMEOUT sekundach bezczynności.
 */
final class AuthFlow
{
    private const KEY     = 'portal_auth_flow';
    private const TIMEOUT = 120;

    public function start(string $email, bool $isAdmin, bool $isUser): void
    {
        $_SESSION[self::KEY] = [
            'email'    => $email,
            'ip'       => Ip::raw(),
            'is_admin' => $isAdmin,
            'is_user'  => $isUser,
            'expires'  => time() + self::TIMEOUT,
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

    public function isAdmin(): bool
    {
        return !empty($_SESSION[self::KEY]['is_admin']);
    }

    public function clear(): void
    {
        unset($_SESSION[self::KEY]);
    }
}
