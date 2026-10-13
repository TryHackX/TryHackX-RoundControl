<?php
declare(strict_types=1);

namespace App\Panel;

use App\Admin\AdminRepository;
use App\Admin\AuditLog;
use App\Admin\LogRetention;
use App\I18n\Lang;
use App\Security\Captcha;
use App\Security\Csrf;
use App\Security\Password;
use App\Settings\Settings;
use App\Support\Database;
use App\Support\Env;
use App\Support\Html;
use App\Support\Http;
use App\Support\Ip;
use App\Support\Session;
use App\Support\View;
use App\System\Commands;
use App\Users\Accounts;
use App\Users\UserRepository;
use PDO;
use Throwable;

use function App\I18n\t;

/**
 * Kontroler panelu administratora. Rozdziela żądanie na:
 *  - mutacje POST (ustawienia, bany, quota, usuwanie, hasło, fail2ban, alias),
 *  - endpointy AJAX (listy z paginacją, statystyki, dostępność nazwy),
 *  - render strony.
 *
 * 1.2.0: każdy formularz panelu wysyła się przez fetch() (pole _ajax) i dostaje JSON
 *   {ok, msg, error, refresh?: [...], values?: {pole: wartość}, data?: {...}, done?, auth?, csrf?}
 * — komunikat zawsze pochodzi z serwera, "values" to wartości PO normalizacji (formularz pokazuje to, co zapisano),
 * "refresh" mówi, które listy / liczniki odświeżyć. Bez JavaScriptu działa jak dawniej (POST + przekierowanie, PRG).
 */
final class PanelController
{
    private PDO $pdo;
    private UserAdminRepository $users;
    private BanRepository $bans;
    private LogRepository $logs;
    private AdminRepository $admins;
    private AuditLog $audit;

    private string $success = '';
    private string $error   = '';
    /** @var string[] listy / liczniki do odświeżenia po stronie przeglądarki: users | bans | stats | audit */
    private array $refresh = [];
    /** @var array<string,string|bool> wartości pól formularza po zapisie (znormalizowane przez serwer) */
    private array $values = [];
    /** @var array<string,mixed> dodatkowe dane odpowiedzi */
    private array $data = [];
    /** Główna operacja się odbyła (np. konto usunięte), nawet jeśli $error opisuje problem z czymś pobocznym. */
    private bool $done = false;
    private bool $csrfFailed = false;
    /** Sesja zakończona (za dużo błędnych haseł w oknie potwierdzenia) — odpowiedź każe zalogować się ponownie. */
    private bool $ended = false;

    /** Ile błędnych haseł administratora w oknach potwierdzenia (usuwanie konta, zmiana hasła) kończy sesję. */
    public const REAUTH_LIMIT = 5;

    private const TPL = APP_ROOT . '/templates/panel';

    public function __construct()
    {
        $this->pdo    = Database::pdo();
        $this->users  = new UserAdminRepository($this->pdo);
        $this->bans   = new BanRepository($this->pdo);
        $this->logs   = new LogRepository($this->pdo);
        $this->admins = new AdminRepository($this->pdo);
        $this->audit  = new AuditLog($this->pdo);
    }

    public function handle(): void
    {
        // Ban ze skanera: AJAX POST z odpowiedzią JSON (bez PRG — modal skanera
        // zostaje otwarty, wyniki skanu nie przepadają).
        if (Http::isPost() && isset($_POST['ajax_scan_ban'])) {
            $this->ajaxScanBan();
        }

        // Mutacje wyłącznie przez POST + wzorzec PRG (redirect po zapisie),
        // żeby odświeżenie strony nie ponawiało operacji.
        if (Http::isPost()) {
            if (!Csrf::check(Http::post('_csrf'))) {
                $this->error = t('msg.common.csrf');
                $this->csrfFailed = true;
                $this->redirectAfterPost();
            }
            $this->handlePost();
            $this->redirectAfterPost();
        }

        if ($this->handleAjax()) {
            return; // AJAX już odpowiedział i zakończył
        }

        $this->renderPage();
    }

    /** Post/Redirect/Get: komunikat do sesji, powrót na właściwą zakładkę. */
    private function redirectAfterPost(): never
    {
        // Formularze wysłane fetch-em (pole _ajax) dostają JSON zamiast przekierowania — strona się nie przeładowuje,
        // przewinięcie, otwarte okna i stan JS (np. wyniki skanera) zostają nietknięte.
        if (Http::isAjax()) {
            $out = ['ok' => $this->error === '', 'msg' => $this->success, 'error' => $this->error];
            if ($this->refresh !== []) {
                $out['refresh'] = array_values(array_unique($this->refresh));
            }
            if ($this->values !== []) {
                $out['values'] = $this->values;
            }
            if ($this->data !== []) {
                $out['data'] = $this->data;
            }
            if ($this->done) {
                $out['done'] = true;
            }
            if ($this->csrfFailed) {
                http_response_code(403);
                $out['csrf'] = true;
            }
            if ($this->ended) {
                Session::destroy();
                http_response_code(403);
                $out['auth'] = true;
            }
            Http::json($out);
        }

        if ($this->ended) {
            Session::destroy();
            header('Location: ../portal.php');
            exit;
        }

        if ($this->success !== '') {
            $_SESSION['flash_success'] = $this->success;
        }
        if ($this->error !== '') {
            $_SESSION['flash_error'] = $this->error;
        }

        $tab  = (string) ($_POST['_tab'] ?? 'settings');
        $tabs = ['settings', 'users', 'bans', 'logs', 'fail2ban', 'security'];
        if (!in_array($tab, $tabs, true)) {
            $tab = 'settings';
        }

        // ?tab= (nie #hash): serwer od razu renderuje właściwą zakładkę — bez mignięcia pierwszej po wczytaniu
        header('Location: index.php?tab=' . $tab);
        exit;
    }

    /* ==================== POST ==================== */

    private function handlePost(): void
    {
        try {
            match (true) {
                isset($_POST['update_settings'])     => $this->saveSettings(),
                isset($_POST['update_security'])     => $this->saveSecurity(),
                isset($_POST['update_antispam'])     => $this->saveAntispam(),
                isset($_POST['change_password'])     => $this->changePassword(),
                isset($_POST['save_fail2ban'])       => $this->saveFail2ban(),
                isset($_POST['update_user_quota'])   => $this->updateUserQuota(),
                isset($_POST['reset_user_password'])  => $this->resetUserPassword(),
                isset($_POST['ban_user_ip'])         => $this->banUserIp(),
                isset($_POST['create_user'])         => $this->createUser(),
                isset($_POST['f2b_ban'])             => $this->fail2banBanIp(),
                isset($_POST['move_ban'])            => $this->moveBan(),
                isset($_POST['unban_ip'])            => $this->unbanIp(),
                isset($_POST['delete_user_confirm']) => $this->deleteUser(),
                isset($_POST['delete_alias'])        => $this->deleteAlias(),
                isset($_POST['lang_op'])             => $this->languageOp(),
                isset($_POST['log_retention_save'])  => $this->saveRetention(),
                isset($_POST['log_cleanup_now'])     => $this->cleanupLogsNow(),
                default => $this->error = t('msg.common.unknown_op'),
            };
        } catch (Throwable $e) {
            // Szczegóły (SQL, ścieżki) idą do logu serwera, nie do przeglądarki — poza trybem dev.
            error_log(sprintf('panel: wyjątek w akcji POST: [%s] %s w %s:%d', $e::class, $e->getMessage(), $e->getFile(), $e->getLine()));
            $detail = $e instanceof \RuntimeException && !$e instanceof \PDOException ? $e->getMessage() : '';
            $this->error = $this->error ?: (Env::isDev()
                ? t('msg.common.op_failed_dev', ['detail' => $e->getMessage()])
                : ($detail !== ''
                    ? t('msg.common.op_failed_detail', ['detail' => $detail])
                    : t('msg.common.op_failed')));
        }
    }

    private function saveSettings(): void
    {
        $allow = isset($_POST['registration_enabled']) ? '1' : '0';
        $recap = isset($_POST['recaptcha_enabled']) ? '1' : '0';

        $quotaMb = Quota::toMb(
            (float) ($_POST['mailbox_quota_value'] ?? 0),
            Quota::normalizeUnit($_POST['mailbox_quota_unit'] ?? 'MB')
        );

        Settings::set('allow_registration', $allow);
        Settings::set('registration_enabled', $allow);
        $captchaError = $this->saveCaptcha($recap === '1');

        // Zarezerwowane nazwy kont (poza listą wbudowaną w Accounts::BASE_RESERVED) i okres blokady usuniętych nazw.
        if (isset($_POST['reserved_local_parts'])) {
            Settings::set('reserved_local_parts', implode(',', Accounts::parseList((string) $_POST['reserved_local_parts'])));
        }
        Settings::set('deleted_name_hold_days', (string) max(0, min(3650, (int) ($_POST['deleted_name_hold_days'] ?? 365))));
        Settings::set('mailbox_default_quota_mb', (string) $quotaMb);
        Settings::set('daily_send_limit', (string) max(0, (int) ($_POST['daily_send_limit'] ?? 0)));
        $accPerIp = (string) max(1, (int) ($_POST['accounts_per_ip'] ?? 1));
        Settings::set('ip_max_accounts', $accPerIp);
        Settings::set('accounts_per_ip', $accPerIp);
        Settings::set('ip_window_hours', (string) max(1, (int) ($_POST['ip_window_hours'] ?? 24)));
        Settings::set('ip_total_limit', (string) max(1, (int) ($_POST['ip_total_limit'] ?? 999)));

        $extra = '';
        if (isset($_POST['apply_quota_to_all'])) {
            $count = $this->users->applyQuotaToAll($quotaMb);
            $extra = ' ' . t('msg.settings.quota_applied', ['count' => $count]);
            $this->refresh[] = 'users';
            $this->audit->log('QUOTA_ALL', Lang::site('audit.quota_all', ['quota' => Quota::display($quotaMb), 'count' => $count]));
        }

        $this->audit->log('SETTINGS_UPDATE', Lang::site('audit.settings_update'));
        $this->success = t('msg.settings.saved') . $extra;

        // to, co naprawdę zapisano (normalizacja i przycięcia po stronie serwera) wraca do formularza
        [$qValue, $qUnit] = Quota::toBest($quotaMb);
        $this->values = [
            'mailbox_quota_value'    => (string) $qValue,
            'mailbox_quota_unit'     => $qUnit,
            'apply_quota_to_all'     => false,
            'daily_send_limit'       => (string) Settings::int('daily_send_limit', 0),
            'registration_enabled'   => $allow === '1',
            'accounts_per_ip'        => $accPerIp,
            'ip_window_hours'        => (string) Settings::int('ip_window_hours', 24),
            'ip_total_limit'         => (string) Settings::int('ip_total_limit', 999),
            'reserved_local_parts'   => (string) Settings::get('reserved_local_parts', ''),
            'deleted_name_hold_days' => (string) Settings::int('deleted_name_hold_days', 365),
        ];
        // CAPTCHA: stan po zapisie — klucze witryny wracają, sekrety NIGDY (tylko "zapisany / brak")
        $cap = Captcha::panelState();
        $this->values['recaptcha_enabled']      = $cap['enabled'];
        $this->values['captcha_provider']       = $cap['provider'];
        $this->values['recaptcha_v3_min_score'] = $cap['min_score'];
        foreach ($cap['keys'] as $pid => $k) {
            $this->values[$pid . '_site_key']   = $k['site'];
            $this->values[$pid . '_secret_key'] = '';
        }
        $this->data['captcha_secret_set'] = array_map(static fn(array $k): bool => $k['secret_set'], $cap['keys']);
        if ($captchaError !== '') {
            $this->error = $captchaError;
        }
    }

    /**
     * CAPTCHA (1.3.0): dostawca, klucze witryny, sekrety (puste pole = zostaw zapisany; sekret nigdy nie wraca do
     * przeglądarki), próg v3. Włączenie bez klucza witryny i sekretu wybranego dostawcy jest odrzucane (CAPTCHA zostaje
     * wyłączona) — inaczej nikt, także administrator, nie przeszedłby kroku "adres e-mail". Zwraca komunikat błędu albo ''.
     */
    private function saveCaptcha(bool $enable): string
    {
        $errors   = [];
        $before   = Captcha::panelState();
        $provider = (string) ($_POST['captcha_provider'] ?? $before['provider']);
        if (!isset(Captcha::PROVIDERS[$provider])) {
            $errors[] = t('msg.captcha.bad_provider');
            $provider = $before['provider'];
        }
        Settings::set('captcha_provider', $provider);

        foreach (array_keys(Captcha::PROVIDERS) as $pid) {
            $label = t(Captcha::PROVIDERS[$pid]);
            if (isset($_POST[$pid . '_site_key'])) {
                $site = trim((string) $_POST[$pid . '_site_key']);
                if ($site === '' || Captcha::validKey($site)) {
                    Settings::set($pid . '_site_key', $site);
                } else {
                    $errors[] = t('msg.captcha.bad_site_key', ['provider' => $label]);
                }
            }
            $secret = trim((string) ($_POST[$pid . '_secret_key'] ?? ''));
            if ($secret !== '') {
                if (Captcha::validKey($secret)) {
                    Settings::set($pid . '_secret', $secret);
                } else {
                    $errors[] = t('msg.captcha.bad_secret', ['provider' => $label]);
                }
            }
        }
        if (isset($_POST['recaptcha_v3_min_score'])) {
            $raw = str_replace(',', '.', trim((string) $_POST['recaptcha_v3_min_score']));
            $score = is_numeric($raw) ? max(0.0, min(1.0, round((float) $raw, 2))) : 0.5;
            Settings::set('recaptcha_v3_min_score', (string) $score);
        }

        $cfg = Captcha::config();
        $cfg['recaptcha_enabled'] = $enable ? '1' : '0';
        if ($enable && !Captcha::activeIn($cfg)) {
            $enable   = false;
            $errors[] = t('msg.captcha.enable_without_keys', ['provider' => t(Captcha::PROVIDERS[$provider])]);
        }
        Settings::set('recaptcha_enabled', $enable ? '1' : '0');
        if ($before['enabled'] !== $enable || ($enable && $before['provider'] !== $provider)) {
            $this->audit->log('CAPTCHA', Lang::site($enable ? 'audit.captcha_on' : 'audit.captcha_off', ['provider' => $provider]));
        }
        return $errors === [] ? '' : implode(' ', $errors);
    }

    /** Języki interfejsu (1.3.0): włącz/wyłącz, domyślny, kopia, wgranie pakietu, usunięcie — logika w LanguageAdmin. */
    private function languageOp(): void
    {
        $r = (new LanguageAdmin($this->audit))->handle($_POST, $_FILES);
        $this->success = $r['ok'] ? $r['msg'] : '';
        $this->error   = $r['ok'] ? '' : $r['msg'];
        $listing = LanguageAdmin::listing();
        $this->data['languages_html'] = View::render(self::TPL . '/partials/lang_rows.php', ['lang' => $listing, 'csrf' => Csrf::token()]);
        // 1.3.1: przełącznik języka w nagłówku i lista "język źródłowy" od razu po zmianie (bez przeładowania strony)
        $this->data['lang_switch_html'] = View::render(APP_ROOT . '/templates/partials/lang_switch.php', ['langSwitchTab' => 'settings']);
        $this->data['lang_sources'] = array_map(static fn(array $r): array => [$r['code'], strtoupper($r['code']) . ' — ' . $r['name']], $listing['rows']);
        if (isset($r['dropped'])) {
            $this->data['dropped'] = $r['dropped'];
        }
    }

    /** Przechowywanie logów (1.3.2): zapis okna (90 / 180 / 365 / 730 dni / 0 = bez limitu). */
    private function saveRetention(): void
    {
        $raw  = (string) ($_POST['log_retention_days'] ?? '');
        $days = ctype_digit($raw) ? (int) $raw : -1;
        if (!in_array($days, LogRetention::OPTIONS, true)) {
            $this->error = t('msg.retention.bad_value');
            return;
        }
        Settings::set('log_retention_days', (string) $days);
        $this->audit->log('LOG_RETENTION_SET', Lang::site('audit.retention_set', ['what' => Lang::site('panel.retention.opt_' . $days)]));
        $this->success = t('msg.retention.saved', ['what' => t('panel.retention.opt_' . $days)]);
        $this->values  = ['log_retention_days' => (string) $days];
        $this->data['retention_note'] = LogRetention::note();
    }

    /** "Wyczyść teraz": jeden przebieg sprzątania od razu (CSRF i sesję administratora sprawdza handle()). */
    private function cleanupLogsNow(): void
    {
        if (LogRetention::days() === 0) {
            $this->success = t('msg.retention.never');
            return;
        }
        $n = LogRetention::run($this->pdo, true);
        $this->success = t('msg.retention.cleaned', [
            'total' => array_sum($n), 'audit' => $n['audit'], 'admin' => $n['admin_logins'], 'reg' => $n['registrations'],
            'user' => $n['user_logins'], 'send' => $n['send_counters'], 'del' => $n['deleted_accounts'],
        ]);
        $this->refresh[] = 'audit';
        $this->data['retention_last'] = LogRetention::lastText();
    }

    private function saveSecurity(): void
    {        $maxLog = (string) max(1, (int) ($_POST['max_login_attempts'] ?? 5));
        $banMin = (string) max(1, (int) ($_POST['ban_duration_minutes'] ?? 15));

        Settings::set('admin_lock_threshold', $maxLog);
        Settings::set('max_login_attempts', $maxLog);
        Settings::set('admin_lock_minutes', $banMin);
        Settings::set('ban_duration_minutes', $banMin);

        $this->audit->log('SECURITY_UPDATE', Lang::site('audit.security_update', ['max' => $maxLog, 'min' => $banMin]));
        $this->success = t('msg.security.saved');
        $this->values  = ['max_login_attempts' => $maxLog, 'ban_duration_minutes' => $banMin];
    }

    private function saveAntispam(): void
    {
        $rcMax  = (int) ($_POST['rc_max_recipients'] ?? 0);
        $rcMsg  = (int) ($_POST['pf_message_rate_limit'] ?? 0);
        $pfConn = (int) ($_POST['pf_connection_rate_limit'] ?? 0);
        $pfCnt  = (int) ($_POST['pf_connection_count_limit'] ?? 0);

        Settings::set('rc_max_recipients', (string) $rcMax);
        Settings::set('rc_sendmail_delay', (string) (int) ($_POST['rc_sendmail_delay'] ?? 0));
        Settings::set('rc_login_rate_limit', (string) (int) ($_POST['rc_login_rate_limit'] ?? 0));
        Settings::set('pf_message_rate_limit', (string) $rcMsg);
        Settings::set('pf_connection_rate_limit', (string) $pfConn);
        Settings::set('pf_connection_count_limit', (string) $pfCnt);

        $failed = false;
        if (Commands::postfixAvailable()) {
            $r = Commands::postfixApply($rcMsg, $pfConn, $pfCnt);
            if ($r['ok']) {
                $done = t('msg.antispam.saved_applied');
            } else {
                // Skrypt kończy się kodem != 0, gdy zapis do main.cf się nie udał albo wartość po zapisie jest inna
                // niż żądana — nie raportujemy wtedy sukcesu (patrz sekcja o ReadWritePaths w README).
                $failed = true;
                $done = t('msg.antispam.postfix_failed', ['detail' => $r['output'] !== '' ? $r['output'] : t('msg.antispam.script_error')]);
            }
        } else {
            $done = t('msg.antispam.saved_no_sudo');
        }

        $this->audit->log('ANTISPAM_UPDATE', $failed
            ? Lang::site('audit.antispam_update_failed', ['rc' => $rcMax, 'pf' => $rcMsg])
            : Lang::site('audit.antispam_update', ['rc' => $rcMax, 'pf' => $rcMsg]));
        foreach (['rc_max_recipients', 'rc_sendmail_delay', 'rc_login_rate_limit', 'pf_message_rate_limit',
                  'pf_connection_rate_limit', 'pf_connection_count_limit'] as $k) {
            $this->values[$k] = (string) Settings::int($k, 0);
        }
        if ($failed) {
            $this->error = $done;
        } else {
            $this->success = $done;
        }
    }

    private function changePassword(): void
    {
        $cur  = (string) ($_POST['current_password'] ?? '');
        $new  = (string) ($_POST['new_password'] ?? '');
        $conf = (string) ($_POST['confirm_password'] ?? '');

        if ($new !== $conf) {
            $this->error = t('msg.password.mismatch');
            return;
        }
        // Jedna polityka z hasłami skrzynek (długość + klasy znaków z ustawień), ale administrator: min. 12 znaków.
        if (!Password::meetsAdminPolicy($new)) {
            $this->error = t('msg.password.admin_policy', ['min' => max(Password::ADMIN_MIN_LENGTH, Settings::int('pwd_min_length', 10))]);
            return;
        }

        $admin = $this->reauth($cur, Lang::site('audit.reauth_what_password'));
        if ($admin === null) {
            $this->values = ['current_password' => ''];
            if (!$this->ended) {
                $this->error = t('msg.password.wrong_current');
            }
            return;
        }

        $newHash = Password::adminHash($new);
        $this->admins->updatePassword((int) $admin['id'], $newHash);
        // Inne sesje tego admina przestają być ważne (odcisk hasha w sesji), ta dostaje nowy identyfikator i odcisk.
        $_SESSION['admin_fp'] = hash('sha256', $newHash);
        \App\Support\Session::regenerate();
        $this->audit->log('ADMIN_PASS_CHANGE', Lang::site('audit.admin_pass_change'));
        $this->success = t('msg.password.changed');
        $this->values  = ['current_password' => '', 'new_password' => '', 'confirm_password' => ''];
    }

    /**
     * Potwierdzenie operacji hasłem administratora (usuwanie konta, zmiana hasła). 1.2.0: błędne hasło jest zapisywane
     * w dzienniku audytowym panelu (REAUTH_FAILED) i w syslogu jako "reauth-failure" (filtr Fail2Ban celowo go nie liczy
     * — AuthLog); po REAUTH_LIMIT błędach w tej sesji sesja się kończy (REAUTH_LOCKOUT), więc przejętą sesją nie da się
     * zgadywać hasła administratora bez końca.
     *
     * @return array{id:int,pwd_hash:string}|null
     */
    private function reauth(string $password, string $what): ?array
    {
        $admin = $this->admins->findById(\App\Admin\AdminGuard::id());
        if ($admin !== null && $password !== '' && Password::verifyAdmin($password, (string) $admin['pwd_hash'])) {
            $_SESSION['reauth_fail'] = 0;
            return ['id' => (int) $admin['id'], 'pwd_hash' => (string) $admin['pwd_hash']];
        }

        $fails = (int) ($_SESSION['reauth_fail'] ?? 0) + 1;
        $_SESSION['reauth_fail'] = $fails;
        $this->audit->log('REAUTH_FAILED', Lang::site('audit.reauth_failed', ['what' => $what, 'n' => $fails, 'max' => self::REAUTH_LIMIT]));
        \App\Support\AuthLog::reauthFailure(\App\Admin\AdminGuard::username());
        $this->refresh[] = 'audit';

        if ($fails >= self::REAUTH_LIMIT) {
            $this->audit->log('REAUTH_LOCKOUT', Lang::site('audit.reauth_lockout', ['max' => self::REAUTH_LIMIT]));
            $this->ended = true;
            $this->error = t('msg.reauth.too_many');
        }
        return null;
    }

    private function saveFail2ban(): void
    {
        // Wyjątek (z komunikatem helpera) oznacza: odrzucone, nie zapisano / nie sprawdzono / nie przeładowano.
        // Brak wyjątku = helper sam zweryfikował zapis (odczyt zwrotny), przeszedł `fail2ban-client -t` i przeładował usługę.
        $msg = Commands::fail2banSaveConfig($_POST);

        $this->values = $this->f2bFormValues();
        if ($msg === 'unchanged' || $msg === 'bez zmian') {   // odpowiedź helpera: od 1.3.1 po angielsku; "bez zmian" = helper sprzed 1.3.1
            $this->success = t('msg.f2b.unchanged');
            return;
        }
        $this->audit->log('FAIL2BAN_UPDATE', Lang::site('audit.fail2ban_update', ['result' => $msg]));
        $this->success = t('msg.f2b.saved');
    }

    /**
     * Pola formularza Fail2Ban => [sekcja jail.local, klucz, domyślna, rodzaj]. Jedno źródło dla strony (page.php)
     * i dla wartości odsyłanych po zapisie.
     *
     * @return array<string,array{0:string,1:string,2:string,3:string}>
     */
    public static function f2bFields(): array
    {
        $f = [
            'f2b_default_findtime'          => ['DEFAULT', 'findtime', '10m', 'text'],
            'f2b_default_bantime'           => ['DEFAULT', 'bantime', '1h', 'text'],
            'f2b_default_maxretry'          => ['DEFAULT', 'maxretry', '6', 'number'],
            'f2b_default_bantime_factor'    => ['DEFAULT', 'bantime.factor', '1.5', 'text'],
            'f2b_default_bantime_maxtime'   => ['DEFAULT', 'bantime.maxtime', '1w', 'text'],
            'f2b_default_bantime_increment' => ['DEFAULT', 'bantime.increment', 'true', 'check'],
            'f2b_default_ignoreip'          => ['DEFAULT', 'ignoreip', '', 'text'],
        ];
        foreach (['roundcube' => 'roundcube-auth', 'dovecot' => 'dovecot', 'postfix' => 'postfix-sasl', 'recidive' => 'recidive'] as $p => $sec) {
            $f["f2b_{$p}_enabled"]  = [$sec, 'enabled', 'true', 'check'];
            $f["f2b_{$p}_findtime"] = [$sec, 'findtime', '10m', 'text'];
            $f["f2b_{$p}_maxretry"] = [$sec, 'maxretry', '6', 'number'];
            $f["f2b_{$p}_bantime"]  = [$sec, 'bantime', '2h', 'text'];
        }
        return $f;
    }

    /** @return array<string,string|bool> wartości formularza Fail2Ban odczytane z jail.local (po zapisie) */
    private function f2bFormValues(): array
    {
        $cfg = Commands::fail2banLoadConfig();
        $out = [];
        foreach (self::f2bFields() as $name => [$sec, $key, $def, $kind]) {
            $v = (string) ($cfg['values'][$sec][$key] ?? $def);
            $out[$name] = $kind === 'check' ? $v === 'true' : $v;
        }
        return $out;
    }

    private function updateUserQuota(): void
    {
        $id    = (int) ($_POST['user_id'] ?? 0);
        $email = $this->users->emailById($id);
        if ($email === null) {
            $this->error = t('msg.users.gone_refreshed');
            $this->refresh[] = 'users';
            return;
        }
        $mb  = Quota::toMb(
            (float) ($_POST['user_quota_value'] ?? 0),
            Quota::normalizeUnit($_POST['user_quota_unit'] ?? 'MB')
        );
        $this->users->updateQuota($id, $mb);

        // Indywidualny dzienny limit wysyłki: puste pole = globalny (NULL), 0 = bez limitu.
        $limitRaw = trim((string) ($_POST['user_send_limit'] ?? ''));
        $limit    = $limitRaw === '' ? null : max(0, (int) $limitRaw);
        $this->users->updateSendLimit($id, $limit);

        $this->audit->log('USER_QUOTA', Lang::site('audit.user_quota', [
            'email' => $email, 'id' => $id, 'quota' => Quota::display($mb), 'limit' => self::limitText($limit, true),
        ]));
        $this->success = t('msg.users.quota_updated', [
            'email' => $email, 'quota' => Quota::display($mb), 'limit' => self::limitText($limit),
        ]);
        $this->refresh[] = 'users';
        $this->data['id'] = $id;
    }

    /**
     * Opis dziennego limitu wysyłki konta (null = globalny, 0 = bez limitu). $audit = język serwisu (dziennik audytowy),
     * w przeciwnym razie język administratora.
     */
    private static function limitText(?int $limit, bool $audit = false): string
    {
        $code = $audit ? Lang::defaultLanguage() : null;
        return match (true) {
            $limit === null => Lang::t('msg.users.limit_global', [], $code),
            $limit === 0    => Lang::t('msg.users.limit_none', [], $code),
            default         => Lang::t('msg.users.limit_per_day', ['n' => $limit], $code),
        };
    }

    /** Czas bana w dniach (od 2 dni) albo godzinach; $audit jak w limitText(). */
    private static function durationText(int $seconds, bool $audit = false): string
    {
        $code = $audit ? Lang::defaultLanguage() : null;
        return $seconds >= 172800
            ? Lang::t('msg.bans.dur_days', ['n' => intdiv($seconds, 86400)], $code)
            : Lang::t('msg.bans.dur_hours', ['n' => intdiv($seconds, 3600)], $code);
    }

    /** Reset hasła skrzynki przez administratora (modal na karcie Użytkownicy). */
    private function resetUserPassword(): void
    {
        $id    = (int) ($_POST['user_id'] ?? 0);
        $email = (string) ($_POST['user_email'] ?? '');
        $pass  = (string) ($_POST['new_password'] ?? '');
        $pass2 = (string) ($_POST['new_password2'] ?? '');

        $stored = $this->users->emailById($id);
        if ($stored === null || Accounts::normalize($stored) !== Accounts::normalize($email)) {
            $this->error = t('msg.users.bad_data');
            return;
        }
        $email = Accounts::normalize($stored);
        if ($pass !== $pass2) {
            $this->error = t('msg.common.pass_mismatch');
            return;
        }
        if (!Password::meetsPolicy($pass)) {
            $this->error = t('msg.common.pass_policy');
            return;
        }

        $this->users->updatePassword($id, Password::dovecotHash($pass));
        $this->audit->log('USER_PASS_RESET', Lang::site('audit.user_pass_reset', ['email' => $email, 'id' => $id]));
        $this->success = t('msg.users.pass_reset', ['email' => $email]);
        $this->values  = ['new_password' => '', 'new_password2' => ''];
    }

    private function banUserIp(): void
    {
        $ip    = trim((string) ($_POST['ban_ip'] ?? ''));
        $ipBin = Ip::toBin($ip);
        if ($ipBin === null) {
            $this->error = t('msg.common.bad_ip');
            return;
        }
        if ($this->isScanTrusted($ip)) {
            $this->error = t('msg.bans.trusted');
            return;
        }
        $reason   = trim((string) ($_POST['ban_reason'] ?? Lang::site('audit.ban_reason_default')));
        $note     = trim((string) ($_POST['ban_note'] ?? ''));
        $duration = (int) ($_POST['ban_duration'] ?? 60);
        if ($duration < 1 || $duration > 129600) {      // 1 minuta .. 90 dni
            $this->error = t('msg.bans.duration_range');
            return;
        }
        if ($note !== '') {
            $reason .= ' - ' . $note;
        }
        $reason = mb_substr($reason, 0, 240);

        // Zakres 1.1.0: ban działa w DWÓCH warstwach. (1) ip_blocks — portal i panel (logowanie, rejestracja);
        // (2) jail Fail2Ban panel-tempban — firewall, czyli także webmail, IMAP i SMTP. Gdy Fail2Ban jest
        // niedostępny, ban obejmuje tylko portal/panel i komunikat mówi to wprost.
        $f2b = false;
        if (in_array('panel-tempban', Commands::fail2banJails(), true)) {
            $f2b = Commands::fail2banSetBantime('panel-tempban', max(60, $duration * 60))
                && Commands::fail2banBan('panel-tempban', $ip);
        }
        $this->bans->ban($ipBin, $duration, $reason . ($f2b ? BanRepository::F2B_MARK : ''));
        $this->refresh = array_merge($this->refresh, ['bans', 'stats']);
        $banArgs = ['ip' => $ip, 'min' => $duration];
        $this->audit->log('BAN_IP', $f2b
            ? Lang::site('audit.ban_ip_f2b', $banArgs + ['reason' => $reason])
            : Lang::site('audit.ban_ip_local', $banArgs + ['reason' => $reason]));
        $this->success = $f2b
            ? t('msg.bans.banned_f2b', $banArgs)
            : t('msg.bans.banned_local', $banArgs);
    }

    private function deleteUser(): void
    {
        $id    = (int) ($_POST['delete_user_id'] ?? 0);
        $email = (string) ($_POST['delete_user_email'] ?? '');
        $pass  = (string) ($_POST['admin_confirm_password'] ?? '');

        $this->values = ['admin_confirm_password' => ''];
        $what = Lang::site('audit.reauth_what_delete', ['email' => Accounts::normalize($email), 'id' => $id]);
        if ($this->reauth($pass, $what) === null) {
            if (!$this->ended) {
                $left = self::REAUTH_LIMIT - (int) ($_SESSION['reauth_fail'] ?? 0);
                $this->error = $left > 0
                    ? t('msg.users.delete_bad_pass_left', ['left' => $left])
                    : t('msg.users.delete_bad_pass');
            }
            return;
        }
        $stored = $this->users->emailById($id);
        if ($stored === null || Accounts::normalize($stored) !== Accounts::normalize($email)) {
            $this->error = t('msg.users.gone_mismatch');
            $this->refresh[] = 'users';
            return;
        }
        $email = Accounts::normalize($stored);   // skrzynka na dysku jest zawsze pod nazwą małymi literami

        // Konto, aliasy kierujące na nie, historia logowań i licznik wysyłki znikają w jednej transakcji; zostaje
        // "nagrobek" (rejestracja publiczna nie odda tej nazwy od razu) — patrz UserAdminRepository::deleteAccount().
        $r = $this->users->deleteAccount($id, $email);
        $this->done = true;
        $this->refresh = array_merge($this->refresh, ['users', 'stats']);
        $this->data['id'] = $id;

        $extra    = [];   // zdania dopisywane do komunikatu (język administratora)
        $extraLog = [];   // te same zdania do dziennika audytowego (język serwisu)
        if ($r['aliases'] > 0) {
            $extra[]    = t('msg.users.deleted_aliases', ['count' => $r['aliases']]);
            $extraLog[] = Lang::site('msg.users.deleted_aliases', ['count' => $r['aliases']]);
        }
        if ($r['lists'] !== []) {
            $ids = implode(', ', array_map(static fn(array $a): string => '#' . $a['id'] . ' (' . $a['source'] . ')', $r['lists']));
            $extra[]    = t('msg.users.deleted_lists', ['ids' => $ids]);
            $extraLog[] = Lang::site('msg.users.deleted_lists', ['ids' => $ids]);
        }

        $filesOk = true;
        $code    = -1;
        if (Commands::mailboxDeleteAvailable()) {
            $f = Commands::mailboxDelete($email);
            $code = $f['code'];
            $filesOk = $f['ok'] || $f['code'] === 4;
            $files = ' ' . Commands::mailboxDeleteMessage($f['code']);
        } else {
            $filesOk = false;
            $files = ' ' . t('msg.users.delete_files_no_sudo');
        }

        $delArgs = ['email' => $email, 'id' => $id];
        $this->audit->log('DELETE_USER', match (true) {
            $code === 0 => Lang::site('audit.delete_user_moved', $delArgs),
            $code === 4 => Lang::site('audit.delete_user_nodir', $delArgs),
            default     => Lang::site('audit.delete_user_kept', $delArgs),
        } . ($extraLog === [] ? '' : ' ' . implode(' ', $extraLog)));
        $this->success = t('msg.users.deleted', ['email' => $email]) . ($extra === [] ? '' : ' ' . implode(' ', $extra));
        if ($filesOk) {
            $this->success .= $files;
        } else {
            // Konto zniknęło z bazy, ale skrzynka jest na dysku: ma być widoczne jako błąd, nie jako dopisek do sukcesu.
            $this->error = trim($files);
        }
    }

    /** Adresy, przez które urzędy certyfikacji potwierdzają domenę (CA/B Forum BR 3.2.2.4.4). */
    private const CA_NAMES = ['admin', 'administrator', 'hostmaster', 'postmaster', 'webmaster'];

    /**
     * Ocena nazwy konta dla administratora (tworzenie konta i podgląd dostępności).
     *
     * status: free | taken (konto istnieje) | alias (nazwa jest ŹRÓDŁEM aliasu — poczta idzie gdzie indziej) |
     *         reserved (lista zarezerwowanych) | held (alias wskazuje na ten adres albo konto niedawno usunięto).
     * Administrator może świadomie zająć reserved / held (pole nu_allow_reserved, override = true); taken i alias — nigdy:
     * skrzynka o nazwie aliasu nie dostałaby żadnej poczty (Postfix rozwiązuje virtual_alias_maps PRZED doręczeniem do
     * skrzynek), więc zgoda nic by nie dała — wyjściem jest usunięcie aliasu (deleteAlias) albo pozostawienie go.
     *
     * @return array{status:string,msg:string,detail:string,override:bool,aliases:array<int,array{id:int,source:string,destination:string}>}
     */
    private function classifyName(UserRepository $users, string $local, string $email, ?string $code = null): array
    {
        // $code = język tekstów (null = język administratora; kod języka serwisu dla wpisu w dzienniku audytowym)
        $out = ['status' => 'free', 'msg' => Lang::t('msg.name.free', [], $code), 'detail' => '', 'override' => false, 'aliases' => []];

        if ($users->exists($email)) {
            return ['status' => 'taken', 'msg' => Lang::t('msg.name.taken', [], $code),
                    'detail' => Lang::t('msg.name.taken_detail', ['email' => $email], $code)] + $out;
        }
        $from = $users->aliasesFrom($email);
        if ($from !== []) {
            $dest = implode('; ', array_map(static fn(array $a): string => $a['destination'], $from));
            return ['status' => 'alias', 'msg' => Lang::t('msg.name.alias', [], $code),
                    'detail' => Lang::t('msg.name.alias_detail', ['email' => $email, 'dest' => $dest], $code),
                    'aliases' => $from] + $out;
        }

        $reasons = [];
        $res = Accounts::reservedBy($local, Settings::get('reserved_local_parts'));
        if ($res !== null) {
            [$which, $pattern] = $res;
            $out['status'] = 'reserved';
            $out['msg']    = $which === 'base' ? Lang::t('msg.name.reserved_base', [], $code) : Lang::t('msg.name.reserved_list', [], $code);
            $reasons[] = match (true) {
                $which === 'list' => Lang::t('msg.name.why_list', ['pattern' => $pattern], $code),
                in_array($pattern, self::CA_NAMES, true) => Lang::t('msg.name.why_ca', ['local' => $local], $code),
                default => Lang::t('msg.name.why_system', ['pattern' => $pattern], $code),
            };
        }
        $days    = Settings::int('deleted_name_hold_days', 365);
        $deleted = $users->deletedAt($email, $days);
        $to      = $users->aliasesPointingTo($email);
        if ($deleted !== null) {
            $out['status'] = $out['status'] === 'free' ? 'held' : $out['status'];
            $out['msg']    = $out['status'] === 'held' ? Lang::t('msg.name.held_deleted', [], $code) : $out['msg'];
            $reasons[] = Lang::t('msg.name.why_deleted', ['email' => $email, 'when' => substr($deleted, 0, 16), 'days' => $days], $code);
        }
        if ($to !== []) {
            $out['status'] = $out['status'] === 'free' ? 'held' : $out['status'];
            $out['msg']    = $out['status'] === 'held' && $deleted === null ? Lang::t('msg.name.held_alias', [], $code) : $out['msg'];
            $list = implode(', ', array_map(
                static fn(array $a): string => $a['exact'] ? $a['source'] : Lang::t('msg.name.list_item', ['source' => $a['source']], $code),
                $to
            ));
            $reasons[] = Lang::t('msg.name.why_alias_to', ['list' => $list, 'email' => $email], $code);
            $out['aliases'] = array_map(static fn(array $a): array => ['id' => $a['id'], 'source' => $a['source'], 'destination' => $a['destination']], $to);
        }
        if ($out['status'] !== 'free') {
            $out['override'] = true;
            $out['detail']   = implode(' ', $reasons) . ' ' . Lang::t('msg.name.override_hint', [], $code);
        }
        return $out;
    }

    /** Ręczne utworzenie konta pocztowego przez administratora (modal na karcie Użytkownicy). */
    private function createUser(): void
    {
        $local  = Accounts::normalize((string) ($_POST['nu_local'] ?? ''));
        $domain = Accounts::normalize((string) ($_POST['nu_domain'] ?? ''));
        $pass   = (string) ($_POST['nu_password'] ?? '');
        $quota  = Quota::toMb(
            (float) ($_POST['nu_quota_value'] ?? 0),
            Quota::normalizeUnit($_POST['nu_quota_unit'] ?? 'MB')
        );

        $users = new UserRepository($this->pdo);

        if (!Accounts::validLocal($local)) {
            $this->error = t('msg.users.bad_local');
            return;
        }
        if (!in_array($domain, $users->domains(), true)) {
            $this->error = t('msg.users.bad_domain');
            return;
        }
        $email = $local . '@' . $domain;
        if (strlen($email) > Accounts::MAX_EMAIL || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->error = t('msg.users.bad_email');
            return;
        }
        if ($pass !== (string) ($_POST['nu_password2'] ?? '')) {
            $this->error = t('msg.common.pass_mismatch');
            return;
        }
        if (!Password::meetsPolicy($pass)) {
            $this->error = t('msg.common.pass_policy');
            return;
        }

        $name     = $this->classifyName($users, $local, $email);
        $status   = $name['status'];
        $override = false;
        $overrideWhy = '';
        $this->data['name'] = $name;     // modal pokazuje powód i (dla aliasu) przycisk usunięcia aliasu
        if ($status === 'taken') {
            $this->error = t('msg.users.exists', ['email' => $email]);
            return;
        }
        if ($status === 'alias') {
            $dest = implode('; ', array_map(static fn(array $a): string => $a['destination'], $name['aliases']));
            $this->error = t('msg.users.is_alias', ['email' => $email, 'dest' => $dest]);
            return;
        }
        if ($status !== 'free') {
            if (empty($_POST['nu_allow_reserved'])) {
                $this->error = t('msg.users.need_consent', ['email' => $email, 'why' => mb_strtolower($name['msg'])]);
                return;
            }
            $override = true;
            // powód do dziennika audytowego w języku serwisu — oceniany TERAZ, przed utworzeniem konta (potem nazwa byłaby "zajęta")
            $overrideWhy = mb_strtolower($this->classifyName($users, $local, $email, Lang::defaultLanguage())['msg']);
        }
        $domainId = $users->domainIdForEmail($email);
        if ($domainId === null) {
            $this->error = t('msg.users.domain_config');
            return;
        }

        try {
            $users->create($domainId, Password::dovecotHash($pass), $email, $quota);
        } catch (\PDOException $e) {
            if (($e->errorInfo[1] ?? 0) === 1062) {
                $this->error = t('msg.users.exists_race', ['email' => $email]);
                $this->refresh[] = 'users';
                return;
            }
            throw $e;
        }
        $newId = (int) $this->pdo->lastInsertId();

        // Indywidualny dzienny limit wysyłki (puste = globalny, 0 = bez limitu)
        $limitRaw = trim((string) ($_POST['nu_send_limit'] ?? ''));
        $limit = null;
        if ($limitRaw !== '' && $newId > 0) {
            $limit = max(0, (int) $limitRaw);
            $this->users->updateSendLimit($newId, $limit);
        }

        $createArgs = ['email' => $email, 'quota' => $quota . 'MB', 'limit' => self::limitText($limit, true)];
        $this->audit->log('CREATE_USER', $override
            ? Lang::site('audit.create_user_override', $createArgs + ['why' => $overrideWhy])
            : Lang::site('audit.create_user', $createArgs));
        $this->success = t('msg.users.created', ['email' => $email, 'quota' => Quota::display($quota), 'limit' => self::limitText($limit)]);
        $this->refresh = array_merge($this->refresh, ['users', 'stats']);
        $this->data    = ['id' => $newId, 'email' => $email];
        $this->values  = ['nu_password' => '', 'nu_password2' => ''];
    }

    /**
     * Usunięcie aliasu (1.2.0) — wyjście z sytuacji "nazwa jest źródłem aliasu" w oknie Dodaj użytkownika. Konto bazy
     * panelu ma DELETE na virtual_aliases (usuwanie konta zdejmuje aliasy kierujące na nie), ale nie INSERT/UPDATE: panel
     * nie odtworzy usuniętego aliasu, dlatego pełny wiersz trafia do dziennika audytowego.
     */
    private function deleteAlias(): void
    {
        $id     = (int) ($_POST['alias_id'] ?? 0);
        $source = Accounts::normalize((string) ($_POST['alias_source'] ?? ''));
        $users  = new UserRepository($this->pdo);
        $row    = $users->aliasById($id);
        if ($row === null || Accounts::normalize($row['source']) !== $source) {
            $this->error = t('msg.alias.gone');
            $this->data['source'] = $source;
            return;
        }
        $users->deleteAlias($id);
        $aliasArgs = ['id' => $row['id'], 'source' => $row['source'], 'dest' => $row['destination']];
        $this->audit->log('DELETE_ALIAS', Lang::site('audit.delete_alias', $aliasArgs));
        $this->success = t('msg.alias.deleted', $aliasArgs);
        $this->data['source'] = $row['source'];
    }

    /** Ręczny ban IP w wybranym jailu Fail2Ban (formularz w zakładce Blokady IP). */
    private function fail2banBanIp(): void
    {
        $ip   = trim((string) ($_POST['f2b_ip'] ?? ''));
        $jail = (string) ($_POST['f2b_jail'] ?? '');

        if (Ip::toBin($ip) === null) {
            $this->error = t('msg.common.bad_ip');
            return;
        }
        if (!in_array($jail, Commands::fail2banJails(), true)) {
            $this->error = t('msg.f2b.bad_jail');
            return;
        }

        if (Commands::fail2banBan($jail, $ip)) {
            $this->audit->log('F2B_BAN', Lang::site('audit.f2b_ban', ['ip' => $ip, 'jail' => $jail]));
            $this->success = t('msg.f2b.banned', ['ip' => $ip, 'jail' => $jail]);
            $this->refresh = array_merge($this->refresh, ['bans', 'stats']);
        } else {
            $this->error = t('msg.f2b.ban_failed', ['cmd' => 'fail2ban-client set <jail> banip <ip>']);
        }
    }

    /** Zmiana typu bana: przeniesienie IP z jednego jaila Fail2Ban do innego. */
    private function moveBan(): void
    {
        $ip   = trim((string) ($_POST['ip'] ?? ''));
        $from = (string) ($_POST['from_jail'] ?? '');
        $to   = (string) ($_POST['to_jail'] ?? '');

        if (Ip::toBin($ip) === null) {
            $this->error = t('msg.common.bad_ip');
            return;
        }
        $jails = Commands::fail2banJails();
        if (!in_array($to, $jails, true)) {
            $this->error = t('msg.f2b.bad_jail_to');
            return;
        }
        if ($from !== '' && !in_array($from, $jails, true)) {
            $this->error = t('msg.f2b.bad_jail_from');
            return;
        }
        if ($from !== '') {
            Commands::fail2banUnban($from, $ip);
        }

        // Ban czasowy: przy przenoszeniu do panel-tempban można podać czas
        // (1h..3 miesiące) — ustawiany tak samo jak przy banie ze skanera.
        $durTxt = '';   // " na 3 dni" w języku administratora
        $durLog = '';   // to samo w języku serwisu (dziennik audytowy)
        $dur    = (int) ($_POST['duration'] ?? 0);
        if ($to === 'panel-tempban' && $dur >= 3600 && $dur <= 7776000) {
            if (!Commands::fail2banSetBantime('panel-tempban', $dur)) {
                $this->error = t('msg.f2b.bantime_failed', ['cmd' => 'set panel-tempban bantime <' . t('msg.f2b.seconds') . '>']);
                return;
            }
            $durTxt = t('msg.bans.for_dur', ['dur' => self::durationText($dur)]);
            $durLog = Lang::site('msg.bans.for_dur', ['dur' => self::durationText($dur, true)]);
        }

        if (Commands::fail2banBan($to, $ip)) {
            $move = ['ip' => $ip, 'from' => $from, 'to' => $to];
            $this->audit->log('MOVE_BAN', $durLog === ''
                ? Lang::site('audit.move_ban', $move)
                : Lang::site('audit.move_ban_for', $move + ['dur' => $durLog]));
            $this->success = $durTxt === ''
                ? t('msg.bans.moved', $move)
                : t('msg.bans.moved_for', $move + ['dur' => $durTxt]);
            $this->refresh = array_merge($this->refresh, ['bans', 'stats']);
        } else {
            $this->error = t('msg.f2b.move_failed');
        }
    }

    private function unbanIp(): void
    {
        $ip   = trim((string) ($_POST['ip'] ?? ''));
        $type = (string) ($_POST['unban_type'] ?? 'local');

        $ipBin = Ip::toBin($ip);
        if ($ipBin === null) {
            $this->error = t('msg.common.bad_ip');
            return;
        }

        if ($type === 'fail2ban' && !empty($_POST['jail'])) {
            $jail = (string) $_POST['jail'];
            if (!in_array($jail, Commands::fail2banJails(), true)) {
                $this->error = t('msg.f2b.bad_jail');
                return;
            }
            if (Commands::fail2banUnban($jail, $ip)) {
                $this->audit->log('UNBAN_IP', Lang::site('audit.unban_f2b', ['ip' => $ip, 'jail' => $jail]));
                $this->success = t('msg.bans.unbanned_f2b', ['ip' => $ip]);
                $this->refresh = array_merge($this->refresh, ['bans', 'stats']);
            } else {
                $this->error = t('msg.f2b.cmd_failed');
            }
            return;
        }

        // Ban lokalny założony przez "Zbanuj" ma też wpis w jailu panel-tempban (znacznik w powodzie) — zdejmujemy oba.
        $reason = $this->bans->reasonFor($ipBin);
        $also   = '';   // '' | 'also' (zdjęto też wpis w Fail2Ban) | 'warn' (wpisu w Fail2Ban nie udało się zdjąć)
        if ($reason !== null && str_contains($reason, BanRepository::F2B_MARK)) {
            $also = Commands::fail2banUnban('panel-tempban', $ip) ? 'also' : 'warn';
        }
        $this->bans->unbanLocal($ipBin);
        $this->audit->log('UNBAN_IP', match ($also) {
            'also'  => Lang::site('audit.unban_ip_also', ['ip' => $ip]),
            'warn'  => Lang::site('audit.unban_ip_warn', ['ip' => $ip]),
            default => Lang::site('audit.unban_ip', ['ip' => $ip]),
        });
        $this->success = match ($also) {
            'also'  => t('msg.bans.unbanned_also', ['ip' => $ip]),
            'warn'  => t('msg.bans.unbanned_warn', ['ip' => $ip]),
            default => t('msg.bans.unbanned', ['ip' => $ip]),
        };
        $this->refresh = array_merge($this->refresh, ['bans', 'stats']);
    }

    /* ==================== AJAX ==================== */

    private function handleAjax(): bool
    {
        // Endpointy tylko do odczytu zwalniają blokadę sesji od razu — inaczej długi skan / 100 wywołań doveadm
        // (ajax_quota_usage) zamraża każde inne żądanie tego administratora do końca. (Sesja po zamknięciu jest
        // nadal czytelna; nic poniżej jej nie zapisuje.)
        foreach (['ajax_fetch_users', 'ajax_fetch_reg_logs', 'ajax_fetch_admin_logs', 'ajax_fetch_audit_logs',
                  'ajax_quota_usage', 'ajax_fetch_bans', 'ajax_check_user', 'ajax_sent_today', 'ajax_scan', 'ajax_stats'] as $key) {
            if (isset($_GET[$key])) {
                if (session_status() === PHP_SESSION_ACTIVE) {
                    session_write_close();
                }
                break;
            }
        }

        if (isset($_GET['ajax_fetch_users'])) {
            $this->ajaxUsers();
        } elseif (isset($_GET['ajax_fetch_reg_logs'])) {
            $this->ajaxLog('reg', [$this->logs, 'countRegistration'], 'reg_rows.php', [$this->logs, 'registrationPage']);
        } elseif (isset($_GET['ajax_fetch_admin_logs'])) {
            $this->ajaxLog('admin', [$this->logs, 'countAdminLogin'], 'admin_rows.php', [$this->logs, 'adminLoginPage']);
        } elseif (isset($_GET['ajax_fetch_audit_logs'])) {
            $this->ajaxLog('audit', [$this->logs, 'countAudit'], 'audit_rows.php', [$this->logs, 'auditPage']);
        } elseif (isset($_GET['ajax_quota_usage'])) {
            $this->ajaxQuotaUsage();
        } elseif (isset($_GET['ajax_fetch_bans'])) {
            $this->ajaxBans();
        } elseif (isset($_GET['ajax_check_user'])) {
            $this->ajaxCheckUser();
        } elseif (isset($_GET['ajax_sent_today'])) {
            $this->ajaxSentToday();
        } elseif (isset($_GET['ajax_scan'])) {
            $this->ajaxScanSuspicious();
        } elseif (isset($_GET['ajax_stats'])) {
            $this->ajaxStats();
        } elseif (isset($_GET['lang_export'])) {
            LanguageAdmin::export((string) $_GET['lang_export']);
        } else {
            return false;
        }
        return true;
    }

    /** Liczniki w nagłówku panelu po zmianie (bez banów — te liczy ajaxBans razem z listą: to wywołania fail2ban-client). */
    private function ajaxStats(): void
    {
        $s = Stats::collect($this->pdo, 0);
        unset($s['banned_ips']);
        Http::json(['ok' => true] + $s);
    }

    /** Świeża lista blokad (po banie/unbanie przez AJAX — bez przeładowania strony). */
    private function ajaxBans(): void
    {
        $bans = $this->collectBans(Commands::fail2banJails());
        Http::json([
            'html'  => View::render(self::TPL . '/partials/ban_rows.php', ['bans' => $bans]),
            'count' => count($bans),
        ]);
    }

    /** Dostępność adresu dla modalu "Dodaj użytkownika" (walidacja jak przy rejestracji). */
    private function ajaxCheckUser(): void
    {
        $local  = Accounts::normalize((string) ($_GET['local'] ?? ''));
        $domain = Accounts::normalize((string) ($_GET['domain'] ?? ''));
        $users  = new UserRepository($this->pdo);

        if (!Accounts::validLocal($local)) {
            Http::json(['ok' => false, 'msg' => t('msg.name.bad_chars')]);
        }
        if (!in_array($domain, $users->domains(), true)) {
            Http::json(['ok' => false, 'msg' => t('msg.name.bad_domain')]);
        }
        $email = $local . '@' . $domain;
        Http::json(['ok' => true, 'email' => $email] + $this->classifyName($users, $local, $email));
    }

    /**
     * Wysłane dziś wiadomości + efektywny limit konta (modal "Zmiana Quota").
     * Licznik prowadzi policy service Postfiksa w tabeli send_counters.
     */
    private function ajaxSentToday(): void
    {
        $id = (int) ($_GET['id'] ?? 0);
        $st = $this->pdo->prepare('SELECT email, daily_send_limit FROM virtual_users WHERE id = ?');
        $st->execute([$id]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            Http::json(['ok' => false]);
        }

        $global     = Settings::int('daily_send_limit', 0);
        $individual = $row['daily_send_limit'] !== null;
        $limit      = $individual ? (int) $row['daily_send_limit'] : $global;

        $sent = 0;
        try {
            $cst = $this->pdo->prepare('SELECT cnt FROM send_counters WHERE email = ? AND day = ?');
            $cst->execute([$row['email'], date('Y-m-d')]);
            $sent = (int) ($cst->fetchColumn() ?: 0);
        } catch (Throwable) {
            // brak tabeli/grantu (np. środowisko dev) — pokazujemy 0
        }

        Http::json([
            'ok'         => true,
            'sent'       => $sent,
            'limit'      => $limit,
            'global'     => $global,
            'individual' => $individual,
        ]);
    }

    /**
     * IP pomijane w skanerze: localhost + lista z .env (SCAN_TRUSTED_IPS,
     * rozdzielona przecinkami — np. IP serwera i stałe IP administratora).
     * @return string[]
     */
    private function scanTrusted(): array
    {
        return array_merge(['127.0.0.1', '::1'], Ip::trustedFromEnv());
    }

    /** Czy adres jest na liście zaufanych (dokładny adres albo zakres CIDR)? */
    private function isScanTrusted(string $ip): bool
    {
        foreach ($this->scanTrusted() as $entry) {
            if ($entry === $ip || Ip::inCidr($ip, $entry)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Skaner podejrzanych IP — jedno źródło na żądanie (?source=ssh|dovecot|postfix|db).
     * Front odpytuje źródła sekwencyjnie i sam agreguje/filtruje po progu,
     * dzięki czemu wyniki doładowują się na bieżąco i PHP nie wisi na jednym
     * długim wywołaniu. Zwraca pary {ip, svc, c} już bez IP zaufanych.
     */
    private function ajaxScanSuspicious(): void
    {
        $from   = (string) ($_GET['from'] ?? '');
        $to     = (string) ($_GET['to'] ?? '');
        $source = (string) ($_GET['source'] ?? '');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) {
            $from = date('Y-m-d', strtotime('-7 days'));
        }
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) {
            $to = date('Y-m-d');
        }

        $pairs   = [];
        $push = function (string $ip, string $svc, int $c) use (&$pairs): void {
            if ($ip === '' || $c < 1 || $this->isScanTrusted($ip)) {
                return;
            }
            $pairs[] = ['ip' => $ip, 'svc' => $svc, 'c' => $c];
        };

        if (in_array($source, ['ssh', 'dovecot', 'postfix'], true)) {
            foreach (Commands::scanSystemLogs($from, $to, $source) as $ip => $d) {
                foreach (($d['services'] ?? []) as $svc => $c) {
                    $push((string) $ip, (string) $svc, (int) $c);
                }
            }
        } elseif ($source === 'db') {
            $toIncl = date('Y-m-d', strtotime($to . ' +1 day'));
            $addDb = function (string $sql, string $service) use ($push, $from, $toIncl): void {
                $st = $this->pdo->prepare($sql);
                $st->execute([$from, $toIncl]);
                foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
                    $push((string) ($r['ip'] ?? ''), $service, (int) $r['c']);
                }
            };
            $addDb('SELECT INET6_NTOA(ip) ip, COUNT(*) c FROM admin_login_attempts WHERE success = 0 AND created_at >= ? AND created_at < ? GROUP BY ip', 'panel-admin');
            $addDb("SELECT INET6_NTOA(ip) ip, COUNT(*) c FROM registration_events WHERE status IN ('failed','blocked') AND created_at >= ? AND created_at < ? GROUP BY ip", t('msg.scan.svc_registration'));
        } else {
            Http::json(['error' => t('msg.scan.bad_source')]);
        }

        usort($pairs, static fn($a, $b) => $b['c'] <=> $a['c']);

        Http::json([
            'from'   => $from,
            'to'     => $to,
            'source' => $source,
            'pairs'  => array_slice($pairs, 0, 500),
        ]);
    }

    /**
     * Ban IP prosto z wyników skanera (AJAX, bez przeładowania strony).
     * mode: "perma" = jail panel-permaban (bantime -1), liczba sekund
     * = ban czasowy przez jail panel-tempban (bantime ustawiany per ban).
     */
    private function ajaxScanBan(): never
    {
        if (!Csrf::check(Http::post('_csrf'))) {
            Http::json(['ok' => false, 'error' => t('msg.scan.session_expired')]);
        }

        $ip   = trim((string) ($_POST['ip'] ?? ''));
        $mode = trim((string) ($_POST['mode'] ?? 'perma'));

        if (Ip::toBin($ip) === null) {
            Http::json(['ok' => false, 'error' => t('msg.common.bad_ip')]);
        }
        if ($this->isScanTrusted($ip)) {
            Http::json(['ok' => false, 'error' => t('msg.scan.trusted')]);
        }

        $jails = Commands::fail2banJails();

        if ($mode === 'perma') {
            if (!in_array('panel-permaban', $jails, true)) {
                Http::json(['ok' => false, 'error' => t('msg.scan.no_permaban')]);
            }
            if (!Commands::fail2banBan('panel-permaban', $ip)) {
                Http::json(['ok' => false, 'error' => t('msg.scan.cmd_failed', ['cmd' => 'set <jail> banip <ip>'])]);
            }
            $this->audit->log('F2B_BAN', Lang::site('audit.scan_ban_perma', ['ip' => $ip]));
            Http::json(['ok' => true, 'label' => t('msg.scan.label_perma')]);
        }

        // Ban czasowy: 1h..3 miesiące
        $seconds = (int) $mode;
        if ($seconds < 3600 || $seconds > 7776000) {
            Http::json(['ok' => false, 'error' => t('msg.scan.bad_duration')]);
        }
        if (!in_array('panel-tempban', $jails, true)) {
            Http::json(['ok' => false, 'error' => t('msg.scan.no_tempban')]);
        }
        if (!Commands::fail2banSetBantime('panel-tempban', $seconds)) {
            Http::json(['ok' => false, 'error' => t('msg.scan.bantime_failed', ['cmd' => 'set panel-tempban bantime <' . t('msg.f2b.seconds') . '>'])]);
        }
        if (!Commands::fail2banBan('panel-tempban', $ip)) {
            Http::json(['ok' => false, 'error' => t('msg.scan.ban_failed')]);
        }

        $label = self::durationText($seconds);
        $this->audit->log('F2B_BAN', Lang::site('audit.scan_ban_temp', ['ip' => $ip, 'label' => self::durationText($seconds, true)]));
        Http::json(['ok' => true, 'label' => $label]);
    }

    /** Wykorzystanie quoty dla listy kont (ładowane asynchronicznie po renderze tabeli). */
    private function ajaxQuotaUsage(): void
    {
        $emails = array_slice(array_filter(array_map('trim', explode(',', (string) ($_GET['emails'] ?? '')))), 0, 100);
        $result = [];

        if ($emails !== []) {
            $place = implode(',', array_fill(0, count($emails), '?'));
            $stmt  = $this->pdo->prepare("SELECT email FROM virtual_users WHERE email IN ($place)");
            $stmt->execute(array_values($emails));
            foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $email) {
                $quota = Commands::dovecotQuota((string) $email);
                if ($quota !== null) {
                    $result[$email] = $quota;
                }
            }
        }

        Http::json(['usage' => $result]);
    }

    private function ajaxUsers(): void
    {
        $per    = Pagination::perOrDefault($_GET['per'] ?? 20);
        $search = (string) ($_GET['search'] ?? '');
        $sort   = $this->users->parseSort((string) ($_GET['sort'] ?? ''));

        $total = $this->users->count($search);
        $page  = Pagination::clamp((int) ($_GET['page'] ?? 1), $total, $per);
        $off   = ($page - 1) * $per;

        $rows = $this->users->rows($search, $sort, $per, $off);

        Http::json([
            'html'       => View::render(self::TPL . '/partials/user_rows.php', ['users' => $rows]),
            'pagination' => Pagination::render($page, $per, $total, 'users'),
            'page'       => $page,
            'total'      => $total,
        ]);
    }

    private function ajaxLog(string $nav, callable $counter, string $partial, callable $pager): void
    {
        $search = (string) ($_GET['search'] ?? '');
        $per    = Pagination::perOrDefault($_GET['per'] ?? 20);
        $total  = $counter($search);
        $page   = Pagination::clamp((int) ($_GET['page'] ?? 1), $total, $per);
        $off    = ($page - 1) * $per;
        $sort   = $this->logs->parseSort($nav, (string) ($_GET['sort'] ?? ''));

        Http::json([
            'html'       => View::render(self::TPL . '/partials/' . $partial, ['rows' => $pager($per, $off, $sort, $search)]),
            'pagination' => Pagination::render($page, $per, $total, $nav, t('pager.showing')),
            'total'      => $total,
        ]);
    }

    /* ==================== Render ==================== */

    /**
     * Bany: lokalne (baza) + Fail2Ban ze wszystkich jaili (z czasem
     * wygaśnięcia per IP, gdy dostępny). Wspólne dla strony i AJAX.
     */
    private function collectBans(array $jails): array
    {
        $bans = $this->bans->activeLocal();
        foreach ($jails as $jail) {
            $isPerma = $jail === 'panel-permaban';
            $isTemp  = $jail === 'panel-tempban';
            foreach (Commands::fail2banBansWithTime($jail) as $b) {
                $perm = $isPerma || $b['perm'];
                $bans[] = [
                    'type' => 'fail2ban', 'ip_address' => $b['ip'],
                    'reason' => $isPerma
                        ? t('msg.bans.reason_perma')
                        : ($isTemp ? t('msg.bans.reason_temp') : t('msg.bans.reason_auto', ['jail' => $jail])),
                    'jail' => $jail,
                    'service' => $isPerma ? t('msg.bans.service_perma') : $jail,
                    'until' => $perm ? t('msg.bans.until_perma') : t('msg.bans.until_dynamic'),
                    'until_epoch' => $perm ? null : $b['end'],
                ];
            }
        }
        return $bans;
    }

    private function renderPage(): void
    {
        $jails = Commands::fail2banJails();
        $bans  = $this->collectBans($jails);

        [$qValue, $qUnit] = Quota::toBest(Settings::int('mailbox_default_quota_mb', 1024));

        // Komunikaty flash z poprzedniego żądania POST (wzorzec PRG).
        $flashSuccess = (string) ($_SESSION['flash_success'] ?? '');
        $flashError   = (string) ($_SESSION['flash_error'] ?? '');
        unset($_SESSION['flash_success'], $_SESSION['flash_error']);

        // 1.2.0: pierwsza strona każdej listy renderowana od razu (dawniej puste tabele doładowywane po wczytaniu strony
        // — wszystko pod nimi skakało). Domyślne parametry jak w panel.js: 20 na stronę, konta od najnowszego.
        $usersTotal = $this->users->count('');
        $lists = [
            'users' => [
                'rows'  => View::render(self::TPL . '/partials/user_rows.php', ['users' => $this->users->rows('', [], 20, 0)]),
                'pages' => Pagination::render(1, 20, $usersTotal, 'users'),
                'total' => $usersTotal,
            ],
        ];
        foreach ([
            'reg'   => ['countRegistration', 'registrationPage', 'reg_rows.php'],
            'admin' => ['countAdminLogin', 'adminLoginPage', 'admin_rows.php'],
            'audit' => ['countAudit', 'auditPage', 'audit_rows.php'],
        ] as $nav => [$count, $page, $partial]) {
            $total = $this->logs->$count('');
            $lists[$nav] = [
                'rows'  => View::render(self::TPL . '/partials/' . $partial, ['rows' => $this->logs->$page(20, 0, [], '')]),
                'pages' => Pagination::render(1, 20, $total, $nav, t('pager.showing')),
                'total' => $total,
            ];
        }

        $data = [
            'success'  => $flashSuccess,
            'retentionNote' => LogRetention::note(),
            'error'    => $flashError,
            'csrf'     => Csrf::token(),
            'admin'    => (string) ($_SESSION['admin_username'] ?? ''),
            'theme'    => ($_COOKIE['mp_theme'] ?? '') === 'light' ? 'light' : 'dark',
            'stats'    => Stats::collect($this->pdo, count($bans)),
            'bans'     => $bans,
            'bansRowsHtml' => View::render(self::TPL . '/partials/ban_rows.php', ['bans' => $bans]),
            'lists'    => $lists,
            'f2bFields' => self::f2bFields(),
            'reauthLimit' => self::REAUTH_LIMIT,
            'jails'    => $jails,
            'pwd'      => [
                'min'   => Settings::int('pwd_min_length', 10),
                'upper' => Settings::int('pwd_require_upper', 1),
                'digit' => Settings::int('pwd_require_digit', 1),
                'spec'  => Settings::int('pwd_require_special', 1),
            ],
            'domains'  => (new UserRepository($this->pdo))->domains(),
            'f2b'      => Commands::fail2banLoadConfig(),
            'f2bAvail' => Commands::fail2banAvailable(),
            'pfAvail'  => Commands::postfixAvailable(),
            'delAvail' => Commands::mailboxDeleteAvailable(),
            'settings' => [
                'mailbox_quota_value'       => $qValue,
                'mailbox_quota_unit'        => $qUnit,
                'daily_send_limit'          => Settings::int('daily_send_limit', 200),
                'accounts_per_ip'           => Settings::int('accounts_per_ip', Settings::int('ip_max_accounts', 3)),
                'ip_window_hours'           => Settings::int('ip_window_hours', 24),
                'ip_total_limit'            => Settings::int('ip_total_limit', 999),
                'registration_enabled'      => Settings::get('registration_enabled', Settings::get('allow_registration', '1')),
                'captcha'                   => Captcha::panelState(),   // sekrety nie trafiają do HTML (tylko "zapisany")
                'languages'                 => LanguageAdmin::listing(),
                'retention'                 => ['days' => LogRetention::days(), 'last' => LogRetention::lastText()],
                'reserved_local_parts'      => (string) Settings::get('reserved_local_parts', Accounts::DEFAULT_EXTRA),
                'reserved_base'             => Accounts::BASE_RESERVED,
                'deleted_name_hold_days'    => Settings::int('deleted_name_hold_days', 365),
                'max_login_attempts'        => Settings::int('max_login_attempts', 5),
                'ban_duration_minutes'      => Settings::int('ban_duration_minutes', 15),
                'rc_max_recipients'         => Settings::int('rc_max_recipients', 10),
                'rc_sendmail_delay'         => Settings::int('rc_sendmail_delay', 60),
                'rc_login_rate_limit'       => Settings::int('rc_login_rate_limit', 3),
                'pf_message_rate_limit'     => Settings::int('pf_message_rate_limit', 15),
                'pf_connection_rate_limit'  => Settings::int('pf_connection_rate_limit', 10),
                'pf_connection_count_limit' => Settings::int('pf_connection_count_limit', 10),
            ],
        ];

        echo View::render(self::TPL . '/page.php', $data);
    }
}
