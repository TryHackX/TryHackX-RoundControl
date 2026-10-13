<?php
declare(strict_types=1);

namespace App\Admin;

use App\Support\Database;
use App\Support\Http;
use App\Support\Session;
use Throwable;

use function App\I18n\t;

/**
 * Strażnik dostępu do panelu — wymaga aktywnej sesji administratora.
 *
 * Sesja kończy się, gdy: minęła godzina bez aktywności, minęło 12 godzin od zalogowania, konto admina przestało
 * istnieć albo zmieniono jego hasło (odcisk hasha zapisany w sesji przestaje pasować — zmiana hasła wylogowuje
 * wszystkie inne sesje tego admina).
 */
final class AdminGuard
{
    /** Limit bezczynności sesji administratora (sekundy). */
    private const IDLE_SECONDS = 3600;
    /** Bezwzględny czas życia sesji administratora (sekundy), niezależnie od aktywności. */
    private const MAX_SECONDS = 43200;

    /** @param bool $json true = endpoint AJAX: odpowiedź 403 JSON zamiast przekierowania */
    public static function ensure(bool $json = false): void
    {
        if (empty($_SESSION['admin_id']) || !self::stillValid()) {
            self::deny($json);
        }
        $_SESSION['admin_last_seen'] = time();
    }

    private static function stillValid(): bool
    {
        $now   = time();
        $last  = (int) ($_SESSION['admin_last_seen'] ?? $now);
        $since = (int) ($_SESSION['admin_login_at'] ?? 0);   // brak (sesja sprzed 1.1.0) = zaloguj się ponownie

        if ($now - $last > self::IDLE_SECONDS || $since === 0 || $now - $since > self::MAX_SECONDS) {
            return false;
        }
        $fp = (string) ($_SESSION['admin_fp'] ?? '');
        if ($fp === '') {
            return false;
        }
        try {
            $row = (new AdminRepository(Database::pdo()))->findById((int) $_SESSION['admin_id']);
        } catch (Throwable $e) {
            return false;   // bez bazy nie da się potwierdzić konta — fail-closed
        }
        return $row !== null && hash_equals($fp, hash('sha256', (string) $row['pwd_hash']));
    }

    private static function deny(bool $json): never
    {
        Session::destroy();
        if ($json) {
            // 1.2.0: zapytania fetch() dostają JSON z wyjaśnieniem (panel pokazuje go i link do logowania), nie stronę portalu
            http_response_code(403);
            Http::json(['ok' => false, 'auth' => true, 'error' => t('guard.session_expired')]);
        }
        header('Location: ../portal.php');
        exit;
    }

    public static function id(): int
    {
        return (int) ($_SESSION['admin_id'] ?? 0);
    }

    public static function username(): string
    {
        return (string) ($_SESSION['admin_username'] ?? '');
    }
}
