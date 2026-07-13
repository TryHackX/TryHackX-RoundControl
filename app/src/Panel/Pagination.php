<?php
declare(strict_types=1);

namespace App\Panel;

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
    public static function render(int $page, int $per, int $total, string $nav, string $label = 'Wyświetlanie'): string
    {
        $totalPages = self::totalPages($total, $per);
        $page       = min($page, $totalPages);
        $off        = ($page - 1) * $per;
        $from       = $total > 0 ? $off + 1 : 0;
        $to         = min($off + $per, $total);

        $btn = static fn(int $p, string $text, bool $active = false): string =>
            '<button class="pagination-btn' . ($active ? ' active' : '') . '" data-nav="' . $nav . '" data-page="' . $p . '">' . $text . '</button>';

        $html  = '<div class="pagination-info">' . $label . ' ' . $from . ' - ' . $to . ' z ' . $total . '</div>';
        $html .= '<div class="pagination-controls">';

        if ($page > 1) {
            $html .= $btn(1, '« Pierwsza');
            $html .= $btn($page - 1, '‹ Poprzednia');
        }
        for ($i = max(1, $page - 2); $i <= min($totalPages, $page + 2); $i++) {
            $html .= $btn($i, (string) $i, $i === $page);
        }
        if ($page < $totalPages) {
            $html .= $btn($page + 1, 'Następna ›');
            $html .= $btn($totalPages, 'Ostatnia »');
        }

        return $html . '</div>';
    }
}
