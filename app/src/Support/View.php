<?php
declare(strict_types=1);

namespace App\Support;

/** Renderuje szablon PHP do stringa (bufor wyjścia). */
final class View
{
    public static function render(string $file, array $vars = []): string
    {
        extract($vars, EXTR_SKIP);
        ob_start();
        include $file;
        return (string) ob_get_clean();
    }

    /**
     * Znacznik pliku do adresu assetu (?v=…): 8 znaków skrótu z czasu modyfikacji i rozmiaru. Zmienia się, gdy zmienia
     * się plik (przeglądarka pobiera nowy), a nie zdradza ani wersji aplikacji, ani daty wdrożenia.
     */
    public static function assetTag(string $path): string
    {
        $m = @filemtime($path);
        $s = @filesize($path);
        return $m === false ? '1' : substr(hash('sha256', $m . ':' . (int) $s), 0, 8);
    }
}
