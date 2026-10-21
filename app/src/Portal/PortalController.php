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
use App\Security\Captcha;
use App\Settings\Settings;
use App\Support\AuthLog;
use App\Support\Cookie;
use App\Support\Database;
use App\Support\Env;
use App\Support\Http;
use App\Support\Ip;
use App\Support\Session;
use App\Users\Accounts;
use App\Users\UserRepository;
use PDO;
use PDOException;
use Throwable;

use function App\I18n\t;

/**
 * Router akcji AJAX portalu. Każda metoda-handler zwraca tablicę odpowiedzi;
 * wyjątki PortalException zamieniane są na komunikat dla użytkownika.
 *
 * Serwer nie zdradza, czy konto istnieje ani czy jest administratorem (krok "identyfikacja" odpowiada tak samo
 * na każdy poprawnie zapisany adres); limity są szybkie (odmowa zamiast sleep()) — patrz RateLimiter.
 *
 * Logowanie: adres -> hasło -> (administrator) kafelki. Hasło sprawdzane jest NAJPIERW jako hasło do panelu
 * (zawsze jedno Argon2id, dla nie-administratora względem atrapy), a gdy nim nie jest — jako hasło skrzynki (logowanie
 * IMAP przez Roundcube). Każde złe hasło przechodzi więc tę samą drogę (Argon2id + IMAP) i dostaje tę samą odpowiedź,
 * niezależnie od tego, czy adres nie istnieje, jest zwykłym kontem czy kontem administratora. Kto zna hasło do panelu,
 * a ma też skrzynkę, wybiera kafelek (Panel / Webmail); kto zna tylko hasło skrzynki — wchodzi prosto do webmaila.
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
            return ['ok' => false, 'msg' => t('portal.err.csrf'), 'reload' => true];
        }

        try {
            // Ręcznie zablokowany adres (ip_blocks, panel → "Zbanuj"): odmowa na wszystkie akcje portalu.
            if (!Ip::isTrusted(Ip::raw()) && $this->limiter->ipBlockedUntil(Ip::bin()) !== null) {
                throw new PortalException(t('rate.ip_blocked'));
            }

            if ($action === 'perform_login' && !$this->flow->valid('password')) {
                // ten sam komunikat dla każdego adresu; front wraca do kroku "adres e-mail"
                throw PortalException::withReset(t('portal.err.flow_expired'));
            }

            $resp = match ($action) {
                'check_identity'     => $this->checkIdentity($resp),
                'perform_login'      => $this->performLogin($resp),
                'choose_target'      => $this->chooseTarget($resp),
                'cancel_login'       => $this->cancelLogin($resp),
                'check_availability' => $this->checkAvailability($resp),
                'register'           => $this->register($resp),
                default              => throw new PortalException(t('portal.err.unknown_action')),
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
                'portal: unexpected exception in action "%s": [%s] %s in %s:%d',
                $action, $e::class, $e->getMessage(), $e->getFile(), $e->getLine()
            ));
            $resp['ok']  = false;
            $resp['msg'] = Env::isDev() ? t('portal.err.dev', ['error' => $e->getMessage()]) : t('portal.err.server');
        }

        return $resp;
    }

    private function checkIdentity(array $resp): array
    {
        $email = Accounts::normalize((string) filter_var((string) Http::post('email', ''), FILTER_SANITIZE_EMAIL));

        if ($email === '') {
            throw new PortalException(t('portal.err.email_required'));
        }
        if (strlen($email) > 190 || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new PortalException(t('portal.err.email_format'));
        }
        // Najpierw tania odmowa (baza), dopiero potem CAPTCHA (żądanie HTTP do dostawcy, do 8 s).
        $blocked = $this->limiter->loginBlocked($email, Ip::bin());
        if ($blocked !== null) {
            throw new PortalException($blocked);
        }
        // 1.4.0: addresses in SCAN_TRUSTED_IPS skip the CAPTCHA at sign-in, as they skip the limits — the administrator's
        // way in when the CAPTCHA keys stop working (otherwise only an edit of app_settings helps; README "CAPTCHA").
        if (!Ip::isTrusted(Ip::raw()) && !Captcha::check('login')) {
            throw new PortalException(t('portal.err.captcha'));
        }

        // Żadnego zapytania o konto: ten sam ekran dla istniejących i nieistniejących adresów, dla adminów i reszty.
        $this->flow->start($email);

        $resp['html']     = Screens::password($email);
        $resp['focus_id'] = 'inpPassword';
        $resp['ok']       = true;

        return $resp;
    }

    /** Ostatnio wybrana usługa (ciasteczko tej przeglądarki) — tylko do podświetlenia kafelka po poprawnym haśle admina. */
    private function preferredTarget(): string
    {
        return ($_COOKIE['mp_target'] ?? '') === 'webmail' ? 'webmail' : 'admin';
    }

    private function rememberTarget(string $target): void
    {
        Cookie::set('mp_target', $target, 86400 * 180);
    }

    /**
     * Krok 2: hasło. Najpierw jako hasło do panelu, potem jako hasło skrzynki — patrz opis klasy. Do chwili poprawnego
     * hasła odpowiedź nie zależy od tego, czy adres istnieje i czy należy do administratora.
     */
    private function performLogin(array $resp): array
    {
        $password = (string) Http::post('password', '');
        $email    = $this->flow->email();
        $ipBin    = Ip::bin();

        if ($password === '') {
            throw new PortalException(t('portal.err.password_required'));
        }
        $blocked = $this->limiter->loginBlocked($email, $ipBin);
        if ($blocked !== null) {
            $this->flow->clear();
            throw PortalException::withReset($blocked);
        }
        // 1.4.0: longer than any password we accept = wrong, answered like one without hashing it (SHA-512-crypt's
        // work grows with the length: a 100 KB password cost Dovecot 24 s; the client is counted as for a wrong one)
        if (strlen($password) > 4 * Password::MAX_LENGTH) {
            $this->flow->clear();
            $this->limiter->recordLoginFailure('webmail', $email, $ipBin);
            AuthLog::failure('webmail', $email);
            throw PortalException::withReset(t('portal.err.bad_credentials'));
        }

        $isAdmin = false;
        $admin   = (new AdminAuthenticator($this->admins))->verify($email, $password, $isAdmin);
        if ($admin !== null) {
            return $this->adminPasswordOk($resp, $email, $password, $admin);
        }

        // Nie hasło do panelu: decyduje skrzynka. Flow czyścimy PRZED logowaniem — w produkcji authenticator zamyka
        // sesję portalu (przekazanie do Roundcube), więc po tym momencie nic do niej nie zapisujemy.
        $this->flow->clear();
        if (!AuthenticatorFactory::make($this->users)->authenticate($email, $password)) {
            // Zapisy po stronie serwera (nie trafiają do odpowiedzi): limit, syslog dla Fail2Ban, dziennik prób admina.
            $this->limiter->recordLoginFailure($isAdmin ? 'admin' : 'webmail', $email, $ipBin);
            AuthLog::failure($isAdmin ? 'admin' : 'webmail', $email);
            if ($isAdmin) {
                $this->admins->logAttempt($email, false, 'Invalid password', $ipBin);
            }
            throw PortalException::withReset(t('portal.err.bad_credentials'));
        }

        return $this->webmailEntered($resp, $email);
    }

    /**
     * Poprawne hasło do panelu. Administrator bez skrzynki pod tym adresem wchodzi prosto do panelu; ze skrzynką —
     * wybiera kafelek (Webmail jest aktywny, gdy to samo hasło pasuje do skrzynki albo nie da się tego sprawdzić z hasha).
     *
     * @param array{id:int,pwd_hash:string} $admin
     */
    private function adminPasswordOk(array $resp, string $email, string $password, array $admin): array
    {
        if (!$this->users->exists($email)) {
            return $this->panelEntered($resp, $email, $admin);
        }
        $same      = $this->users->passwordMatches($email, $password);   // true | false | null (nie wiadomo)
        $preferred = $this->preferredTarget();
        $key       = $this->flow->offerChoice($admin['id'], $admin['pwd_hash'], $same === false ? null : $password);
        $webmail   = $key !== '';

        $resp['ok']         = true;
        $resp['html']       = Screens::choice($email, $preferred, $webmail, in_array($_COOKIE['mp_target'] ?? '', ['admin', 'webmail'], true));
        $resp['focus_id']   = Screens::choiceFocus($preferred, $webmail);
        $resp['choice_key'] = $key;
        $resp['step']       = 'choose';

        return $resp;
    }

    /** Krok 3 (kafelki): wejście do wybranej usługi. */
    private function chooseTarget(array $resp): array
    {
        if (!$this->flow->valid('choose')) {
            $this->flow->clear();
            throw PortalException::withReset(t('portal.err.choice_expired'));
        }
        $email = $this->flow->email();

        if (Http::post('target') !== 'webmail') {
            // hasło administratora zmienione albo konto usunięte w międzyczasie: wybór nieważny
            $row = $this->admins->findById($this->flow->adminId());
            if ($row === null || !hash_equals($this->flow->adminFp(), hash('sha256', (string) $row['pwd_hash']))) {
                $this->flow->clear();
                throw PortalException::withReset(t('portal.err.choice_expired'));
            }
            return $this->panelEntered($resp, $email, ['id' => (int) $row['id'], 'pwd_hash' => (string) $row['pwd_hash']]);
        }

        $password = $this->flow->takePassword((string) Http::post('key', ''));
        $this->flow->clear();
        if ($password === null) {
            throw PortalException::withReset(t('portal.err.choice_expired'));
        }
        // Hasło zostało sprawdzone przed chwilą (administrator). Skrzynka może je mimo to odrzucić (konto zablokowane
        // w Dovecot, chwilowa awaria IMAP) — to nie jest zgadywanie hasła, więc bez zapisu porażki do limitów.
        if (!AuthenticatorFactory::make($this->users)->authenticate($email, $password)) {
            throw PortalException::withReset(t('portal.err.webmail_refused'));
        }
        return $this->webmailEntered($resp, $email);
    }

    /** "Wróć" z ekranu hasła / kafelków: zapomnij rozpoczęte logowanie (także zaszyfrowane hasło dla kafelka). */
    private function cancelLogin(array $resp): array
    {
        $this->flow->clear();
        $resp['ok'] = true;
        return $resp;
    }

    /** @param array{id:int,pwd_hash:string} $admin */
    private function panelEntered(array $resp, string $email, array $admin): array
    {
        $this->admins->logAttempt($email, true, 'Portal login success', Ip::bin());
        $this->admins->markLogin($admin['id']);

        Session::regenerate();
        Csrf::rotate();             // 1.4.0: no token from before the sign-in stays valid in the admin session
        $_SESSION['admin_id']       = $admin['id'];
        $_SESSION['admin_username'] = $email;
        $_SESSION['admin_login_at'] = time();
        $_SESSION['admin_fp']       = hash('sha256', $admin['pwd_hash']);
        $this->flow->clear();
        $this->rememberTarget('admin');

        $resp['ok']       = true;
        $resp['redirect'] = 'panel/index.php';

        return $resp;
    }

    private function webmailEntered(array $resp, string $email): array
    {
        try {
            $this->pdo->prepare('CALL sp_register_login(?, ?)')->execute([$email, Ip::raw()]);
        } catch (Throwable $e) {
            // rejestracja zdarzenia logowania nie może blokować wejścia
        }
        $this->rememberTarget('webmail');

        $resp['ok']       = true;
        $resp['redirect'] = './?_task=mail';

        return $resp;
    }

    /**
     * Dlaczego nazwy nie wolno zająć: 'reserved' | 'taken' | 'held' (alias albo niedawno usunięte konto) | null.
     * Wspólne dla sprawdzania dostępności i rejestracji.
     */
    private function nameProblem(string $local, string $email): ?string
    {
        if (Accounts::isReserved($local, Settings::get('reserved_local_parts'))) {
            return 'reserved';
        }
        if ($this->users->exists($email)) {
            return 'taken';
        }
        if ($this->users->aliasReferences($email)
            || $this->users->recentlyDeleted($email, Settings::int('deleted_name_hold_days', 365))
            || $this->users->filesLeft($email)) {   // 1.4.0: the old mailbox is still on disk
            return 'held';
        }
        return null;
    }

    /** @return array{0:string,1:string} [local, domain] po normalizacji; wyjątek przy złym kształcie / domenie */
    private function parseName(): array
    {
        $local  = Accounts::normalize((string) Http::post('local', ''));
        $domain = Accounts::normalize((string) Http::post('domain', ''));

        if (!Accounts::validLocal($local)) {
            throw new PortalException(t('portal.reg.bad_chars'));
        }
        if (!in_array($domain, $this->users->domains(), true)) {
            throw new PortalException(t('portal.reg.bad_domain'));
        }
        if (strlen($local . '@' . $domain) > Accounts::MAX_EMAIL) {
            throw new PortalException(t('portal.reg.too_long'));
        }
        return [$local, $domain];
    }

    private function checkAvailability(array $resp): array
    {
        if (!$this->registrationEnabled()) {
            throw new PortalException(t('portal.reg.disabled'));
        }
        if (!$this->limiter->allowNameProbe(Ip::bin())) {
            throw new PortalException(t('portal.reg.too_fast'));
        }

        [$local, $domain] = $this->parseName();
        $problem = $this->nameProblem($local, $local . '@' . $domain);

        $resp['ok']     = true;
        $resp['status'] = $problem === null ? 'free' : 'taken';
        $resp['msg']    = match ($problem) {
            null       => t('portal.reg.free'),
            'reserved' => t('portal.reg.reserved'),
            'taken'    => t('portal.reg.taken'),
            default    => t('portal.reg.unavailable'),
        };

        return $resp;
    }

    private function register(array $resp): array
    {
        if (!$this->registrationEnabled()) {
            throw new PortalException(t('portal.reg.disabled'));
        }
        if (!$this->limiter->allowNameProbe(Ip::bin())) {
            throw new PortalException(t('portal.reg.too_fast'));
        }

        $pass  = (string) Http::post('password', '');
        $pass2 = (string) Http::post('password2', '');

        if (!Captcha::check('register')) {
            throw new PortalException(t('portal.err.captcha'));
        }
        [$local, $domain] = $this->parseName();
        $email = $local . '@' . $domain;
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new PortalException(t('portal.err.email_format'));
        }
        if (Accounts::isReserved($local, Settings::get('reserved_local_parts'))) {
            throw new PortalException(t('portal.reg.reserved_name'));
        }
        if ($pass !== $pass2) {
            throw new PortalException(t('portal.reg.pass_mismatch'));
        }
        if (!Password::meetsPolicy($pass)) {
            throw new PortalException(t('portal.reg.pass_policy'));
        }

        // Limity + sprawdzenie nazwy + utworzenie konta pod jedną blokadą, żeby równoległe żądania nie przekraczały limitów.
        $ipBin = Ip::bin();
        if (!$this->limiter->lockRegistration($ipBin)) {
            throw new PortalException(t('portal.reg.retry_later'));
        }
        try {
            $throttle = $this->limiter->registrationThrottle();
            if (!$throttle['ok']) {
                $this->logRegistration($email, $throttle['code'] === 'blocked' ? 'blocked' : 'failed', $throttle['code']);
                throw new PortalException($throttle['msg']);
            }

            $domainId = $this->users->domainIdForEmail($email);
            if ($domainId === null) {
                throw new PortalException(t('portal.reg.domain_config'));
            }
            $problem = $this->nameProblem($local, $email);
            if ($problem === 'taken') {
                throw new PortalException(t('portal.reg.exists'));
            }
            if ($problem !== null) {
                throw new PortalException(t('portal.reg.name_unavailable'));
            }

            $quota = Settings::int('mailbox_default_quota_mb', 1024);
            try {
                $this->users->create($domainId, Password::dovecotHash($pass), $email, $quota);
            } catch (PDOException $e) {
                if (($e->errorInfo[1] ?? 0) === 1062) {
                    throw new PortalException(t('portal.reg.exists'));
                }
                throw $e;
            }
            $this->logRegistration($email, 'success', '');
        } finally {
            $this->limiter->unlockRegistration($ipBin);
        }

        $resp['ok']  = true;
        $resp['msg'] = t('portal.reg.created');

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
