<?php
declare(strict_types=1);

namespace App\Security;

use App\Settings\Settings;

final class Password
{
    /** Czy hasło spełnia politykę z app_settings (długość + wymagane klasy znaków). */
    public static function meetsPolicy(string $password): bool
    {
        if (mb_strlen($password) < Settings::int('pwd_min_length', 10)) {
            return false;
        }
        if (Settings::bool('pwd_require_upper', true) && !preg_match('/[A-Z]/', $password)) {
            return false;
        }
        if (Settings::bool('pwd_require_digit', true) && !preg_match('/\d/', $password)) {
            return false;
        }
        if (Settings::bool('pwd_require_special', true) && !preg_match('/[^a-zA-Z0-9]/', $password)) {
            return false;
        }
        return true;
    }

    /** Hash SHA-512 crypt ($6$) zgodny z Dovecot dla tabeli virtual_users. */
    public static function dovecotHash(string $password): string
    {
        $salt = rtrim(strtr(base64_encode(random_bytes(12)), '+/', '._'), '=');
        return crypt($password, '$6$' . $salt . '$');
    }

    /** Weryfikacja hasła względem hasha $6$ (stałoczasowo). */
    public static function verifyDovecot(string $password, string $hash): bool
    {
        if ($hash === '') {
            return false;
        }
        return hash_equals($hash, crypt($password, $hash));
    }

    public static function adminHash(string $password): string
    {
        return password_hash($password, PASSWORD_ARGON2ID);
    }

    public static function verifyAdmin(string $password, string $hash): bool
    {
        return password_verify($password, $hash);
    }
}
