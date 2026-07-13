<?php
declare(strict_types=1);

namespace App\Admin;

use PDO;

/** Dostęp do kont administratorów panelu (panel_admins) i logu prób. */
final class AdminRepository
{
    public function __construct(private PDO $pdo) {}

    public function isAdminEmail(string $email): bool
    {
        $s = $this->pdo->prepare('SELECT 1 FROM panel_admins WHERE email = ?');
        $s->execute([$email]);
        return (bool) $s->fetchColumn();
    }

    /** @return array{id:int,pwd_hash:string}|null */
    public function findByEmail(string $email): ?array
    {
        $s = $this->pdo->prepare('SELECT id, pwd_hash FROM panel_admins WHERE email = ?');
        $s->execute([$email]);
        $row = $s->fetch();
        return $row ?: null;
    }

    /** @return array{id:int,pwd_hash:string}|null */
    public function findById(int $id): ?array
    {
        $s = $this->pdo->prepare('SELECT id, pwd_hash FROM panel_admins WHERE id = ?');
        $s->execute([$id]);
        $row = $s->fetch();
        return $row ?: null;
    }

    public function updatePassword(int $id, string $hash): void
    {
        $this->pdo->prepare('UPDATE panel_admins SET pwd_hash = ? WHERE id = ?')->execute([$hash, $id]);
    }

    public function markLogin(int $id): void
    {
        $this->pdo->prepare('UPDATE panel_admins SET last_login = NOW() WHERE id = ?')->execute([$id]);
    }

    public function logAttempt(string $email, bool $success, ?string $details, string $ipBin): void
    {
        $this->pdo->prepare(
            'INSERT INTO admin_login_attempts (ip, username, success, details, created_at)
             VALUES (?, ?, ?, ?, NOW())'
        )->execute([$ipBin, $email, $success ? 1 : 0, $details]);
    }
}
