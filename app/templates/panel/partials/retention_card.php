<?php
declare(strict_types=1);

use App\Admin\LogRetention;
use App\Support\Html;

use function App\I18n\h;
use function App\I18n\html;

/**
 * Ustawienia → Przechowywanie logów (1.3.2). Dołączana z page.php poza wielkim formularzem ustawień; dwa małe formularze:
 * zapis okna i "Wyczyść teraz". Zmienne z prefiksem $ret…, bo plik działa w zakresie zmiennych strony.
 *
 * @var array  $s    ustawienia strony (klucz 'retention')
 * @var string $csrf
 */
$ret       = $s['retention'];
$retHidden = '<input type="hidden" name="_csrf" value="' . Html::e($csrf) . '"><input type="hidden" name="_tab" value="settings">';
?>
<div class="settings-card retention-card" id="retention-card">
    <div class="settings-card-header"><i class="ri-history-line" aria-hidden="true"></i> <?= h('panel.retention.title') ?></div>
    <div class="settings-card-body">
        <p class="help-text"><?= h('panel.retention.lead') ?></p>
        <form method="POST" data-ajax class="retention-form">
            <?= $retHidden ?><input type="hidden" name="log_retention_save" value="1">
            <div class="retention-row">
                <label for="log_retention_days"><?= h('panel.retention.label') ?></label>
                <select id="log_retention_days" name="log_retention_days">
                    <?php foreach (LogRetention::OPTIONS as $retOpt): ?>
                        <option value="<?= (int) $retOpt ?>" <?= $ret['days'] === $retOpt ? 'selected' : '' ?>><?= h('panel.retention.opt_' . $retOpt) ?></option>
                    <?php endforeach; ?>
                </select>
                <button type="submit" class="btn btn-small"><i class="ri-save-line" aria-hidden="true"></i> <?= h('panel.retention.save') ?></button>
            </div>
        </form>
        <ul class="retention-rules help-text">
            <li><?= h('panel.retention.rule_logs') ?></li>
            <li><?= h('panel.retention.rule_accounts') ?></li>
            <li><?= h('panel.retention.rule_send') ?></li>
            <li><?= h('panel.retention.rule_deleted') ?></li>
            <li><?= html('panel.retention.rule_min') ?></li>
        </ul>
        <form method="POST" data-ajax class="retention-form retention-now" data-busy-label="<?= h('panel.retention.cleaning') ?>">
            <?= $retHidden ?><input type="hidden" name="log_cleanup_now" value="1">
            <button type="submit" class="btn btn-small btn-secondary"><i class="ri-delete-bin-6-line" aria-hidden="true"></i> <?= h('panel.retention.clean_now') ?></button>
            <span class="help-text" id="retentionLast"><?= Html::e($ret['last']) ?></span>
        </form>
    </div>
</div>
