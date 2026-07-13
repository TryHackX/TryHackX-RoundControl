<?php
declare(strict_types=1);

namespace App\Admin;

use App\Support\Session;

/** Strażnik dostępu do panelu — wymaga aktywnej sesji administratora. */
final class AdminGuard
{
    /** Limit bezczynności sesji administratora (sekundy). */
    private const IDLE_SECONDS = 3600;

    public static function ensure(): void
    {
        if (empty($_SESSION['admin_id'])) {
            header('Location: ../portal.php');
            exit;
        }

        $last = (int) ($_SESSION['admin_last_seen'] ?? time());
        if (time() - $last > self::IDLE_SECONDS) {
            Session::destroy();
            header('Location: ../portal.php');
            exit;
        }
        $_SESSION['admin_last_seen'] = time();
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
