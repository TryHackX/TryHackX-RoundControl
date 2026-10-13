<?php
declare(strict_types=1);

namespace App\I18n;

use App\Settings\Settings;
use App\Support\Cookie;
use App\Support\Env;
use Throwable;

/**
 * Język interfejsu (1.3.0) — wzorem panelu trackera, z jedną zasadniczą różnicą: kod aplikacji należy do roota, a użytkownik
 * puli (mailweb) nie może do niego pisać.
 *
 *  - JĘZYKI WBUDOWANE (pl — domyślny i wzorcowy, en) leżą w kodzie: app/lang/<kod>/<obszar>.php (tablice, `require`).
 *  - JĘZYKI DODANE (kopia, wgrany pakiet) to pliki JSON w katalogu DANYCH (LANG_DATA_DIR w .env, domyślnie
 *    /var/lib/roundcontrol/lang, właściciel mailweb, 0750). Czytane wyłącznie json_decode() — z miejsca, do którego pisze
 *    PHP, nigdy nic nie jest dołączane (include/require). Każda wartość przechodzi sanitizeValue() przy zapisie I przy
 *    odczycie (plik mógł się tam znaleźć inną drogą).
 *
 * KLUCZE: płaska mapa "obszar.nazwa" => tekst. Teksty mogą zawierać nieco HTML (<b>, <code>, <a href>…): szablony
 * drukują je przez html() (parametry escapowane), a zwykłe przez h() (wszystko escapowane). Brak klucza: język bieżący
 * → język domyślny serwisu → polski (wzorzec) → sam klucz (widać od razu, czego brakuje).
 *
 * WYBÓR JĘZYKA (pierwszy pasujący, tylko spośród WŁĄCZONYCH):
 *   1. ?lang=xx (przełącznik) — zapamiętany w ciasteczku `lang` (rok, SameSite=Lax, Secure poza trybem dev),
 *   2. ciasteczko `lang`,
 *   3. Accept-Language przeglądarki,
 *   4. default_language z ustawień (domyślnie pl).
 * Ciasteczko jest czytane przez PHP, więc strona od pierwszego bajtu jest w wybranym języku (bez mignięcia).
 */
final class Lang
{
    public const BUILT_IN  = ['pl', 'en'];
    public const REFERENCE = 'pl';
    public const COOKIE    = 'lang';
    public const CODE_RE   = '/^[a-z]{2,3}$/';

    /** Tagi dozwolone w tekstach z zewnątrz (pakiet, kopia); atrybut tylko href w <a>. */
    public const SAFE_TAGS = ['a', 'strong', 'em', 'b', 'i', 'code', 'kbd', 'br', 'span', 'small', 'sup'];

    /** Limit wgrywanego pakietu (bajty). */
    public const UPLOAD_MAX = 512 * 1024;

    public const NAMES = [
        'pl' => 'Polski', 'en' => 'English', 'de' => 'Deutsch', 'fr' => 'Français', 'es' => 'Español', 'it' => 'Italiano',
        'nl' => 'Nederlands', 'pt' => 'Português', 'cs' => 'Čeština', 'sk' => 'Slovenčina', 'uk' => 'Українська',
        'ru' => 'Русский', 'lt' => 'Lietuvių', 'lv' => 'Latviešu', 'et' => 'Eesti', 'hu' => 'Magyar', 'ro' => 'Română',
        'sv' => 'Svenska', 'da' => 'Dansk', 'fi' => 'Suomi', 'no' => 'Norsk', 'tr' => 'Türkçe', 'el' => 'Ελληνικά',
        'ja' => '日本語', 'zh' => '中文', 'ko' => '한국어',
    ];

    private static ?string $current = null;
    /** @var array<string,array<string,string>> */
    private static array $loaded = [];
    private static ?array $available = null;

    /* ------------------------------------------------------------------ katalogi i lista języków */

    public static function codeDir(): string
    {
        return APP_ROOT . '/lang';
    }

    /** @return string[] pliki języka wbudowanego (posortowane, stała kolejność) */
    public static function builtInFiles(string $code): array
    {
        $files = glob(self::codeDir() . '/' . $code . '/*.php') ?: [];
        sort($files);
        return $files;
    }

    public static function dataDir(): string
    {
        $d = trim((string) Env::get('LANG_DATA_DIR', ''));
        return $d !== '' ? rtrim($d, '/\\') : '/var/lib/roundcontrol/lang';
    }

    public static function name(string $code): string
    {
        if (isset(self::NAMES[$code])) {
            return self::NAMES[$code];
        }
        if (class_exists('Locale') && preg_match(self::CODE_RE, $code)) {
            try {
                $n = (string) \Locale::getDisplayLanguage($code, $code);
                if ($n !== '' && strtolower($n) !== $code) {
                    return $n;
                }
            } catch (Throwable) {
            }
        }
        return strtoupper($code);
    }

    /** @return array<string,array{name:string,builtIn:bool}> wszystkie zainstalowane, wbudowane najpierw */
    public static function available(): array
    {
        if (self::$available !== null) {
            return self::$available;
        }
        $out = [];
        foreach (self::BUILT_IN as $c) {
            $out[$c] = ['name' => self::name($c), 'builtIn' => true];
        }
        foreach (glob(self::dataDir() . '/*.json') ?: [] as $f) {
            $c = strtolower(basename($f, '.json'));
            if (preg_match(self::CODE_RE, $c) && !isset($out[$c])) {
                $out[$c] = ['name' => self::name($c), 'builtIn' => false];
            }
        }
        return self::$available = $out;
    }

    public static function installed(string $code): bool
    {
        return isset(self::available()[$code]);
    }

    public static function defaultLanguage(): string
    {
        try {
            $d = strtolower(trim((string) Settings::get('default_language', self::REFERENCE)));
        } catch (Throwable) {
            $d = self::REFERENCE;
        }
        return self::installed($d) ? $d : self::REFERENCE;
    }

    /** @return string[] kody włączone (pusta lista w ustawieniach = wszystkie zainstalowane; domyślny zawsze włączony) */
    public static function enabled(): array
    {
        $all = array_keys(self::available());
        try {
            $raw = trim((string) Settings::get('enabled_languages', ''));
        } catch (Throwable) {
            $raw = '';
        }
        $on = $raw === '' ? $all : array_values(array_intersect($all, array_map('trim', explode(',', strtolower($raw)))));
        $def = self::defaultLanguage();
        if (!in_array($def, $on, true)) {
            $on[] = $def;
        }
        return array_values(array_intersect($all, $on));
    }

    public static function invalidate(): void
    {
        self::$available = null;
        self::$loaded = [];
    }

    /* ------------------------------------------------------------------ wybór języka */

    public static function init(): void
    {
        if (self::$current !== null) {
            return;
        }
        $enabled = self::enabled();
        $pick = null;
        $q = $_GET['lang'] ?? null;
        if (is_string($q) && in_array(strtolower($q), $enabled, true)) {
            $pick = strtolower($q);
            self::remember($pick);
        }
        $c = $_COOKIE[self::COOKIE] ?? null;
        if ($pick === null && is_string($c) && in_array(strtolower($c), $enabled, true)) {
            $pick = strtolower($c);
        }
        $pick ??= self::fromAcceptLanguage($enabled);
        self::$current = $pick ?? self::defaultLanguage();
    }

    /** Zapamiętaj wybór w ciasteczku `lang` (rok, te same parametry co pozostałe ciasteczka preferencji). */
    public static function remember(string $code): void
    {
        Cookie::set(self::COOKIE, $code, 365 * 86400);
    }

    /** @param string[] $enabled */
    public static function fromAcceptLanguage(array $enabled): ?string
    {
        $header = (string) ($_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? '');
        $best = null;
        $bestQ = 0.0;
        foreach (explode(',', $header) as $pos => $part) {
            $seg  = array_map('trim', explode(';', $part));
            $code = substr(strtolower((string) array_shift($seg)), 0, 2);
            $qv   = 1.0;
            foreach ($seg as $p) {
                if (preg_match('/^q\s*=\s*([01](?:\.\d{1,3})?)$/i', $p, $m)) {
                    $qv = (float) $m[1];
                }
            }
            $qv -= $pos * 0.0001;   // przy równych wagach wygrywa wcześniejszy
            if ($qv > $bestQ && in_array($code, $enabled, true)) {
                $best = $code;
                $bestQ = $qv;
            }
        }
        return $best;
    }

    public static function current(): string
    {
        return self::$current ?? self::defaultLanguage();
    }

    /** Ustaw język wprost (testy, CLI). */
    public static function force(?string $code): void
    {
        self::$current = $code;
    }

    /* ------------------------------------------------------------------ teksty */

    /** @return array<string,string> */
    public static function load(string $code): array
    {
        if (isset(self::$loaded[$code])) {
            return self::$loaded[$code];
        }
        $out = [];
        if (in_array($code, self::BUILT_IN, true)) {
            // app/lang/<kod>/<obszar>.php — pliki z kodu (root:root), każdy zwraca płaską tablicę klucz => tekst
            foreach (self::builtInFiles($code) as $f) {
                $data = require $f;
                if (is_array($data)) {
                    $out += $data;
                }
            }
        } elseif (preg_match(self::CODE_RE, $code)) {
            $file = self::dataDir() . '/' . $code . '.json';
            $raw  = @file_get_contents($file, false, null, 0, self::UPLOAD_MAX * 4);
            $data = is_string($raw) ? json_decode($raw, true, 2) : null;
            if (is_array($data)) {
                $ref = self::load(self::REFERENCE);
                foreach ($data as $k => $v) {
                    if (is_string($k) && is_string($v) && isset($ref[$k]) && self::sanitizeValue($v) !== null) {
                        $out[$k] = $v;
                    }
                }
            }
        }
        return self::$loaded[$code] = $out;
    }

    /** Tekst surowy (z ewentualnym dozwolonym HTML), parametry :nazwa podstawione bez escapowania. */
    public static function t(string $key, array $params = [], ?string $code = null): string
    {
        $code ??= self::current();
        $s = self::load($code)[$key]
            ?? self::load(self::defaultLanguage())[$key]
            ?? self::load(self::REFERENCE)[$key]
            ?? $key;
        if ($params) {
            $repl = [];
            foreach ($params as $k => $v) {
                $repl[':' . $k] = (string) $v;
            }
            $s = strtr($s, $repl);
        }
        return $s;
    }

    /** Tekst do HTML jako zwykły tekst (całość escapowana). */
    public static function h(string $key, array $params = []): string
    {
        return htmlspecialchars(self::t($key, $params), ENT_QUOTES, 'UTF-8');
    }

    /** Tekst do HTML z dozwolonym HTML z pliku języka; escapowane są tylko parametry (dane). */
    public static function html(string $key, array $params = []): string
    {
        $safe = [];
        foreach ($params as $k => $v) {
            $safe[$k] = htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
        }
        return self::t($key, $safe);
    }

    /** Tekst w języku domyślnym serwisu (zapisy trwałe: dziennik audytowy — jeden język niezależnie od przeglądarki). */
    public static function site(string $key, array $params = []): string
    {
        return self::t($key, $params, self::defaultLanguage());
    }

    /** @return array<string,string> teksty dla skryptów: klucze js.* w bieżącym języku (z uzupełnieniem). */
    public static function jsStrings(string $area): array
    {
        $out    = [];
        $prefix = 'js.' . $area . '.';
        foreach (self::load(self::REFERENCE) as $k => $_) {
            if (str_starts_with($k, $prefix)) {
                $out[substr($k, strlen($prefix))] = self::t($k);
            }
        }
        return $out;
    }

    /**
     * Blok danych dla JS (nie skrypt — CSP go nie dotyczy): <script type="application/json" id="i18n-data"> z tekstami
     * js.<obszar>.* (portal | panel); skrypt czyta je funkcją t('klucz', {param}) — klucz bez prefiksu.
     */
    public static function jsBridge(string $area): string
    {
        $json = json_encode(['lang' => self::current(), 'strings' => self::jsStrings($area)],
            JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE);
        return '<script type="application/json" id="i18n-data">' . $json . '</script>';
    }

    /** @return array{strings:int,total:int,percent:int,missing:int} */
    public static function coverage(string $code): array
    {
        $ref  = self::load(self::REFERENCE);
        $mine = self::load($code);
        $have = 0;
        foreach ($ref as $k => $_) {
            if (isset($mine[$k]) && trim($mine[$k]) !== '') {
                $have++;
            }
        }
        $total = count($ref);
        return ['strings' => count($mine), 'total' => $total,
                'percent' => $total > 0 ? (int) floor($have / $total * 100) : 0, 'missing' => max(0, $total - $have)];
    }

    /* ------------------------------------------------------------------ teksty z zewnątrz */

    /**
     * Wartość z pakietu / kopii, jaka może trafić do pliku języka, albo null. Lista dozwolonych tagów, a nie czarna lista:
     * szablony drukują część tekstów jako HTML, więc <img onerror>, <script>, javascript: w odnośniku, znaczniki PHP
     * i znaki sterujące są odrzucane, a klucz trafia do odpowiedzi jako odrzucony.
     */
    public static function sanitizeValue(string $v): ?string
    {
        if (strlen($v) > 10000 || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $v) || !mb_check_encoding($v, 'UTF-8')) {
            return null;
        }
        if (preg_match('/<\?|\?>|<%|<(?:script|iframe|object|embed|svg|math|style|link|meta|base|form|img|video|audio|source)\b|(?<![a-z0-9])(?:javascript|vbscript):/i', $v)) {
            return null;
        }
        if (!str_contains($v, '<')) {
            return $v;
        }
        $bad  = false;
        $rest = preg_replace_callback('~<(/?)([a-zA-Z][a-zA-Z0-9]*)([^<>]*)>~', static function (array $m) use (&$bad): string {
            $tag   = strtolower($m[2]);
            $attrs = trim($m[3]);
            if (!in_array($tag, self::SAFE_TAGS, true) || preg_match('/on\w+\s*=/i', $attrs)) {
                $bad = true;
                return '';
            }
            if ($m[1] === '/') {
                if ($attrs !== '') {
                    $bad = true;
                }
                return '';
            }
            $attrs = rtrim((string) preg_replace('~/\z~', '', $attrs));
            if ($tag !== 'a') {
                if ($attrs !== '') {
                    $bad = true;
                }
                return '';
            }
            if ($attrs === '') {
                return '';
            }
            if (!preg_match('~\Ahref\s*=\s*(?:"([^"]*)"|\'([^\']*)\')\z~i', $attrs, $h)) {
                $bad = true;
                return '';
            }
            $href = $h[1] !== '' ? $h[1] : ($h[2] ?? '');
            $href = (string) preg_replace('/&#(x[0-9a-f]++|[0-9]++)(?!;)/i', '$0;', $href);
            $url  = html_entity_decode($href, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            if (preg_match('/[\x00-\x20\x7F]/', $url)
                || (preg_match('~\A[a-z][a-z0-9+.\-]*:~i', $url) && !preg_match('~\Ahttps?://~i', $url))
                || preg_match('~\A(?:/[/\\\\]|\\\\)~', $url)) {
                $bad = true;
            }
            return '';
        }, $v);
        if ($bad || $rest === null || preg_match('~<[a-zA-Z/!?]~', $rest)) {
            return null;
        }
        return $v;
    }

    /**
     * Zapis języka dodanego (JSON, atomowo: plik tymczasowy + rename, 0640). Tylko dane już sprawdzone.
     *
     * @param array<string,string> $strings
     */
    public static function writeCustom(string $code, array $strings): bool
    {
        if (!preg_match(self::CODE_RE, $code) || in_array($code, self::BUILT_IN, true)) {
            return false;
        }
        $dir = self::dataDir();
        if (!is_dir($dir) || !is_writable($dir)) {
            return false;
        }
        $body = json_encode($strings, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($body)) {
            return false;
        }
        $target = $dir . '/' . $code . '.json';
        $tmp    = $target . '.tmp.' . bin2hex(random_bytes(6));
        if (@file_put_contents($tmp, $body, LOCK_EX) !== strlen($body)) {
            @unlink($tmp);
            return false;
        }
        @chmod($tmp, 0640);
        if (!@rename($tmp, $target)) {
            @unlink($tmp);
            return false;
        }
        self::invalidate();
        return true;
    }

    public static function deleteCustom(string $code): bool
    {
        if (!preg_match(self::CODE_RE, $code) || in_array($code, self::BUILT_IN, true)) {
            return false;
        }
        $f = self::dataDir() . '/' . $code . '.json';
        $ok = is_file($f) && @unlink($f);
        self::invalidate();
        return $ok;
    }
}
