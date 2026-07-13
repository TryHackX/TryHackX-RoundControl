<?php
declare(strict_types=1);

namespace App\Settings;

use App\Support\Database;
use PDO;

/**
 * Dostęp do tabeli app_settings (klucz => wartość) z cache w obrębie żądania.
 * Wszystkie ustawienia ładowane są JEDNYM zapytaniem przy pierwszym użyciu,
 * co eliminuje kilkanaście osobnych SELECT-ów na stronę (problem z bootstrap.php).
 */
final class Settings
{
    /** @var array<string,string> */
    private static array $cache = [];
    private static bool $loaded = false;

    private static function loadAll(): void
    {
        if (self::$loaded) {
            return;
        }
        self::$loaded = true;

        $rows = Database::pdo()
            ->query('SELECT k, v FROM app_settings')
            ->fetchAll(PDO::FETCH_KEY_PAIR);

        self::$cache = $rows ?: [];
    }

    public static function get(string $key, ?string $default = null): ?string
    {
        self::loadAll();
        return array_key_exists($key, self::$cache) ? self::$cache[$key] : $default;
    }

    public static function int(string $key, int $default = 0): int
    {
        $value = self::get($key);
        return $value === null ? $default : (int) $value;
    }

    public static function bool(string $key, bool $default = false): bool
    {
        $value = self::get($key);
        if ($value === null) {
            return $default;
        }
        return $value === '1' || strtolower($value) === 'true';
    }

    public static function set(string $key, string $value): void
    {
        Database::pdo()
            ->prepare('INSERT INTO app_settings (k, v) VALUES (?, ?)
                       ON DUPLICATE KEY UPDATE v = VALUES(v)')
            ->execute([$key, $value]);

        self::$cache[$key] = $value;
    }

    /** Wymuś ponowne wczytanie z bazy (np. w testach). */
    public static function flush(): void
    {
        self::$cache  = [];
        self::$loaded = false;
    }
}
