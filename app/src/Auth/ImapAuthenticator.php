<?php
declare(strict_types=1);

namespace App\Auth;

/**
 * Uwierzytelnianie konta pocztowego. Dwie implementacje:
 *  - CryptImapAuthenticator (dev): weryfikacja po hashu $6$ z bazy,
 *  - RoundcubeImapAuthenticator (prod): realne logowanie IMAP + handoff do webmaila.
 */
interface ImapAuthenticator
{
    public function authenticate(string $email, string $password): bool;
}
