<?php
declare(strict_types=1);

/**
 * Kernel warstwy webowej: fundament + sesja + tryb błędów.
 * Dołączają go punkty wejścia (portal.php, panel/*), które renderują HTML
 * lub obsługują AJAX. Skrypty czysto-CLI/serwisowe używają samego bootstrap.php.
 */

require __DIR__ . '/bootstrap.php';

use App\Support\Env;
use App\Support\Session;

if (Env::isDev()) {
    ini_set('display_errors', '1');
    error_reporting(E_ALL);
} else {
    ini_set('display_errors', '0');
}

Session::start();

// Język strony (1.3.0): ?lang= → ciasteczko `lang` → Accept-Language → domyślny z ustawień. Ustalany przed pierwszym
// bajtem odpowiedzi, więc strona od razu jest w wybranym języku.
\App\I18n\Lang::init();

// Przechowywanie logów (1.3.2): najwyżej raz na dobę, po wysłaniu odpowiedzi (bez crona) — App\Admin\LogRetention.
if (PHP_SAPI !== 'cli') {
    register_shutdown_function([\App\Admin\LogRetention::class, 'afterResponse']);
}
