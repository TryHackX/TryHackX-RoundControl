<?php
declare(strict_types=1);

namespace App\Portal;

use App\Support\Html;

/**
 * Dynamiczne ekrany wstrzykiwane przez AJAX (wybór usługi / hasło).
 * Bez inline-onclick — interakcje obsługuje portal.js przez data-action.
 */
final class Screens
{
    public static function choice(string $email): string
    {
        $e = Html::e($email);
        $i = Html::e(mb_substr($email, 0, 1));

        return <<<HTML
        <div class="fade-in">
            <div class="user-profile">
                <div class="avatar" aria-hidden="true">{$i}</div>
                <div class="user-info">
                    <div class="email">{$e}</div>
                    <div class="change-user" role="button" tabindex="0" data-action="back">Zmień użytkownika</div>
                </div>
            </div>
            <p class="muted">Wybierz usługę:</p>
            <div class="service-choices">
                <div class="service-card" role="button" tabindex="0" data-action="select-target" data-target="webmail">
                    <div class="service-icon">📧</div>
                    <div class="service-name">Webmail</div>
                    <div class="service-desc">Poczta elektroniczna</div>
                </div>
                <div class="service-card" role="button" tabindex="0" data-action="select-target" data-target="admin">
                    <div class="service-icon">⚙️</div>
                    <div class="service-name">Panel Admina</div>
                    <div class="service-desc">Zarządzanie systemem</div>
                </div>
            </div>
        </div>
        HTML;
    }

    public static function password(string $email, string $target): string
    {
        $e          = Html::e($email);
        $i          = Html::e(mb_substr($email, 0, 1));
        $t          = Html::e($target);
        $targetName = $target === 'admin' ? 'Panel Admina' : 'Webmail';

        return <<<HTML
        <div class="fade-in">
            <div class="user-profile">
                <div class="avatar" aria-hidden="true">{$i}</div>
                <div class="user-info">
                    <div class="email">{$e}</div>
                    <div class="service-badge">{$targetName}</div>
                </div>
            </div>
            <form data-form="login">
                <input type="hidden" name="ajax_action" value="perform_login">
                <input type="hidden" name="target" value="{$t}">
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
