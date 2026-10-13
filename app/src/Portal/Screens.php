<?php
declare(strict_types=1);

namespace App\Portal;

use App\Support\Html;

/**
 * Dynamiczny ekran wstrzykiwany przez AJAX (hasło + wybór usługi).
 * Bez inline-onclick — interakcje obsługuje portal.js przez data-action.
 *
 * Ekran jest TAKI SAM dla każdego poprawnego adresu (istniejącego czy nie, administratora czy zwykłego konta):
 * wybór "Webmail / Panel Admina" robi użytkownik, a nie serwer, który nic o koncie nie zdradza.
 */
final class Screens
{
    public static function password(string $email, string $target): string
    {
        $e = Html::e($email);
        $i = Html::e(mb_substr($email, 0, 1));
        $selWebmail = $target === 'admin' ? '' : ' selected';
        $selAdmin   = $target === 'admin' ? ' selected' : '';

        return <<<HTML
        <div class="fade-in">
            <div class="user-profile">
                <div class="avatar" aria-hidden="true">{$i}</div>
                <div class="user-info">
                    <div class="email">{$e}</div>
                    <div class="change-user" role="button" tabindex="0" data-action="back">Zmień użytkownika</div>
                </div>
            </div>
            <form data-form="login">
                <input type="hidden" name="ajax_action" value="perform_login">
                <label for="inpTarget">Usługa</label>
                <select id="inpTarget" name="target">
                    <option value="webmail"{$selWebmail}>Webmail — poczta elektroniczna</option>
                    <option value="admin"{$selAdmin}>Panel Admina — zarządzanie systemem</option>
                </select>
                <label for="inpPassword">Hasło</label>
                <input type="password" id="inpPassword" name="password" required
                       placeholder="Wpisz hasło" autocomplete="current-password">
                <button type="submit" class="btn-action">Zaloguj się</button>
            </form>
            <div class="back-link" role="button" tabindex="0" data-action="back">← Wróć</div>
        </div>
        HTML;
    }
}
