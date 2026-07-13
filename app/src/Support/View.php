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
}
