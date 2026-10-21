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

    /**
     * Czy $password jest hasłem tej skrzynki według hasha w virtual_users? (1.2.0: logowanie administratora — czy kafelek
     * "Webmail" ma szansę zadziałać tym samym hasłem; o samym wejściu decyduje i tak logowanie IMAP.)
     *
     * @return ?bool true / false; null = nie da się tego ustalić z hasha (schemat inny niż *-CRYPT, np. ARGON2ID)
     */
    public function passwordMatches(string $email, string $password): ?bool
    {
        $hash = $this->passwordHash($email);
        if ($hash === null || $hash === '') {
            return false;
        }
        if (preg_match('/^\{([A-Z0-9.-]+)\}(.*)$/s', $hash, $m) === 1) {
            if (!in_array($m[1], ['SHA512-CRYPT', 'SHA256-CRYPT', 'BLF-CRYPT', 'MD5-CRYPT', 'CRYPT'], true)) {
                return null;
            }
            $hash = $m[2];
        }
        if (preg_match('/^\$(?:1|2[abxy]|5|6)\$/', $hash) !== 1) {
            return null;
        }
        return hash_equals($hash, crypt($password, $hash));
    }

    /** Wiersze aliasów, których ŹRÓDŁEM jest ten adres (zwykle jeden). @return array<int,array{id:int,source:string,destination:string}> */
    public function aliasesFrom(string $email): array
    {
        $s = $this->pdo->prepare('SELECT id, source, destination FROM virtual_aliases WHERE source = ? ORDER BY id');
        $s->execute([Accounts::normalize($email)]);
        return array_map(static fn(array $r): array => [
            'id' => (int) $r['id'], 'source' => (string) $r['source'], 'destination' => (string) $r['destination'],
        ], $s->fetchAll(PDO::FETCH_ASSOC));
    }

    /** @return array{id:int,source:string,destination:string}|null */
    public function aliasById(int $id): ?array
    {
        $s = $this->pdo->prepare('SELECT id, source, destination FROM virtual_aliases WHERE id = ?');
        $s->execute([$id]);
        $r = $s->fetch(PDO::FETCH_ASSOC);
        return $r ? ['id' => (int) $r['id'], 'source' => (string) $r['source'], 'destination' => (string) $r['destination']] : null;
    }

    public function deleteAlias(int $id): void
    {
        $this->pdo->prepare('DELETE FROM virtual_aliases WHERE id = ?')->execute([$id]);
    }

    /** Kiedy usunięto to konto (nagrobek), jeśli w ciągu ostatnich $days dni; null = nie usunięto / blokada wyłączona. */
    public function deletedAt(string $email, int $days): ?string
    {
        if ($days <= 0) {
            return null;
        }
        $s = $this->pdo->prepare('SELECT deleted_at FROM deleted_accounts WHERE email = ? AND deleted_at > NOW() - INTERVAL ? DAY');
        $s->execute([Accounts::normalize($email), $days]);
        $v = $s->fetchColumn();
        return $v === false ? null : (string) $v;
    }

    /**
     * 1.4.0: were this address's mailbox files left on disk when it was deleted (the move to the quarantine did not
     * happen)? Then the name is not given out again, whatever the hold period says: the new owner would get the old mail.
     */
    public function filesLeft(string $email): bool
    {
        $s = $this->pdo->prepare('SELECT files_left FROM deleted_accounts WHERE email = ?');
        $s->execute([Accounts::normalize($email)]);
        return (int) $s->fetchColumn() === 1;
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

    /** $sendLimit: the account's own daily send limit (null = the global one, 0 = none) — 1.4.0: in the same INSERT. */
    public function create(int $domainId, string $passwordHash, string $email, int $quotaMb, ?int $sendLimit = null): void
    {
        $this->pdo->prepare(
            'INSERT INTO virtual_users (domain_id, password, email, quota_mb, daily_send_limit) VALUES (?, ?, ?, ?, ?)'
        )->execute([$domainId, $passwordHash, Accounts::normalize($email), $quotaMb, $sendLimit]);
    }
}
