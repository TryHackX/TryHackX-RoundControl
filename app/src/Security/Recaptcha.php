<?php
declare(strict_types=1);

namespace App\Security;

use App\Settings\Settings;
use App\Support\Ip;

final class Recaptcha
{
    private const VERIFY_URL = 'https://www.google.com/recaptcha/api/siteverify';
    private const TIMEOUT    = 5;

    /** Czy widżet ma być pokazany (włączony + jest site key). */
    public static function isEnabled(): bool
    {
        return Settings::bool('recaptcha_enabled', false)
            && (string) Settings::get('recaptcha_site_key', '') !== '';
    }

    public static function siteKey(): string
    {
        return (string) Settings::get('recaptcha_site_key', '');
    }

    /**
     * Weryfikacja tokenu — FAIL-CLOSED.
     * Gdy ochrona jest włączona, każdy błąd (brak sekretu, brak tokenu,
     * timeout, błędna odpowiedź) traktujemy jako niepowodzenie.
     */
    public static function verify(?string $token): bool
    {
        if (!Settings::bool('recaptcha_enabled', false)) {
            return true; // ochrona wyłączona globalnie
        }

        $secret = (string) Settings::get('recaptcha_secret', '');
        if ($secret === '' || $token === null || $token === '') {
            return false;
        }

        $context = stream_context_create([
            'http' => [
                'method'        => 'POST',
                'header'        => "Content-type: application/x-www-form-urlencoded\r\n",
                'content'       => http_build_query([
                    'secret'   => $secret,
                    'response' => $token,
                    'remoteip' => Ip::raw(),
                ]),
                'timeout'       => self::TIMEOUT,
                'ignore_errors' => true,
            ],
        ]);

        $response = @file_get_contents(self::VERIFY_URL, false, $context);
        if ($response === false) {
            return false;
        }

        $data = json_decode($response, true);
        return is_array($data) && !empty($data['success']);
    }
}
