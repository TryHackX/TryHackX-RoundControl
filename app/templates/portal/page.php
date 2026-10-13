<?php
declare(strict_types=1);

use App\I18n\Lang;
use App\Support\Html;

use function App\I18n\h;

/**
 * @var bool     $enabled   rejestracja włączona
 * @var ?array   $captcha   aktywna CAPTCHA: provider, siteKey, script, class ('' = v3, bez widżetu), size — albo null
 * @var string[] $domains   dostępne domeny
 * @var int      $pwdMin    min. długość hasła
 * @var int      $pwdUpper  wymagana duża litera (0/1)
 * @var int      $pwdDigit  wymagana cyfra (0/1)
 * @var int      $pwdSpec   wymagany znak specjalny (0/1)
 * @var string   $csrf      token CSRF
 */
/** URL assetu z krótkim skrótem pliku (zmiana pliku = nowy adres). Nigdy numer wersji: na stronie publicznej go nie podajemy. */
$av = static fn(string $f): string => 'assets/' . $f . '?v=' . \App\Support\View::assetTag(dirname(__DIR__, 3) . '/assets/' . $f);
/* Widżet CAPTCHA (render niejawny dostawcy) w oknie przycinającym ramkę iframe do rozmiaru widżetu; v3 nie ma widżetu. */
$captchaWidget = '';
if ($captcha && $captcha['class'] !== '' && $captcha['size']) {
    [$cw, $ch] = $captcha['size'];
    $captchaWidget = '<div class="captcha-container"><div class="captcha-box captcha-box--' . Html::e($captcha['provider'])
        . '" style="--rc-w:' . (int) $cw . 'px;--rc-h:' . (int) $ch . 'px" data-w="' . (int) $cw . '"><div class="' . Html::e($captcha['class'])
        . '" data-sitekey="' . Html::e($captcha['siteKey']) . '" data-theme="dark" data-language="' . Html::e(Lang::current()) . '"></div></div></div>';
}
$eye = '<svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M2 12s3.6-7 10-7 10 7 10 7-3.6 7-10 7S2 12 2 12Z"/><circle cx="12" cy="12" r="3"/></svg>';
?>
<!doctype html>
<html lang="<?= Html::e(Lang::current()) ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="dark">
    <title><?= h('portal.title') ?></title>
    <link rel="stylesheet" href="<?= $av('portal.css') ?>">
    <?php if ($captcha): ?>
        <script src="<?= Html::e($captcha['script']) ?>" async defer></script>
    <?php endif; ?>
</head>
<body
    data-csrf="<?= Html::e($csrf) ?>"
    data-captcha="<?= $captcha ? Html::e($captcha['provider']) : '' ?>"
    data-captcha-key="<?= $captcha ? Html::e($captcha['siteKey']) : '' ?>"
    data-pwd-min="<?= (int) $pwdMin ?>"
    data-pwd-upper="<?= (int) $pwdUpper ?>"
    data-pwd-digit="<?= (int) $pwdDigit ?>"
    data-pwd-spec="<?= (int) $pwdSpec ?>">

<?php $langSwitchClass = 'lang-switch--portal'; include __DIR__ . '/../partials/lang_switch.php'; ?>

<main class="scene">
    <section class="card active" id="card-login" aria-labelledby="loginTitle">
        <h1 id="loginTitle"><?= h('portal.login.title') ?></h1>
        <div id="global-message" class="msg-box" role="status" aria-live="polite"><div class="msg-inner"></div></div>

        <div id="auth-flow">
            <div id="step-identity">
                <p class="muted"><?= h('portal.login.lead') ?></p>
                <form id="form-identity" novalidate>
                    <input type="hidden" name="ajax_action" value="check_identity">
                    <label for="inpEmail"><?= h('portal.email') ?></label>
                    <input type="text" id="inpEmail" name="email" required inputmode="email"
                           autocapitalize="none" autocorrect="off" spellcheck="false"
                           placeholder="<?= h('portal.login.email_ph') ?>" autocomplete="username" autofocus>
                    <?= $captchaWidget ?>
                    <button type="submit" class="btn-action"><?= h('portal.login.next') ?></button>
                </form>
            </div>
            <div id="step-dynamic" hidden></div>
            <div id="step-choice" hidden></div>
        </div>

        <?php if ($enabled): ?>
            <button type="button" class="link-btn switch-link" data-action="switch" data-view="register">
                <?= h('portal.login.to_register') ?>
            </button>
        <?php endif; ?>
    </section>

    <section class="card" id="card-register" aria-labelledby="registerTitle" inert>
        <h1 id="registerTitle"><?= h('portal.reg.title') ?></h1>
        <p class="muted"><?= h('portal.reg.lead') ?></p>

        <div id="register-message" class="msg-box" role="status" aria-live="polite"><div class="msg-inner"></div></div>

        <?php if ($enabled): ?>
            <form id="form-register" autocomplete="off" novalidate>
                <label for="fieldLocal"><?= h('portal.email') ?></label>
                <div class="email-group">
                    <input type="text" name="local" id="fieldLocal" required autocapitalize="none" autocorrect="off"
                           spellcheck="false" placeholder="<?= h('portal.reg.local_ph') ?>" autocomplete="off" aria-describedby="availability-status">
                    <span aria-hidden="true">@</span>
                    <select name="domain" id="fieldDomain" required aria-label="<?= h('portal.reg.domain') ?>">
                        <?php foreach ($domains as $dm): ?>
                            <option value="<?= Html::e($dm) ?>"><?= Html::e($dm) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div id="availability-status" aria-live="polite"></div>

                <label for="fieldPass"><?= h('portal.password') ?></label>
                <div class="pwd-field">
                    <input type="password" name="password" id="fieldPass" required
                           placeholder="<?= h('portal.reg.pass_ph') ?>" autocomplete="new-password" aria-describedby="pwdReqs">
                    <button type="button" class="pwd-toggle" data-action="toggle-pwd" data-for="fieldPass"
                            aria-label="<?= h('portal.show_password') ?>" aria-pressed="false"><?= $eye ?></button>
                </div>
                <div class="pwd-meter" aria-hidden="true"><div class="pwd-meter-fill" id="pwdBar"></div></div>
                <ul class="pwd-reqs" id="pwdReqs">
                    <li id="reqLen"><?= h('portal.reg.req_len', ['n' => (int) $pwdMin]) ?></li>
                    <?php if ($pwdUpper): ?><li id="reqUp"><?= h('portal.reg.req_upper') ?></li><?php endif; ?>
                    <?php if ($pwdDigit): ?><li id="reqDig"><?= h('portal.reg.req_digit') ?></li><?php endif; ?>
                    <?php if ($pwdSpec): ?><li id="reqSpec"><?= h('portal.reg.req_special') ?></li><?php endif; ?>
                </ul>

                <label for="fieldPass2"><?= h('portal.reg.pass2') ?></label>
                <input type="password" name="password2" id="fieldPass2" required
                       placeholder="<?= h('portal.reg.pass2') ?>" autocomplete="new-password" aria-describedby="pass-match-status">
                <div id="pass-match-status" aria-live="polite"></div>

                <?= $captchaWidget ?>

                <button type="submit" class="btn-action"><?= h('portal.reg.submit') ?></button>
            </form>
        <?php else: ?>
            <div class="msg-box msg-err is-open"><div class="msg-inner"><?= h('portal.reg.disabled_now') ?></div></div>
        <?php endif; ?>

        <button type="button" class="link-btn switch-link" data-action="switch" data-view="login">
            <?= h('portal.reg.to_login') ?>
        </button>
    </section>
</main>
<?php $footerInPanel = false; include __DIR__ . '/../partials/footer.php'; ?>

<?= Lang::jsBridge('portal') ?>
<script src="<?= $av('portal.js') ?>" defer></script>
</body>
</html>
