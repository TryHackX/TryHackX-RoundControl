<?php
declare(strict_types=1);

/** Punkt wejścia panelu administratora — cienka warstwa nad PanelController. */

require __DIR__ . '/../app/web.php';

use App\Admin\AdminGuard;
use App\Panel\PanelController;
use App\Support\Http;
use App\Support\SecurityHeaders;

// 1.4.0: headers first, so the guard's 403 JSON / redirect carries them too.
SecurityHeaders::sendPanel();
// 1.2.0: zapytania fetch() (zapis formularza, listy, statystyki) dostają przy wygasłej sesji JSON 403, nie przekierowanie.
AdminGuard::ensure(Http::wantsJson());

(new PanelController())->handle();
