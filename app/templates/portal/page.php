<?php
declare(strict_types=1);

use App\Support\Html;

/**
 * @var bool     $enabled   rejestracja włączona
 * @var bool     $rcOn      reCAPTCHA aktywna
 * @var string   $siteKey   klucz publiczny reCAPTCHA
 * @var string[] $domains   dostępne domeny
 * @var int      $pwdMin    min. długość hasła
 * @var int      $pwdUpper  wymagana duża litera (0/1)
 * @var int      $pwdDigit  wymagana cyfra (0/1)
 * @var int      $pwdSpec   wymagany znak specjalny (0/1)
 * @var string   $csrf      token CSRF
 */
/** URL assetu z wersją (mtime) — wymusza pobranie świeżego pliku po zmianie. */
$av = static fn(string $f): string => 'assets/' . $f . '?v=' . (@filemtime(dirname(__DIR__, 3) . '/assets/' . $f) ?: '1');
$eye = '<svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M2 12s3.6-7 10-7 10 7 10 7-3.6 7-10 7S2 12 2 12Z"/><circle cx="12" cy="12" r="3"/></svg>';
?>
<!doctype html>
<html lang="pl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="dark">
    <title>Portal — Logowanie</title>
    <link rel="stylesheet" href="<?= $av('portal.css') ?>">
    <?php if ($rcOn): ?>
        <script src="https://www.google.com/recaptcha/api.js" async defer></script>
    <?php endif; ?>
</head>
<body
    data-csrf="<?= Html::e($csrf) ?>"
    data-recaptcha="<?= $rcOn ? '1' : '0' ?>"
    data-pwd-min="<?= (int) $pwdMin ?>"
    data-pwd-upper="<?= (int) $pwdUpper ?>"
    data-pwd-digit="<?= (int) $pwdDigit ?>"
    data-pwd-spec="<?= (int) $pwdSpec ?>">

<main class="scene">
    <section class="card active" id="card-login" aria-labelledby="loginTitle">
        <h1 id="loginTitle">Logowanie</h1>
        <div id="global-message" class="msg-box" role="status" aria-live="polite"><div class="msg-inner"></div></div>

        <div id="auth-flow">
            <div id="step-identity">
                <p class="muted">Wprowadź adres e-mail, aby kontynuować</p>
                <form id="form-identity" novalidate>
                    <input type="hidden" name="ajax_action" value="check_identity">
                    <label for="inpEmail">Adres e-mail</label>
                    <input type="text" id="inpEmail" name="email" required inputmode="email"
                           autocapitalize="none" autocorrect="off" spellcheck="false"
                           placeholder="adres@domena.pl" autocomplete="username" autofocus>
                    <?php if ($rcOn): ?>
                        <div class="captcha-container">
                            <div class="g-recaptcha" data-sitekey="<?= Html::e($siteKey) ?>" data-theme="dark"></div>
                        </div>
                    <?php endif; ?>
                    <button type="submit" class="btn-action">Dalej</button>
                </form>
            </div>
            <div id="step-dynamic" hidden></div>
            <div id="step-choice" hidden></div>
        </div>

        <?php if ($enabled): ?>
            <button type="button" class="link-btn switch-link" data-action="switch" data-view="register">
                Nie masz konta? Załóż je tutaj
            </button>
        <?php endif; ?>
    </section>

    <section class="card" id="card-register" aria-labelledby="registerTitle" inert>
        <h1 id="registerTitle">Rejestracja</h1>
        <p class="muted">Utwórz nowe konto e-mail</p>

        <div id="register-message" class="msg-box" role="status" aria-live="polite"><div class="msg-inner"></div></div>

        <?php if ($enabled): ?>
            <form id="form-register" autocomplete="off" novalidate>
                <label for="fieldLocal">Adres e-mail</label>
                <div class="email-group">
                    <input type="text" name="local" id="fieldLocal" required autocapitalize="none" autocorrect="off"
                           spellcheck="false" placeholder="nazwa" autocomplete="off" aria-describedby="availability-status">
                    <span aria-hidden="true">@</span>
                    <select name="domain" id="fieldDomain" required aria-label="Domena">
                        <?php foreach ($domains as $dm): ?>
                            <option value="<?= Html::e($dm) ?>"><?= Html::e($dm) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div id="availability-status" aria-live="polite"></div>

                <label for="fieldPass">Hasło</label>
                <div class="pwd-field">
                    <input type="password" name="password" id="fieldPass" required
                           placeholder="Silne hasło" autocomplete="new-password" aria-describedby="pwdReqs">
                    <button type="button" class="pwd-toggle" data-action="toggle-pwd" data-for="fieldPass"
                            aria-label="Pokaż hasło" aria-pressed="false"><?= $eye ?></button>
                </div>
                <div class="pwd-meter" aria-hidden="true"><div class="pwd-meter-fill" id="pwdBar"></div></div>
                <ul class="pwd-reqs" id="pwdReqs">
                    <li id="reqLen">Min. <?= (int) $pwdMin ?> znaków</li>
                    <?php if ($pwdUpper): ?><li id="reqUp">Duża litera</li><?php endif; ?>
                    <?php if ($pwdDigit): ?><li id="reqDig">Cyfra</li><?php endif; ?>
                    <?php if ($pwdSpec): ?><li id="reqSpec">Znak specjalny</li><?php endif; ?>
                </ul>

                <label for="fieldPass2">Powtórz hasło</label>
                <input type="password" name="password2" id="fieldPass2" required
                       placeholder="Powtórz hasło" autocomplete="new-password" aria-describedby="pass-match-status">
                <div id="pass-match-status" aria-live="polite"></div>

                <?php if ($rcOn): ?>
                    <div class="captcha-container">
                        <div class="g-recaptcha" data-sitekey="<?= Html::e($siteKey) ?>" data-theme="dark"></div>
                    </div>
                <?php endif; ?>

                <button type="submit" class="btn-action">Utwórz konto</button>
            </form>
        <?php else: ?>
            <div class="msg-box msg-err is-open"><div class="msg-inner">Rejestracja jest obecnie wyłączona</div></div>
        <?php endif; ?>

        <button type="button" class="link-btn switch-link" data-action="switch" data-view="login">
            Masz już konto? Wróć do logowania
        </button>
    </section>
</main>

<script src="<?= $av('portal.js') ?>" defer></script>
</body>
</html>
