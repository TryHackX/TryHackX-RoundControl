<?php
declare(strict_types=1);

namespace App\Support;

/**
 * Nieudane logowania do syslogu (identyfikator "mailpanel", facility AUTH), żeby Fail2Ban mógł banować na
 * poziomie firewalla (filtr system/fail2ban/filter.d/mailpanel-portal.conf). Adres IP to wyłącznie REMOTE_ADDR
 * i stoi PIERWSZY w linii; wpisany przez klienta login jest zakodowany (rawurlencode) i stoi na końcu, więc nie da się
 * wstrzyknąć fałszywego "ip=" do dopasowania filtra.
 */
final class AuthLog
{
    public static function failure(string $kind, string $email): void
    {
        if (Env::isDev() || !function_exists('openlog') || !defined('LOG_AUTH')) {
            return;
        }
        $kind = $kind === 'admin' ? 'admin' : 'webmail';
        @openlog('mailpanel', LOG_PID, LOG_AUTH);
        @syslog(LOG_WARNING, sprintf(
            'auth-failure ip=%s kind=%s user=%s',
            Ip::raw(),
            $kind,
            rawurlencode(mb_substr($email, 0, 80))
        ));
        @closelog();
    }
}
