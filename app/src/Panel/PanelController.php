<?php
declare(strict_types=1);

namespace App\Panel;

use App\Admin\AdminRepository;
use App\Admin\AuditLog;
use App\Security\Csrf;
use App\Security\Password;
use App\Settings\Settings;
use App\Support\Database;
use App\Support\Env;
use App\Support\Html;
use App\Support\Http;
use App\Support\Ip;
use App\Support\View;
use App\System\Commands;
use App\Users\UserRepository;
use PDO;
use Throwable;

/**
 * Kontroler panelu administratora. Rozdziela żądanie na:
 *  - mutacje POST (ustawienia, bany, quota, usuwanie, hasło, fail2ban),
 *  - akcję GET (unban),
 *  - endpointy AJAX (listy z paginacją),
 *  - render strony.
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
                $this->error = 'Sesja wygasła lub błędny token bezpieczeństwa. Spróbuj ponownie.';
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
        // Formularze wysłane fetch-em (pole _ajax) dostają JSON zamiast
        // przekierowania — strona się nie przeładowuje, więc stan JS
        // (np. wyniki skanera podejrzanych IP) zostaje nietknięty.
        if (isset($_POST['_ajax'])) {
            Http::json(['ok' => $this->error === '', 'msg' => $this->success, 'error' => $this->error]);
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

        header('Location: index.php#' . $tab);
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
                default => null,
            };
        } catch (Throwable $e) {
            $this->error = $this->error ?: ('Błąd operacji: ' . Html::e($e->getMessage()));
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
        Settings::set('recaptcha_enabled', $recap);
        if (isset($_POST['recaptcha_site_key'])) {
            Settings::set('recaptcha_site_key', trim((string) $_POST['recaptcha_site_key']));
        }
        if (isset($_POST['recaptcha_secret_key'])) {
            Settings::set('recaptcha_secret', trim((string) $_POST['recaptcha_secret_key']));
        }
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
            $extra = " Zaktualizowano limit dla $count użytkowników.";
        }

        $this->audit->log('SETTINGS_UPDATE', 'Zaktualizowano ustawienia ogólne');
        $this->success = 'Ustawienia ogólne zostały zapisane.' . $extra;
    }

    private function saveSecurity(): void
    {
        $maxLog = (string) max(1, (int) ($_POST['max_login_attempts'] ?? 5));
        $banMin = (string) max(1, (int) ($_POST['ban_duration_minutes'] ?? 15));

        Settings::set('admin_lock_threshold', $maxLog);
        Settings::set('max_login_attempts', $maxLog);
        Settings::set('admin_lock_minutes', $banMin);
        Settings::set('ban_duration_minutes', $banMin);

        $this->audit->log('SECURITY_UPDATE', "Zmiana limitów: $maxLog prób, $banMin min blokady");
        $this->success = 'Ustawienia bezpieczeństwa zostały zapisane.';
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

        if (Commands::postfixAvailable()) {
            $r = Commands::postfixApply($rcMsg, $pfConn, $pfCnt);
            $info = $r['ok']
                ? ' (Postfix przeładowany)'
                : ' (Zapisano w bazie; błąd skryptu Postfix: ' . Html::e($r['output']) . ')';
        } else {
            $info = ' (Zapisano w bazie — brak uprawnień sudo dla skryptu)';
        }

        $this->audit->log('ANTISPAM_UPDATE', "RC=$rcMax, PF msg=$rcMsg");
        $this->success = 'Ustawienia antyspamowe zostały zapisane.' . $info;
    }

    private function changePassword(): void
    {
        $cur  = (string) ($_POST['current_password'] ?? '');
        $new  = (string) ($_POST['new_password'] ?? '');
        $conf = (string) ($_POST['confirm_password'] ?? '');

        if ($new !== $conf) {
            $this->error = 'Nowe hasła nie są identyczne.';
            return;
        }
        if (strlen($new) < 8) {
            $this->error = 'Hasło musi mieć minimum 8 znaków.';
            return;
        }

        $admin = $this->admins->findById((int) $_SESSION['admin_id']);
        if (!$admin || !Password::verifyAdmin($cur, $admin['pwd_hash'])) {
            $this->error = 'Obecne hasło jest nieprawidłowe.';
            return;
        }

        $this->admins->updatePassword((int) $admin['id'], Password::adminHash($new));
        $this->audit->log('ADMIN_PASS_CHANGE', 'Administrator zmienił swoje hasło');
        $this->success = 'Hasło administratora zostało zmienione.';
    }

    private function saveFail2ban(): void
    {
        Commands::fail2banSaveConfig($_POST);
        $reloaded = Commands::fail2banReload();

        $this->audit->log('FAIL2BAN_UPDATE', 'Zapis konfiguracji Fail2Ban' . ($reloaded ? ' + reload' : ''));
        $this->success = 'Konfiguracja Fail2Ban została zapisana.'
            . ($reloaded
                ? ' Usługa została przeładowana.'
                : ' Uwaga: nie przeładowano usługi — wykonaj "fail2ban-client reload" lub dodaj wpis sudoers.');
    }

    private function updateUserQuota(): void
    {
        $id  = (int) ($_POST['user_id'] ?? 0);
        $mb  = Quota::toMb(
            (float) ($_POST['user_quota_value'] ?? 0),
            Quota::normalizeUnit($_POST['user_quota_unit'] ?? 'MB')
        );
        $this->users->updateQuota($id, $mb);

        // Indywidualny dzienny limit wysyłki: puste pole = globalny (NULL), 0 = bez limitu.
        $limitRaw = trim((string) ($_POST['user_send_limit'] ?? ''));
        $limit    = $limitRaw === '' ? null : max(0, (int) $limitRaw);
        $this->users->updateSendLimit($id, $limit);

        $limitTxt = $limit === null ? 'globalny' : ($limit === 0 ? 'bez limitu' : $limit . '/dzień');
        $this->success = "Quota użytkownika została zaktualizowana (limit wysyłki: $limitTxt).";
    }

    /** Reset hasła skrzynki przez administratora (modal na karcie Użytkownicy). */
    private function resetUserPassword(): void
    {
        $id    = (int) ($_POST['user_id'] ?? 0);
        $email = (string) ($_POST['user_email'] ?? '');
        $pass  = (string) ($_POST['new_password'] ?? '');
        $pass2 = (string) ($_POST['new_password2'] ?? '');

        if ($this->users->emailById($id) !== $email) {
            $this->error = 'Nieprawidłowe dane użytkownika.';
            return;
        }
        if ($pass !== $pass2) {
            $this->error = 'Hasła nie są identyczne.';
            return;
        }
        if (!Password::meetsPolicy($pass)) {
            $this->error = 'Hasło nie spełnia wymogów bezpieczeństwa (długość i wymagane klasy znaków).';
            return;
        }

        $this->users->updatePassword($id, Password::dovecotHash($pass));
        $this->audit->log('USER_PASS_RESET', "Reset hasła skrzynki: $email (ID: $id)");
        $this->success = 'Hasło skrzynki ' . Html::e($email) . ' zostało zmienione.';
    }

    private function banUserIp(): void
    {
        $ipBin = Ip::toBin((string) ($_POST['ban_ip'] ?? ''));
        if ($ipBin === null) {
            $this->error = 'Nieprawidłowy adres IP.';
            return;
        }
        $reason   = trim((string) ($_POST['ban_reason'] ?? 'Zbanowany przez admina'));
        $note     = trim((string) ($_POST['ban_note'] ?? ''));
        $duration = (int) ($_POST['ban_duration'] ?? 60);
        if ($note !== '') {
            $reason .= ' - ' . $note;
        }

        $this->bans->ban($ipBin, $duration, $reason);
        $this->audit->log('BAN_IP', "IP {$_POST['ban_ip']} na $duration min. Powód: $reason");
        $this->success = "Adres IP {$_POST['ban_ip']} został zbanowany na $duration minut.";
    }

    private function deleteUser(): void
    {
        $id    = (int) ($_POST['delete_user_id'] ?? 0);
        $email = (string) ($_POST['delete_user_email'] ?? '');
        $pass  = (string) ($_POST['admin_confirm_password'] ?? '');

        $admin = $this->admins->findById((int) $_SESSION['admin_id']);
        if (!$admin || !Password::verifyAdmin($pass, $admin['pwd_hash'])) {
            $this->error = 'Błędne hasło administratora. Operacja przerwana.';
            return;
        }
        if ($this->users->emailById($id) !== $email) {
            $this->error = 'Nieprawidłowe dane użytkownika.';
            return;
        }

        $this->pdo->beginTransaction();
        try {
            $this->users->delete($id);
            $this->pdo->commit();
        } catch (Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }

        if (Commands::mailboxDeleteAvailable()) {
            $r = Commands::mailboxDelete($email);
            $files = $r['ok'] ? ' Pliki poczty usunięte z dysku.' : ' Uwaga: nie usunięto plików (kod ' . $r['code'] . ').';
        } else {
            $files = ' Pliki nie zostały usunięte (brak konfiguracji sudo).';
        }

        $this->audit->log('DELETE_USER', "Usunięto konto: $email (ID: $id)");
        $this->success = 'Konto ' . Html::e($email) . ' zostało usunięte.' . $files;
    }

    /** Ręczne utworzenie konta pocztowego przez administratora (modal na karcie Użytkownicy). */
    private function createUser(): void
    {
        $local  = strtolower(trim((string) ($_POST['nu_local'] ?? '')));
        $domain = (string) ($_POST['nu_domain'] ?? '');
        $pass   = (string) ($_POST['nu_password'] ?? '');
        $quota  = Quota::toMb(
            (float) ($_POST['nu_quota_value'] ?? 0),
            Quota::normalizeUnit($_POST['nu_quota_unit'] ?? 'MB')
        );

        $users = new UserRepository($this->pdo);

        if (!preg_match('/^[a-z0-9._-]+$/', $local)) {
            $this->error = 'Niedozwolone znaki w nazwie konta.';
            return;
        }
        if (!in_array($domain, $users->domains(), true)) {
            $this->error = 'Nieprawidłowa domena.';
            return;
        }
        $email = $local . '@' . $domain;
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->error = 'Nieprawidłowy adres e-mail.';
            return;
        }
        if ($pass !== (string) ($_POST['nu_password2'] ?? '')) {
            $this->error = 'Hasła nie są identyczne.';
            return;
        }
        if (!Password::meetsPolicy($pass)) {
            $this->error = 'Hasło nie spełnia wymogów bezpieczeństwa (długość i wymagane klasy znaków).';
            return;
        }
        if ($users->exists($email)) {
            $this->error = 'Konto ' . Html::e($email) . ' już istnieje.';
            return;
        }
        $domainId = $users->domainIdForEmail($email);
        if ($domainId === null) {
            $this->error = 'Błąd konfiguracji domeny.';
            return;
        }

        $users->create($domainId, Password::dovecotHash($pass), $email, $quota);

        // Indywidualny dzienny limit wysyłki (puste = globalny, 0 = bez limitu)
        $limitRaw = trim((string) ($_POST['nu_send_limit'] ?? ''));
        $limitTxt = 'globalny';
        if ($limitRaw !== '') {
            $limit = max(0, (int) $limitRaw);
            $newId = (int) $this->pdo->lastInsertId();
            if ($newId > 0) {
                $this->users->updateSendLimit($newId, $limit);
                $limitTxt = $limit === 0 ? 'bez limitu' : $limit . '/dzień';
            }
        }

        $this->audit->log('CREATE_USER', "Utworzono konto: $email (quota {$quota}MB, limit wysyłki: $limitTxt)");
        $this->success = 'Konto ' . Html::e($email) . ' zostało utworzone (quota ' . Quota::display($quota) . ', limit wysyłki: ' . $limitTxt . ').';
    }

    /** Ręczny ban IP w wybranym jailu Fail2Ban (formularz w zakładce Blokady IP). */
    private function fail2banBanIp(): void
    {
        $ip   = trim((string) ($_POST['f2b_ip'] ?? ''));
        $jail = (string) ($_POST['f2b_jail'] ?? '');

        if (Ip::toBin($ip) === null) {
            $this->error = 'Nieprawidłowy adres IP.';
            return;
        }
        if (!in_array($jail, Commands::fail2banJails(), true)) {
            $this->error = 'Nieprawidłowy jail.';
            return;
        }

        if (Commands::fail2banBan($jail, $ip)) {
            $this->audit->log('F2B_BAN', "Ręczny ban IP: $ip (jail: $jail)");
            $this->success = "IP $ip zostało zbanowane w Fail2Ban (jail: $jail).";
        } else {
            $this->error = 'Błąd komendy Fail2Ban — sprawdź wpis sudoers: fail2ban-client set * banip *.';
        }
    }

    /** Zmiana typu bana: przeniesienie IP z jednego jaila Fail2Ban do innego. */
    private function moveBan(): void
    {
        $ip   = trim((string) ($_POST['ip'] ?? ''));
        $from = (string) ($_POST['from_jail'] ?? '');
        $to   = (string) ($_POST['to_jail'] ?? '');

        if (Ip::toBin($ip) === null) {
            $this->error = 'Nieprawidłowy adres IP.';
            return;
        }
        if (!in_array($to, Commands::fail2banJails(), true)) {
            $this->error = 'Nieprawidłowy jail docelowy.';
            return;
        }
        if ($from !== '') {
            Commands::fail2banUnban($from, $ip);
        }

        // Ban czasowy: przy przenoszeniu do panel-tempban można podać czas
        // (1h..3 miesiące) — ustawiany tak samo jak przy banie ze skanera.
        $durTxt = '';
        $dur    = (int) ($_POST['duration'] ?? 0);
        if ($to === 'panel-tempban' && $dur >= 3600 && $dur <= 7776000) {
            if (!Commands::fail2banSetBantime('panel-tempban', $dur)) {
                $this->error = 'Błąd ustawiania czasu bana (sudoers: set * bantime *).';
                return;
            }
            $durTxt = $dur >= 172800 ? ' na ' . intdiv($dur, 86400) . ' dni' : ' na ' . intdiv($dur, 3600) . ' godz.';
        }

        if (Commands::fail2banBan($to, $ip)) {
            $this->audit->log('MOVE_BAN', "Przeniesiono IP: $ip ($from -> $to$durTxt)");
            $this->success = "IP $ip przeniesione: $from → $to$durTxt.";
        } else {
            $this->error = 'Błąd komendy Fail2Ban przy przenoszeniu.';
        }
    }

    private function unbanIp(): void
    {
        $ip   = (string) ($_POST['ip'] ?? '');
        $type = $_POST['unban_type'] ?? 'local';

        if ($type === 'fail2ban' && !empty($_POST['jail'])) {
            if (Commands::fail2banUnban((string) $_POST['jail'], $ip)) {
                $this->audit->log('UNBAN_IP', "Odblokowano IP: $ip (Fail2Ban: {$_POST['jail']})");
                $this->success = "IP $ip zostało odblokowane w Fail2Ban.";
            } else {
                $this->error = 'Błąd komendy Fail2Ban.';
            }
            return;
        }

        $ipBin = Ip::toBin($ip);
        if ($ipBin === null) {
            $this->error = 'Nieprawidłowy adres IP.';
            return;
        }
        $this->bans->unbanLocal($ipBin);
        $this->audit->log('UNBAN_IP', "Odblokowano IP: $ip");
        $this->success = "IP $ip zostało odblokowane.";
    }

    /* ==================== AJAX ==================== */

    private function handleAjax(): bool
    {
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
        } else {
            return false;
        }
        return true;
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
        $local  = strtolower(trim((string) ($_GET['local'] ?? '')));
        $domain = trim((string) ($_GET['domain'] ?? ''));
        $users  = new UserRepository($this->pdo);

        if (!preg_match('/^[a-z0-9._-]+$/', $local)) {
            Http::json(['ok' => false, 'msg' => 'Niedozwolone znaki']);
        }
        if (!in_array($domain, $users->domains(), true)) {
            Http::json(['ok' => false, 'msg' => 'Nieprawidłowa domena']);
        }
        $exists = $users->exists($local . '@' . $domain);
        Http::json(['ok' => true, 'status' => $exists ? 'taken' : 'free', 'msg' => $exists ? 'Zajęte' : 'Wolne']);
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
        $extra = array_filter(array_map('trim', explode(',', (string) Env::get('SCAN_TRUSTED_IPS', ''))));
        return array_merge(['127.0.0.1', '::1'], $extra);
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
        $trusted = $this->scanTrusted();
        $push = static function (string $ip, string $svc, int $c) use (&$pairs, $trusted): void {
            if ($ip === '' || $c < 1 || in_array($ip, $trusted, true)) {
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
            $addDb("SELECT INET6_NTOA(ip) ip, COUNT(*) c FROM registration_events WHERE status IN ('failed','blocked') AND created_at >= ? AND created_at < ? GROUP BY ip", 'rejestracja');
        } else {
            Http::json(['error' => 'Nieznane źródło skanu.']);
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
            Http::json(['ok' => false, 'error' => 'Sesja wygasła — odśwież stronę panelu.']);
        }

        $ip   = trim((string) ($_POST['ip'] ?? ''));
        $mode = trim((string) ($_POST['mode'] ?? 'perma'));

        if (Ip::toBin($ip) === null) {
            Http::json(['ok' => false, 'error' => 'Nieprawidłowy adres IP.']);
        }
        if (in_array($ip, $this->scanTrusted(), true)) {
            Http::json(['ok' => false, 'error' => 'To IP jest na liście zaufanych.']);
        }

        $jails = Commands::fail2banJails();

        if ($mode === 'perma') {
            if (!in_array('panel-permaban', $jails, true)) {
                Http::json(['ok' => false, 'error' => 'Brak jaila panel-permaban w Fail2Ban.']);
            }
            if (!Commands::fail2banBan('panel-permaban', $ip)) {
                Http::json(['ok' => false, 'error' => 'Błąd fail2ban-client (sudoers: set * banip *).']);
            }
            $this->audit->log('F2B_BAN', "Ban ze skanera: $ip (na stałe, jail: panel-permaban)");
            Http::json(['ok' => true, 'label' => 'na stałe']);
        }

        // Ban czasowy: 1h..3 miesiące
        $seconds = (int) $mode;
        if ($seconds < 3600 || $seconds > 7776000) {
            Http::json(['ok' => false, 'error' => 'Nieprawidłowy czas bana.']);
        }
        if (!in_array('panel-tempban', $jails, true)) {
            Http::json(['ok' => false, 'error' => 'Brak jaila panel-tempban w Fail2Ban (dodaj sekcję w jail.local).']);
        }
        if (!Commands::fail2banSetBantime('panel-tempban', $seconds)) {
            Http::json(['ok' => false, 'error' => 'Błąd ustawiania bantime (sudoers: set * bantime *).']);
        }
        if (!Commands::fail2banBan('panel-tempban', $ip)) {
            Http::json(['ok' => false, 'error' => 'Błąd fail2ban-client przy banowaniu.']);
        }

        $label = $seconds >= 172800
            ? intdiv($seconds, 86400) . ' dni'
            : intdiv($seconds, 3600) . ' godz.';
        $this->audit->log('F2B_BAN', "Ban ze skanera: $ip (czasowy $label, jail: panel-tempban)");
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
            'pagination' => Pagination::render($page, $per, $total, $nav, 'Wyświetlanie'),
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
                        ? 'Blokada stała (panel)'
                        : ($isTemp ? 'Blokada czasowa (panel)' : "Auto-Ban: $jail"),
                    'jail' => $jail,
                    'service' => $isPerma ? 'PERMANENTNY' : $jail,
                    'until' => $perm ? 'Na stałe' : 'Dynamicznie',
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

        $data = [
            'success'  => $flashSuccess,
            'error'    => $flashError,
            'csrf'     => Csrf::token(),
            'admin'    => (string) ($_SESSION['admin_username'] ?? ''),
            'stats'    => Stats::collect($this->pdo, count($bans)),
            'bans'     => $bans,
            'bansRowsHtml' => View::render(self::TPL . '/partials/ban_rows.php', ['bans' => $bans]),
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
                'recaptcha_enabled'         => Settings::get('recaptcha_enabled', '0'),
                'recaptcha_site_key'        => Settings::get('recaptcha_site_key', ''),
                'recaptcha_secret_key'      => Settings::get('recaptcha_secret', ''),
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
