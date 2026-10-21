<?php
declare(strict_types=1);

use App\Support\Html;

use function App\I18n\h;

/**
 * Wiersze tabeli języków (Ustawienia → Języki interfejsu). Renderowane przez View::render przy wczytaniu strony i po
 * każdej operacji (odpowiedź JSON: data.languages_html).
 *
 * @var array{rows:list<array<string,mixed>>,dir:string,dirOk:bool,default:string} $lang
 * @var string $csrf
 */
$hidden = '<input type="hidden" name="_csrf" value="' . Html::e($csrf) . '"><input type="hidden" name="_tab" value="settings">';
foreach ($lang['rows'] as $r):
    $c = Html::e($r['code']);
?>
<tr data-code="<?= $c ?>">
    <td><span class="badge-type-local"><?= Html::e(strtoupper($r['code'])) ?></span></td>
    <td lang="<?= $c ?>"><?= Html::e($r['name']) ?>
        <?php if ($r['builtIn']): ?><span class="lang-builtin" title="<?= h('panel.lang.builtin_title') ?>"><?= h('panel.lang.builtin') ?></span><?php endif; ?>
    </td>
    <td>
        <div class="lang-cov" title="<?= h('panel.lang.coverage_title', ['missing' => (int) $r['missing']]) ?>">
            <div class="lang-cov-bar" aria-hidden="true"><span style="width: <?= (int) $r['percent'] ?>%"></span></div>
            <span class="lang-cov-n"><?= (int) $r['percent'] ?>%</span>
        </div>
    </td>
    <td>
        <form method="POST" data-ajax class="inline-form">
            <?= $hidden ?><input type="hidden" name="lang_op" value="toggle"><input type="hidden" name="code" value="<?= $c ?>">
            <button type="submit" class="btn btn-small <?= $r['enabled'] ? 'btn-success-action' : 'btn-secondary' ?>" aria-pressed="<?= $r['enabled'] ? 'true' : 'false' ?>"
                <?= $r['default'] ? 'disabled title="' . h('panel.lang.default_always_on') . '"' : 'title="' . h($r['enabled'] ? 'panel.lang.turn_off' : 'panel.lang.turn_on') . '"' ?>>
                <?= $r['enabled'] ? h('panel.lang.on') : h('panel.lang.off') ?>
            </button>
        </form>
    </td>
    <td>
        <?php if ($r['default']): ?>
            <span class="badge badge-success"><?= h('panel.lang.is_default') ?></span>
        <?php else: ?>
            <form method="POST" data-ajax class="inline-form">
                <?= $hidden ?><input type="hidden" name="lang_op" value="default"><input type="hidden" name="code" value="<?= $c ?>">
                <button type="submit" class="btn btn-small btn-secondary"><?= h('panel.lang.make_default') ?></button>
            </form>
        <?php endif; ?>
    </td>
    <td class="lang-actions">
        <a class="btn btn-xs btn-secondary" href="?lang_export=<?= $c ?>" download="roundcontrol-lang-<?= $c ?>.json"
           title="<?= h('panel.lang.download') ?>" aria-label="<?= h('panel.lang.download_aria', ['code' => $r['code']]) ?>"><i class="ri-download-2-line" aria-hidden="true"></i></a>
        <?php if (!$r['builtIn'] && !$r['default']): ?>
            <form method="POST" data-ajax data-confirm="generic" class="inline-form"
                  data-confirm-title="<?= h('panel.lang.del.title') ?>" data-confirm-ok="<?= h('panel.lang.del.ok') ?>"
                  data-confirm-busy="<?= h('panel.lang.del.busy') ?>"
                  data-confirm-msg="<?= h('panel.lang.del.msg', ['name' => $r['name'] . ' (' . $r['code'] . ')']) ?>">
                <?= $hidden ?><input type="hidden" name="lang_op" value="delete"><input type="hidden" name="code" value="<?= $c ?>">
                <button type="submit" class="btn btn-xs btn-danger" title="<?= h('panel.lang.delete') ?>"
                        aria-label="<?= h('panel.lang.delete_aria', ['code' => $r['code']]) ?>"><i class="ri-delete-bin-line" aria-hidden="true"></i></button>
            </form>
        <?php endif; ?>
    </td>
</tr>
<?php endforeach; ?>
