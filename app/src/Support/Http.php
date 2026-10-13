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

    /**
     * Żądanie wysłane przez fetch() panelu/portalu (1.2.0): pole _ajax albo nagłówek X-Requested-With. Odpowiedź ma być
     * JSON-em — także odmowa (sesja wygasła, CSRF), zamiast przekierowania, którego fetch nie pokaże.
     */
    public static function isAjax(): bool
    {
        return isset($_POST['_ajax']) || isset($_POST['ajax_scan_ban'])
            || (string) ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') !== '';
    }

    /** isAjax() albo endpoint odczytu panelu (?ajax_...=1). */
    public static function wantsJson(): bool
    {
        if (self::isAjax()) {
            return true;
        }
        foreach (array_keys($_GET) as $k) {
            if (str_starts_with((string) $k, 'ajax_')) {
                return true;
            }
        }
        return false;
    }

    /** Wyślij odpowiedź JSON i zakończ żądanie. */
    public static function json(array $data): never
    {
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        exit;
    }

    // 1.1.0: secureSleep() (usleep 0.3-0.6 s po każdym błędzie) usunięte — trzymało wątek FPM, a na serwerze jedna
    // pula obsługuje wszystkie strony. Zamiast tego: szybka odmowa (RateLimiter) i stały czas weryfikacji hasła admina.
}
