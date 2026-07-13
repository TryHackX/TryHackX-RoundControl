<?php
declare(strict_types=1);

namespace App\Users;

use PDO;
use Throwable;

/** Dostęp do kont pocztowych (virtual_users) i domen (virtual_domains). */
final class UserRepository
{
    public function __construct(private PDO $pdo) {}

    public function exists(string $email): bool
    {
        $s = $this->pdo->prepare('SELECT 1 FROM virtual_users WHERE email = ?');
        $s->execute([$email]);
        return (bool) $s->fetchColumn();
    }

    public function passwordHash(string $email): ?string
    {
        $s = $this->pdo->prepare('SELECT password FROM virtual_users WHERE email = ?');
        $s->execute([$email]);
        $value = $s->fetchColumn();
        return $value === false ? null : (string) $value;
    }

    /** @return string[] nazwy domen */
    public function domains(): array
    {
        try {
            return $this->pdo->query('SELECT name FROM virtual_domains ORDER BY name')
                             ->fetchAll(PDO::FETCH_COLUMN) ?: [];
        } catch (Throwable $e) {
            return [];
        }
    }

    public function domainIdForEmail(string $email): ?int
    {
        $parts = explode('@', $email, 2);
        if (count($parts) !== 2) {
            return null;
        }
        $s = $this->pdo->prepare('SELECT id FROM virtual_domains WHERE name = ?');
        $s->execute([$parts[1]]);
        $id = $s->fetchColumn();
        return $id === false ? null : (int) $id;
    }

    public function create(int $domainId, string $passwordHash, string $email, int $quotaMb): void
    {
        $this->pdo->prepare(
            'INSERT INTO virtual_users (domain_id, password, email, quota_mb) VALUES (?, ?, ?, ?)'
        )->execute([$domainId, $passwordHash, $email, $quotaMb]);
    }
}
