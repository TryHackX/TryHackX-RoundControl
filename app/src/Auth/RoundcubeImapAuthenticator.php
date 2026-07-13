<?php
declare(strict_types=1);

namespace App\Auth;

/**
 * Tryb produkcyjny: realne logowanie do IMAP przez Roundcube i przekazanie
 * sesji webmaila (sekwencja jak w public_html/index.php po udanym loginie).
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

        // Host tak jak w index.php (RC >= 1.5: imap_host, nie default_host).
        if (!$rcmail->login($email, $password, $rcmail->autoselect_host(), true)) {
            return false;
        }

        // Sekwencja po udanym logowaniu — odwzorowana z public_html/index.php.
        $rcmail->session->remove('temp');
        $rcmail->session->regenerate_id(false);
        $rcmail->session->set_auth_cookie();
        $rcmail->log_login();
        $rcmail->plugins->exec_hook('login_after', ['_task' => 'mail']);

        return true;
    }
}
