<?php
declare(strict_types=1);

namespace App\Panel;

use App\Support\Ip;
use PDO;

/** Lokalne blokady IP (tabela ip_blocks). */
final class BanRepository
{
    public function __construct(private PDO $pdo) {}

    /** @return array<int,array{type:string,ip_address:?string,reason:string,jail:string,service:string,until:string,until_epoch:?int}> */
    public function activeLocal(): array
    {
        $out = [];
        $rows = $this->pdo->query('SELECT ip, reason, until_ts, UNIX_TIMESTAMP(until_ts) AS until_epoch FROM ip_blocks WHERE until_ts > NOW() ORDER BY until_ts DESC');
        foreach ($rows as $row) {
            $out[] = [
                'type'        => 'local',
                'ip_address'  => Ip::toStr($row['ip']),
                'reason'      => $row['reason'] ?: 'Brak powodu',
                'jail'        => 'Panel',
                'service'     => 'Panel',
                'until'       => (string) $row['until_ts'],
                'until_epoch' => $row['until_epoch'] !== null ? (int) $row['until_epoch'] : null,
            ];
        }
        return $out;
    }

    public function ban(string $ipBin, int $minutes, string $reason): void
    {
        $this->pdo->prepare(
            'INSERT INTO ip_blocks (ip, until_ts, reason)
             VALUES (?, NOW() + INTERVAL ? MINUTE, ?)
             ON DUPLICATE KEY UPDATE until_ts = VALUES(until_ts), reason = VALUES(reason)'
        )->execute([$ipBin, $minutes, $reason]);
    }

    public function unbanLocal(string $ipBin): void
    {
        $this->pdo->prepare('DELETE FROM ip_blocks WHERE ip = ?')->execute([$ipBin]);
    }
}
