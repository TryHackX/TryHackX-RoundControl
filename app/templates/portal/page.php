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
?>
<!doctype html>
<html lang="pl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Portal — Logowanie</title>
    <link rel="stylesheet" href="<?= $av('portal.css') ?>">
    <?php if ($rcOn): ?>
        <script src="https://www.google.com/recaptcha/api.js" async defer></script>
    <?php endif; ?>
</head>
<body
    data-csrf="<?= Html::e($csrf) ?>"
    data-pwd-min="<?= (int) $pwdMin ?>"
    data-pwd-upper="<?= (int) $pwdUpper ?>"
    data-pwd-digit="<?= (int) $pwdDigit ?>"
    data-pwd-spec="<?= (int) $pwdSpec ?>">

<div class="scene">
    <div class="card active" id="card-login">
        <h1>Logowanie</h1>
        <div id="global-message" class="msg-box hidden"></div>

        <div id="auth-flow">
            <div id="step-identity" class="fade-in">
                <p class="muted">Wprowadź adres e-mail aby kontynuować</p>
                <form id="form-identity">
                    <input type="hidden" name="ajax_action" value="check_identity">
                    <label for="inpEmail">Adres e-mail</label>
                    <input type="text" id="inpEmail" name="email" required
                           placeholder="adres@domena.pl" autocomplete="username" autofocus>
                    <?php if ($rcOn): ?>
                        <div class="captcha-container">
                            <div class="g-recaptcha" data-sitekey="<?= Html::e($siteKey) ?>" data-theme="dark"></div>
                        </div>
                    <?php endif; ?>
                    <button type="submit" class="btn-action">Dalej</button>
                </form>
            </div>
            <div id="step-dynamic"></div>
        </div>

        <?php if ($enabled): ?>
            <div class="switch-link" role="button" tabindex="0" data-action="switch" data-view="register">
                Nie masz konta? Załóż je tutaj
            </div>
        <?php endif; ?>
    </div>

    <div class="card" id="card-register">
        <h1>Rejestracja</h1>
        <p class="muted">Utwórz nowe konto email</p>

        <div id="register-message" class="msg-box hidden"></div>

        <?php if ($enabled): ?>
            <form id="form-register" autocomplete="off">
                <label for="fieldLocal">Adres e-mail</label>
                <div class="email-group">
                    <input type="text" name="local" id="fieldLocal" required
                           placeholder="nazwa" autocomplete="off">
                    <span>@</span>
                    <select name="domain" id="fieldDomain" required>
                        <?php foreach ($domains as $dm): ?>
                            <option value="<?= Html::e($dm) ?>"><?= Html::e($dm) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div id="availability-status"></div>

                <label for="fieldPass">Hasło</label>
                <input type="password" name="password" id="fieldPass" required
                       placeholder="Silne hasło" autocomplete="new-password">
                <div class="pwd-meter"><div class="pwd-meter-fill" id="pwdBar"></div></div>
                <ul class="pwd-reqs">
                    <li id="reqLen">Min. <?= (int) $pwdMin ?> znaków</li>
                    <?php if ($pwdUpper): ?><li id="reqUp">Duża litera</li><?php endif; ?>
                    <?php if ($pwdDigit): ?><li id="reqDig">Cyfra</li><?php endif; ?>
                    <?php if ($pwdSpec): ?><li id="reqSpec">Znak specjalny</li><?php endif; ?>
                </ul>

                <label for="fieldPass2">Powtórz hasło</label>
                <input type="password" name="password2" id="fieldPass2" required
                       placeholder="Powtórz hasło" autocomplete="new-password">
                <div id="pass-match-status"></div>

                <?php if ($rcOn): ?>
                    <div class="captcha-container">
                        <div class="g-recaptcha" data-sitekey="<?= Html::e($siteKey) ?>" data-theme="dark"></div>
                    </div>
                <?php endif; ?>

                <button type="submit" class="btn-action">Utwórz konto</button>
            </form>
        <?php else: ?>
            <div class="msg-box msg-err">Rejestracja jest obecnie wyłączona</div>
        <?php endif; ?>

        <div class="switch-link" role="button" tabindex="0" data-action="switch" data-view="login">
            Masz już konto? Wróć do logowania
        </div>
    </div>
</div>

<script src="<?= $av('portal.js') ?>" defer></script>
</body>
</html>
