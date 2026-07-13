<?php
declare(strict_types=1);

namespace App\Auth;

use App\Support\Env;
use App\Users\UserRepository;

final class AuthenticatorFactory
{
    public static function make(UserRepository $users): ImapAuthenticator
    {
        return Env::isDev()
            ? new CryptImapAuthenticator($users)
            : new RoundcubeImapAuthenticator();
    }
}
