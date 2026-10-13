<?php
declare(strict_types=1);

use App\Support\Html;
use App\Support\Ip;

/** @var array<int,array> $rows */
if ($rows === []): ?>
<tr class="empty-row"><td colspan="5" class="empty-state-text">Brak wpisów.</td></tr>
<?php endif;
foreach ($rows as $l):
    $ip   = Ip::toStr($l['ip']) ?? '';
    $type = (string) $l['action_type'];
    // nieudane potwierdzenia hasłem (1.2.0) wyróżnione — łatwo je wyłowić wzrokiem i wyszukać ("REAUTH")
    $cls  = str_starts_with($type, 'REAUTH') ? 'badge badge-danger' : 'badge-type-local';
?>
<tr>
    <td><?= Html::e($l['created_at']) ?></td>
    <td><strong><?= Html::e($l['admin_email']) ?></strong></td>
    <td><?= Html::e($ip) ?></td>
    <td><span class="<?= $cls ?>"><?= Html::e($type) ?></span></td>
    <td><?= Html::e($l['details']) ?></td>
</tr>
<?php endforeach; ?>
