<?php
declare(strict_types=1);

/** Punkt wejścia panelu administratora — cienka warstwa nad PanelController. */

require __DIR__ . '/../app/web.php';

use App\Admin\AdminGuard;
use App\Panel\PanelController;
use App\Support\SecurityHeaders;

AdminGuard::ensure();
SecurityHeaders::sendPanel();

(new PanelController())->handle();
