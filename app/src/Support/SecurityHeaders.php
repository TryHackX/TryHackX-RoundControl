<?php
declare(strict_types=1);

namespace App\Support;

final class SecurityHeaders
{
    /**
     * Nagłówki bezpieczeństwa dla stron portalu.
     * CSP nie zawiera 'unsafe-inline' dla skryptów — cały JS jest w plikach
     * zewnętrznych, a obsługa zdarzeń idzie przez delegację (bez inline onclick).
     *
     * 1.3.0: hosty CAPTCHA tylko AKTYWNEGO dostawcy (Captcha::cspHostsIn()); bez CAPTCHA — żadnych obcych hostów.
     *
     * @param array{script?:string[],frame?:string[],connect?:string[],style?:string[]} $captcha
     */
    public static function send(array $captcha = []): void
    {
        self::common();
        $list = static fn(string $kind): string => implode('', array_map(static fn(string $h): string => ' ' . $h, $captcha[$kind] ?? []));

        $csp = "default-src 'self'; "
             . "script-src 'self'" . $list('script') . '; '
             . ($list('frame') !== '' ? 'frame-src' . $list('frame') . '; ' : "frame-src 'none'; ")
             . "style-src 'self' 'unsafe-inline'" . $list('style') . '; '
             . "img-src 'self' data:; "
             . "connect-src 'self'" . $list('connect') . '; '
             . "base-uri 'self'; "
             . "form-action 'self'";

        header("Content-Security-Policy: $csp");
    }

    /** Wspólne nagłówki; bez X-Powered-By (nie podajemy wersji PHP ani niczego, co ułatwia dobranie exploita). */
    private static function common(): void
    {
        header_remove('X-Powered-By');
        header('X-Frame-Options: DENY');
        header('X-Content-Type-Options: nosniff');
        header('Referrer-Policy: strict-origin-when-cross-origin');

        if (!Env::isDev()) {
            header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
        }
    }

    /**
     * Nagłówki dla panelu admina. RemixIcon jest self-hostowany (M4),
     * więc CSP nie dopuszcza żadnych zewnętrznych źródeł — skrypty, style
     * i fonty wyłącznie z 'self' (style-src 'unsafe-inline' pod komponenty panelu).
     */
    public static function sendPanel(): void
    {
        self::common();

        $csp = "default-src 'self'; "
             . "script-src 'self'; "
             . "style-src 'self' 'unsafe-inline'; "
             . "font-src 'self'; "
             . "img-src 'self' data:; "
             . "connect-src 'self'; "
             . "base-uri 'self'; "
             . "form-action 'self'";

        header("Content-Security-Policy: $csp");
    }
}
