<?php
declare(strict_types=1);

use App\Support\Html;
use App\Support\Ip;

use function App\I18n\h;

/** @var array<int,array> $rows */
if ($rows === []): ?>
<tr class="empty-row"><td colspan="5" class="empty-state-text"><?= h('rows.empty_events') ?></td></tr>
<?php endif;
foreach ($rows as $l):
    $ip = Ip::toStr($l['ip']) ?? '';
?>
<tr>
    <td><?= Html::e($l['created_at']) ?></td>
    <td><button type="button" class="badge-ip" data-action="search-ip" data-ip="<?= Html::e($ip) ?>" title="<?= h('rows.search_ip_title') ?>"><?= Html::e($ip) ?></button></td>
    <td><?= Html::e($l['email']) ?></td>
    <td>
        <?php if ($l['status'] === 'success'): ?>
            <span class="badge badge-success"><?= h('rows.ok') ?></span>
        <?php else: ?>
            <span class="badge badge-danger"><?= Html::e($l['status']) ?></span>
        <?php endif; ?>
    </td>
    <td><?= Html::e($l['reason']) ?></td>
</tr>
<?php endforeach; ?>
