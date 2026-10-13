<?php
declare(strict_types=1);

namespace App\Support;

/**
 * Natywna, utwardzona sesja PHP dla portalu i panelu — niezależna od sesji
 * Roundcube. Dzięki temu panel admina można testować lokalnie bez IMAP,
 * a sesja administratora jest oddzielona od sesji webmaila.
 */
final class Session
{
    private const NAME = 'mailpanel_sess';

    public static function start(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }

        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        ini_set('session.use_trans_sid', '0');
        ini_set('session.cookie_httponly', '1');
        // Debian czyści sesje własnym cronem (phpsessionclean) wg php.ini — tu tylko spójna wartość dla wewnętrznego GC.
        ini_set('session.gc_maxlifetime', '7200');
        session_name(self::NAME);
        session_set_cookie_params([
            'lifetime' => 0,
            'path'     => '/',
            'domain'   => '',
            'secure'   => !Env::isDev(),   // po HTTPS na produkcji; HTTP lokalnie
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        session_start();
    }

    public static function regenerate(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }
    }

    public static function destroy(): void
    {
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $p = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
        }
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_destroy();
        }
    }
}
