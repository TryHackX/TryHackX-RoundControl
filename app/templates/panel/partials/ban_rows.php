<?php
declare(strict_types=1);

use App\Support\Html;

use function App\I18n\h;
use function App\I18n\t;

/** @var array<int,array> $bans */

/** Time left until $ts, one format for every length: a space between number and unit ("2 d 5 h", "1 h 12 min", "5 min"). */
$remaining = static function (int $ts): string {
    $d = $ts - time();
    if ($d <= 0) {
        return t('rows.expires_now');
    }
    $days = intdiv($d, 86400);
    $h    = intdiv($d % 86400, 3600);
    $m    = intdiv($d % 3600, 60);
    if ($days > 0) {
        return $days . ' ' . t('rows.unit_d') . ' ' . $h . ' ' . t('rows.unit_h');
    }
    if ($h > 0) {
        return $h . ' ' . t('rows.unit_h') . ' ' . $m . ' ' . t('rows.unit_min');
    }
    return max(1, $m) . ' ' . t('rows.unit_min');
};

if ($bans === []): ?>
<tr class="empty-row"><td colspan="5" class="empty-state-text"><?= h('rows.no_bans') ?></td></tr>
<?php else: ?>
<?php foreach ($bans as $b):
    $cls = $b['type'] === 'local' ? 'badge-type-local' : 'badge-jail jail-' . Html::e($b['service']);
    $untilEpoch = $b['until_epoch'] ?? null;
?>
<tr data-ip="<?= Html::e((string) $b['ip_address']) ?>">
    <td><span class="<?= $cls ?>"><?= Html::e($b['service']) ?></span></td>
    <td><strong><?= Html::e((string) $b['ip_address']) ?></strong></td>
    <td><?= Html::e($b['reason']) ?></td>
    <td data-sort="<?= $untilEpoch !== null ? (int) $untilEpoch : PHP_INT_MAX ?>"
        <?= $untilEpoch !== null ? 'title="' . h('rows.until_title', ['date' => date('Y-m-d H:i:s', $untilEpoch)]) . '"' : '' ?>>
        <?= Html::e($b['until']) ?>
        <?php if ($untilEpoch !== null): ?>
            <div class="ban-remaining"><?= h('rows.remaining', ['time' => $remaining($untilEpoch)]) ?></div>
        <?php endif; ?>
    </td>
    <td>
        <button type="button" class="btn-action btn-manage"
                data-action="manage-ban"
                data-ip="<?= Html::e((string) $b['ip_address']) ?>"
                data-type="<?= Html::e($b['type']) ?>"
                data-jail="<?= Html::e((string) $b['jail']) ?>"
                aria-label="<?= h('rows.manage_aria', ['ip' => (string) $b['ip_address']]) ?>">
            <i class="ri-settings-3-line" aria-hidden="true"></i> <?= h('rows.manage') ?>
        </button>
    </td>
</tr>
<?php endforeach; ?>
<?php endif; ?>
