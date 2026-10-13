<?php
declare(strict_types=1);

use App\I18n\Lang;
use App\Support\Html;
use App\Support\View;

use function App\I18n\h;
use function App\I18n\html;

/**
 * Ustawienia → Języki interfejsu. Dołączana z page.php POZA wielkim formularzem ustawień (każda operacja to osobny mały
 * formularz). Zmienne mają prefiks $langAdm…, bo plik działa w zakresie zmiennych strony.
 *
 * @var array  $s    ustawienia strony (klucz 'languages' = LanguageAdmin::listing())
 * @var string $csrf
 */
$langAdm       = $s['languages'];
$langAdmHidden = '<input type="hidden" name="_csrf" value="' . Html::e($csrf) . '"><input type="hidden" name="_tab" value="settings">';
?>
<div class="settings-card lang-card" id="lang-card">
    <div class="settings-card-header"><i class="ri-translate-2" aria-hidden="true"></i> <?= h('panel.lang.title') ?></div>
    <div class="settings-card-body">
        <p class="help-text"><?= h('panel.lang.lead') ?></p>
        <?php if (!$langAdm['dirOk']): ?>
            <div class="alert alert-error"><i class="ri-error-warning-line" aria-hidden="true"></i>
                <span><?= html('panel.lang.dir_missing', ['dir' => $langAdm['dir']]) ?></span></div>
        <?php endif; ?>
        <div class="table-responsive">
            <table class="lang-table">
                <thead>
                <tr>
                    <th scope="col"><?= h('panel.lang.th.code') ?></th>
                    <th scope="col"><?= h('panel.lang.th.name') ?></th>
                    <th scope="col"><?= h('panel.lang.th.coverage') ?></th>
                    <th scope="col"><?= h('panel.lang.th.enabled') ?></th>
                    <th scope="col"><?= h('panel.lang.th.default') ?></th>
                    <th scope="col"><span class="sr-only"><?= h('panel.lang.th.actions') ?></span></th>
                </tr>
                </thead>
                <tbody id="lang-rows">
                <?= View::render(__DIR__ . '/lang_rows.php', ['lang' => $langAdm, 'csrf' => $csrf]) ?>
                </tbody>
            </table>
        </div>

        <div class="lang-tools">
            <form method="POST" data-ajax class="lang-tool" data-reset-on-success>
                <?= $langAdmHidden ?><input type="hidden" name="lang_op" value="duplicate">
                <div class="lang-tool-title"><?= h('panel.lang.dup.title') ?></div>
                <div class="lang-tool-row">
                    <label for="lang_dup_from" class="sr-only"><?= h('panel.lang.dup.from') ?></label>
                    <select id="lang_dup_from" name="from">
                        <?php foreach ($langAdm['rows'] as $langAdmRow): ?>
                            <option value="<?= Html::e($langAdmRow['code']) ?>" <?= $langAdmRow['code'] === 'en' ? 'selected' : '' ?>><?= Html::e(strtoupper($langAdmRow['code']) . ' — ' . $langAdmRow['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <span aria-hidden="true">→</span>
                    <label for="lang_dup_code" class="sr-only"><?= h('panel.lang.new_code') ?></label>
                    <input type="text" id="lang_dup_code" name="code" required pattern="[a-z]{2,3}" maxlength="3" size="5"
                           placeholder="<?= h('panel.lang.code_ph') ?>" autocomplete="off" spellcheck="false">
                    <button type="submit" class="btn btn-small"><i class="ri-file-copy-line" aria-hidden="true"></i> <?= h('panel.lang.dup.submit') ?></button>
                </div>
                <div class="help-text"><?= h('panel.lang.dup.help') ?></div>
            </form>

            <form method="POST" data-ajax enctype="multipart/form-data" class="lang-tool" data-reset-on-success>
                <?= $langAdmHidden ?><input type="hidden" name="lang_op" value="upload">
                <div class="lang-tool-title"><?= h('panel.lang.up.title') ?></div>
                <div class="lang-tool-row">
                    <label for="lang_up_code" class="sr-only"><?= h('panel.lang.new_code') ?></label>
                    <input type="text" id="lang_up_code" name="code" required pattern="[a-z]{2,3}" maxlength="3" size="5"
                           placeholder="<?= h('panel.lang.code_ph') ?>" autocomplete="off" spellcheck="false">
                    <label for="lang_up_file" class="sr-only"><?= h('panel.lang.up.file') ?></label>
                    <input type="file" id="lang_up_file" name="pack" accept=".json,application/json" required>
                    <button type="submit" class="btn btn-small"><i class="ri-upload-2-line" aria-hidden="true"></i> <?= h('panel.lang.up.submit') ?></button>
                </div>
                <div class="help-text"><?= h('panel.lang.up.help', ['kb' => (int) (Lang::UPLOAD_MAX / 1024)]) ?></div>
            </form>
        </div>
    </div>
</div>
