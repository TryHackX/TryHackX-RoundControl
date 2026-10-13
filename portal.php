<?php
declare(strict_types=1);

/**
 * Punkt wejścia portalu logowania/rejestracji.
 * Cienka warstwa: bootstrap -> (AJAX: kontroler) lub (GET: render szablonu).
 */

require __DIR__ . '/app/web.php';

use App\I18n\Lang;
use App\Portal\PortalController;
use App\Security\Captcha;
use App\Security\Csrf;
use App\Settings\Settings;
use App\Support\Database;
use App\Support\Http;
use App\Support\SecurityHeaders;
use App\Users\UserRepository;

// Zalogowany administrator trafia prosto do panelu.
if (!empty($_SESSION['admin_id'])) {
    header('Location: panel/index.php');
    exit;
}

// CAPTCHA: dowolny z dostawców; polityka CSP dopuszcza wyłącznie hosty AKTYWNEGO dostawcy.
$captchaCfg = Captcha::config();
SecurityHeaders::send(Captcha::cspHostsIn($captchaCfg));

// Obsługa akcji AJAX.
if (Http::isPost() && isset($_POST['ajax_action'])) {
    Http::json((new PortalController())->handle((string) $_POST['ajax_action']));
}

// Dane dla widoku.
$users   = new UserRepository(Database::pdo());
$domains = $users->domains();

$enabled  = (Settings::get('allow_registration', Settings::get('registration_enabled', '1')) === '1')
            && $domains !== [];
$captcha  = Captcha::activeIn($captchaCfg) ? [
    'provider' => Captcha::providerOf($captchaCfg),
    'siteKey'  => Captcha::siteKeyOf($captchaCfg),
    'script'   => Captcha::scriptUrlIn($captchaCfg, Lang::current()),
    'class'    => Captcha::widgetClassIn($captchaCfg),
    'size'     => Captcha::WIDGET_SIZE[Captcha::providerOf($captchaCfg)] ?? null,
] : null;
$pwdMin   = Settings::int('pwd_min_length', 10);
$pwdUpper = Settings::int('pwd_require_upper', 1);
$pwdDigit = Settings::int('pwd_require_digit', 1);
$pwdSpec  = Settings::int('pwd_require_special', 1);
$csrf     = Csrf::token();

require __DIR__ . '/app/templates/portal/page.php';
