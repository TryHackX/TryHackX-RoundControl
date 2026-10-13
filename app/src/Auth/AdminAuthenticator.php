<?php
declare(strict_types=1);

namespace App\Auth;

use App\Admin\AdminRepository;
use App\Security\Password;
use App\Security\RateLimiter;
use App\Support\AuthLog;
use App\Support\Ip;

/** Wynik próby logowania administratora. */
final class AdminResult
{
    public function __construct(
        public readonly bool $ok,
        public readonly ?int $id = null,
        public readonly string $error = '',
        public readonly string $pwdHash = ''
    ) {}
}

/**
 * Logowanie administratora. Limity (RateLimiter): para (konto, klient) i sam klient — nigdy samo konto, więc
 * obcy atakujący nie zablokuje administratora. Dla konta, które nie istnieje, hasło jest weryfikowane względem
 * stałego "atrapy" hasha, więc czas odpowiedzi nie zdradza, czy takie konto admina istnieje. Nieudane próby
 * prawdziwych kont admina zostają w admin_login_attempts (nigdy nie są kasowane).
 */
final class AdminAuthenticator
{
    /** Argon2id (te same parametry co prawdziwe hashe) hasła, którego nikt nie zna. */
    private const DUMMY_HASH = '$argon2id$v=19$m=65536,t=4,p=1$MzhGbDhMZVVya0F6NDh0NQ$m17wfOCv9qhOCRhWdzyw5LNGQtaF2PFSNfLBnXCcHKo';

    public function __construct(
        private AdminRepository $admins,
        private RateLimiter $limiter
    ) {}

    public function attempt(string $email, string $password): AdminResult
    {
        $ipBin = Ip::bin();

        $blocked = $this->limiter->loginBlocked($email, $ipBin);
        if ($blocked !== null) {
            return new AdminResult(false, null, $blocked);
        }

        $row   = $this->admins->findByEmail($email);
        $valid = Password::verifyAdmin($password, $row['pwd_hash'] ?? self::DUMMY_HASH) && $row !== null;

        if ($valid) {
            $this->admins->logAttempt($email, true, 'Portal login success', $ipBin);
            $this->admins->markLogin((int) $row['id']);
            return new AdminResult(true, (int) $row['id'], '', (string) $row['pwd_hash']);
        }

        if ($row !== null) {
            $this->admins->logAttempt($email, false, 'Invalid password', $ipBin);
        }
        $this->limiter->recordLoginFailure('admin', $email, $ipBin);
        AuthLog::failure('admin', $email);
        return new AdminResult(false, null, 'Nieprawidłowe dane logowania');
    }
}
