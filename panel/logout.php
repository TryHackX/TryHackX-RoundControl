<?php
declare(strict_types=1);

/**
 * Wylogowanie — tylko POST z tokenem CSRF (przycisk w nagłówku panelu). Wejście GET nie wylogowuje
 * (obcy link/obrazek nie może zakończyć sesji administratora); wraca do panelu.
 */

require __DIR__ . '/../app/web.php';

use App\Security\Csrf;
use App\Support\Http;
use App\Support\SecurityHeaders;
use App\Support\Session;

SecurityHeaders::sendPanel();   // 1.3.3: the same headers as every panel response

if (Http::isPost() && Csrf::check(Http::post('_csrf'))) {
    Session::destroy();
    header('Location: ../portal.php');
    exit;
}

header('Location: index.php');
exit;
