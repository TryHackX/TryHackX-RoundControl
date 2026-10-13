<?php
declare(strict_types=1);

namespace App\Support;

/**
 * Nieudane logowania do syslogu (identyfikator "mailpanel", facility AUTH), żeby Fail2Ban mógł banować na
 * poziomie firewalla (filtr system/fail2ban/filter.d/mailpanel-portal.conf). Adres IP to wyłącznie REMOTE_ADDR
 * i stoi PIERWSZY w linii; wpisany przez klienta login jest zakodowany (rawurlencode) i stoi na końcu, więc nie da się
 * wstrzyknąć fałszywego "ip=" do dopasowania filtra.
 *
 * 1.2.0 — dwa rodzaje zdarzeń:
 *  - "auth-failure"   złe hasło w PORTALU (logowanie). Filtr mailpanel-portal je liczy (jail domyślnie wyłączony).
 *  - "reauth-failure" złe hasło administratora w oknie POTWIERDZENIA operacji w panelu (usuwanie konta, zmiana hasła
 *    administratora). Trafia do dziennika (journalctl -t mailpanel) i do dziennika audytowego panelu, ale filtr go
 *    celowo NIE liczy: to literówka już zalogowanego administratora, a zgadywanie hasła tą drogą zatrzymuje sam panel
 *    (sesja kończy się po PanelController::REAUTH_LIMIT błędach). Ban na firewallu za literówki odciąłby
 *    administratorowi panel i webmail na godzinę. Kto chce banować i za to — README, sekcja "Fail2Ban".
 */
final class AuthLog
{
    public static function failure(string $kind, string $email): void
    {
        self::write(self::line('auth-failure', $kind, $email, Ip::raw()));
    }

    /** Złe hasło administratora przy potwierdzaniu operacji w panelu (NIE liczone przez filtr Fail2Ban — patrz wyżej). */
    public static function reauthFailure(string $email): void
    {
        self::write(self::line('reauth-failure', 'admin', $email, Ip::raw()));
    }

    /** Treść linii (bez prefiksu syslogu) — osobno, żeby testy sprawdzały ją względem wyrażenia z filtra Fail2Ban. */
    public static function line(string $event, string $kind, string $email, string $ip): string
    {
        return sprintf(
            '%s ip=%s kind=%s user=%s',
            $event === 'reauth-failure' ? 'reauth-failure' : 'auth-failure',
            $ip,
            $kind === 'admin' ? 'admin' : 'webmail',
            rawurlencode(mb_substr($email, 0, 80))
        );
    }

    private static function write(string $line): void
    {
        if (Env::isDev() || !function_exists('openlog') || !defined('LOG_AUTH')) {
            return;
        }
        @openlog('mailpanel', LOG_PID, LOG_AUTH);
        @syslog(LOG_WARNING, $line);
        @closelog();
    }
}
