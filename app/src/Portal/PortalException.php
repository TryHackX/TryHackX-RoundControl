<?php
declare(strict_types=1);

namespace App\Portal;

use RuntimeException;

/**
 * Błąd prezentowany użytkownikowi portalu.
 * Kod 1 oznacza, że front powinien przeładować stronę (np. wygasła sesja/CSRF).
 */
final class PortalException extends RuntimeException
{
    public const RELOAD = 1;

    /** Czy front ma zresetować widok do ekranu identyfikacji (błędne dane logowania). */
    public bool $reset = false;

    public static function withReset(string $message): self
    {
        $e = new self($message);
        $e->reset = true;
        return $e;
    }
}
