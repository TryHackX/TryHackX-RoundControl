<?php
declare(strict_types=1);

namespace App\Panel;

use PDO;

final class Stats
{
    /** @return array{total_users:int,today_registrations:int,banned_ips:int,failed_logins_24h:int} */
    public static function collect(PDO $pdo, int $bannedIps): array
    {
        return [
            'total_users'         => (int) $pdo->query('SELECT COUNT(*) FROM virtual_users')->fetchColumn(),
            'today_registrations' => (int) $pdo->query("SELECT COUNT(*) FROM registration_events WHERE status = 'success' AND created_at >= CURDATE()")->fetchColumn(),
            'banned_ips'          => $bannedIps,
            'failed_logins_24h'   => (int) $pdo->query('SELECT COUNT(*) FROM admin_login_attempts WHERE success = 0 AND created_at > NOW() - INTERVAL 24 HOUR')->fetchColumn(),
        ];
    }
}
