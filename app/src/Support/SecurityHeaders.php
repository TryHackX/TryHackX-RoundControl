<?php
declare(strict_types=1);

namespace App\Support;

final class SecurityHeaders
{
    /**
     * Nagłówki bezpieczeństwa dla stron portalu.
     * CSP nie zawiera 'unsafe-inline' dla skryptów — cały JS jest w plikach
     * zewnętrznych, a obsługa zdarzeń idzie przez delegację (bez inline onclick).
     */
    public static function send(): void
    {
        header('X-Frame-Options: DENY');
        header('X-Content-Type-Options: nosniff');
        header('Referrer-Policy: strict-origin-when-cross-origin');

        if (!Env::isDev()) {
            header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
        }

        $csp = "default-src 'self'; "
             . "script-src 'self' https://www.google.com https://www.gstatic.com; "
             . "frame-src https://www.google.com; "
             . "style-src 'self' 'unsafe-inline'; "
             . "img-src 'self' data:; "
             . "connect-src 'self'; "
             . "base-uri 'self'; "
             . "form-action 'self'";

        header("Content-Security-Policy: $csp");
    }

    /**
     * Nagłówki dla panelu admina. RemixIcon jest self-hostowany (M4),
     * więc CSP nie dopuszcza żadnych zewnętrznych źródeł — skrypty, style
     * i fonty wyłącznie z 'self' (style-src 'unsafe-inline' pod komponenty panelu).
     */
    public static function sendPanel(): void
    {
        header('X-Frame-Options: DENY');
        header('X-Content-Type-Options: nosniff');
        header('Referrer-Policy: strict-origin-when-cross-origin');

        if (!Env::isDev()) {
            header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
        }

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
