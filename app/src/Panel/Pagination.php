<?php
declare(strict_types=1);

namespace App\Panel;

use function App\I18n\h;
use function App\I18n\t;

/**
 * Jeden generator paginacji dla wszystkich list panelu (zastępuje 4x
 * powielony kod). Przyciski używają data-atrybutów (obsługa w panel.js),
 * a nie inline-onclick — spójnie z CSP bez 'unsafe-inline' dla skryptów.
 */
final class Pagination
{
    public static function perOrDefault(mixed $per, int $default = 20): int
    {
        $per = (int) $per;
        return in_array($per, [10, 20, 50, 100], true) ? $per : $default;
    }

    public static function totalPages(int $total, int $per): int
    {
        return $per > 0 ? max(1, (int) ceil($total / $per)) : 1;
    }

    public static function clamp(int $page, int $total, int $per): int
    {
        return min(max(1, $page), self::totalPages($total, $per));
    }

    /**
     * @param string $nav klucz nawigacji odczytywany przez panel.js (np. "users")
     */
    public static function render(int $page, int $per, int $total, string $nav, ?string $label = null): string
    {
        $label ??= t('pager.showing');
        $totalPages = self::totalPages($total, $per);
        $page       = min($page, $totalPages);
        $off        = ($page - 1) * $per;
        $from       = $total > 0 ? $off + 1 : 0;
        $to         = min($off + $per, $total);

        // 1.2.0: przyciski "Pierwsza / Poprzednia / Następna / Ostatnia" są ZAWSZE (nieaktywne, gdy nie ma dokąd iść),
        // a okno numerów ma stałą liczbę pozycji — klikany przycisk nie ucieka spod kursora po zmianie strony.
        $btn = static fn(int $p, string $text, bool $active = false, bool $off = false, string $aria = ''): string =>
            '<button type="button" class="pagination-btn' . ($active ? ' active' : '') . '" data-nav="' . $nav . '" data-page="' . $p . '"'
            . ($active ? ' aria-current="page"' : '') . ($off ? ' disabled' : '') . ($aria !== '' ? ' aria-label="' . $aria . '"' : '') . '>' . $text . '</button>';

        // Teksty z plików językowych idą do HTML-a przez h() (całość escapowana — także z pakietów językowych z zewnątrz).
        // 1.4.0: no aria-live here — panel.js replaces this element on every load; #list-status announces the list
        // only after the admin changed it (search, page, sort, Refresh)
        $html  = '<div class="pagination-info">'
            . h('pager.range', ['label' => $label, 'from' => $from, 'to' => $to, 'total' => $total]) . '</div>';
        $html .= '<nav class="pagination-controls" aria-label="' . h('pager.nav_label') . '">';

        $html .= $btn(1, h('pager.first'), false, $page <= 1, h('pager.first_page'));
        $html .= $btn(max(1, $page - 1), h('pager.prev'), false, $page <= 1, h('pager.prev_page'));
        $width = min(5, $totalPages);
        $start = max(1, min($page - 2, $totalPages - $width + 1));
        for ($i = $start; $i < $start + $width; $i++) {
            $html .= $btn($i, (string) $i, $i === $page, false, h('pager.page', ['n' => $i]));
        }
        $html .= $btn(min($totalPages, $page + 1), h('pager.next'), false, $page >= $totalPages, h('pager.next_page'));
        $html .= $btn($totalPages, h('pager.last'), false, $page >= $totalPages, h('pager.last_page', ['n' => $totalPages]));

        return $html . '</nav>';
    }
}
