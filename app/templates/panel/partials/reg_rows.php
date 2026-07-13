<?php
declare(strict_types=1);

use App\Support\Html;
use App\Support\Ip;

/** @var array<int,array> $rows */
foreach ($rows as $l):
    $ip = Ip::toStr($l['ip']) ?? '';
?>
<tr>
    <td><?= Html::e($l['created_at']) ?></td>
    <td><span class="badge-ip" data-action="search-ip" data-ip="<?= Html::e($ip) ?>"><?= Html::e($ip) ?></span></td>
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
