<?php
declare(strict_types=1);

/** Endpoint AJAX: zestaw IP powiązanych z kontem (dla modala banowania). */

require __DIR__ . '/../app/web.php';

use App\Admin\AdminGuard;
use App\Panel\UserAdminRepository;
use App\Support\Database;
use App\Support\Http;
use App\Support\SecurityHeaders;

SecurityHeaders::sendPanel();   // 1.3.3: the same headers as every panel response
// Te same reguły sesji co panel (bezczynność, czas życia, istnienie konta) — odpowiedź JSON 403 zamiast przekierowania.
AdminGuard::ensure(true);
session_write_close();   // endpoint tylko do odczytu: nie trzymamy blokady sesji

$email = strtolower(trim((string) Http::get('email', '')));
if ($email === '') {
    Http::json(['error' => 'no_email']);
}

try {
    Http::json((new UserAdminRepository(Database::pdo()))->ipsForEmail($email));
} catch (Throwable $e) {
    error_log('panel/user_ips: ' . $e->getMessage());
    http_response_code(500);
    Http::json(['error' => 'server']);
}
