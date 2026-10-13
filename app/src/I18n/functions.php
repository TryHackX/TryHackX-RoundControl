<?php
declare(strict_types=1);

namespace App\I18n;

/*
 * Krótkie funkcje do szablonów (1.3.0): `use function App\I18n\h, App\I18n\html, App\I18n\t;`
 * W przestrzeni nazw App\I18n — żadnej globalnej funkcji __(), która mogłaby się zderzyć z Roundcube (portal ładuje jego
 * kod w tym samym procesie przy przekazaniu logowania do webmaila).
 */

/** Tekst surowy (komunikaty JSON, textContent). */
function t(string $key, array $params = []): string
{
    return Lang::t($key, $params);
}

/** Tekst do HTML, całość escapowana. */
function h(string $key, array $params = []): string
{
    return Lang::h($key, $params);
}

/** Tekst do HTML z dozwolonym HTML z pliku języka (parametry escapowane). */
function html(string $key, array $params = []): string
{
    return Lang::html($key, $params);
}
