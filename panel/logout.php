<?php
declare(strict_types=1);

require __DIR__ . '/../app/web.php';

use App\Support\Session;

Session::destroy();

header('Location: ../portal.php');
exit;
