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
    <td><strong><?= Html::e($l['admin_email']) ?></strong></td>
    <td><?= Html::e($ip) ?></td>
    <td><span class="badge badge-type-local"><?= Html::e($l['action_type']) ?></span></td>
    <td><?= Html::e($l['details']) ?></td>
</tr>
<?php endforeach; ?>
