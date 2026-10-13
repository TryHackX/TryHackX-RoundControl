<?php
declare(strict_types=1);

namespace App\Support;

final class Http
{
    public static function method(): string
    {
        return $_SERVER['REQUEST_METHOD'] ?? 'GET';
    }

    public static function isPost(): bool
    {
        return self::method() === 'POST';
    }

    public static function post(string $key, ?string $default = null): ?string
    {
        $value = $_POST[$key] ?? null;
        return is_string($value) ? $value : $default;
    }

    public static function get(string $key, ?string $default = null): ?string
    {
        $value = $_GET[$key] ?? null;
        return is_string($value) ? $value : $default;
    }

    /** Wyślij odpowiedź JSON i zakończ żądanie. */
    public static function json(array $data): never
    {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($data, JSON_UNESCAPED_UNICODE);
        exit;
    }

    // 1.1.0: secureSleep() (usleep 0.3-0.6 s po każdym błędzie) usunięte — trzymało wątek FPM, a na serwerze jedna
    // pula obsługuje wszystkie strony. Zamiast tego: szybka odmowa (RateLimiter) i stały czas weryfikacji hasła admina.
}
