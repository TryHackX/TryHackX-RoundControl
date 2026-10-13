<?php
declare(strict_types=1);

namespace App\Auth;

use App\Admin\AdminRepository;
use App\Security\Password;

/**
 * Sprawdzenie hasła administratora panelu — BEZ skutków ubocznych (zapis prób, limity i syslog robi PortalController,
 * który zna wynik całego logowania: hasło może nie pasować do panelu, a pasować do skrzynki).
 *
 * Zawsze dokładnie jedna weryfikacja Argon2id: dla adresu, który nie jest administratorem, względem stałego hasha hasła,
 * którego nikt nie zna — czas odpowiedzi nie zdradza, czy pod tym adresem jest konto administratora.
 */
final class AdminAuthenticator
{
    /** Argon2id (te same parametry co prawdziwe hashe) hasła, którego nikt nie zna. */
    private const DUMMY_HASH = '$argon2id$v=19$m=65536,t=4,p=1$MzhGbDhMZVVya0F6NDh0NQ$m17wfOCv9qhOCRhWdzyw5LNGQtaF2PFSNfLBnXCcHKo';

    public function __construct(private AdminRepository $admins) {}

    /**
     * @param bool|null $exists wyjście: czy pod tym adresem jest konto administratora (tylko do zapisów po stronie
     *                          serwera — nigdy do odpowiedzi dla klienta przed poprawnym hasłem)
     * @return array{id:int,pwd_hash:string}|null wiersz administratora, gdy hasło jest jego hasłem do panelu
     */
    public function verify(string $email, string $password, ?bool &$exists = null): ?array
    {
        $row    = $this->admins->findByEmail($email);
        $exists = $row !== null;
        $valid  = Password::verifyAdmin($password, $row !== null ? (string) $row['pwd_hash'] : self::DUMMY_HASH);

        return $valid && $row !== null ? ['id' => (int) $row['id'], 'pwd_hash' => (string) $row['pwd_hash']] : null;
    }
}
