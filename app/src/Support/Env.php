<?php
declare(strict_types=1);

namespace App\Support;

/**
 * Minimalny loader plików .env (bez zależności zewnętrznych).
 * Wartości są dostępne przez Env::get(); nie trafiają do $_ENV/getenv,
 * więc nie wyciekną np. do phpinfo() czy logów procesu.
 */
final class Env
{
    /** @var array<string,string> */
    private static array $vars = [];
    private static bool $loaded = false;

    /**
     * Where the configuration lives. Preferred: /etc/mailpanel/panel.env (outside every web tree and outside the
     * code directory); fallback: <project>/.env. From the CLI only, MAILPANEL_ENV_FILE overrides both (tests).
     * 1.2.0: also PHP's built-in development server (SAPI cli-server, started by tests/http_test.php — never used in
     * production, where PHP runs as php-fpm), so the HTTP tests run the real entry points against a scratch database.
     */
    public static function defaultPath(string $projectRoot): string
    {
        if (PHP_SAPI === 'cli' || PHP_SAPI === 'cli-server') {
            $override = getenv('MAILPANEL_ENV_FILE');
            if (is_string($override) && $override !== '' && is_file($override)) {
                return $override;
            }
        }
        $outside = '/etc/mailpanel/panel.env';
        if (@is_file($outside) && @is_readable($outside)) {
            return $outside;
        }
        return $projectRoot . '/.env';
    }

    public static function load(string $path): void
    {
        if (self::$loaded) {
            return;
        }
        self::$loaded = true;

        if (!is_readable($path)) {
            return;
        }

        foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#' || !str_contains($line, '=')) {
                continue;
            }

            [$key, $value] = explode('=', $line, 2);
            $key   = trim($key);
            $value = trim($value);

            // Zdejmij otaczające cudzysłowy (pojedyncze lub podwójne).
            $len = strlen($value);
            if ($len >= 2
                && ($value[0] === '"' || $value[0] === "'")
                && $value[$len - 1] === $value[0]) {
                $value = substr($value, 1, -1);
            }

            self::$vars[$key] = $value;
        }
    }

    public static function get(string $key, ?string $default = null): ?string
    {
        return self::$vars[$key] ?? $default;
    }

    public static function bool(string $key, bool $default = false): bool
    {
        $value = self::$vars[$key] ?? null;
        if ($value === null) {
            return $default;
        }
        return in_array(strtolower($value), ['1', 'true', 'yes', 'on'], true);
    }

    public static function isDev(): bool
    {
        return strtolower(self::$vars['APP_ENV'] ?? 'prod') === 'dev';
    }
}
