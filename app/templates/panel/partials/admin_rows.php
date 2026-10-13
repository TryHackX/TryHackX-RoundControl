<?php
declare(strict_types=1);

use App\Support\Html;
use App\Support\Ip;

/** @var array<int,array> $rows */
if ($rows === []): ?>
<tr class="empty-row"><td colspan="5" class="empty-state-text">Brak wpisów.</td></tr>
<?php endif;
foreach ($rows as $al):
    $ip = Ip::toStr($al['ip']) ?? '';
?>
<tr>
    <td><?= Html::e($al['created_at']) ?></td>
    <td><strong><?= Html::e($al['username']) ?></strong></td>
    <td><button type="button" class="badge-ip" data-action="ban-ip" data-ip="<?= Html::e($ip) ?>" title="Kliknij, aby zbanować"><?= Html::e($ip) ?></button></td>
    <td>
        <?php if ($al['success']): ?>
            <span class="badge badge-success">Sukces</span>
        <?php else: ?>
            <span class="badge badge-danger">Błąd</span>
        <?php endif; ?>
    </td>
    <td><?= Html::e($al['details']) ?></td>
</tr>
<?php endforeach; ?>
