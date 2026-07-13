<?php
declare(strict_types=1);

namespace App\Panel;

use PDO;

/**
 * Odczyt/edycja kont pocztowych na potrzeby panelu: lista z wyszukiwaniem
 * (email lub IP), wielokolumnowym sortowaniem i paginacją, zmiana quoty,
 * usuwanie oraz zestaw IP powiązanych z kontem.
 */
final class UserAdminRepository
{
    /** Mapa dozwolonych kolumn sortowania (whitelist chroni przed SQL injection). */
    private const SORTS = [
        'id'         => 'v.id',
        'email'      => 'v.email',
        'created'    => 'created',
        'last_login' => 'last_login',
        'quota'      => 'v.quota_mb',
        'reg_ip'     => 'reg_ip',
    ];

    public function __construct(private PDO $pdo) {}

    /** @return array<string,string> pole => ASC|DESC */
    public function parseSort(string $raw): array
    {
        $out = [];
        foreach (array_filter(array_map('trim', explode(',', $raw))) as $part) {
            [$field, $dir] = array_pad(explode(':', $part, 2), 2, 'asc');
            if (!isset(self::SORTS[$field])) {
                continue;
            }
            $out[$field] = strtolower($dir) === 'desc' ? 'DESC' : 'ASC';
        }
        return $out;
    }

    /** Liczba pasujących kont (osobno od danych — jedna para zapytań na żądanie). */
    public function count(string $search): int
    {
        [$where, $params] = $this->buildSearch($search);

        if ($where === '') {
            return (int) $this->pdo->query('SELECT COUNT(*) FROM virtual_users')->fetchColumn();
        }

        $countSql = "SELECT COUNT(*) FROM (
            SELECT v.id
            FROM virtual_users v
            LEFT JOIN (
                SELECT email, MIN(created_at) AS created_at
                FROM registration_events WHERE status = 'success' GROUP BY email
            ) r ON r.email = v.email
            LEFT JOIN (
                SELECT email, MAX(created_at) AS last_login,
                       GROUP_CONCAT(DISTINCT INET6_NTOA(ip) SEPARATOR ',') AS ips
                FROM user_login_events GROUP BY email
            ) ul ON ul.email = v.email
            $where
        ) t";
        $st = $this->pdo->prepare($countSql);
        $st->execute($params);
        return (int) $st->fetchColumn();
    }

    /**
     * @param array<string,string> $sortState
     * @return array<int,array> wiersze bieżącej strony
     */
    public function rows(string $search, array $sortState, int $per, int $off): array
    {
        [$where, $params] = $this->buildSearch($search);
        $orderBy = $this->orderBy($sortState);

        $sql = "SELECT
              v.id, v.email, v.quota_mb, v.daily_send_limit,
              COALESCE(r.created_at, '-') AS created,
              COALESCE(ul.last_login, '-') AS last_login,
              ul.ips AS last_ips,
              (SELECT INET6_NTOA(re.ip) FROM registration_events re WHERE re.email = v.email AND re.status = 'success' ORDER BY re.created_at ASC LIMIT 1) AS reg_ip,
              (SELECT INET6_NTOA(e.ip) FROM user_login_events e WHERE e.email = v.email ORDER BY e.created_at ASC LIMIT 1) AS first_login_ip,
              (SELECT INET6_NTOA(e.ip) FROM user_login_events e WHERE e.email = v.email ORDER BY e.created_at DESC LIMIT 1) AS last_login_ip,
              (SELECT INET6_NTOA(e.ip) FROM user_login_events e WHERE e.email = v.email ORDER BY e.created_at DESC LIMIT 1 OFFSET 1) AS prev_login_ip
            FROM virtual_users v
            LEFT JOIN (
                SELECT email, MIN(created_at) AS created_at
                FROM registration_events WHERE status = 'success' GROUP BY email
            ) r ON r.email = v.email
            LEFT JOIN (
                SELECT email, MAX(created_at) AS last_login,
                       GROUP_CONCAT(DISTINCT INET6_NTOA(ip) SEPARATOR ',') AS ips
                FROM user_login_events GROUP BY email
            ) ul ON ul.email = v.email
            $where
            ORDER BY $orderBy
            LIMIT ? OFFSET ?";

        $st = $this->pdo->prepare($sql);
        $i = 1;
        foreach ($params as $p) {
            $st->bindValue($i++, $p);
        }
        $st->bindValue($i++, $per, PDO::PARAM_INT);
        $st->bindValue($i++, $off, PDO::PARAM_INT);
        $st->execute();

        return $st->fetchAll();
    }

    /**
     * @param array<string,string> $sortState
     * @return array{0: array<int,array>, 1: int}  [wiersze, total]
     */
    public function fetch(string $search, array $sortState, int $per, int $off): array
    {
        return [$this->rows($search, $sortState, $per, $off), $this->count($search)];
    }

    /**
     * "Stóg" wyszukiwania konta: e-mail, wszystkie znane IP (logowania +
     * rejestracji), data utworzenia i ostatniego logowania oraz quota.
     */
    private const HAY = "CONCAT_WS(' ',
        v.email,
        COALESCE(ul.ips, ''),
        COALESCE((SELECT INET6_NTOA(re.ip) FROM registration_events re
                  WHERE re.email = v.email AND re.status = 'success'
                  ORDER BY re.created_at ASC LIMIT 1), ''),
        COALESCE(r.created_at, ''),
        COALESCE(ul.last_login, ''),
        CAST(v.quota_mb AS CHAR))";

    /**
     * Grupy rozdzielone ";" muszą pasować WSZYSTKIE (AND); w obrębie grupy
     * alternatywy po spacji/przecinku (OR) — np. shift-klik w kilka IP.
     * Warunki procentu użycia (np. ">50%") filtruje klient (dane z Dovecot,
     * nie ma ich w SQL) — tu ich nie dostajemy.
     *
     * @return array{0:string,1:array<int,string>} [warunek WHERE, parametry]
     */
    private function buildSearch(string $search): array
    {
        $search = trim($search);
        if ($search === '') {
            return ['', []];
        }

        $conds  = [];
        $params = [];
        foreach (array_filter(array_map('trim', explode(';', $search))) as $group) {
            $terms = preg_split('/[\s,]+/', $group, -1, PREG_SPLIT_NO_EMPTY) ?: [];
            if ($terms === []) {
                continue;
            }
            $ors = [];
            foreach ($terms as $term) {
                $ors[]    = self::HAY . ' LIKE ?';
                $params[] = '%' . $term . '%';
            }
            $conds[] = '(' . implode(' OR ', $ors) . ')';
        }

        if ($conds === []) {
            return ['', []];
        }
        return [' WHERE ' . implode(' AND ', $conds), $params];
    }

    private function orderBy(array $sortState): string
    {
        $state = $sortState ?: ['id' => 'DESC'];
        $parts = [];
        foreach ($state as $field => $dir) {
            $parts[] = self::SORTS[$field] . ' ' . $dir;
        }
        return implode(', ', $parts);
    }

    public function updateQuota(int $id, int $mb): void
    {
        $this->pdo->prepare('UPDATE virtual_users SET quota_mb = ? WHERE id = ?')->execute([$mb, $id]);
    }

    /** Indywidualny dzienny limit wysyłki (NULL = globalny, 0 = bez limitu). */
    public function updateSendLimit(int $id, ?int $limit): void
    {
        $this->pdo->prepare('UPDATE virtual_users SET daily_send_limit = ? WHERE id = ?')->execute([$limit, $id]);
    }

    /** Reset hasła skrzynki (hash $6$ Dovecota) — używane przy resecie z panelu. */
    public function updatePassword(int $id, string $hash): void
    {
        $this->pdo->prepare('UPDATE virtual_users SET password = ? WHERE id = ?')->execute([$hash, $id]);
    }

    public function applyQuotaToAll(int $mb): int
    {
        $st = $this->pdo->prepare("UPDATE virtual_users SET quota_mb = ? WHERE email NOT LIKE 'admin@%'");
        $st->execute([$mb]);
        return $st->rowCount();
    }

    public function emailById(int $id): ?string
    {
        $st = $this->pdo->prepare('SELECT email FROM virtual_users WHERE id = ?');
        $st->execute([$id]);
        $v = $st->fetchColumn();
        return $v === false ? null : (string) $v;
    }

    public function delete(int $id): void
    {
        $this->pdo->prepare('DELETE FROM virtual_users WHERE id = ?')->execute([$id]);
    }

    /** Zestaw IP powiązanych z kontem: flagi (rej/pierwsze/poprz/ostatnie) + ostatnie 30. */
    public function ipsForEmail(string $email): array
    {
        $flagsStmt = $this->pdo->prepare("
            SELECT
              (SELECT INET6_NTOA(re.ip) FROM registration_events re WHERE re.email = v.email AND re.status = 'success' ORDER BY re.created_at ASC LIMIT 1) AS reg_ip,
              (SELECT INET6_NTOA(e.ip) FROM user_login_events e WHERE e.email = v.email ORDER BY e.created_at ASC LIMIT 1) AS first_login_ip,
              (SELECT INET6_NTOA(e.ip) FROM user_login_events e WHERE e.email = v.email ORDER BY e.created_at DESC LIMIT 1) AS last_login_ip,
              (SELECT INET6_NTOA(e.ip) FROM user_login_events e WHERE e.email = v.email ORDER BY e.created_at DESC LIMIT 1 OFFSET 1) AS prev_login_ip
            FROM virtual_users v WHERE v.email = ? LIMIT 1
        ");
        $flagsStmt->execute([$email]);
        $flags = $flagsStmt->fetch(PDO::FETCH_ASSOC) ?: [];

        $recentStmt = $this->pdo->prepare("
            SELECT DISTINCT INET6_NTOA(ip) AS ip
            FROM user_login_events WHERE email = ?
            ORDER BY created_at DESC LIMIT 30
        ");
        $recentStmt->execute([$email]);
        $recent = $recentStmt->fetchAll(PDO::FETCH_COLUMN) ?: [];

        return ['flags' => $flags, 'recent' => $recent];
    }
}
