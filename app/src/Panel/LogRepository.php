<?php
declare(strict_types=1);

namespace App\Panel;

use PDO;

/** Odczyt logów panelu: rejestracje, logowania adminów, dziennik audytowy. */
final class LogRepository
{
    /** Mapy dozwolonych kolumn sortowania per tabela (whitelist = ochrona SQL). */
    private const SORTS = [
        'reg'   => ['created' => 'created_at', 'ip' => 'ip', 'email' => 'email', 'status' => 'status'],
        'admin' => ['created' => 'created_at', 'ip' => 'ip', 'email' => 'username', 'status' => 'success'],
        'audit' => ['created' => 'created_at', 'ip' => 'ip', 'admin' => 'admin_email', 'action' => 'action_type'],
    ];

    /**
     * "Stogi" wyszukiwania — wszystkie pola tabeli widoczne w UI sklejone
     * w jeden tekst (+ polskie/angielskie aliasy statusów, żeby "ok"/"błąd"
     * trafiały w wartości liczbowo-enumowe).
     */
    private const HAYSTACKS = [
        'reg'   => "CONCAT_WS(' ', INET6_NTOA(ip), email, status, IF(status = 'success', 'ok sukces', 'błąd blad'), reason, created_at)",
        'admin' => "CONCAT_WS(' ', INET6_NTOA(ip), username, IF(success = 1, 'sukces success ok', 'błąd blad failed'), details, created_at)",
        'audit' => "CONCAT_WS(' ', INET6_NTOA(ip), admin_email, action_type, details, created_at)",
    ];

    public function __construct(private PDO $pdo) {}

    /**
     * Wyszukiwarka logów: warunki rozdzielone ";" muszą pasować WSZYSTKIE
     * (AND); każdy warunek to FRAZA dopasowywana w całości (spacje są jej
     * częścią — "Ręczny ban" znaczy dokładnie to). Zwraca [WHERE, parametry].
     *
     * @return array{0:string,1:array<int,string>}
     */
    private function searchWhere(string $table, string $search): array
    {
        $search = trim($search);
        if ($search === '') {
            return ['', []];
        }
        $hay    = self::HAYSTACKS[$table];
        $conds  = [];
        $params = [];
        foreach (array_filter(array_map('trim', explode(';', $search))) as $phrase) {
            $conds[]  = "$hay LIKE ?";
            $params[] = '%' . addcslashes($phrase, '\\%_') . '%';   // 1.4.0: "_" and "%" are literal characters here
        }
        if ($conds === []) {
            return ['', []];
        }
        return [' WHERE ' . implode(' AND ', $conds), $params];
    }

    /**
     * Parsuje "pole:kierunek,pole:kierunek" wg whitelisty danej tabeli.
     * @return array<string,string> pole => ASC|DESC
     */
    public function parseSort(string $table, string $raw): array
    {
        $allowed = self::SORTS[$table] ?? [];
        $out = [];
        foreach (array_filter(array_map('trim', explode(',', $raw))) as $part) {
            [$field, $dir] = array_pad(explode(':', $part, 2), 2, 'asc');
            if (!isset($allowed[$field])) {
                continue;
            }
            $out[$field] = strtolower($dir) === 'desc' ? 'DESC' : 'ASC';
        }
        return $out;
    }

    public function countRegistration(string $search = ''): int
    {
        return $this->countWhere('registration_events', 'reg', $search);
    }

    /** @param array<string,string> $sort */
    public function registrationPage(int $per, int $off, array $sort = [], string $search = ''): array
    {
        [$where, $params] = $this->searchWhere('reg', $search);
        return $this->page(
            'SELECT ip, email, status, reason, created_at FROM registration_events' . $where
                . ' ORDER BY ' . $this->orderBy('reg', $sort) . ' LIMIT ? OFFSET ?',
            $per, $off, $params
        );
    }

    public function countAdminLogin(string $search = ''): int
    {
        return $this->countWhere('admin_login_attempts', 'admin', $search);
    }

    /** @param array<string,string> $sort */
    public function adminLoginPage(int $per, int $off, array $sort = [], string $search = ''): array
    {
        [$where, $params] = $this->searchWhere('admin', $search);
        return $this->page(
            'SELECT ip, username, success, details, created_at FROM admin_login_attempts' . $where
                . ' ORDER BY ' . $this->orderBy('admin', $sort) . ' LIMIT ? OFFSET ?',
            $per, $off, $params
        );
    }

    public function countAudit(string $search = ''): int
    {
        return $this->countWhere('admin_audit_logs', 'audit', $search);
    }

    /** @param array<string,string> $sort */
    public function auditPage(int $per, int $off, array $sort = [], string $search = ''): array
    {
        [$where, $params] = $this->searchWhere('audit', $search);
        return $this->page(
            'SELECT created_at, admin_email, ip, action_type, details FROM admin_audit_logs' . $where
                . ' ORDER BY ' . $this->orderBy('audit', $sort) . ' LIMIT ? OFFSET ?',
            $per, $off, $params
        );
    }

    private function countWhere(string $table, string $hayKey, string $search): int
    {
        [$where, $params] = $this->searchWhere($hayKey, $search);
        $st = $this->pdo->prepare("SELECT COUNT(*) FROM $table" . $where);
        $st->execute($params);
        return (int) $st->fetchColumn();
    }

    /** ORDER BY z whitelisty; domyślnie najnowsze pierwsze. */
    private function orderBy(string $table, array $sort): string
    {
        if ($sort === []) {
            return 'id DESC';
        }
        $parts = [];
        foreach ($sort as $field => $dir) {
            $parts[] = self::SORTS[$table][$field] . ' ' . $dir;
        }
        $parts[] = 'id DESC'; // stabilny tie-breaker
        return implode(', ', $parts);
    }

    private function page(string $sql, int $per, int $off, array $params = []): array
    {
        $st = $this->pdo->prepare($sql);
        $i  = 1;
        foreach ($params as $p) {
            $st->bindValue($i++, $p);
        }
        $st->bindValue($i++, $per, PDO::PARAM_INT);
        $st->bindValue($i, $off, PDO::PARAM_INT);
        $st->execute();
        return $st->fetchAll();
    }
}
