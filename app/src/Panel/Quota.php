<?php
declare(strict_types=1);

namespace App\Panel;

/**
 * Konwersje limitu skrzynki (quota) między MiB a jednostkami formularza.
 *
 * `virtual_users.quota_mb` to MEBIBAJTY (dwójkowe): Dovecot liczy quotę
 * w KB=1024 B (2048 => Limit=2097152 KB), stąd w całym panelu prezentujemy
 * jednostki dwójkowe (KiB/MiB/GiB). Stare tokeny KB/MB/GB są akceptowane
 * jako aliasy (kompatybilność ze starymi formularzami).
 */
final class Quota
{
    /** Aliasy jednostek formularza -> jednostka kanoniczna (dwójkowa). */
    private const ALIASES = [
        'B'   => 'B',
        'KB'  => 'KiB', 'KiB' => 'KiB',
        'MB'  => 'MiB', 'MiB' => 'MiB',
        'GB'  => 'GiB', 'GiB' => 'GiB',
    ];

    public static function normalizeUnit(?string $unit): string
    {
        return self::ALIASES[$unit] ?? 'MiB';
    }

    public static function toMb(float $value, string $unit): int
    {
        $mb = match (self::normalizeUnit($unit)) {
            'B'   => (int) round($value / 1048576),
            'KiB' => (int) round($value / 1024),
            'GiB' => (int) ($value * 1024),
            default => (int) $value,
        };
        return max(0, $mb);
    }

    /** MiB -> [wartość, jednostka] do formularza (preferuje GiB, gdy podzielne). */
    public static function toBest(int $mb): array
    {
        if ($mb >= 1024 && $mb % 1024 === 0) {
            return [(int) ($mb / 1024), 'GiB'];
        }
        return [$mb, 'MiB'];
    }

    public static function display(int $mb): string
    {
        return $mb >= 1024 ? round($mb / 1024, 2) . ' GiB' : $mb . ' MiB';
    }
}
