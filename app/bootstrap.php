<?php
declare(strict_types=1);

/**
 * Fundament aplikacji panelu (Moduł M0).
 *
 * Odpowiada za: załadowanie .env, rejestrację autoloadera PSR-4 (App\ => app/src)
 * oraz ustawienie strefy czasowej. NIE inicjalizuje Roundcube (rcmail) —
 * punkty wejścia, które potrzebują IMAP, robią to samodzielnie.
 */

/** Wersja TryHackX RoundControl (pokazywana WYŁĄCZNIE zalogowanemu administratorowi — w stopce panelu). */
define('APP_VERSION', '1.4.0');
define('APP_ROOT', __DIR__);
define('PROJECT_ROOT', dirname(__DIR__));

spl_autoload_register(static function (string $class): void {
    $prefix = 'App\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }
    $relative = substr($class, strlen($prefix));
    $file     = APP_ROOT . '/src/' . str_replace('\\', '/', $relative) . '.php';
    if (is_file($file)) {
        require $file;
    }
});

// Funkcje tłumaczeń t() / h() / html() w przestrzeni nazw App\I18n (autoloader nie ładuje funkcji).
require APP_ROOT . '/src/I18n/functions.php';

\App\Support\Env::load(\App\Support\Env::defaultPath(PROJECT_ROOT));

date_default_timezone_set(\App\Support\Env::get('APP_TIMEZONE', 'Europe/Warsaw'));
