<?php
declare(strict_types=1);

use App\Support\Html;
use App\Panel\Quota;

/** @var array<int,array> $users */
if ($users === []): ?>
<tr class="empty-row"><td colspan="9" class="empty-state-text">Brak kont spełniających warunki wyszukiwania.</td></tr>
<?php endif;
foreach ($users as $u):
    $mb = (int) $u['quota_mb'];
    $id = (int) $u['id'];
    $em = Html::e($u['email']);
    $flags = [];
    if (!empty($u['first_login_ip'])) $flags[] = ['ip' => $u['first_login_ip'], 'cls' => 'badge-ip badge-ip-first', 'title' => 'IP pierwszego logowania'];
    if (!empty($u['prev_login_ip']))  $flags[] = ['ip' => $u['prev_login_ip'],  'cls' => 'badge-ip badge-ip-prev',  'title' => 'IP poprzedniego logowania'];
    if (!empty($u['last_login_ip']))  $flags[] = ['ip' => $u['last_login_ip'],  'cls' => 'badge-ip badge-ip-last',  'title' => 'IP ostatniego logowania'];
?>
<tr data-id="<?= $id ?>">
    <td><?= $id ?></td>
    <td><strong><?= $em ?></strong></td>
    <td><?= Html::e($u['created']) ?></td>
    <td><?= Html::e($u['last_login']) ?></td>
    <td>
        <?php if (!empty($u['reg_ip'])): ?>
            <button type="button" class="badge-ip badge-ip-reg" title="IP rejestracji — kliknij, aby wyszukać (Shift: dodaj do wyszukiwania)" data-action="search-ip" data-ip="<?= Html::e($u['reg_ip']) ?>"><?= Html::e($u['reg_ip']) ?></button>
        <?php else: ?>
            <span class="text-placeholder">-</span>
        <?php endif; ?>
    </td>
    <td class="ips-cell">
        <?php if ($flags): ?>
            <div class="ip-stack">
                <?php foreach ($flags as $f): ?>
                    <button type="button" class="<?= Html::e($f['cls']) ?>" title="<?= Html::e($f['title']) ?> — kliknij, aby wyszukać" data-action="search-ip" data-ip="<?= Html::e($f['ip']) ?>"><?= Html::e($f['ip']) ?></button>
                <?php endforeach; ?>
            </div>
        <?php else: ?>
            <span class="text-placeholder">-</span>
        <?php endif; ?>
    </td>
    <td>
        <div class="quota-dis">
            <span class="quota-display"><?= Html::e(Quota::display($mb)) ?></span>
            <button type="button" class="btn btn-xs ms-1" data-action="quota" data-id="<?= $id ?>" data-email="<?= $em ?>" data-quota="<?= $mb ?>" data-send-limit="<?= $u['daily_send_limit'] === null ? '' : (int) $u['daily_send_limit'] ?>" aria-label="Zmień quota i limit wysyłki: <?= $em ?>" title="Zmień quota i limit wysyłki"><i class="ri-edit-line" aria-hidden="true"></i></button>
        </div>
    </td>
    <td class="usage-cell" data-email="<?= $em ?>"><span class="usage-wait">…</span></td>
    <td>
        <div class="actions-col">
            <button type="button" class="btn btn-small btn-secondary" data-action="reset-pass" data-id="<?= $id ?>" data-email="<?= $em ?>" aria-label="Zmień hasło: <?= $em ?>"><i class="ri-key-2-line" aria-hidden="true"></i> Hasło</button>
            <button type="button" class="btn btn-small btn-warning" data-action="ban" data-email="<?= $em ?>" aria-label="Zbanuj IP konta <?= $em ?>"><i class="ri-forbid-line" aria-hidden="true"></i> Ban</button>
            <button type="button" class="btn btn-danger btn-small" data-action="delete" data-id="<?= $id ?>" data-email="<?= $em ?>" aria-label="Usuń konto <?= $em ?>"><i class="ri-delete-bin-line" aria-hidden="true"></i> Usuń</button>
        </div>
    </td>
</tr>
<?php endforeach; ?>
