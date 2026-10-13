<?php
declare(strict_types=1);

namespace App\Users;

use PDO;
use Throwable;

/** Dostęp do kont pocztowych (virtual_users) i domen (virtual_domains). Adresy są zawsze w lower case. */
final class UserRepository
{
    public function __construct(private PDO $pdo) {}

    public function exists(string $email): bool
    {
        $s = $this->pdo->prepare('SELECT 1 FROM virtual_users WHERE email = ?');
        $s->execute([Accounts::normalize($email)]);
        return (bool) $s->fetchColumn();
    }

    public function passwordHash(string $email): ?string
    {
        $s = $this->pdo->prepare('SELECT password FROM virtual_users WHERE email = ?');
        $s->execute([Accounts::normalize($email)]);
        $value = $s->fetchColumn();
        return $value === false ? null : (string) $value;
    }

    /** @return string[] nazwy domen (lower case) */
    public function domains(): array
    {
        try {
            $rows = $this->pdo->query('SELECT name FROM virtual_domains ORDER BY name')
                              ->fetchAll(PDO::FETCH_COLUMN) ?: [];
            return array_map('strtolower', $rows);
        } catch (Throwable $e) {
            return [];
        }
    }

    public function domainIdForEmail(string $email): ?int
    {
        $parts = explode('@', Accounts::normalize($email), 2);
        if (count($parts) !== 2) {
            return null;
        }
        $s = $this->pdo->prepare('SELECT id FROM virtual_domains WHERE name = ?');
        $s->execute([$parts[1]]);
        $id = $s->fetchColumn();
        return $id === false ? null : (int) $id;
    }

    /**
     * Does a virtual alias already claim this address — as its SOURCE (mail to it goes elsewhere, but whoever owns
     * a mailbox of that name could send as it) or as a DESTINATION (a leftover alias that would forward somebody
     * else's mail to the new owner)?
     */
    public function aliasReferences(string $email): bool
    {
        $email = Accounts::normalize($email);

        $s = $this->pdo->prepare('SELECT 1 FROM virtual_aliases WHERE source = ? LIMIT 1');
        $s->execute([$email]);
        if ($s->fetchColumn()) {
            return true;
        }
        return $this->aliasesPointingTo($email) !== [];
    }

    /** Is this address the SOURCE of some alias? (mail to it never reaches a mailbox of that name) */
    public function aliasSourceExists(string $email): bool
    {
        $s = $this->pdo->prepare('SELECT 1 FROM virtual_aliases WHERE source = ? LIMIT 1');
        $s->execute([Accounts::normalize($email)]);
        return (bool) $s->fetchColumn();
    }

    /**
     * Aliases whose destination is this address.
     *
     * exact = the destination is exactly this address; otherwise it is a list that merely contains it.
     *
     * @return array<int,array{id:int,source:string,destination:string,exact:bool}>
     */
    public function aliasesPointingTo(string $email): array
    {
        $email = Accounts::normalize($email);
        $like  = '%' . addcslashes($email, '\\%_') . '%';
        $s = $this->pdo->prepare('SELECT id, source, destination FROM virtual_aliases WHERE destination LIKE ?');
        $s->execute([$like]);

        $rows = [];
        foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $r) {
            if (!Accounts::listContains((string) $r['destination'], $email)) {
                continue;
            }
            $rows[] = [
                'id'          => (int) $r['id'],
                'source'      => (string) $r['source'],
                'destination' => (string) $r['destination'],
                'exact'       => Accounts::normalize((string) $r['destination']) === $email,
            ];
        }
        return $rows;
    }

    /** Was this address deleted within the last $days days? (0 = the hold is switched off) */
    public function recentlyDeleted(string $email, int $days): bool
    {
        if ($days <= 0) {
            return false;
        }
        $s = $this->pdo->prepare('SELECT 1 FROM deleted_accounts WHERE email = ? AND deleted_at > NOW() - INTERVAL ? DAY');
        $s->execute([Accounts::normalize($email), $days]);
        return (bool) $s->fetchColumn();
    }

    public function create(int $domainId, string $passwordHash, string $email, int $quotaMb): void
    {
        $this->pdo->prepare(
            'INSERT INTO virtual_users (domain_id, password, email, quota_mb) VALUES (?, ?, ?, ?)'
        )->execute([$domainId, $passwordHash, Accounts::normalize($email), $quotaMb]);
    }
}
