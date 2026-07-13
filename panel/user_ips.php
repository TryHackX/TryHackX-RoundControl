<?php
declare(strict_types=1);

/** Endpoint AJAX: zestaw IP powiązanych z kontem (dla modala banowania). */

require __DIR__ . '/../app/web.php';

use App\Panel\UserAdminRepository;
use App\Support\Database;
use App\Support\Http;

if (empty($_SESSION['admin_id'])) {
    http_response_code(403);
    Http::json(['error' => 'forbidden']);
}

$email = trim((string) Http::get('email', ''));
if ($email === '') {
    Http::json(['error' => 'no_email']);
}

try {
    Http::json((new UserAdminRepository(Database::pdo()))->ipsForEmail($email));
} catch (Throwable $e) {
    http_response_code(500);
    Http::json(['error' => 'server']);
}
