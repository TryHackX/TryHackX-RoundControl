<?php
declare(strict_types=1);

namespace App\Panel;

use App\Support\Ip;
use App\System\Commands;
use PDO;
use Throwable;

/**
 * Timed Fail2Ban bans of the panel — jail panel-tempban (1.4.0).
 *
 * Fail2Ban gives a manually banned address no ban time of its own: it ends EVERY such ban by the jail's CURRENT
 * bantime. Up to 1.3.2 the panel set that bantime right before each ban, so every new timed ban moved the end of all the
 * others (a 30-day ban was lifted soon after a later 1-hour one). Now the jail keeps one fixed bantime — 90 days, the
 * longest ban the panel hands out (system/fail2ban/jail.d/mailpanel.local) — the panel stores the end of each of its
 * bans in f2b_tempbans and lifts the ban itself when the time is up: the timer mailpanel-tempban-expire every minute,
 * and the panel whenever it lists the bans. If both stop, Fail2Ban still lifts every ban after 90 days.
 *
 * The three Fail2Ban calls can be replaced (tests: Commands does nothing in dev mode).
 */
final class TempBans
{
    public const JAIL        = 'panel-tempban';
    public const MIN_SECONDS = 60;
    public const MAX_SECONDS = 7776000;     // 90 days = bantime of the jail

    /** @var callable(string, string): bool */
    private $ban;
    /** @var callable(string, string): bool */
    private $unban;
    /** @var callable(string): ?array */
    private $banned;

    public function __construct(private PDO $pdo, ?callable $ban = null, ?callable $unban = null, ?callable $banned = null)
    {
        $this->ban    = $ban ?? [Commands::class, 'fail2banBan'];
        $this->unban  = $unban ?? [Commands::class, 'fail2banUnban'];
        $this->banned = $banned ?? [Commands::class, 'fail2banBanList'];
    }

    /**
     * Ban $ip in panel-tempban for $seconds. The row is written first — the write locks it until the ban is done, so an
     * expiry run cannot lift the new ban on the strength of an older, due row of the same address (it waits, then sees
     * the new end) — and a failed ban rolls the row back to what it was. No SELECT … FOR UPDATE: on an address without
     * a row it would take a gap lock, and two bans of different new addresses could deadlock.
     */
    public function ban(string $ip, int $seconds): bool
    {
        $bin = Ip::toBin($ip);
        if ($bin === null || $seconds < self::MIN_SECONDS || $seconds > self::MAX_SECONDS) {
            return false;
        }
        $text   = (string) Ip::toStr($bin);
        $banned = false;
        $this->pdo->beginTransaction();
        try {
            $this->pdo->prepare(
                'INSERT INTO f2b_tempbans (ip, until_ts, created_at) VALUES (?, NOW() + INTERVAL ? SECOND, NOW())
                 ON DUPLICATE KEY UPDATE until_ts = VALUES(until_ts), created_at = VALUES(created_at)'
            )->execute([$bin, $seconds]);
            if (!($this->ban)(self::JAIL, $text)) {
                $this->pdo->rollBack();
                return false;
            }
            $banned = true;
            $this->pdo->commit();
            return true;
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            if ($banned) {
                ($this->unban)(self::JAIL, $text);      // no stored end = no 90-day ban by accident
            }
            throw $e;
        }
    }

    /** The ban was lifted by hand or moved to another jail: forget its end. */
    public function forget(string $ip): void
    {
        $bin = Ip::toBin($ip);
        if ($bin !== null) {
            $this->pdo->prepare('DELETE FROM f2b_tempbans WHERE ip = ?')->execute([$bin]);
        }
    }

    /** @return array<string,int> address (as Ip::toStr prints it) => end of the panel's ban (epoch) */
    public function ends(): array
    {
        $out = [];
        foreach ($this->pdo->query('SELECT ip, UNIX_TIMESTAMP(until_ts) AS e FROM f2b_tempbans') as $row) {
            $ip = Ip::toStr((string) $row['ip']);
            if ($ip !== null) {
                $out[$ip] = (int) $row['e'];
            }
        }
        return $out;
    }

    /**
     * Lift the bans whose time is up, oldest first. Each row stays locked while its ban is lifted (a new ban of the same
     * address waits for it, then stores its own end). A failed unban keeps the row, unless Fail2Ban says the address is
     * no longer banned in the jail (lifted by hand, or Fail2Ban dropped it); when Fail2Ban does not answer at all the
     * run stops and the next one tries again.
     *
     * @return string[] the addresses whose ban ended
     */
    public function expire(int $limit = 200): array
    {
        $due = $this->pdo->query(
            'SELECT ip FROM f2b_tempbans WHERE until_ts <= NOW() ORDER BY until_ts LIMIT ' . max(1, min($limit, 1000))
        )->fetchAll(PDO::FETCH_COLUMN);

        $ended = [];
        $list  = null;      // what Fail2Ban still bans in the jail (binary addresses), read on the first failed unban
        foreach ($due as $bin) {
            $bin  = (string) $bin;
            $text = Ip::toStr($bin);
            $this->pdo->beginTransaction();
            try {
                $q = $this->pdo->prepare('SELECT 1 FROM f2b_tempbans WHERE ip = ? AND until_ts <= NOW() FOR UPDATE');
                $q->execute([$bin]);
                if ($q->fetchColumn() === false || $text === null) {     // banned again meanwhile: not due any more
                    $this->pdo->commit();
                    continue;
                }
                $gone = (bool) ($this->unban)(self::JAIL, $text);
                if (!$gone) {
                    if ($list === null) {
                        $names = ($this->banned)(self::JAIL);
                        if ($names === null) {
                            $this->pdo->rollBack();
                            break;
                        }
                        $list = array_values(array_filter(array_map([Ip::class, 'toBin'], $names), static fn($b) => $b !== null));
                    }
                    $gone = !in_array($bin, $list, true);
                }
                if ($gone) {
                    $this->pdo->prepare('DELETE FROM f2b_tempbans WHERE ip = ?')->execute([$bin]);
                    $ended[] = $text;
                }
                $this->pdo->commit();
            } catch (Throwable $e) {
                if ($this->pdo->inTransaction()) {
                    $this->pdo->rollBack();
                }
                throw $e;
            }
        }
        return $ended;
    }
}
