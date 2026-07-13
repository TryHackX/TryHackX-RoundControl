<?php
declare(strict_types=1);

namespace App\Support;

final class Html
{
    /** Ucieczka do bezpiecznego osadzenia w HTML (odpowiednik dawnej funkcji h()). */
    public static function e(?string $value): string
    {
        return htmlspecialchars($value ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
