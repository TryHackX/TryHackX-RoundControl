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
    /** Górna granica quoty w MiB (1 PiB): mieści się w kolumnie INT i w liczbach doveadm. */
    public const MAX_MB = 1073741824;

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
        // Obliczenia na float i przycięcie PRZED rzutem na int: ogromna wartość przepełniała int (na PHP 8.5 do
        // wartości ujemnej/0), a quota 0 oznacza "bez limitu". NaN / INF / wartość ujemna daje 0 tylko wtedy,
        // gdy pole było puste — nigdy przez przepełnienie.
        $mb = match (self::normalizeUnit($unit)) {
            'B'   => $value / 1048576,
            'KiB' => $value / 1024,
            'GiB' => $value * 1024,
            default => $value,
        };
        if (is_nan($mb) || $mb < 0) {
            return 0;
        }
        return (int) min(round($mb), (float) self::MAX_MB);
    }

    /**
     * 1.4.0: a quota typed in a form, in MiB. '' = null (nothing typed); a negative or non-numeric value = false
     * (refused); 0 = no limit (Dovecot's meaning, typed on purpose); any other value at least 1 MiB — 500 KiB used to
     * round to 0 and -5 to 0, i.e. both silently became "no limit".
     */
    public static function parse(string $raw, string $unit): int|false|null
    {
        $raw = trim(str_replace(',', '.', $raw));
        if ($raw === '') {
            return null;
        }
        if (!is_numeric($raw) || (float) $raw < 0) {
            return false;
        }
        return (float) $raw === 0.0 ? 0 : max(1, self::toMb((float) $raw, $unit));
    }

    /** MiB -> [wartość, jednostka] do formularza (preferuje GiB, gdy podzielne). */
    public static function toBest(int $mb): array
    {
        if ($mb >= 1024 && $mb % 1024 === 0) {
            return [(int) ($mb / 1024), 'GiB'];
        }
        return [$mb, 'MiB'];
    }

    /** "1.5 GiB", "50 MiB"; 1.4.0: 0 = no limit is shown as "∞" (it read "0 MiB"). */
    public static function display(int $mb): string
    {
        if ($mb <= 0) {
            return '∞';
        }
        return $mb >= 1024 ? round($mb / 1024, 2) . ' GiB' : $mb . ' MiB';
    }
}
