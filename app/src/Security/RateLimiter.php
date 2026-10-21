<?php
declare(strict_types=1);

namespace App\Security;

use App\Settings\Settings;
use App\Support\Ip;
use PDO;
use Throwable;

use function App\I18n\t;

/**
 * Limity prób: logowanie (admin / webmail), zapytania o dostępność nazwy, blokady IP (ip_blocks) i throttling rejestracji.
 *
 * 1.1.0 — zasady:
 *  - nieudane logowania trafiają do WŁASNEJ tabeli throttle_events (nie do user_login_events, które kasuje
 *    sp_register_login przy każdym udanym logowaniu z nowego IP — udane logowanie IMAP ofiary zerowało licznik
 *    atakującego) i nie są nigdy kasowane przy kolejnych próbach (admin_login_attempts to dziennik, zostaje cały);
 *  - blokada dotyczy PARY (konto, klient) albo samego klienta — NIGDY samego konta: dwa cudze IP nie mogą
 *    zablokować administratora dla wszystkich. "Klient" = adres IPv4 albo prefiks /64 dla IPv6 (Ip::key());
 *  - odmowa jest natychmiastowa (żadnego sleep(): wątek FPM nie jest wtedy zajęty);
 *  - adresy z SCAN_TRUSTED_IPS nie podlegają limitom.
 */
final class RateLimiter
{
    /** Rodzaje zdarzeń liczone jako nieudane logowanie. */
    private const LOGIN_KINDS = "'admin','webmail'";

    public function __construct(private PDO $pdo) {}

    private function windowMinutes(): int
    {
        return max(1, Settings::int('admin_lock_minutes', 15));
    }

    private function pairLimit(): int
    {
        return max(1, Settings::int('admin_lock_threshold', 5));
    }

    /** Ile nieudanych prób z jednego klienta (na wszystkie konta) w oknie — ochrona przed "password spraying". */
    private function clientLimit(): int
    {
        return max(20, $this->pairLimit() * 4);
    }

    /**
     * Czy to żądanie logowania ma być odrzucone od razu?
     *
     * @return ?string komunikat dla użytkownika albo null (wolno próbować)
     */
    public function loginBlocked(string $email, string $ipBin): ?string
    {
        if (Ip::isTrusted(Ip::toStr($ipBin) ?? '')) {
            return null;
        }
        $msg = t('rate.too_many_logins');

        if ($this->ipBlockedUntil($ipBin) !== null) {
            return t('rate.ip_blocked');
        }

        // Awaria samego limitera (np. brak tabeli / grantu w trakcie aktualizacji) nie może zamknąć logowania wszystkim,
        // także administratorowi: błąd idzie do logu serwera, a logowanie działa bez licznika (jak przed 1.1.0).
        try {
            $key = Ip::key($ipBin);
            $win = $this->windowMinutes();

            $s = $this->pdo->prepare(
                'SELECT COUNT(*) FROM throttle_events
                 WHERE kind IN (' . self::LOGIN_KINDS . ') AND email = ? AND ip = ?
                   AND created_at > NOW() - INTERVAL ? MINUTE'
            );
            $s->execute([mb_substr($email, 0, 190), $key, $win]);
            if ((int) $s->fetchColumn() >= $this->pairLimit()) {
                return $msg;
            }

            $s = $this->pdo->prepare(
                'SELECT COUNT(*) FROM throttle_events
                 WHERE kind IN (' . self::LOGIN_KINDS . ') AND ip = ?
                   AND created_at > NOW() - INTERVAL ? MINUTE'
            );
            $s->execute([$key, $win]);
            if ((int) $s->fetchColumn() >= $this->clientLimit()) {
                return $msg;
            }
        } catch (Throwable $e) {
            error_log('mailpanel: sign-in limiter unavailable (fail-open): ' . $e->getMessage());
        }

        return null;
    }

    /** Zapisz nieudane logowanie ($kind: admin | webmail). */
    public function recordLoginFailure(string $kind, string $email, string $ipBin): void
    {
        $this->event($kind === 'admin' ? 'admin' : 'webmail', $email, $ipBin);
    }

    /**
     * Zapytania o dostępność nazwy / próby rejestracji: limit na klienta (nie na sesję — nową sesję zakłada się
     * jednym żądaniem). Zapisuje zdarzenie i zwraca false, gdy limit przekroczony.
     */
    public function allowNameProbe(string $ipBin, int $perMinute = 30): bool
    {
        if (Ip::isTrusted(Ip::toStr($ipBin) ?? '')) {
            return true;
        }
        try {
            $s = $this->pdo->prepare(
                "SELECT COUNT(*) FROM throttle_events WHERE kind = 'probe' AND ip = ? AND created_at > NOW() - INTERVAL 1 MINUTE"
            );
            $s->execute([Ip::key($ipBin)]);
            if ((int) $s->fetchColumn() >= $perMinute) {
                return false;
            }
        } catch (Throwable $e) {
            error_log('mailpanel: name-check limiter unavailable (fail-open): ' . $e->getMessage());
            return true;
        }
        $this->event('probe', '', $ipBin);
        return true;
    }

    private function event(string $kind, string $email, string $ipBin): void
    {
        try {
            $this->pdo->prepare('INSERT INTO throttle_events (kind, email, ip, created_at) VALUES (?, ?, ?, NOW())')
                      ->execute([$kind, mb_substr($email, 0, 190), Ip::key($ipBin)]);
            if (random_int(1, 50) === 1) {
                $this->pdo->exec('DELETE FROM throttle_events WHERE created_at < NOW() - INTERVAL 1 DAY LIMIT 2000');
            }
        } catch (Throwable $e) {
            error_log('mailpanel: throttle_events: ' . $e->getMessage());
        }
    }

    /** Zwraca termin wygaśnięcia blokady IP (ip_blocks: ręczne bany z panelu) lub null. */
    public function ipBlockedUntil(string $ipBin): ?string
    {
        $s = $this->pdo->prepare('SELECT until_ts FROM ip_blocks WHERE ip = ? AND until_ts > NOW()');
        $s->execute([$ipBin]);
        $value = $s->fetchColumn();
        return $value === false ? null : (string) $value;
    }

    /**
     * Throttling rejestracji per klient (IPv4 / prefiks /64 IPv6).
     * @return array{ok:bool,msg:string,code:string}
     */
    public function registrationThrottle(): array
    {
        $ip = Ip::bin();

        if (Ip::isTrusted(Ip::raw())) {
            return ['ok' => true, 'msg' => '', 'code' => 'ok'];
        }
        if ($this->ipBlockedUntil($ip) !== null) {
            return ['ok' => false, 'msg' => t('rate.ip_blocked'), 'code' => 'blocked'];
        }

        $window     = Settings::int('ip_window_hours', 24) ?: 24;
        $maxPerWin  = Settings::int('ip_max_accounts', Settings::int('accounts_per_ip', 3));
        $minSeconds = Settings::int('register_min_interval_seconds', 20);
        $totalLimit = Settings::int('ip_total_limit', 0);
        [$lo, $hi]  = Ip::keyRange($ip);

        // 1.4.0: the age of the last registration comes from the database clock (NOW() wrote created_at); PHP's
        // strtotime() read it in APP_TIMEZONE, so with UTC on a Warsaw server the interval was 2 h longer than set.
        $s = $this->pdo->prepare(
            'SELECT COUNT(*), TIMESTAMPDIFF(SECOND, MAX(created_at), NOW()) FROM registration_events
             WHERE ip BETWEEN ? AND ? AND LENGTH(ip) = ? AND status = "success" AND created_at > NOW() - INTERVAL ? HOUR'
        );
        $s->execute([$lo, $hi, strlen($lo), $window]);
        $row   = $s->fetch(PDO::FETCH_NUM) ?: [0, null];
        $count = (int) $row[0];
        $age   = $row[1] === null ? null : (int) $row[1];

        if ($maxPerWin > 0 && $count >= $maxPerWin) {
            return ['ok' => false, 'msg' => t('rate.reg_window', ['hours' => $window]), 'code' => 'rate'];
        }
        if ($minSeconds > 0 && $age !== null && $age < $minSeconds) {
            return ['ok' => false, 'msg' => t('rate.reg_interval'), 'code' => 'interval'];
        }
        if ($totalLimit > 0) {
            $sa = $this->pdo->prepare(
                'SELECT COUNT(*) FROM registration_events WHERE ip BETWEEN ? AND ? AND LENGTH(ip) = ? AND status = "success"'
            );
            $sa->execute([$lo, $hi, strlen($lo)]);
            if ((int) $sa->fetchColumn() >= $totalLimit) {
                return ['ok' => false, 'msg' => t('rate.reg_total'), 'code' => 'total'];
            }
        }

        return ['ok' => true, 'msg' => '', 'code' => 'ok'];
    }

    /** Blokada na czas sprawdzania limitu + utworzenia konta (limity nie są wtedy omijalne równoległymi żądaniami). */
    public function lockRegistration(string $ipBin, int $waitSeconds = 5): bool
    {
        $name = 'mp_reg_' . bin2hex(Ip::key($ipBin));
        try {
            $s = $this->pdo->prepare('SELECT GET_LOCK(?, ?)');
            $s->execute([$name, $waitSeconds]);
            return (int) $s->fetchColumn() === 1;
        } catch (Throwable $e) {
            return false;
        }
    }

    public function unlockRegistration(string $ipBin): void
    {
        try {
            $s = $this->pdo->prepare('SELECT RELEASE_LOCK(?)');
            $s->execute(['mp_reg_' . bin2hex(Ip::key($ipBin))]);
            $s->fetchColumn();
        } catch (Throwable $e) {
            // blokada zniknie razem z połączeniem
        }
    }
}
