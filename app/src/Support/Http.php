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

    /** Losowe opóźnienie utrudniające timing/enumerację (dawne secure_sleep). */
    public static function secureSleep(): void
    {
        usleep(random_int(300000, 600000));
    }
}
