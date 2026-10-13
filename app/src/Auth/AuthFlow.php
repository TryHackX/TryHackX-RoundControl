<?php
declare(strict_types=1);

namespace App\Auth;

use App\Support\Ip;

/**
 * Stan wieloetapowego logowania w sesji portalu, wiązany z IP i wygasający po TIMEOUT sekundach bezczynności:
 *
 *   'password' — po kroku "adres e-mail": znamy adres, nic więcej (serwer odpowiada na każdy poprawnie zapisany adres
 *                tak samo, więc nie da się wyliczać kont ani ról);
 *   'choose'   — 1.2.0: hasło okazało się hasłem ADMINISTRATORA, a pod tym adresem jest też skrzynka. Użytkownik wybiera
 *                kafelek: Panel Admina albo Webmail.
 *
 * Do wejścia w webmail z kafelka potrzebne jest hasło (logowanie IMAP przez Roundcube). Nie leży ono w sesji jawnym
 * tekstem: jest zaszyfrowane (AES-256-GCM) kluczem, który dostaje TYLKO przeglądarka (w odpowiedzi JSON; strona trzyma go
 * w pamięci) i odsyła razem z wyborem kafelka. Sam plik sesji nie wystarcza do odczytania hasła, sam klucz też nie;
 * szyfrogram jest jednorazowy i znika po wyborze, po "Wróć" albo po TIMEOUT.
 */
final class AuthFlow
{
    private const KEY     = 'portal_auth_flow';
    private const TIMEOUT = 120;
    private const CIPHER  = 'aes-256-gcm';

    public function start(string $email): void
    {
        $_SESSION[self::KEY] = [
            'stage'   => 'password',
            'email'   => $email,
            'ip'      => Ip::raw(),
            'expires' => time() + self::TIMEOUT,
        ];
    }

    /** Czy trwa logowanie (na danym etapie, gdy podany)? Przedłuża ważność. */
    public function valid(?string $stage = null): bool
    {
        if (empty($_SESSION[self::KEY]) || !is_array($_SESSION[self::KEY])) {
            return false;
        }
        $flow = $_SESSION[self::KEY];
        if (time() > (int) ($flow['expires'] ?? 0) || ($flow['ip'] ?? '') !== Ip::raw()) {
            $this->clear();
            return false;
        }
        if ($stage !== null && ($flow['stage'] ?? 'password') !== $stage) {
            return false;
        }
        $_SESSION[self::KEY]['expires'] = time() + self::TIMEOUT;
        return true;
    }

    public function email(): string
    {
        return (string) ($_SESSION[self::KEY]['email'] ?? '');
    }

    /**
     * Hasło administratora poprawne, pod adresem jest skrzynka: przejście do wyboru kafelka.
     *
     * @param ?string $password hasło do zaszyfrowania dla kafelka Webmail; null = kafelek Webmail niedostępny
     * @return string klucz dla przeglądarki (base64url) albo '' gdy nic nie zaszyfrowano
     */
    public function offerChoice(int $adminId, string $pwdHash, ?string $password): string
    {
        $flow = $_SESSION[self::KEY] ?? [];
        $flow['stage']    = 'choose';
        $flow['admin_id'] = $adminId;
        $flow['admin_fp'] = hash('sha256', $pwdHash);
        $flow['expires']  = time() + self::TIMEOUT;
        unset($flow['pw']);

        $key = '';
        if ($password !== null && function_exists('openssl_encrypt')) {
            $raw = random_bytes(32);
            $iv  = random_bytes(12);
            $tag = '';
            $ct  = openssl_encrypt($password, self::CIPHER, $raw, OPENSSL_RAW_DATA, $iv, $tag, $this->aad($flow));
            if (is_string($ct) && strlen($tag) === 16) {
                $flow['pw'] = base64_encode($iv . $tag . $ct);
                $key = rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
            }
        }
        $_SESSION[self::KEY] = $flow;
        return $key;
    }

    public function adminId(): int
    {
        return (int) ($_SESSION[self::KEY]['admin_id'] ?? 0);
    }

    /** Odcisk hasha hasła administratora z chwili sprawdzenia hasła (zmiana hasła w międzyczasie unieważnia wybór). */
    public function adminFp(): string
    {
        return (string) ($_SESSION[self::KEY]['admin_fp'] ?? '');
    }

    /** Jednorazowo odszyfruj hasło dla kafelka Webmail kluczem od przeglądarki; null = brak szyfrogramu albo zły klucz. */
    public function takePassword(string $key): ?string
    {
        $flow = is_array($_SESSION[self::KEY] ?? null) ? $_SESSION[self::KEY] : [];
        unset($_SESSION[self::KEY]['pw']);
        $blob = base64_decode((string) ($flow['pw'] ?? ''), true);
        $raw  = base64_decode(strtr($key, '-_', '+/'), true);
        if ($blob === false || strlen($blob) < 28 || $raw === false || strlen($raw) !== 32 || !function_exists('openssl_decrypt')) {
            return null;
        }
        $plain = openssl_decrypt(substr($blob, 28), self::CIPHER, $raw, OPENSSL_RAW_DATA,
            substr($blob, 0, 12), substr($blob, 12, 16), $this->aad($flow));
        return is_string($plain) ? $plain : null;
    }

    public function clear(): void
    {
        unset($_SESSION[self::KEY]);
    }

    /** Dane uwierzytelniane razem z szyfrogramem: szyfrogram przeniesiony do innego logowania się nie odszyfruje. */
    private function aad(array $flow): string
    {
        return 'rc-choice|' . ($flow['email'] ?? '') . '|' . ($flow['admin_id'] ?? 0) . '|' . ($flow['ip'] ?? '');
    }
}
