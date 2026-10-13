<?php
declare(strict_types=1);

use App\Support\Html;

/** @var array<int,array> $bans */

/** Pozostały czas do chwili $ts w zwięzłej formie (np. "2d 5h", "1h 12min"). */
$remaining = static function (int $ts): string {
    $d = $ts - time();
    if ($d <= 0) {
        return 'wygasa';
    }
    $days = intdiv($d, 86400);
    $h    = intdiv($d % 86400, 3600);
    $m    = intdiv($d % 3600, 60);
    if ($days > 0) {
        return $days . 'd ' . $h . 'h';
    }
    if ($h > 0) {
        return $h . 'h ' . $m . 'min';
    }
    return max(1, $m) . ' min';
};

if ($bans === []): ?>
<tr class="empty-row"><td colspan="5" class="empty-state-text">Brak aktywnych blokad (SQL oraz Fail2Ban).</td></tr>
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
        <?= $untilEpoch !== null ? 'title="do ' . Html::e(date('Y-m-d H:i:s', $untilEpoch)) . '"' : '' ?>>
        <?= Html::e($b['until']) ?>
        <?php if ($untilEpoch !== null): ?>
            <div class="ban-remaining">(pozostało <?= Html::e($remaining($untilEpoch)) ?>)</div>
        <?php endif; ?>
    </td>
    <td>
        <button type="button" class="btn-action btn-manage"
                data-action="manage-ban"
                data-ip="<?= Html::e((string) $b['ip_address']) ?>"
                data-type="<?= Html::e($b['type']) ?>"
                data-jail="<?= Html::e((string) $b['jail']) ?>"
                aria-label="Zarządzaj blokadą <?= Html::e((string) $b['ip_address']) ?>">
            <i class="ri-settings-3-line" aria-hidden="true"></i> Zarządzaj
        </button>
    </td>
</tr>
<?php endforeach; ?>
<?php endif; ?>
