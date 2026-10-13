<?php
declare(strict_types=1);

namespace App\Auth;

use Throwable;

/**
 * Tryb produkcyjny: realne logowanie do IMAP przez Roundcube i przekazanie
 * sesji webmaila (sekwencja jak w index.php Roundcube po wysłaniu formularza logowania:
 * kill_session -> hook "authenticate" -> login -> remove('temp') -> regenerate_id -> set_auth_cookie ->
 * log_login -> hook "login_after").
 */
final class RoundcubeImapAuthenticator implements ImapAuthenticator
{
    public function authenticate(string $email, string $password): bool
    {
        // Zamykamy sesję portalu i ODŁĄCZAMY jej ID: rcube::session_init()
        // pomija inicjalizację, gdy session_id() jest ustawione (a po samym
        // session_write_close() wciąż jest) — wtedy $rcmail->session zostaje
        // null i set_auth_cookie() wywala się mimo udanego logowania IMAP.
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }
        if (session_id() !== '') {
            session_id('');
        }

        require_once PROJECT_ROOT . '/program/include/iniset.php';
        $rcmail = \rcmail::get_instance();

        // Jak index.php: nowe logowanie zaczyna od czystej sesji Roundcube. Bez tego dane sesji poprzedniego
        // użytkownika tej przeglądarki (wspólny komputer) przechodziły do nowego logowania.
        try {
            if ($rcmail->session !== null) {
                $rcmail->kill_session();
            }
        } catch (Throwable $e) {
            error_log('portal: kill_session() przed logowaniem nie powiodło się: ' . $e->getMessage());
        }

        // Język webmaila jak w portalu (pl -> pl_PL, en -> en_US; Roundcube sam dobiera wariant). Dotyczy sesji i kont
        // logujących się pierwszy raz; język zapisany przez użytkownika w ustawieniach Roundcube nadal wygrywa.
        try {
            $rcmail->load_language(\App\I18n\Lang::current());
        } catch (Throwable $e) {
            error_log('portal: ustawienie języka Roundcube nie powiodło się: ' . $e->getMessage());
        }

        // Hook "authenticate" (jak w index.php) — wtyczki uwierzytelniania / 2FA mogą przerwać lub zmienić dane.
        $auth = $rcmail->plugins->exec_hook('authenticate', [
            'host'        => $rcmail->autoselect_host(),   // RC >= 1.5: imap_host, nie default_host
            'user'        => $email,
            'pass'        => $password,
            'valid'       => true,
            'cookiecheck' => true,
        ]);
        if (!empty($auth['abort']) || empty($auth['valid'])) {
            return false;
        }

        if (!$rcmail->login($auth['user'], $auth['pass'], $auth['host'], $auth['cookiecheck'])) {
            return false;
        }

        // Sekwencja po udanym logowaniu — odwzorowana z index.php.
        $rcmail->session->remove('temp');
        $rcmail->session->regenerate_id(false);
        $rcmail->session->set_auth_cookie();
        $rcmail->log_login();
        $rcmail->plugins->exec_hook('login_after', ['_task' => 'mail']);

        return true;
    }
}
