<?php
declare(strict_types=1);

namespace App\Auth;

use App\Admin\AdminRepository;
use App\Security\Password;
use App\Security\RateLimiter;
use App\Settings\Settings;
use App\Support\Ip;

/** Wynik próby logowania administratora. */
final class AdminResult
{
    public function __construct(
        public readonly bool $ok,
        public readonly ?int $id = null,
        public readonly string $error = ''
    ) {}
}

/**
 * Logowanie administratora z ochroną brute-force opartą na ip_blocks
 * i licznikiem nieudanych prób z admin_login_attempts.
 */
final class AdminAuthenticator
{
    public function __construct(
        private AdminRepository $admins,
        private RateLimiter $limiter
    ) {}

    public function attempt(string $email, string $password): AdminResult
    {
        $ipBin = Ip::bin();

        if ($this->limiter->ipBlockedUntil($ipBin) !== null) {
            return new AdminResult(false, null, 'IP zablokowane.');
        }

        $threshold = Settings::int('admin_lock_threshold', 5);
        $minutes   = Settings::int('admin_lock_minutes', 15);

        if ($this->limiter->adminFailCount($ipBin, $minutes) >= $threshold) {
            $this->limiter->banIp($ipBin, $minutes, 'Brute-force admin panel');
            $this->limiter->clearAdminFails($ipBin);
            return new AdminResult(false, null, "Zbyt wiele prób. IP zablokowane na $minutes minut.");
        }

        $row = $this->admins->findByEmail($email);
        if ($row && Password::verifyAdmin($password, $row['pwd_hash'])) {
            $this->admins->logAttempt($email, true, 'Portal login success', $ipBin);
            $this->admins->markLogin((int) $row['id']);
            return new AdminResult(true, (int) $row['id']);
        }

        $this->admins->logAttempt($email, false, 'Invalid password', $ipBin);
        return new AdminResult(false, null, 'Nieprawidłowe dane logowania');
    }
}
