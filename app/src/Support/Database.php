<?php
declare(strict_types=1);

namespace App\Support;

use PDO;

/**
 * Fabryka pojedynczego połączenia PDO do bazy panelu.
 * Dane dostępowe pochodzą z .env (Env::load musi być wywołane wcześniej).
 */
final class Database
{
    private static ?PDO $pdo = null;

    public static function pdo(): PDO
    {
        if (self::$pdo instanceof PDO) {
            return self::$pdo;
        }

        self::$pdo = new PDO(
            (string) Env::get('PANEL_DB_DSN', ''),
            (string) Env::get('PANEL_DB_USER', 'root'),
            (string) Env::get('PANEL_DB_PASS', ''),
            [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]
        );

        return self::$pdo;
    }
}
