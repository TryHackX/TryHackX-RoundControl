<?php
declare(strict_types=1);

namespace App\Panel;

use App\Support\Ip;
use PDO;

use function App\I18n\t;

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
                'reason'      => $row['reason'] ?: t('rows.no_reason'),
                'jail'        => t('rows.panel_jail'),
                'service'     => t('rows.panel_jail'),
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

    /** Powód aktywnej blokady lokalnej (null = brak) — zawiera znacznik F2B_MARK, gdy ban poszedł też do Fail2Ban. */
    public function reasonFor(string $ipBin): ?string
    {
        $s = $this->pdo->prepare('SELECT reason FROM ip_blocks WHERE ip = ? AND until_ts > NOW()');
        $s->execute([$ipBin]);
        $v = $s->fetchColumn();
        return $v === false ? null : (string) $v;
    }

    public function unbanLocal(string $ipBin): void
    {
        $this->pdo->prepare('DELETE FROM ip_blocks WHERE ip = ?')->execute([$ipBin]);
    }

    /** Znacznik w powodzie bana lokalnego: ten ban ma też wpis w jailu panel-tempban (webmail / IMAP / SMTP). */
    public const F2B_MARK = ' [F2B]';
}
