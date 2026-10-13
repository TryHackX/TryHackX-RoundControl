<?php
declare(strict_types=1);

use App\Support\Html;
use App\Support\Ip;

/** @var array<int,array> $rows */
if ($rows === []): ?>
<tr class="empty-row"><td colspan="5" class="empty-state-text">Brak zdarzeń.</td></tr>
<?php endif;
foreach ($rows as $l):
    $ip = Ip::toStr($l['ip']) ?? '';
?>
<tr>
    <td><?= Html::e($l['created_at']) ?></td>
    <td><button type="button" class="badge-ip" data-action="search-ip" data-ip="<?= Html::e($ip) ?>" title="Szukaj kont z tym IP"><?= Html::e($ip) ?></button></td>
    <td><?= Html::e($l['email']) ?></td>
    <td>
        <?php if ($l['status'] === 'success'): ?>
            <span class="badge badge-success">OK</span>
        <?php else: ?>
            <span class="badge badge-danger"><?= Html::e($l['status']) ?></span>
        <?php endif; ?>
    </td>
    <td><?= Html::e($l['reason']) ?></td>
</tr>
<?php endforeach; ?>
