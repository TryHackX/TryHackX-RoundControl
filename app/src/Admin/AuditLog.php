<?php
declare(strict_types=1);

namespace App\Admin;

use App\Support\Ip;
use PDO;
use Throwable;

/** Dziennik audytowy akcji administratorów (admin_audit_logs). */
final class AuditLog
{
    public function __construct(private PDO $pdo) {}

    public function log(string $action, ?string $details = null): void
    {
        if (empty($_SESSION['admin_username'])) {
            return;
        }
        try {
            $this->pdo->prepare(
                'INSERT INTO admin_audit_logs (admin_email, ip, action_type, details, created_at)
                 VALUES (?, ?, ?, ?, NOW())'
            )->execute([(string) $_SESSION['admin_username'], Ip::bin(), $action, $details]);
        } catch (Throwable $e) {
            // audyt nie może blokować operacji
        }
    }
}
