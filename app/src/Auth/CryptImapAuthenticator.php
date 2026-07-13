<?php
declare(strict_types=1);

namespace App\Auth;

use App\Security\Password;
use App\Users\UserRepository;

/**
 * Tryb deweloperski (APP_ENV=dev): weryfikuje hasło względem hasha $6$
 * zapisanego w virtual_users. Pozwala testować pełny flow logowania
 * webmaila lokalnie, bez działającego serwera IMAP.
 */
final class CryptImapAuthenticator implements ImapAuthenticator
{
    public function __construct(private UserRepository $users) {}

    public function authenticate(string $email, string $password): bool
    {
        $hash = $this->users->passwordHash($email);
        return $hash !== null && Password::verifyDovecot($password, $hash);
    }
}
