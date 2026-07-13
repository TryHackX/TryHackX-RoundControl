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

    /** Przerwij żądanie POST, jeśli token jest nieprawidłowy. */
    public static function requirePost(): void
    {
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
            return;
        }
        if (!self::check($_POST['_csrf'] ?? null)) {
            http_response_code(400);
            exit('CSRF');
        }
    }
}
