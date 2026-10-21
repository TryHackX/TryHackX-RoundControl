<?php
declare(strict_types=1);

namespace App\Security;

/**
 * Ochrona CSRF oparta na tokenie sesyjnym (per-sesja).
 * Wymaga aktywnej sesji PHP.
 */
final class Csrf
{
    public static function token(): string
    {
        if (empty($_SESSION['csrf'])) {
            $_SESSION['csrf'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['csrf'];
    }

    public static function check(?string $token): bool
    {
        return $token !== null
            && $token !== ''
            && !empty($_SESSION['csrf'])
            && hash_equals($_SESSION['csrf'], $token);
    }

    /** 1.4.0: a new token at a privilege change (sign-in), so nothing handed out before it stays valid after it. */
    public static function rotate(): void
    {
        unset($_SESSION['csrf']);
    }
}
