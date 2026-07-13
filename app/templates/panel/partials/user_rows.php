<?php
declare(strict_types=1);

use App\Support\Html;
use App\Panel\Quota;

/** @var array<int,array> $users */
foreach ($users as $u):
    $mb = (int) $u['quota_mb'];
    $flags = [];
    if (!empty($u['first_login_ip'])) $flags[] = ['ip' => $u['first_login_ip'], 'cls' => 'badge-ip badge-ip-first', 'title' => 'IP pierwszego logowania'];
    if (!empty($u['prev_login_ip']))  $flags[] = ['ip' => $u['prev_login_ip'],  'cls' => 'badge-ip badge-ip-prev',  'title' => 'IP poprzedniego logowania'];
    if (!empty($u['last_login_ip']))  $flags[] = ['ip' => $u['last_login_ip'],  'cls' => 'badge-ip badge-ip-last',  'title' => 'IP ostatniego logowania'];
?>
<tr>
    <td><?= (int) $u['id'] ?></td>
    <td><strong><?= Html::e($u['email']) ?></strong></td>
    <td><?= Html::e($u['created']) ?></td>
    <td><?= Html::e($u['last_login']) ?></td>
    <td>
        <?php if (!empty($u['reg_ip'])): ?>
            <span class="badge-ip badge-ip-reg" title="IP Rejestracji" data-action="search-ip" data-ip="<?= Html::e($u['reg_ip']) ?>"><?= Html::e($u['reg_ip']) ?></span>
        <?php else: ?>
            <span class="text-placeholder">-</span>
        <?php endif; ?>
    </td>
    <td class="ips-cell">
        <?php if ($flags): ?>
            <div class="ip-stack">
                <?php foreach ($flags as $f): ?>
                    <span class="<?= Html::e($f['cls']) ?>" title="<?= Html::e($f['title']) ?>" data-action="search-ip" data-ip="<?= Html::e($f['ip']) ?>"><?= Html::e($f['ip']) ?></span>
                <?php endforeach; ?>
            </div>
        <?php else: ?>
            <span class="text-placeholder">-</span>
        <?php endif; ?>
    </td>
    <td>
        <div class="quota-dis">
            <span class="quota-display"><?= Html::e(Quota::display($mb)) ?></span>
            <button class="btn btn-xs ms-1" data-action="quota" data-id="<?= (int) $u['id'] ?>" data-email="<?= Html::e($u['email']) ?>" data-quota="<?= $mb ?>" data-send-limit="<?= $u['daily_send_limit'] === null ? '' : (int) $u['daily_send_limit'] ?>"><i class="ri-edit-line"></i></button>
        </div>
    </td>
    <td class="usage-cell" data-email="<?= Html::e($u['email']) ?>"><span class="usage-wait">…</span></td>
    <td>
        <div class="actions-col">
            <button class="btn btn-small btn-secondary" data-action="reset-pass" data-id="<?= (int) $u['id'] ?>" data-email="<?= Html::e($u['email']) ?>"><i class="ri-key-2-line"></i> Hasło</button>
            <button class="btn btn-small btn-warning" data-action="ban" data-email="<?= Html::e($u['email']) ?>"><i class="ri-forbid-line"></i> Ban</button>
            <button class="btn btn-danger btn-small" data-action="delete" data-id="<?= (int) $u['id'] ?>" data-email="<?= Html::e($u['email']) ?>"><i class="ri-delete-bin-line"></i> Usuń</button>
        </div>
    </td>
</tr>
<?php endforeach; ?>
