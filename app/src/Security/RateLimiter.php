<?php
declare(strict_types=1);

namespace App\Security;

use App\Settings\Settings;
use App\Support\Ip;
use PDO;
use Throwable;

/**
 * Ograniczanie prób: logowanie (email/IP), blokady admina (ip_blocks),
 * oraz throttling rejestracji. Cała logika czasowa liczona po stronie bazy.
 */
final class RateLimiter
{
    public function __construct(private PDO $pdo) {}

    /** Miękki limit na etapie identyfikacji (po nazwie użytkownika). */
    public function isLoginRateLimited(string $email): bool
    {
        try {
            $s = $this->pdo->prepare(
                'SELECT COUNT(*) FROM admin_login_attempts
                 WHERE username = ? AND success = 0 AND created_at > NOW() - INTERVAL 15 MINUTE'
            );
            $s->execute([$email]);
            if ((int) $s->fetchColumn() >= 10) {
                return true;
            }

            $s2 = $this->pdo->prepare(
                'SELECT COUNT(*) FROM user_login_events
                 WHERE email = ? AND created_at > NOW() - INTERVAL 15 MINUTE'
            );
            $s2->execute([$email]);
            if ((int) $s2->fetchColumn() >= 15) {
                return true;
            }
        } catch (Throwable $e) {
            return false;
        }

        return false;
    }

    /** Zwraca termin wygaśnięcia blokady IP lub null, gdy IP nie jest zablokowane. */
    public function ipBlockedUntil(string $ipBin): ?string
    {
        $s = $this->pdo->prepare('SELECT until_ts FROM ip_blocks WHERE ip = ? AND until_ts > NOW()');
        $s->execute([$ipBin]);
        $value = $s->fetchColumn();
        return $value === false ? null : (string) $value;
    }

    public function adminFailCount(string $ipBin, int $minutes): int
    {
        $s = $this->pdo->prepare(
            'SELECT COUNT(*) FROM admin_login_attempts
             WHERE ip = ? AND success = 0 AND created_at > NOW() - INTERVAL ? MINUTE'
        );
        $s->execute([$ipBin, $minutes]);
        return (int) $s->fetchColumn();
    }

    public function banIp(string $ipBin, int $minutes, string $reason): void
    {
        $s = $this->pdo->prepare(
            'INSERT INTO ip_blocks (ip, until_ts, reason)
             VALUES (?, NOW() + INTERVAL ? MINUTE, ?)
             ON DUPLICATE KEY UPDATE until_ts = VALUES(until_ts), reason = VALUES(reason)'
        );
        $s->execute([$ipBin, $minutes, $reason]);
    }

    public function clearAdminFails(string $ipBin): void
    {
        $this->pdo->prepare('DELETE FROM admin_login_attempts WHERE ip = ? AND success = 0')
                  ->execute([$ipBin]);
    }

    public function recordUserLoginFail(string $email, string $ipBin): void
    {
        try {
            $this->pdo->prepare('INSERT INTO user_login_events (email, ip, created_at) VALUES (?, ?, NOW())')
                      ->execute([$email, $ipBin]);
        } catch (Throwable $e) {
            // brak logowania nie może blokować logiki
        }
    }

    /**
     * Throttling rejestracji per-IP.
     * @return array{ok:bool,msg:string,code:string}
     */
    public function registrationThrottle(): array
    {
        $ip = Ip::bin();

        if ($this->ipBlockedUntil($ip) !== null) {
            return ['ok' => false, 'msg' => 'Twoje IP jest zablokowane.', 'code' => 'blocked'];
        }

        $window     = Settings::int('ip_window_hours', 24) ?: 24;
        $maxPerWin  = Settings::int('ip_max_accounts', Settings::int('accounts_per_ip', 3));
        $minSeconds = Settings::int('register_min_interval_seconds', 20);
        $totalLimit = Settings::int('ip_total_limit', 0);

        $s = $this->pdo->prepare(
            'SELECT COUNT(*), MAX(created_at) FROM registration_events
             WHERE ip = ? AND status = "success" AND created_at > NOW() - INTERVAL ? HOUR'
        );
        $s->execute([$ip, $window]);
        $row  = $s->fetch(PDO::FETCH_NUM) ?: [0, null];
        $count = (int) $row[0];
        $last  = $row[1] ? strtotime((string) $row[1]) : false;

        if ($maxPerWin > 0 && $count >= $maxPerWin) {
            return ['ok' => false, 'msg' => "Limit rejestracji ($window h).", 'code' => 'rate'];
        }
        if ($minSeconds > 0 && $last !== false && (time() - $last) < $minSeconds) {
            return ['ok' => false, 'msg' => 'Zbyt szybko.', 'code' => 'interval'];
        }
        if ($totalLimit > 0) {
            $sa = $this->pdo->prepare('SELECT COUNT(*) FROM registration_events WHERE ip = ? AND status = "success"');
            $sa->execute([$ip]);
            if ((int) $sa->fetchColumn() >= $totalLimit) {
                return ['ok' => false, 'msg' => 'Limit całkowity IP.', 'code' => 'total'];
            }
        }

        return ['ok' => true, 'msg' => '', 'code' => 'ok'];
    }
}
