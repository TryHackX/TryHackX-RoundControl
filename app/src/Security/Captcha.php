<?php
declare(strict_types=1);

namespace App\Security;

use App\Settings\Settings;
use App\Support\Ip;

/**
 * CAPTCHA niezależna od dostawcy (1.3.0): Google reCAPTCHA v2 (pole wyboru — domyślnie, jak dotąd), Google reCAPTCHA v3
 * (niewidoczna, wynik 0.0–1.0), Cloudflare Turnstile albo hCaptcha. Wszystkie miejsca, które pytają o CAPTCHA (krok
 * "adres e-mail" w portalu, rejestracja), idą przez tę klasę.
 *
 * Ustawienia (app_settings) — nazwy z 1.x zostają, więc istniejąca instalacja z reCAPTCHA v2 działa bez migracji:
 *   recaptcha_enabled            główny przełącznik "CAPTCHA włączona"
 *   captcha_provider             recaptcha | recaptcha_v3 | turnstile | hcaptcha (brak = recaptcha)
 *   <dostawca>_site_key / <dostawca>_secret   klucz witryny (publiczny) i sekret (nigdy nie trafia do przeglądarki)
 *   recaptcha_v3_min_score       próg wyniku v3 (domyślnie 0.5)
 *
 * CAPTCHA obowiązuje tylko, gdy jest włączona I aktywny dostawca ma oba klucze (panel nie pozwala jej włączyć bez nich —
 * inaczej nikt, także administrator, nie zalogowałby się). Weryfikacja jest FAIL-CLOSED: brak sekretu, brak tokenu,
 * błąd sieci / odpowiedzi, zbyt niski wynik albo inna akcja v3 = odmowa.
 */
final class Captcha
{
    public const PROVIDERS = [
        'recaptcha'    => 'captcha.provider.recaptcha',
        'recaptcha_v3' => 'captcha.provider.recaptcha_v3',
        'turnstile'    => 'captcha.provider.turnstile',
        'hcaptcha'     => 'captcha.provider.hcaptcha',
    ];

    /** Wymiary widżetu (px) — okno .captcha-box przycina ramkę iframe dokładnie do nich. */
    public const WIDGET_SIZE = [
        'recaptcha' => [302, 76],
        'turnstile' => [300, 65],
        'hcaptcha'  => [302, 76],
    ];

    private const VERIFY = [
        'recaptcha'    => 'https://www.google.com/recaptcha/api/siteverify',
        'recaptcha_v3' => 'https://www.google.com/recaptcha/api/siteverify',
        'turnstile'    => 'https://challenges.cloudflare.com/turnstile/v0/siteverify',
        'hcaptcha'     => 'https://api.hcaptcha.com/siteverify',
    ];

    /** Klucze ustawień, z których składa się konfiguracja (do odczytu z bazy). */
    public const SETTING_KEYS = [
        'recaptcha_enabled', 'captcha_provider', 'recaptcha_v3_min_score',
        'recaptcha_site_key', 'recaptcha_secret', 'recaptcha_v3_site_key', 'recaptcha_v3_secret',
        'turnstile_site_key', 'turnstile_secret', 'hcaptcha_site_key', 'hcaptcha_secret',
    ];

    /** Transport HTTP do testów: fn(string $url, array $fields): ?array — null = błąd sieci. */
    public static ?\Closure $transport = null;

    /* ---------------------------------------------------- czyste funkcje na tablicy ustawień (testowalne) */

    public static function providerOf(array $cfg): string
    {
        $p = (string) ($cfg['captcha_provider'] ?? 'recaptcha');
        return isset(self::PROVIDERS[$p]) ? $p : 'recaptcha';
    }

    public static function siteKeyOf(array $cfg, ?string $provider = null): string
    {
        return trim((string) ($cfg[($provider ?? self::providerOf($cfg)) . '_site_key'] ?? ''));
    }

    public static function secretOf(array $cfg, ?string $provider = null): string
    {
        return trim((string) ($cfg[($provider ?? self::providerOf($cfg)) . '_secret'] ?? ''));
    }

    public static function minScoreOf(array $cfg): float
    {
        $raw = trim((string) ($cfg['recaptcha_v3_min_score'] ?? '0.5'));
        return is_numeric($raw) ? max(0.0, min(1.0, (float) $raw)) : 0.5;
    }

    /** Włączona i aktywny dostawca ma klucz witryny oraz sekret. */
    public static function activeIn(array $cfg): bool
    {
        return (string) ($cfg['recaptcha_enabled'] ?? '0') === '1'
            && self::siteKeyOf($cfg) !== '' && self::secretOf($cfg) !== '';
    }

    /**
     * Weryfikacja tokenu u aktywnego dostawcy — FAIL-CLOSED. $action: nazwa akcji v3 (np. "login"); dla v3 musi się
     * zgadzać z tym, co zwrócił Google (brak akcji w odpowiedzi = odmowa), pozostali dostawcy ją pomijają.
     */
    public static function verifyIn(array $cfg, string $token, ?string $action = null, ?string $remoteIp = null): bool
    {
        $provider = self::providerOf($cfg);
        $secret   = self::secretOf($cfg);
        if ($secret === '' || $token === '' || strlen($token) > 4096) {
            return false;
        }
        $fields = ['secret' => $secret, 'response' => $token];
        if ($remoteIp !== null && $remoteIp !== '') {
            $fields['remoteip'] = $remoteIp;
        }
        if ($provider === 'hcaptcha') {
            $fields['sitekey'] = self::siteKeyOf($cfg);
        }
        $json = self::post(self::VERIFY[$provider], $fields);
        if ($json === null || ($json['success'] ?? false) !== true) {
            if ($json !== null && isset($json['error-codes']) && is_array($json['error-codes'])) {
                error_log('[captcha] ' . $provider . ' rejected the token: ' . implode(',', array_map('strval', $json['error-codes'])));
            }
            return false;
        }
        if ($provider === 'recaptcha_v3') {
            // token v3 zawsze ma wynik; brak wyniku = klucz v2 wklejony jako v3 — odmowa
            if (!isset($json['score']) || !is_numeric($json['score']) || (float) $json['score'] < self::minScoreOf($cfg)) {
                return false;
            }
            if ($action !== null && (string) ($json['action'] ?? '') !== $action) {
                return false;
            }
        }
        return true;
    }

    /**
     * 1.4.0: does the active provider reject the saved secret? Asked with a token that cannot be valid. Cloudflare
     * Turnstile answers "invalid-input-secret" for a wrong secret; Google and hCaptcha check the token first and answer
     * "invalid-input-response" either way (checked 2026-10-21), so for them a wrong secret goes unnoticed here.
     * true = rejected, false = not rejected, null = no answer (network). Enabling CAPTCHA with a rejected secret locks
     * everyone out, the administrator included (unless the address is in SCAN_TRUSTED_IPS).
     */
    public static function secretRejectedIn(array $cfg): ?bool
    {
        $provider = self::providerOf($cfg);
        $fields   = ['secret' => self::secretOf($cfg), 'response' => 'roundcontrol-secret-check'];
        if ($provider === 'hcaptcha') {
            $fields['sitekey'] = self::siteKeyOf($cfg);
        }
        $json = self::post(self::VERIFY[$provider], $fields);
        if ($json === null) {
            return null;
        }
        $codes = is_array($json['error-codes'] ?? null) ? array_map('strval', $json['error-codes']) : [];
        return array_intersect($codes, ['invalid-input-secret', 'missing-input-secret', 'sitekey-secret-mismatch']) !== [];
    }

    /**
     * Hosty, które polityka CSP strony z CAPTCHA musi dopuścić — tylko AKTYWNEGO dostawcy (strona z Turnstile nie
     * pozwala skryptom Google'a i odwrotnie). Puste listy, gdy CAPTCHA nie obowiązuje.
     *
     * @return array{script:string[],frame:string[],connect:string[],style:string[]}
     */
    public static function cspHostsIn(array $cfg): array
    {
        $none = ['script' => [], 'frame' => [], 'connect' => [], 'style' => []];
        if (!self::activeIn($cfg)) {
            return $none;
        }
        return match (self::providerOf($cfg)) {
            'turnstile' => ['script' => ['https://challenges.cloudflare.com'], 'frame' => ['https://challenges.cloudflare.com'],
                            'connect' => ['https://challenges.cloudflare.com'], 'style' => []],
            // apex obok wildcardu: *.hcaptcha.com nie obejmuje samego hcaptcha.com
            'hcaptcha'  => ['script' => ['https://js.hcaptcha.com', 'https://hcaptcha.com', 'https://*.hcaptcha.com'],
                            'frame' => ['https://hcaptcha.com', 'https://*.hcaptcha.com'],
                            'connect' => ['https://hcaptcha.com', 'https://*.hcaptcha.com'],
                            'style' => ['https://hcaptcha.com', 'https://*.hcaptcha.com']],
            // v3 nie ma ramki z widżetem; api.js pobiera resztę kodu z www.gstatic.com
            'recaptcha_v3' => ['script' => ['https://www.google.com', 'https://www.gstatic.com'], 'frame' => ['https://www.google.com'],
                               'connect' => ['https://www.google.com'], 'style' => []],
            default     => ['script' => ['https://www.google.com', 'https://www.gstatic.com'], 'frame' => ['https://www.google.com'],
                            'connect' => [], 'style' => []],
        };
    }

    /**
     * Adres skryptu dostawcy (render niejawny: widżet rysuje się sam w .g-recaptcha / .cf-turnstile / .h-captcha).
     * $lang: język widżetu (?hl= u Google i hCaptcha; Turnstile bierze go z atrybutu data-language kontenera).
     */
    public static function scriptUrlIn(array $cfg, string $lang = ''): string
    {
        $hl = preg_match('/^[a-z]{2,3}$/', $lang) ? $lang : '';
        return match (self::providerOf($cfg)) {
            'turnstile'    => 'https://challenges.cloudflare.com/turnstile/v0/api.js',
            'hcaptcha'     => 'https://js.hcaptcha.com/1/api.js' . ($hl !== '' ? '?hl=' . $hl : ''),
            'recaptcha_v3' => 'https://www.google.com/recaptcha/api.js?render=' . rawurlencode(self::siteKeyOf($cfg))
                              . ($hl !== '' ? '&hl=' . $hl : ''),
            default        => 'https://www.google.com/recaptcha/api.js' . ($hl !== '' ? '?hl=' . $hl : ''),
        };
    }

    /** Klasa kontenera widżetu dla renderu niejawnego ('' dla v3 — bez widżetu). */
    public static function widgetClassIn(array $cfg): string
    {
        return match (self::providerOf($cfg)) {
            'turnstile'    => 'cf-turnstile',
            'hcaptcha'     => 'h-captcha',
            'recaptcha_v3' => '',
            default        => 'g-recaptcha',
        };
    }

    /** Token z formularza: pole ogólne (v3) albo pole, które wstawia widżet danego dostawcy. */
    public static function tokenFrom(array $post): string
    {
        foreach (['captcha_token', 'cf-turnstile-response', 'h-captcha-response', 'g-recaptcha-response'] as $k) {
            if (isset($post[$k]) && is_string($post[$k]) && $post[$k] !== '') {
                return $post[$k];
            }
        }
        return '';
    }

    /* ---------------------------------------------------- to samo na ustawieniach z bazy */

    /** @return array<string,string> */
    public static function config(): array
    {
        $cfg = [];
        foreach (self::SETTING_KEYS as $k) {
            $v = Settings::get($k);
            if ($v !== null) {
                $cfg[$k] = $v;
            }
        }
        return $cfg;
    }

    /**
     * Stan dla formularza panelu — BEZ sekretów (tylko informacja, czy są zapisane).
     *
     * @return array{enabled:bool,provider:string,min_score:string,keys:array<string,array{site:string,secret_set:bool}>}
     */
    public static function panelState(?array $cfg = null): array
    {
        $cfg ??= self::config();
        $keys = [];
        foreach (array_keys(self::PROVIDERS) as $pid) {
            $keys[$pid] = ['site' => self::siteKeyOf($cfg, $pid), 'secret_set' => self::secretOf($cfg, $pid) !== ''];
        }
        $score = rtrim(rtrim(number_format(self::minScoreOf($cfg), 2, '.', ''), '0'), '.');
        return [
            'enabled'   => (string) ($cfg['recaptcha_enabled'] ?? '0') === '1',
            'provider'  => self::providerOf($cfg),
            'min_score' => $score === '' ? '0' : $score,
            'keys'      => $keys,
        ];
    }

    /** Klucz witryny / sekret: znaki, jakich używają wszyscy czterej dostawcy (6Lc…, 0x4AAA…, UUID hCaptcha). */
    public static function validKey(string $v): bool
    {
        return (bool) preg_match('/^[A-Za-z0-9_.\-]{1,300}$/', $v);
    }

    public static function provider(): string
    {
        return self::providerOf(self::config());
    }

    /** Sprawdzenie odpowiedzi z bieżącego żądania POST (true, gdy CAPTCHA nie obowiązuje). */
    public static function check(?string $action = null): bool
    {
        $cfg = self::config();
        if (!self::activeIn($cfg)) {
            return true;
        }
        return self::verifyIn($cfg, self::tokenFrom($_POST), $action, Ip::raw());
    }

    /* ---------------------------------------------------- HTTP */

    /**
     * POST do weryfikatora: TLS sprawdzany, twarde limity czasu (połączenie 5 s, całość 8 s), dwie próby — łącze tego
     * serwera bywa stratne (ten sam host co tracker), a pierwsza próba połączenia bywała przyczyną odmowy przy poprawnej
     * odpowiedzi. null = błąd (wywołujący odmawia).
     */
    private static function post(string $url, array $fields): ?array
    {
        if (self::$transport !== null) {
            return (self::$transport)($url, $fields);
        }
        for ($attempt = 1; $attempt <= 2; $attempt++) {
            $json = self::postOnce($url, $fields, $err);
            if ($json !== null) {
                return $json;
            }
            error_log('[captcha] verification, attempt ' . $attempt . ': ' . ($err ?: 'error') . ' (' . parse_url($url, PHP_URL_HOST) . ')');
        }
        return null;
    }

    private static function postOnce(string $url, array $fields, ?string &$err = null): ?array
    {
        $err  = null;
        $body = http_build_query($fields);
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            if ($ch === false) {
                $err = 'curl_init';
                return null;
            }
            curl_setopt_array($ch, [
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => $body,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CONNECTTIMEOUT => 5,
                CURLOPT_TIMEOUT        => 8,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_HTTPHEADER     => ['Content-Type: application/x-www-form-urlencoded', 'Accept: application/json'],
            ]);
            $result = curl_exec($ch);
            $code   = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            if ($result === false) {
                $err = 'cURL: ' . curl_error($ch);
                return null;
            }
            if ($code < 200 || $code >= 300) {
                $err = 'HTTP ' . $code;
                return null;
            }
        } else {
            $result = @file_get_contents($url, false, stream_context_create([
                'http' => ['method' => 'POST', 'header' => "Content-Type: application/x-www-form-urlencoded\r\nAccept: application/json\r\n",
                           'content' => $body, 'timeout' => 8],
                'ssl'  => ['verify_peer' => true, 'verify_peer_name' => true],
            ]));
            if ($result === false) {
                $err = 'stream';
                return null;
            }
        }
        $json = json_decode((string) $result, true);
        if (!is_array($json)) {
            $err = 'response is not JSON';
            return null;
        }
        return $json;
    }
}
