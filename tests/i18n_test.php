<?php
declare(strict_types=1);

/**
 * Testy języków (1.3.0) bez bazy danych:  php tests/i18n_test.php
 *
 *  - pl (wzorzec) i en mają IDENTYCZNE zestawy kluczy (plik po pliku), te same parametry :nazwa, bez duplikatów między
 *    plikami, klucze w formacie [a-z0-9_.], wartości przechodzą ten sam filtr co wgrywane pakiety,
 *  - każdy klucz użyty w kodzie (t/h/html/Lang::…('klucz') w PHP, t('klucz') w portal.js / panel.js) istnieje,
 *  - nigdzie nie został polski tekst wpisany na sztywno: szablony (poza kodem PHP), literały w JS (poza komentarzami),
 *    literały w PHP aplikacji (poza error_log / komentarzami),
 *  - wybór języka: ?lang= → ciasteczko → Accept-Language → domyślny; filtr wartości odrzuca złośliwe teksty.
 */

$tmp = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'mp_i18n_' . bin2hex(random_bytes(4));
mkdir($tmp . '/lang', 0777, true);
register_shutdown_function(static function () use ($tmp): void {
    foreach (glob($tmp . '/lang/*') ?: [] as $f) { @unlink($f); }
    @rmdir($tmp . '/lang');
    foreach (glob($tmp . '/*') ?: [] as $f) { @unlink($f); }
    @rmdir($tmp);
});
// baza celowo nieosiągalna (port 1): ustawienia -> wartości domyślne, test nie dotyka żadnej bazy
file_put_contents($tmp . '/test.env', "APP_ENV=dev\nDB_HOST=127.0.0.1\nDB_PORT=1\nDB_NAME=none\nDB_USER=none\nDB_PASS=\nLANG_DATA_DIR=" . $tmp . "/lang\n");
putenv('MAILPANEL_ENV_FILE=' . $tmp . '/test.env');

require __DIR__ . '/../app/bootstrap.php';

use App\I18n\Lang;

$T = ['pass' => 0, 'fail' => 0, 'fails' => []];
function ok(bool $cond, string $label): void
{
    global $T;
    if ($cond) { $T['pass']++; } else { $T['fail']++; $T['fails'][] = $label; }
}
function eq(mixed $a, mixed $b, string $label): void
{
    ok($a === $b, $label . ($a === $b ? '' : ' (got=' . var_export($a, true) . ' exp=' . var_export($b, true) . ')'));
}
function group(string $name): void { echo "\n# $name\n"; }

$root = dirname(__DIR__);
const PL_CHARS = '/[ąćęłńóśźżĄĆĘŁŃÓŚŹŻ]/u';

/** @return array<string,array<string,string>> plik => tablica */
function langFiles(string $code): array
{
    $out = [];
    foreach (Lang::builtInFiles($code) as $f) {
        $out[basename($f)] = require $f;
    }
    return $out;
}

function params(string $s): array
{
    preg_match_all('/:([a-z][a-z0-9_]*)/', $s, $m);
    $p = array_unique($m[1]);
    sort($p);
    return $p;
}

group('Pliki języków: pl i en');
$pl = langFiles('pl');
$en = langFiles('en');
ok($pl !== [], 'są pliki app/lang/pl/*.php');
eq(array_keys($en), array_keys($pl), 'en ma te same pliki co pl');
$seen = [];
foreach ($pl as $file => $arr) {
    ok(is_array($arr), "pl/$file zwraca tablicę");
    $enArr = $en[$file] ?? [];
    $missing = array_diff(array_keys($arr), array_keys($enArr));
    $extra   = array_diff(array_keys($enArr), array_keys($arr));
    ok($missing === [], "en/$file: brak kluczy: " . implode(', ', array_slice($missing, 0, 10)));
    ok($extra === [], "en/$file: nadmiarowe klucze: " . implode(', ', array_slice($extra, 0, 10)));
    foreach ($arr as $k => $v) {
        ok(is_string($k) && preg_match('/^[a-z0-9_.]+$/', $k) === 1, "klucz w formacie [a-z0-9_.]: $k");
        ok(!isset($seen[$k]), "klucz $k tylko w jednym pliku (" . ($seen[$k] ?? '') . " / $file)");
        $seen[$k] = $file;
        ok(is_string($v) && $v !== '', "pl $k: niepusty tekst");
        ok(Lang::sanitizeValue((string) $v) !== null, "pl $k: przechodzi filtr wartości");
        if (isset($enArr[$k])) {
            ok(Lang::sanitizeValue((string) $enArr[$k]) !== null, "en $k: przechodzi filtr wartości");
            eq(params((string) $enArr[$k]), params((string) $v), "en $k: te same parametry");
            ok(!preg_match(PL_CHARS, (string) $enArr[$k]), "en $k: bez polskich znaków");
        }
    }
}
$plAll = Lang::load('pl');
$enAll = Lang::load('en');
eq(count($enAll), count($plAll), 'pl i en: tyle samo tekstów');
eq(Lang::coverage('en')['percent'], 100, 'en: pokrycie 100%');

group('Klucze użyte w kodzie istnieją');
$phpFiles = array_merge(
    glob($root . '/app/src/*/*.php') ?: [],
    glob($root . '/app/templates/*/*.php') ?: [],
    glob($root . '/app/templates/*/*/*.php') ?: [],
    [$root . '/portal.php'],
    glob($root . '/panel/*.php') ?: []
);
$used = 0;
foreach ($phpFiles as $f) {
    $src = '';
    foreach (token_get_all((string) file_get_contents($f)) as $tok) {   // bez komentarzy (przykłady w opisach)
        $src .= is_array($tok) ? (in_array($tok[0], [T_COMMENT, T_DOC_COMMENT], true) ? ' ' : $tok[1]) : $tok;
    }
    preg_match_all('/(?<![A-Za-z0-9_>$])(?:t|h|html|Lang::t|Lang::h|Lang::html|Lang::site)\(\s*\'([a-z0-9_.]+)\'/', $src, $m);
    foreach ($m[1] as $k) {
        $used++;
        ok(isset($plAll[$k]), 'klucz ' . $k . ' (' . basename(dirname($f)) . '/' . basename($f) . ') istnieje w pl');
    }
}
ok($used > 100, "kod używa tłumaczeń ($used wywołań)");
foreach (\App\Security\Captcha::PROVIDERS as $pid => $key) {
    ok(isset($plAll[$key]), "etykieta dostawcy $pid ($key)");
}
foreach (['portal' => 'portal.js', 'panel' => 'panel.js'] as $area => $js) {
    $src = (string) file_get_contents($root . '/assets/' . $js);
    preg_match_all('/(?<![A-Za-z0-9_.$])t\(\s*\'([a-z0-9_.]+)\'/', $src, $m);
    ok(count($m[1]) > 5, "$js używa t()");
    foreach ($m[1] as $k) {
        ok(isset($plAll['js.' . $area . '.' . $k]), "$js: klucz js.$area.$k istnieje w pl");
    }
}

group('Bez polskich tekstów na sztywno');
/** Tekst szablonu poza blokami PHP i komentarzami HTML. */
function templateText(string $src): string
{
    $out = '';
    foreach (token_get_all($src) as $tok) {
        if (is_array($tok) && $tok[0] === T_INLINE_HTML) {
            $out .= $tok[1];
        }
    }
    return (string) preg_replace('/<!--.*?-->/s', '', $out);
}
/** Literały napisowe JS (komentarze pominięte). */
function jsLiterals(string $src): array
{
    $lits = [];
    preg_match_all('~(\'(?:[^\'\\\\\n]|\\\\.)*\'|"(?:[^"\\\\\n]|\\\\.)*"|`(?:[^`\\\\]|\\\\.)*`)|(//[^\n]*|/\*.*?\*/)~s', $src, $m, PREG_SET_ORDER);
    foreach ($m as $x) {
        if (($x[1] ?? '') !== '') {
            $lits[] = $x[1];
        }
    }
    return $lits;
}
foreach (array_merge(glob($root . '/app/templates/*/*.php') ?: [], glob($root . '/app/templates/*/*/*.php') ?: []) as $f) {
    $txt = templateText((string) file_get_contents($f));
    preg_match_all('/[^\s<>]*[ąćęłńóśźżĄĆĘŁŃÓŚŹŻ][^\s<>]*/u', $txt, $m);
    ok($m[0] === [], 'szablon ' . basename(dirname($f)) . '/' . basename($f) . ' bez polskiego tekstu: ' . implode(' ', array_slice($m[0], 0, 6)));
}
foreach (['portal.js', 'panel.js'] as $js) {
    $bad = array_values(array_filter(jsLiterals((string) file_get_contents($root . '/assets/' . $js)), static fn($l) => preg_match(PL_CHARS, $l)));
    ok($bad === [], "$js: literały bez polskich znaków: " . implode(' | ', array_slice($bad, 0, 5)));
}
/** Literały PHP z polskimi znakami poza error_log()/syslog() i komentarzami. */
function phpPolishLiterals(string $src): array
{
    $toks = token_get_all($src);
    $bad  = [];
    foreach ($toks as $i => $tok) {
        if (!is_array($tok) || !in_array($tok[0], [T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE], true)
            || !preg_match(PL_CHARS, $tok[1])) {
            continue;
        }
        // słowa kluczowe wyszukiwania w SQL (LogRepository: "błąd" i "blad" znajdują te same wpisy) — nie tekst interfejsu
        if (str_contains($tok[1], 'CONCAT_WS(')) {
            continue;
        }
        $logged = false;
        for ($j = $i - 1; $j >= 0; $j--) {
            $p = $toks[$j];
            if (!is_array($p) && in_array($p, [';', '{', '}'], true)) {
                break;
            }
            if (is_array($p) && $p[0] === T_STRING && in_array($p[1], ['error_log', 'syslog', 'trigger_error'], true)) {
                $logged = true;
                break;
            }
        }
        if (!$logged) {
            $bad[] = trim($tok[1]);
        }
    }
    return $bad;
}
foreach (array_merge(glob($root . '/app/src/*/*.php') ?: [], [$root . '/portal.php'], glob($root . '/panel/*.php') ?: []) as $f) {
    $bad = phpPolishLiterals((string) file_get_contents($f));
    ok($bad === [], basename(dirname($f)) . '/' . basename($f) . ': polskie literały poza dziennikiem: ' . implode(' | ', array_slice($bad, 0, 4)));
}

group('Wybór języka');
$_GET = []; $_COOKIE = []; $_SERVER['HTTP_ACCEPT_LANGUAGE'] = '';
eq(Lang::fromAcceptLanguage(['pl', 'en']), null, 'pusty Accept-Language -> brak');
$_SERVER['HTTP_ACCEPT_LANGUAGE'] = 'de-DE,de;q=0.9,en-US;q=0.8,en;q=0.7';
eq(Lang::fromAcceptLanguage(['pl', 'en']), 'en', 'Accept-Language: pierwszy WŁĄCZONY (de pominięty)');
$_SERVER['HTTP_ACCEPT_LANGUAGE'] = 'en;q=0.3, pl;q=0.9';
eq(Lang::fromAcceptLanguage(['pl', 'en']), 'pl', 'Accept-Language: wyższa waga wygrywa');
$_SERVER['HTTP_ACCEPT_LANGUAGE'] = 'fr';
eq(Lang::fromAcceptLanguage(['pl', 'en']), null, 'Accept-Language bez włączonego języka -> brak');

$resolve = static function (array $get, array $cookie, string $al): string {
    $_GET = $get; $_COOKIE = $cookie; $_SERVER['HTTP_ACCEPT_LANGUAGE'] = $al;
    Lang::force(null);
    Lang::init();
    return Lang::current();
};
eq($resolve([], [], ''), 'pl', 'nic -> domyślny (pl)');
eq($resolve([], [], 'en-GB,en;q=0.9'), 'en', 'Accept-Language en -> en');
eq($resolve([], ['lang' => 'pl'], 'en'), 'pl', 'ciasteczko ma pierwszeństwo przed Accept-Language');
eq($resolve(['lang' => 'en'], ['lang' => 'pl'], 'pl'), 'en', '?lang= ma pierwszeństwo przed ciasteczkiem');
eq($resolve(['lang' => 'xx'], ['lang' => 'en'], ''), 'en', 'nieznany ?lang= pominięty');
eq($resolve([], ['lang' => '../../etc'], ''), 'pl', 'śmieci w ciasteczku pominięte');
eq($resolve(['lang' => ['en']], [], ''), 'pl', '?lang[]= (tablica) pominięte');
Lang::force('en');
eq(Lang::t('common.footer.author'), $enAll['common.footer.author'], 't() w bieżącym języku');
eq(Lang::t('no.such.key'), 'no.such.key', 'brak klucza -> sam klucz');
Lang::force('pl');

group('Filtr wartości (wgrywane pakiety)');
foreach ([
    'Zwykły tekst', 'Tekst z <b>pogrubieniem</b> i <code>kodem</code>', 'Odnośnik <a href="https://example.org/x">tu</a>',
    'Względny <a href="/panel/">panel</a>', 'Linia<br>druga', '<span>x</span> <small>y</small>', '50% & więcej',
] as $good) {
    ok(Lang::sanitizeValue($good) !== null, 'przepuszcza: ' . $good);
}
foreach ([
    '<script>alert(1)</script>', '<img src=x onerror=alert(1)>', '<b onclick="x()">b</b>', '<a href="javascript:alert(1)">x</a>',
    '<a href="JaVaScRiPt:alert(1)">x</a>', '<a href="java&#115;cript:alert(1)">x</a>', '<a href="data:text/html,x">x</a>',
    '<a href="//evil.example/">x</a>', '<a href="https://ok" onmouseover="x">x</a>', '<?php system("id"); ?>', '<?= 1 ?>',
    '<iframe src="https://x"></iframe>', '<svg onload=alert(1)>', '<style>*{}</style>', '<div>x</div>', "a\x00b", "a\x1bb",
    str_repeat('a', 10001), "\xff\xfe", '<b style="x">y</b>', '<a title="x">y</a>', 'vbscript:msgbox(1)',
] as $bad) {
    ok(Lang::sanitizeValue($bad) === null, 'odrzuca: ' . substr(addcslashes($bad, "\0..\37"), 0, 60));
}

echo "\n==== i18n: {$T['pass']} OK, {$T['fail']} FAIL ====\n";
foreach ($T['fails'] as $f) {
    echo "  FAIL: $f\n";
}
exit($T['fail'] === 0 ? 0 : 1);
