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
use App\Users\Accounts;
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
            // Szczegóły (SQL, ścieżki) idą do logu serwera, nie do przeglądarki — poza trybem dev.
            error_log(sprintf('panel: wyjątek w akcji POST: [%s] %s w %s:%d', $e::class, $e->getMessage(), $e->getFile(), $e->getLine()));
            $detail = $e instanceof \RuntimeException && !$e instanceof \PDOException ? ' ' . $e->getMessage() : '';
            $this->error = $this->error ?: (Env::isDev()
                ? 'Błąd operacji: ' . $e->getMessage()
                : 'Błąd operacji.' . $detail . ' Szczegóły w dzienniku serwera.');
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
        // Sekret reCAPTCHA nie jest już wypisywany w HTML strony: puste pole = zostaw zapisany, nowa wartość = podmień.
        $newSecret = trim((string) ($_POST['recaptcha_secret_key'] ?? ''));
        if ($newSecret !== '') {
            Settings::set('recaptcha_secret', $newSecret);
        }

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

        $failed = false;
        if (Commands::postfixAvailable()) {
            $r = Commands::postfixApply($rcMsg, $pfConn, $pfCnt);
            if ($r['ok']) {
                $info = ' (Postfix: zapisane, sprawdzone i przeładowane)';
            } else {
                // Skrypt kończy się kodem != 0, gdy zapis do main.cf się nie udał albo wartość po zapisie jest inna
                // niż żądana — nie raportujemy wtedy sukcesu (patrz sekcja o ReadWritePaths w README).
                $failed = true;
                $info = ' Zapisano w bazie, ale Postfix NIE został zmieniony: ' . ($r['output'] !== '' ? $r['output'] : 'błąd skryptu');
            }
        } else {
            $info = ' (Zapisano w bazie — brak uprawnień sudo dla skryptu)';
        }

        $this->audit->log('ANTISPAM_UPDATE', "RC=$rcMax, PF msg=$rcMsg" . ($failed ? ' (Postfix: błąd)' : ''));
        if ($failed) {
            $this->error = 'Ustawienia antyspamowe zapisane w bazie.' . $info;
        } else {
            $this->success = 'Ustawienia antyspamowe zostały zapisane.' . $info;
        }
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
        // Jedna polityka z hasłami skrzynek (długość + klasy znaków z ustawień), ale administrator: min. 12 znaków.
        if (!Password::meetsAdminPolicy($new)) {
            $this->error = 'Hasło administratora: minimum ' . max(Password::ADMIN_MIN_LENGTH, Settings::int('pwd_min_length', 10))
                . ' znaków oraz wymagane klasy znaków (jak dla skrzynek).';
            return;
        }

        $admin = $this->admins->findById((int) $_SESSION['admin_id']);
        if (!$admin || !Password::verifyAdmin($cur, $admin['pwd_hash'])) {
            $this->error = 'Obecne hasło jest nieprawidłowe.';
            return;
        }

        $newHash = Password::adminHash($new);
        $this->admins->updatePassword((int) $admin['id'], $newHash);
        // Inne sesje tego admina przestają być ważne (odcisk hasha w sesji), ta dostaje nowy identyfikator i odcisk.
        $_SESSION['admin_fp'] = hash('sha256', $newHash);
        \App\Support\Session::regenerate();
        $this->audit->log('ADMIN_PASS_CHANGE', 'Administrator zmienił swoje hasło');
        $this->success = 'Hasło administratora zostało zmienione. Inne sesje zostały wylogowane.';
    }

    private function saveFail2ban(): void
    {
        // Wyjątek (z komunikatem helpera) oznacza: odrzucone, nie zapisano / nie sprawdzono / nie przeładowano.
        // Brak wyjątku = helper sam zweryfikował zapis (odczyt zwrotny), przeszedł `fail2ban-client -t` i przeładował usługę.
        $msg = Commands::fail2banSaveConfig($_POST);

        if (str_contains($msg, 'bez zmian')) {
            $this->success = 'Konfiguracja Fail2Ban bez zmian — nic nie zapisano.';
            return;
        }
        $this->audit->log('FAIL2BAN_UPDATE', 'Zapis konfiguracji Fail2Ban + reload (' . $msg . ')');
        $this->success = 'Konfiguracja Fail2Ban została zapisana, sprawdzona (fail2ban-client -t) i przeładowana.';
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

        $stored = $this->users->emailById($id);
        if ($stored === null || Accounts::normalize($stored) !== Accounts::normalize($email)) {
            $this->error = 'Nieprawidłowe dane użytkownika.';
            return;
        }
        $email = Accounts::normalize($stored);
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
        $this->success = 'Hasło skrzynki ' . $email . ' zostało zmienione.';
    }

    private function banUserIp(): void
    {
        $ip    = trim((string) ($_POST['ban_ip'] ?? ''));
        $ipBin = Ip::toBin($ip);
        if ($ipBin === null) {
            $this->error = 'Nieprawidłowy adres IP.';
            return;
        }
        if ($this->isScanTrusted($ip)) {
            $this->error = 'To IP jest na liście zaufanych (SCAN_TRUSTED_IPS) — nie można go zbanować.';
            return;
        }
        $reason   = trim((string) ($_POST['ban_reason'] ?? 'Zbanowany przez admina'));
        $note     = trim((string) ($_POST['ban_note'] ?? ''));
        $duration = (int) ($_POST['ban_duration'] ?? 60);
        if ($duration < 1 || $duration > 129600) {      // 1 minuta .. 90 dni
            $this->error = 'Czas bana: od 1 do 129600 minut (90 dni).';
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
        $this->audit->log('BAN_IP', "IP $ip na $duration min" . ($f2b ? ' (portal + Fail2Ban panel-tempban)' : ' (tylko portal/panel)') . ". Powód: $reason");
        $this->success = "Adres IP $ip został zbanowany na $duration min — "
            . ($f2b
                ? 'w portalu i panelu oraz w Fail2Ban (jail panel-tempban: webmail, IMAP, SMTP).'
                : 'TYLKO w portalu i panelu (logowanie, rejestracja). Fail2Ban jest niedostępny, więc IMAP/SMTP/webmail nie są blokowane na firewallu.');
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
        $stored = $this->users->emailById($id);
        if ($stored === null || Accounts::normalize($stored) !== Accounts::normalize($email)) {
            $this->error = 'Nieprawidłowe dane użytkownika.';
            return;
        }
        $email = Accounts::normalize($stored);   // skrzynka na dysku jest zawsze pod nazwą małymi literami

        // Konto, aliasy kierujące na nie, historia logowań i licznik wysyłki znikają w jednej transakcji; zostaje
        // "nagrobek" (rejestracja publiczna nie odda tej nazwy od razu) — patrz UserAdminRepository::deleteAccount().
        $r = $this->users->deleteAccount($id, $email);

        $extra = '';
        if ($r['aliases'] > 0) {
            $extra .= " Usunięto aliasów kierujących na to konto: {$r['aliases']}.";
        }
        if ($r['lists'] !== []) {
            $ids = implode(', ', array_map(static fn(array $a): string => '#' . $a['id'] . ' (' . $a['source'] . ')', $r['lists']));
            $extra .= " UWAGA: aliasy z listą celów nadal wymieniają to konto: $ids — popraw je ręcznie.";
        }

        $filesOk = true;
        if (Commands::mailboxDeleteAvailable()) {
            $f = Commands::mailboxDelete($email);
            $filesOk = $f['ok'] || $f['code'] === 4;
            $files = ' ' . Commands::mailboxDeleteMessage($f['code']);
        } else {
            $filesOk = false;
            $files = ' Uwaga: pliki poczty NIE zostały ruszone (brak konfiguracji sudo dla mailpanel-delete-user.sh).';
        }

        $this->audit->log('DELETE_USER', "Usunięto konto: $email (ID: $id)" . ($filesOk ? '' : ' — pliki poczty NIE usunięte') . $extra);
        $this->success = 'Konto ' . $email . ' zostało usunięte z bazy.' . $extra;
        if ($filesOk) {
            $this->success .= $files;
        } else {
            // Konto zniknęło z bazy, ale skrzynka jest na dysku: ma być widoczne jako błąd, nie jako dopisek do sukcesu.
            $this->error = trim($files);
        }
    }

    /**
     * Ocena nazwy konta dla administratora (tworzenie konta i podgląd dostępności).
     *
     * status: free | taken (konto istnieje) | alias (nazwa jest ŹRÓDŁEM aliasu — poczta idzie gdzie indziej) |
     *         reserved (lista zarezerwowanych) | held (alias wskazuje na ten adres albo konto niedawno usunięto).
     * Administrator może świadomie zająć reserved / held (pole nu_allow_reserved); taken i alias — nigdy.
     *
     * @return array{0:string,1:string} [status, komunikat]
     */
    private function classifyName(UserRepository $users, string $local, string $email): array
    {
        if ($users->exists($email)) {
            return ['taken', 'Zajęte'];
        }
        if ($users->aliasSourceExists($email)) {
            return ['alias', 'Ta nazwa jest źródłem aliasu — usuń alias, żeby założyć skrzynkę'];
        }
        if (Accounts::isReserved($local, Settings::get('reserved_local_parts'))) {
            return ['reserved', 'Nazwa zarezerwowana (wymaga zaznaczenia zgody poniżej)'];
        }
        if ($users->aliasReferences($email) || $users->recentlyDeleted($email, Settings::int('deleted_name_hold_days', 365))) {
            return ['held', 'Alias wskazuje na ten adres albo konto niedawno usunięto (wymaga zaznaczenia zgody poniżej)'];
        }
        return ['free', 'Wolne'];
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
            $this->error = 'Niedozwolone znaki w nazwie konta (litery, cyfry, . _ -; bez kropki/myślnika na brzegach).';
            return;
        }
        if (!in_array($domain, $users->domains(), true)) {
            $this->error = 'Nieprawidłowa domena.';
            return;
        }
        $email = $local . '@' . $domain;
        if (strlen($email) > Accounts::MAX_EMAIL || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
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

        [$status, $why] = $this->classifyName($users, $local, $email);
        $override = false;
        if ($status === 'taken' || $status === 'alias') {
            $this->error = "Konto $email: $why.";
            return;
        }
        if ($status !== 'free') {
            if (empty($_POST['nu_allow_reserved'])) {
                $this->error = "Konto $email: $why.";
                return;
            }
            $override = true;
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

        $this->audit->log('CREATE_USER', "Utworzono konto: $email (quota {$quota}MB, limit wysyłki: $limitTxt)"
            . ($override ? " — za zgodą administratora mimo: $why" : ''));
        $this->success = 'Konto ' . $email . ' zostało utworzone (quota ' . Quota::display($quota) . ', limit wysyłki: ' . $limitTxt . ').';
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
            $this->error = 'Błąd komendy Fail2Ban — sprawdź regułę sudoers: fail2ban-client set <jail> banip <ip> (plik system/sudoers.d/mailpanel).';
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
        $jails = Commands::fail2banJails();
        if (!in_array($to, $jails, true)) {
            $this->error = 'Nieprawidłowy jail docelowy.';
            return;
        }
        if ($from !== '' && !in_array($from, $jails, true)) {
            $this->error = 'Nieprawidłowy jail źródłowy.';
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
                $this->error = 'Błąd ustawiania czasu bana (sudoers: set panel-tempban bantime <sekundy>).';
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
        $ip   = trim((string) ($_POST['ip'] ?? ''));
        $type = (string) ($_POST['unban_type'] ?? 'local');

        $ipBin = Ip::toBin($ip);
        if ($ipBin === null) {
            $this->error = 'Nieprawidłowy adres IP.';
            return;
        }

        if ($type === 'fail2ban' && !empty($_POST['jail'])) {
            $jail = (string) $_POST['jail'];
            if (!in_array($jail, Commands::fail2banJails(), true)) {
                $this->error = 'Nieprawidłowy jail.';
                return;
            }
            if (Commands::fail2banUnban($jail, $ip)) {
                $this->audit->log('UNBAN_IP', "Odblokowano IP: $ip (Fail2Ban: $jail)");
                $this->success = "IP $ip zostało odblokowane w Fail2Ban.";
            } else {
                $this->error = 'Błąd komendy Fail2Ban.';
            }
            return;
        }

        // Ban lokalny założony przez "Zbanuj" ma też wpis w jailu panel-tempban (znacznik w powodzie) — zdejmujemy oba.
        $reason = $this->bans->reasonFor($ipBin);
        $also   = '';
        if ($reason !== null && str_contains($reason, BanRepository::F2B_MARK)) {
            $also = Commands::fail2banUnban('panel-tempban', $ip)
                ? ' (oraz w Fail2Ban: panel-tempban)'
                : ' (UWAGA: wpisu w Fail2Ban panel-tempban nie udało się zdjąć — zdejmij go w liście blokad)';
        }
        $this->bans->unbanLocal($ipBin);
        $this->audit->log('UNBAN_IP', "Odblokowano IP: $ip$also");
        $this->success = "IP $ip zostało odblokowane$also.";
    }

    /* ==================== AJAX ==================== */

    private function handleAjax(): bool
    {
        // Endpointy tylko do odczytu zwalniają blokadę sesji od razu — inaczej długi skan / 100 wywołań doveadm
        // (ajax_quota_usage) zamraża każde inne żądanie tego administratora do końca. (Sesja po zamknięciu jest
        // nadal czytelna; nic poniżej jej nie zapisuje.)
        foreach (['ajax_fetch_users', 'ajax_fetch_reg_logs', 'ajax_fetch_admin_logs', 'ajax_fetch_audit_logs',
                  'ajax_quota_usage', 'ajax_fetch_bans', 'ajax_check_user', 'ajax_sent_today', 'ajax_scan'] as $key) {
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
        $local  = Accounts::normalize((string) ($_GET['local'] ?? ''));
        $domain = Accounts::normalize((string) ($_GET['domain'] ?? ''));
        $users  = new UserRepository($this->pdo);

        if (!Accounts::validLocal($local)) {
            Http::json(['ok' => false, 'msg' => 'Niedozwolone znaki']);
        }
        if (!in_array($domain, $users->domains(), true)) {
            Http::json(['ok' => false, 'msg' => 'Nieprawidłowa domena']);
        }
        [$status, $msg] = $this->classifyName($users, $local, $local . '@' . $domain);
        Http::json(['ok' => true, 'status' => $status, 'msg' => $msg]);
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
        if ($this->isScanTrusted($ip)) {
            Http::json(['ok' => false, 'error' => 'To IP jest na liście zaufanych.']);
        }

        $jails = Commands::fail2banJails();

        if ($mode === 'perma') {
            if (!in_array('panel-permaban', $jails, true)) {
                Http::json(['ok' => false, 'error' => 'Brak jaila panel-permaban w Fail2Ban.']);
            }
            if (!Commands::fail2banBan('panel-permaban', $ip)) {
                Http::json(['ok' => false, 'error' => 'Błąd fail2ban-client (sudoers: set <jail> banip <ip>).']);
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
            Http::json(['ok' => false, 'error' => 'Błąd ustawiania bantime (sudoers: set panel-tempban bantime <sekundy>).']);
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
                'recaptcha_secret_set'      => (string) Settings::get('recaptcha_secret', '') !== '',   // sam sekret nie trafia do HTML
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
