<?php
declare(strict_types=1);

namespace App\Panel;

use App\Admin\AuditLog;
use App\I18n\Lang;
use App\Settings\Settings;

use function App\I18n\t;

/**
 * Zarządzanie językami interfejsu w panelu (1.3.0) — wzorem panelu trackera (api/admin/languages.php).
 *
 * Operacje (POST lang_op, tylko administrator, token CSRF sprawdza PanelController):
 *   toggle    włącz / wyłącz język (domyślnego nie da się wyłączyć),
 *   default   ustaw język domyślny serwisu (zostaje też włączony),
 *   duplicate skopiuj język jako punkt wyjścia dla nowego kodu (kopia startuje WYŁĄCZONA),
 *   upload    wgraj pakiet JSON (nowy język albo nadpisanie dodanego; wbudowanych nie),
 *   delete    usuń język dodany (nie wbudowany, nie domyślny).
 * Pobranie pakietu: GET ?lang_export=<kod> (JSON, załącznik).
 *
 * Pakiet: płaski obiekt JSON "klucz": "tekst", najwyżej UPLOAD_MAX bajtów i 10 000 kluczy. Przyjmowane są wyłącznie
 * klucze, które zna język wzorcowy (pl), z wartościami, które przejdą Lang::sanitizeValue() (bez <script>, atrybutów
 * on…=, odnośników javascript:, znaczników PHP, znaków sterujących). Odrzucone klucze wracają w odpowiedzi z nazwami.
 * Zapis: JSON w katalogu danych (Lang::dataDir(), właściciel mailweb) — kod aplikacji (root:root) zostaje nietknięty,
 * a pliki z tego katalogu nigdy nie są wykonywane (tylko json_decode).
 */
final class LanguageAdmin
{
    public const MAX_KEYS = 10000;

    public function __construct(private AuditLog $audit)
    {
    }

    /**
     * Lista dla tabeli w Ustawieniach.
     *
     * @return array{rows:list<array<string,mixed>>,dir:string,dirOk:bool,default:string}
     */
    public static function listing(): array
    {
        $enabled = Lang::enabled();
        $default = Lang::defaultLanguage();
        $rows = [];
        foreach (Lang::available() as $code => $info) {
            $cov = Lang::coverage($code);
            $rows[] = [
                'code'    => $code,
                'name'    => $info['name'],
                'builtIn' => $info['builtIn'],
                'enabled' => in_array($code, $enabled, true),
                'default' => $code === $default,
                'percent' => $cov['percent'],
                'missing' => $cov['missing'],
            ];
        }
        $dir = Lang::dataDir();
        return ['rows' => $rows, 'dir' => $dir, 'dirOk' => is_dir($dir) && is_writable($dir), 'default' => $default];
    }

    /**
     * @param array<string,mixed> $post
     * @param array<string,mixed> $files
     * @return array{ok:bool,msg:string,dropped?:array<string,string>}
     */
    public function handle(array $post, array $files): array
    {
        $op   = (string) ($post['lang_op'] ?? '');
        $code = strtolower(trim((string) ($post['code'] ?? '')));
        Lang::invalidate();

        if (!in_array($op, ['toggle', 'default', 'duplicate', 'upload', 'delete'], true)) {
            return self::fail(t('msg.lang.unknown_op'));
        }
        if (!preg_match(Lang::CODE_RE, $code)) {
            return self::fail(t('msg.lang.bad_code'));
        }

        return match ($op) {
            'toggle'    => $this->toggle($code),
            'default'   => $this->setDefault($code),
            'duplicate' => $this->duplicate(strtolower(trim((string) ($post['from'] ?? ''))), $code),
            'upload'    => $this->upload($code, $files['pack'] ?? null),
            'delete'    => $this->delete($code),
        };
    }

    /** Pobranie pakietu (JSON) — własne teksty języka w kolejności wzorca. */
    public static function export(string $code): never
    {
        $code = strtolower(trim($code));
        if (!preg_match(Lang::CODE_RE, $code) || !Lang::installed($code)) {
            http_response_code(404);
            \App\Support\Http::json(['ok' => false, 'error' => t('msg.lang.not_installed')]);
        }
        $own = Lang::load($code);
        $out = [];
        foreach (Lang::load(Lang::REFERENCE) as $k => $_) {
            if (isset($own[$k])) {
                $out[$k] = $own[$k];
            }
        }
        header('Content-Type: application/json; charset=utf-8');
        header('Content-Disposition: attachment; filename="roundcontrol-lang-' . $code . '.json"');
        header('Cache-Control: no-store');
        echo json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    /* ------------------------------------------------------------------ operacje */

    private function toggle(string $code): array
    {
        if (!Lang::installed($code)) {
            return self::fail(t('msg.lang.not_installed'));
        }
        $on = Lang::enabled();
        if (in_array($code, $on, true)) {
            if ($code === Lang::defaultLanguage()) {
                return self::fail(t('msg.lang.default_stays_on'));
            }
            $on = array_values(array_diff($on, [$code]));
            $enable = false;
        } else {
            $on[] = $code;
            $enable = true;
        }
        self::saveEnabled($on);
        $this->audit->log('LANGUAGE', Lang::site($enable ? 'audit.lang_enabled' : 'audit.lang_disabled', ['code' => $code]));
        return ['ok' => true, 'msg' => t($enable ? 'msg.lang.enabled' : 'msg.lang.disabled', ['name' => Lang::name($code)])];
    }

    private function setDefault(string $code): array
    {
        if (!Lang::installed($code)) {
            return self::fail(t('msg.lang.not_installed'));
        }
        $on = Lang::enabled();
        if (!in_array($code, $on, true)) {
            $on[] = $code;
        }
        Settings::set('default_language', $code);
        self::saveEnabled($on);
        $this->audit->log('LANGUAGE', Lang::site('audit.lang_default', ['code' => $code]));
        return ['ok' => true, 'msg' => t('msg.lang.default_set', ['name' => Lang::name($code)])];
    }

    private function duplicate(string $from, string $code): array
    {
        if (!preg_match(Lang::CODE_RE, $from) || !Lang::installed($from)) {
            return self::fail(t('msg.lang.not_installed'));
        }
        if (Lang::installed($code)) {
            return self::fail(t('msg.lang.exists', ['code' => $code]));
        }
        if (($dirErr = self::dirError()) !== null) {
            return self::fail($dirErr);
        }
        $on = Lang::enabled();   // zamrożenie listy: nowa kopia nie włącza się sama
        $strings = [];
        foreach (Lang::load(Lang::REFERENCE) as $k => $_) {
            $strings[$k] = Lang::t($k, [], $from);
        }
        if (!Lang::writeCustom($code, $strings)) {
            return self::fail(t('msg.lang.write_failed', ['dir' => Lang::dataDir()]));
        }
        self::saveEnabled($on);
        $this->audit->log('LANGUAGE', Lang::site('audit.lang_duplicated', ['from' => $from, 'code' => $code]));
        return ['ok' => true, 'msg' => t('msg.lang.duplicated', ['from' => Lang::name($from), 'code' => $code])];
    }

    /** @param mixed $file wpis z $_FILES */
    private function upload(string $code, mixed $file): array
    {
        if (in_array($code, Lang::BUILT_IN, true)) {
            return self::fail(t('msg.lang.builtin_readonly'));
        }
        if (!is_array($file) || !isset($file['error'], $file['tmp_name'], $file['size']) || is_array($file['error'])) {
            return self::fail(t('msg.lang.no_file'));
        }
        if ((int) $file['error'] === UPLOAD_ERR_INI_SIZE || (int) $file['error'] === UPLOAD_ERR_FORM_SIZE
            || (int) $file['size'] > Lang::UPLOAD_MAX) {
            return self::fail(t('msg.lang.too_big', ['kb' => (int) (Lang::UPLOAD_MAX / 1024)]));
        }
        if ((int) $file['error'] !== UPLOAD_ERR_OK || !is_uploaded_file((string) $file['tmp_name'])) {
            return self::fail(t('msg.lang.no_file'));
        }
        $raw = (string) @file_get_contents((string) $file['tmp_name'], false, null, 0, Lang::UPLOAD_MAX + 1);
        if (strlen($raw) > Lang::UPLOAD_MAX) {
            return self::fail(t('msg.lang.too_big', ['kb' => (int) (Lang::UPLOAD_MAX / 1024)]));
        }
        if (($dirErr = self::dirError()) !== null) {
            return self::fail($dirErr);
        }

        $parsed = self::parsePack($raw);
        if (isset($parsed['error'])) {
            return self::fail($parsed['error']);
        }
        $isNew = !Lang::installed($code);
        $on    = Lang::enabled();
        if (!Lang::writeCustom($code, $parsed['strings'])) {
            return self::fail(t('msg.lang.write_failed', ['dir' => Lang::dataDir()]));
        }
        if ($isNew) {
            self::saveEnabled($on);   // nowy język startuje wyłączony
        }
        $this->audit->log('LANGUAGE', Lang::site('audit.lang_uploaded', [
            'code' => $code, 'n' => count($parsed['strings']), 'dropped' => count($parsed['dropped']),
        ]));
        $msg = t('msg.lang.uploaded', ['code' => $code, 'n' => count($parsed['strings'])]);
        if ($parsed['dropped'] !== []) {
            $msg .= ' ' . t('msg.lang.dropped', [
                'n'    => count($parsed['dropped']),
                'keys' => implode(', ', array_slice(array_keys($parsed['dropped']), 0, 15)) . (count($parsed['dropped']) > 15 ? ', …' : ''),
            ]);
        }
        $r = ['ok' => true, 'msg' => $msg];
        if ($parsed['dropped'] !== []) {
            $r['dropped'] = array_slice($parsed['dropped'], 0, 200, true);
        }
        return $r;
    }

    private function delete(string $code): array
    {
        if (in_array($code, Lang::BUILT_IN, true)) {
            return self::fail(t('msg.lang.builtin_readonly'));
        }
        if (!Lang::installed($code)) {
            return self::fail(t('msg.lang.not_installed'));
        }
        if ($code === Lang::defaultLanguage()) {
            return self::fail(t('msg.lang.default_no_delete'));
        }
        $on = array_values(array_diff(Lang::enabled(), [$code]));
        if (!Lang::deleteCustom($code)) {
            return self::fail(t('msg.lang.write_failed', ['dir' => Lang::dataDir()]));
        }
        self::saveEnabled($on);
        $this->audit->log('LANGUAGE', Lang::site('audit.lang_deleted', ['code' => $code]));
        return ['ok' => true, 'msg' => t('msg.lang.deleted', ['code' => $code])];
    }

    /* ------------------------------------------------------------------ pomocnicze (publiczne: testy) */

    /**
     * Ścisła kontrola pakietu: płaski obiekt string => string, znane klucze, bezpieczne wartości.
     *
     * @return array{strings:array<string,string>,dropped:array<string,string>}|array{error:string}
     */
    public static function parsePack(string $raw): array
    {
        if (str_starts_with($raw, "\xEF\xBB\xBF")) {
            $raw = substr($raw, 3);
        }
        try {
            $data = json_decode($raw, true, 2, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return ['error' => t('msg.lang.bad_json')];
        }
        if (!is_array($data) || $data === [] || array_is_list($data)) {
            return ['error' => t('msg.lang.bad_shape')];
        }
        if (count($data) > self::MAX_KEYS) {
            return ['error' => t('msg.lang.too_many', ['max' => self::MAX_KEYS])];
        }
        $ref     = Lang::load(Lang::REFERENCE);
        $strings = [];
        $dropped = [];
        foreach ($data as $k => $v) {
            $key = (string) $k;
            if (!preg_match('/^[a-z0-9_.]{1,120}$/', $key) || !isset($ref[$key])) {
                $dropped[substr($key, 0, 120)] = 'unknown_key';
                continue;
            }
            if (!is_string($v)) {
                $dropped[$key] = 'not_string';
                continue;
            }
            if (Lang::sanitizeValue($v) === null) {
                $dropped[$key] = 'unsafe_value';
                continue;
            }
            $strings[$key] = $v;
        }
        if ($strings === []) {
            return ['error' => t('msg.lang.nothing_valid', ['n' => count($dropped)])];
        }
        return ['strings' => $strings, 'dropped' => $dropped];
    }

    /** @param string[] $codes */
    private static function saveEnabled(array $codes): void
    {
        $codes = array_values(array_unique(array_filter($codes, static fn($c) => Lang::installed((string) $c))));
        Settings::set('enabled_languages', implode(',', $codes));
        Lang::invalidate();
    }

    private static function dirError(): ?string
    {
        $dir = Lang::dataDir();
        return is_dir($dir) && is_writable($dir) ? null : t('msg.lang.dir_unwritable', ['dir' => $dir]);
    }

    /** @return array{ok:false,msg:string} */
    private static function fail(string $msg): array
    {
        return ['ok' => false, 'msg' => $msg];
    }
}
