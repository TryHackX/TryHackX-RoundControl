<?php
declare(strict_types=1);

namespace App\Users;

/**
 * Rules for mailbox names, in one place: normalisation (everything is stored and compared in lower case — Dovecot
 * lower-cases user names, so the maildir is always lower case), the allowed shape of a local part and the reserved
 * names that public registration must never hand out.
 */
final class Accounts
{
    /** Local part: starts and ends with a letter/digit, [a-z0-9._-] inside, at most 64 characters, no "..". */
    public const LOCAL_RE = '/^[a-z0-9](?:[a-z0-9._-]{0,62}[a-z0-9])?$/D';

    /** The whole address must fit virtual_users.email (VARCHAR(100)). */
    public const MAX_EMAIL = 100;

    /**
     * Always reserved, whatever the setting says. The first five are the addresses CAs accept for e-mail domain
     * validation (CA/B Forum BR 3.2.2.4.4): whoever owns one of them can obtain a TLS certificate for the domain.
     * The rest: RFC 2142 / system / bounce-and-list-management names. Entries may use "*" as a wildcard.
     */
    public const BASE_RESERVED = [
        'admin', 'administrator', 'hostmaster', 'postmaster', 'webmaster',
        'abuse', 'security', 'noc', 'root', 'ssl-admin', 'nobody', 'daemon', 'mailer-daemon', 'mailerdaemon',
        'noreply', 'no-reply', 'donotreply', 'do-not-reply', 'bounce', 'bounces', 'dmarc', 'dmarc-reports',
        'www', 'ftp', 'news', 'usenet', 'uucp',
        'owner-*', '*-owner', '*-request', '*-bounces',
    ];

    /** Default of the configurable part (app_settings.reserved_local_parts): role mailboxes people write to. */
    public const DEFAULT_EXTRA = 'info,support,contact,sales,marketing,billing,privacy,legal,press,jobs,help,office,'
        . 'mail,team,service,services,staff,owner,moderator';

    public static function normalize(string $email): string
    {
        return strtolower(trim($email));
    }

    /** Shape of a local part (already lower case). */
    public static function validLocal(string $local): bool
    {
        return preg_match(self::LOCAL_RE, $local) === 1 && !str_contains($local, '..');
    }

    /**
     * Parse a comma / space / newline separated list into unique lower-case patterns of [a-z0-9*._-].
     *
     * @return string[]
     */
    public static function parseList(string $raw): array
    {
        $out = [];
        foreach (preg_split('/[\s,;]+/', strtolower($raw), -1, PREG_SPLIT_NO_EMPTY) ?: [] as $item) {
            if (preg_match('/^[a-z0-9*._-]{1,64}$/', $item) === 1) {
                $out[$item] = true;
            }
            if (count($out) >= 300) {
                break;
            }
        }
        return array_keys($out);
    }

    /** Is the (lower-case) local part reserved — by the built-in list or by the configurable one? */
    public static function isReserved(string $local, ?string $configured): bool
    {
        return self::reservedBy($local, $configured) !== null;
    }

    /**
     * Which list reserves the name, and by which entry: ['base'|'list', pattern] or null (1.2.0: the panel tells the
     * administrator WHY a name is reserved — a built-in role address or an entry of the editable list).
     *
     * @return array{0:string,1:string}|null
     */
    public static function reservedBy(string $local, ?string $configured): ?array
    {
        $lists = ['base' => self::BASE_RESERVED, 'list' => self::parseList($configured ?? self::DEFAULT_EXTRA)];
        foreach ($lists as $which => $patterns) {
            foreach ($patterns as $p) {
                if ($p === $local) {
                    return [$which, $p];
                }
                if (str_contains($p, '*')) {
                    $re = '/^' . str_replace('\*', '.*', preg_quote($p, '/')) . '$/D';
                    if (preg_match($re, $local) === 1) {
                        return [$which, $p];
                    }
                }
            }
        }
        return null;
    }

    /** Is $token one entry of an alias destination list ("a@x, b@x" / "a@x b@x")? Comparison in lower case. */
    public static function listContains(string $destination, string $email): bool
    {
        foreach (preg_split('/[\s,]+/', strtolower($destination), -1, PREG_SPLIT_NO_EMPTY) ?: [] as $token) {
            if ($token === $email) {
                return true;
            }
        }
        return false;
    }
}
