<?php
declare(strict_types=1);

namespace App\Portal;

use App\Support\Html;

use function App\I18n\h;

/**
 * Dynamiczne ekrany wstrzykiwane przez AJAX. Bez inline-JS — interakcje obsługuje portal.js przez data-action.
 *
 * Kolejność: adres e-mail -> hasło -> (tylko administrator z poprawnym hasłem do panelu, który ma też skrzynkę)
 * dwa kafelki: Webmail / Panel Admina. Ekran hasła jest TAKI SAM dla każdego poprawnego adresu (istniejącego czy nie,
 * administratora czy zwykłego konta): o roli dowiaduje się dopiero ktoś, kto zna hasło.
 */
final class Screens
{
    private const ICON_MAIL = '<svg viewBox="0 0 24 24" width="34" height="34" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><rect x="3" y="5" width="18" height="14" rx="2.5"/><path d="m4 7 8 6 8-6"/></svg>';
    private const ICON_PANEL = '<svg viewBox="0 0 24 24" width="34" height="34" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M12 3 5 6v5c0 4.4 3 8.3 7 9.5 4-1.2 7-5.1 7-9.5V6l-7-3Z"/><path d="m9 12 2 2 4-4"/></svg>';
    private const ICON_EYE = '<svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M2 12s3.6-7 10-7 10 7 10 7-3.6 7-10 7S2 12 2 12Z"/><circle cx="12" cy="12" r="3"/></svg>';

    private static function profile(string $email, string $link): string
    {
        $e = Html::e($email);
        $i = Html::e(mb_strtoupper(mb_substr($email, 0, 1)));
        return <<<HTML
            <div class="user-profile">
                <div class="avatar" aria-hidden="true">{$i}</div>
                <div class="user-info">
                    <div class="email">{$e}</div>
                    <button type="button" class="link-btn change-user" data-action="back">{$link}</button>
                </div>
            </div>
        HTML;
    }

    /** Krok 2: hasło. Identyczny dla każdego adresu (różni się tylko samym adresem). */
    public static function password(string $email): string
    {
        $e       = Html::e($email);
        $profile = self::profile($email, h('portal.change_user'));
        $eye     = self::ICON_EYE;
        $L = [
            'pass' => h('portal.password'), 'ph' => h('portal.password_ph'), 'show' => h('portal.show_password'),
            'submit' => h('portal.login_submit'), 'back' => h('portal.back'),
        ];

        return <<<HTML
        <div class="step-body">
        {$profile}
            <form data-form="login" novalidate>
                <input type="hidden" name="ajax_action" value="perform_login">
                <input type="text" name="username" value="{$e}" autocomplete="username" hidden readonly tabindex="-1">
                <label for="inpPassword">{$L['pass']}</label>
                <div class="pwd-field">
                    <input type="password" id="inpPassword" name="password" required
                           placeholder="{$L['ph']}" autocomplete="current-password" aria-describedby="loginHint">
                    <button type="button" class="pwd-toggle" data-action="toggle-pwd" data-for="inpPassword"
                            aria-label="{$L['show']}" aria-pressed="false">{$eye}</button>
                </div>
                <div id="loginHint" class="field-hint" aria-live="polite"></div>
                <button type="submit" class="btn-action">{$L['submit']}</button>
            </form>
            <button type="button" class="link-btn back-link" data-action="back">{$L['back']}</button>
        </div>
        HTML;
    }

    /**
     * Krok 3 (tylko administrator, który ma też skrzynkę): dwa kafelki.
     *
     * @param string $preferred  ostatnio wybrana usługa (ciasteczko tej przeglądarki): webmail | admin
     * @param bool   $webmail    czy to samo hasło otwiera skrzynkę (false = kafelek Webmail wyłączony, z wyjaśnieniem)
     * @param bool   $remembered czy ta przeglądarka już kiedyś wybierała (dopiero wtedy znaczek "ostatnio")
     */
    public static function choice(string $email, string $preferred, bool $webmail, bool $remembered = false): string
    {
        $profile  = self::profile($email, h('portal.choice.logout_change'));
        $mail     = self::ICON_MAIL;
        $panel    = self::ICON_PANEL;
        $preferred = $webmail && $preferred === 'webmail' ? 'webmail' : 'admin';
        $badge    = '<span class="service-last">' . h('portal.choice.last') . '</span>';
        $lastW    = $remembered && $preferred === 'webmail' ? $badge : '';
        $lastA    = $remembered && $preferred === 'admin' ? $badge : '';
        $disW     = $webmail ? '' : ' disabled aria-describedby="choiceNote"';
        $note     = $webmail ? '' : '<p class="choice-note" id="choiceNote">'
            . h('portal.choice.no_mailbox', ['email' => $email, 'link' => \App\I18n\t('portal.choice.logout_change')]) . '</p>';
        $L = [
            'lead' => h('portal.choice.lead'),
            'wName' => h('portal.choice.webmail'), 'wDesc' => h('portal.choice.webmail_desc'),
            'aName' => h('portal.choice.admin'), 'aDesc' => h('portal.choice.admin_desc'),
        ];

        return <<<HTML
        <div class="step-body">
        {$profile}
            <p class="muted choice-lead" id="choiceLead">{$L['lead']}</p>
            <div class="service-choices" role="group" aria-labelledby="choiceLead">
                <button type="button" class="service-card" id="tileWebmail" data-action="choose" data-target="webmail"{$disW}>
                    <span class="service-icon">{$mail}</span>
                    <span class="service-name">{$L['wName']}</span>
                    <span class="service-desc">{$L['wDesc']}</span>
                    {$lastW}
                </button>
                <button type="button" class="service-card" id="tileAdmin" data-action="choose" data-target="admin">
                    <span class="service-icon">{$panel}</span>
                    <span class="service-name">{$L['aName']}</span>
                    <span class="service-desc">{$L['aDesc']}</span>
                    {$lastA}
                </button>
            </div>
            {$note}
            <div id="choiceHint" class="field-hint" aria-live="polite"></div>
            <div id="choiceLive" class="sr-only" aria-live="polite"></div>
        </div>
        HTML;
    }

    /** Id kafelka, który dostaje fokus po wyświetleniu ekranu wyboru. */
    public static function choiceFocus(string $preferred, bool $webmail): string
    {
        return $webmail && $preferred === 'webmail' ? 'tileWebmail' : 'tileAdmin';
    }
}
