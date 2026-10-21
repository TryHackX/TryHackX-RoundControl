<?php
declare(strict_types=1);

/**
 * Lifts the panel's timed Fail2Ban bans whose time is up (1.4.0) — App\Panel\TempBans::expire().
 *
 * Run every minute by the systemd timer mailpanel-tempban-expire, as the panel's PHP user: the unban goes through the
 * panel's own sudo rule (fail2ban-client set panel-tempban unbanip <ip>), no extra rights. By hand:
 *     sudo -u mailweb php /var/www/roundcube/app/cli/tempban-expire.php
 * Prints nothing when nothing is due. Every line starts with its syslog level (<5> notice, <3> error) for the journal.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require dirname(__DIR__) . '/bootstrap.php';

try {
    $ended = (new App\Panel\TempBans(App\Support\Database::pdo()))->expire();
} catch (Throwable $e) {
    fwrite(STDERR, '<3>RoundControl: timed bans: ' . $e->getMessage() . "\n");
    exit(1);
}
foreach ($ended as $ip) {
    echo "<5>RoundControl: the timed ban of $ip in panel-tempban ended\n";
}
exit(0);
