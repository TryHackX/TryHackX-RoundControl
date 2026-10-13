<?php
declare(strict_types=1);

namespace App\Support;

/**
 * Trwałe ciasteczka preferencji (ostatnio wybrana usługa, język) — jedne parametry dla wszystkich: cała witryna,
 * HttpOnly (JavaScript ich nie potrzebuje: wybór czyta PHP), SameSite=Lax, Secure poza trybem dev. Sesja ma własne
 * parametry w Session::start() (te same flagi, ale bez daty ważności).
 */
final class Cookie
{
    public static function set(string $name, string $value, int $ttlSeconds): void
    {
        if (headers_sent()) {
            return;
        }
        setcookie($name, $value, [
            'expires'  => time() + $ttlSeconds,
            'path'     => '/',
            'secure'   => !Env::isDev(),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        $_COOKIE[$name] = $value;
    }
}
