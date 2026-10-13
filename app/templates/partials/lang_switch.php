<?php
declare(strict_types=1);

use App\I18n\Lang;
use App\Support\Html;

use function App\I18n\h;

/**
 * Przełącznik języka (portal i panel). Zwykłe odnośniki ?lang=xx — działa bez JavaScriptu; serwer zapamiętuje wybór
 * w ciasteczku `lang` i od razu rysuje stronę w wybranym języku. Ukryty, gdy włączony jest tylko jeden język.
 *
 * @var ?string $langSwitchClass dodatkowa klasa (np. lang-switch--portal)
 */
$langOn = Lang::enabled();
if (count($langOn) > 1):
    $langCur  = Lang::current();
    $langKeep = isset($_GET['tab']) && is_string($_GET['tab']) && preg_match('/^[a-z0-9]{1,20}$/', $_GET['tab']) ? ['tab' => $_GET['tab']] : [];
    $langAll  = Lang::available();
?>
<details class="lang-switch <?= Html::e($langSwitchClass ?? '') ?>">
    <summary aria-label="<?= h('common.lang.switch', ['name' => Lang::name($langCur)]) ?>" title="<?= h('common.lang.title') ?>">
        <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><circle cx="12" cy="12" r="9"/><path d="M3 12h18M12 3c2.5 2.7 3.8 5.7 3.8 9s-1.3 6.3-3.8 9c-2.5-2.7-3.8-5.7-3.8-9S9.5 5.7 12 3Z"/></svg>
        <span class="lang-switch-code"><?= Html::e(strtoupper($langCur)) ?></span>
    </summary>
    <ul class="lang-switch-list">
        <?php foreach ($langOn as $lc): ?>
            <li><a href="?<?= Html::e(http_build_query($langKeep + ['lang' => $lc])) ?>" lang="<?= Html::e($lc) ?>" hreflang="<?= Html::e($lc) ?>"<?= $lc === $langCur ? ' aria-current="true"' : '' ?>>
                <span class="lang-switch-code"><?= Html::e(strtoupper($lc)) ?></span> <?= Html::e($langAll[$lc]['name'] ?? Lang::name($lc)) ?>
            </a></li>
        <?php endforeach; ?>
    </ul>
</details>
<?php endif; ?>
