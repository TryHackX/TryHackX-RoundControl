<?php
declare(strict_types=1);

namespace App\Portal;

use App\Admin\AdminRepository;
use App\Auth\AdminAuthenticator;
use App\Auth\AuthenticatorFactory;
use App\Auth\AuthFlow;
use App\Security\Csrf;
use App\Security\Password;
use App\Security\RateLimiter;
use App\Security\Recaptcha;
use App\Settings\Settings;
use App\Support\Database;
use App\Support\Env;
use App\Support\Http;
use App\Support\Ip;
use App\Support\Session;
use App\Users\UserRepository;
use PDO;
use Throwable;

/**
 * Router akcji AJAX portalu. Każda metoda-handler zwraca tablicę odpowiedzi;
 * wyjątki PortalException zamieniane są na komunikat dla użytkownika.
 */
final class PortalController
{
    private PDO $pdo;
    private UserRepository $users;
    private AdminRepository $admins;
    private RateLimiter $limiter;
    private AuthFlow $flow;

    public function __construct()
    {
        $this->pdo     = Database::pdo();
        $this->users   = new UserRepository($this->pdo);
        $this->admins  = new AdminRepository($this->pdo);
        $this->limiter = new RateLimiter($this->pdo);
        $this->flow    = new AuthFlow();
    }

    /** @return array<string,mixed> */
    public function handle(string $action): array
    {
        $resp = ['ok' => false, 'msg' => '', 'html' => '', 'redirect' => '', 'focus_id' => ''];

        if (!Csrf::check(Http::post('_csrf'))) {
            return ['ok' => false, 'msg' => 'Błąd bezpieczeństwa (CSRF). Odśwież stronę.', 'reload' => true];
        }

        try {
            if (in_array($action, ['select_target', 'perform_login'], true) && !$this->flow->valid()) {
                throw new PortalException('Sesja wygasła. Odśwież stronę.', PortalException::RELOAD);
            }

            $resp = match ($action) {
                'check_identity'     => $this->checkIdentity($resp),
                'select_target'      => $this->selectTarget($resp),
                'perform_login'      => $this->performLogin($resp),
                'check_availability' => $this->checkAvailability($resp),
                'register'           => $this->register($resp),
                default              => throw new PortalException('Nieznana akcja'),
            };
        } catch (PortalException $e) {
            $resp['ok']  = false;
            $resp['msg'] = $e->getMessage();
            if ($e->getCode() === PortalException::RELOAD) {
                $resp['reload'] = true;
            }
            if ($e->reset) {
                $resp['reset'] = true;
            }
        } catch (Throwable $e) {
            // Diagnostyka do logu serwera — użytkownik widzi tylko ogólny komunikat.
            error_log(sprintf(
                'portal: nieoczekiwany wyjątek przy akcji "%s": [%s] %s w %s:%d',
                $action, $e::class, $e->getMessage(), $e->getFile(), $e->getLine()
            ));
            $resp['ok']  = false;
            $resp['msg'] = Env::isDev() ? 'Błąd: ' . $e->getMessage() : 'Wystąpił błąd serwera';
        }

        return $resp;
    }

    private function checkIdentity(array $resp): array
    {
        $email = (string) filter_var((string) Http::post('email', ''), FILTER_SANITIZE_EMAIL);

        if ($email === '') {
            throw new PortalException('Podaj adres e-mail');
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new PortalException('Nieprawidłowy format adresu e-mail');
        }
        if (Recaptcha::isEnabled() && !Recaptcha::verify(Http::post('g-recaptcha-response'))) {
            throw new PortalException('Błąd weryfikacji reCAPTCHA');
        }
        if ($this->limiter->isLoginRateLimited($email)) {
            Http::secureSleep();
            throw new PortalException('Zbyt wiele prób logowania. Spróbuj później.');
        }

        $isAdmin = $this->admins->isAdminEmail($email);
        $isUser  = $this->users->exists($email);

        if (!$isAdmin && !$isUser) {
            Http::secureSleep();
            throw new PortalException('Nieprawidłowe dane logowania');
        }

        $this->flow->start($email, $isAdmin, $isUser);

        if ($isAdmin && $isUser) {
            $resp['html'] = Screens::choice($email);
        } else {
            $resp['html']     = Screens::password($email, $isAdmin ? 'admin' : 'webmail');
            $resp['focus_id'] = 'inpPassword';
        }
        $resp['ok'] = true;

        return $resp;
    }

    private function selectTarget(array $resp): array
    {
        $target = Http::post('target', 'webmail');
        if ($target === 'admin' && !$this->flow->isAdmin()) {
            throw new PortalException('Brak uprawnień');
        }

        $resp['html']     = Screens::password($this->flow->email(), $target === 'admin' ? 'admin' : 'webmail');
        $resp['focus_id'] = 'inpPassword';
        $resp['ok']       = true;

        return $resp;
    }

    private function performLogin(array $resp): array
    {
        $password = (string) Http::post('password', '');
        $target   = Http::post('target', 'webmail');
        $email    = $this->flow->email();

        if ($password === '') {
            Http::secureSleep();
            throw new PortalException('Podaj hasło');
        }

        return $target === 'admin'
            ? $this->loginAdmin($resp, $email, $password)
            : $this->loginWebmail($resp, $email, $password);
    }

    private function loginAdmin(array $resp, string $email, string $password): array
    {
        if (!$this->flow->isAdmin()) {
            throw new PortalException('Brak uprawnień');
        }

        $result = (new AdminAuthenticator($this->admins, $this->limiter))->attempt($email, $password);

        if (!$result->ok) {
            $this->flow->clear();
            Http::secureSleep();
            throw PortalException::withReset($result->error ?: 'Nieprawidłowe dane logowania');
        }

        $_SESSION['admin_id']       = $result->id;
        $_SESSION['admin_username'] = $email;
        Session::regenerate();
        $this->flow->clear();

        $resp['ok']       = true;
        $resp['redirect'] = 'panel/index.php';

        return $resp;
    }

    private function loginWebmail(array $resp, string $email, string $password): array
    {
        // Czyścimy flow przed uwierzytelnianiem — w prod authenticator zamyka
        // naszą sesję, więc nie zapisujemy do niej po tym momencie.
        $this->flow->clear();

        $authenticator = AuthenticatorFactory::make($this->users);
        if (!$authenticator->authenticate($email, $password)) {
            $this->limiter->recordUserLoginFail($email, Ip::bin());
            Http::secureSleep();
            throw PortalException::withReset('Nieprawidłowe dane logowania');
        }

        try {
            $this->pdo->prepare('CALL sp_register_login(?, ?)')->execute([$email, Ip::raw()]);
        } catch (Throwable $e) {
            // rejestracja zdarzenia logowania nie może blokować wejścia
        }

        $resp['ok']       = true;
        $resp['redirect'] = './?_task=mail';

        return $resp;
    }

    private function checkAvailability(array $resp): array
    {
        if (!$this->registrationEnabled()) {
            throw new PortalException('Rejestracja wyłączona');
        }

        $window = array_filter($_SESSION['check_limiter'] ?? [], static fn($ts) => $ts > time() - 60);
        if (count($window) > 15) {
            Http::secureSleep();
            throw new PortalException('Zbyt częste zapytania. Odczekaj chwilę.');
        }
        $window[] = time();
        $_SESSION['check_limiter'] = $window;

        $local  = trim((string) Http::post('local', ''));
        $domain = trim((string) Http::post('domain', ''));

        if (!preg_match('/^[a-z0-9._-]+$/i', $local)) {
            throw new PortalException('Niedozwolone znaki');
        }
        if (!in_array($domain, $this->users->domains(), true)) {
            throw new PortalException('Nieprawidłowa domena');
        }

        $exists = $this->users->exists($local . '@' . $domain);
        $resp['ok']     = true;
        $resp['status'] = $exists ? 'taken' : 'free';
        $resp['msg']    = $exists ? 'Zajęte' : 'Wolne';

        return $resp;
    }

    private function register(array $resp): array
    {
        if (!$this->registrationEnabled()) {
            throw new PortalException('Rejestracja wyłączona');
        }

        $local  = trim((string) Http::post('local', ''));
        $domain = trim((string) Http::post('domain', ''));
        $pass   = (string) Http::post('password', '');
        $pass2  = (string) Http::post('password2', '');

        if (Recaptcha::isEnabled() && !Recaptcha::verify(Http::post('g-recaptcha-response'))) {
            throw new PortalException('Błąd reCAPTCHA');
        }
        if (!preg_match('/^[a-z0-9._-]+$/i', $local)) {
            throw new PortalException('Niedozwolone znaki w nazwie');
        }
        if (!in_array($domain, $this->users->domains(), true)) {
            throw new PortalException('Nieprawidłowa domena');
        }

        $email = (string) filter_var($local . '@' . $domain, FILTER_SANITIZE_EMAIL);
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new PortalException('Nieprawidłowy format adresu email');
        }
        if ($pass !== $pass2) {
            throw new PortalException('Hasła nie są identyczne');
        }
        if (!Password::meetsPolicy($pass)) {
            throw new PortalException('Hasło nie spełnia wymogów bezpieczeństwa');
        }

        $throttle = $this->limiter->registrationThrottle();
        if (!$throttle['ok']) {
            $this->logRegistration($email, $throttle['code'] === 'blocked' ? 'blocked' : 'failed', $throttle['code']);
            Http::secureSleep();
            throw new PortalException($throttle['msg']);
        }

        $domainId = $this->users->domainIdForEmail($email);
        if ($domainId === null) {
            throw new PortalException('Błąd konfiguracji domeny');
        }
        if ($this->users->exists($email)) {
            Http::secureSleep();
            throw new PortalException('Konto o takiej nazwie już istnieje');
        }

        $quota = Settings::int('mailbox_default_quota_mb', 1024);
        $this->users->create($domainId, Password::dovecotHash($pass), $email, $quota);
        $this->logRegistration($email, 'success', '');

        $resp['ok']  = true;
        $resp['msg'] = 'Konto utworzone pomyślnie!';

        return $resp;
    }

    private function registrationEnabled(): bool
    {
        $flag = Settings::get('allow_registration', Settings::get('registration_enabled', '1'));
        return $flag === '1' && $this->users->domains() !== [];
    }

    private function logRegistration(string $email, string $status, string $reason): void
    {
        $this->pdo->prepare(
            'INSERT INTO registration_events (ip, email, status, reason, created_at) VALUES (?, ?, ?, ?, NOW())'
        )->execute([Ip::bin(), $email, $status, $reason]);
    }
}
