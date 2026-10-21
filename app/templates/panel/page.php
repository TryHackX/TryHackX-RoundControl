<?php
declare(strict_types=1);

use App\I18n\Lang;
use App\Support\Html;
use function App\I18n\h, App\I18n\html, App\I18n\t;

/**
 * @var string $success
 * @var string $error
 * @var string $csrf
 * @var string $admin
 * @var string $theme     dark | light (ciasteczko mp_theme — motyw bez mignięcia przy wczytaniu)
 * @var array  $stats
 * @var array  $bans
 * @var array  $lists     users|reg|admin|audit => [rows, pages, total] — pierwsza strona każdej listy
 * @var array  $f2b       ['available'=>bool,'values'=>array]
 * @var array  $f2bFields nazwa pola => [sekcja, klucz, domyślna, rodzaj]
 * @var bool   $f2bAvail
 * @var bool   $pfAvail
 * @var bool   $delAvail
 * @var int    $reauthLimit
 * @var array  $settings
 */
$s  = $settings;
$fv = static fn(string $name): string => Html::e((string) ($f2b['values'][$f2bFields[$name][0]][$f2bFields[$name][1]] ?? $f2bFields[$name][2]));
$fc = static fn(string $name): bool => (string) ($f2b['values'][$f2bFields[$name][0]][$f2bFields[$name][1]] ?? $f2bFields[$name][2]) === 'true';
/** URL assetu ze skrótem pliku (zmiana pliku = nowy adres) — bez numeru wersji (ten jest tylko w stopce panelu). */
$av = static fn(string $f): string => '../assets/' . $f . '?v=' . \App\Support\View::assetTag(dirname(__DIR__, 3) . '/assets/' . $f);
/* Nazwy zakładek: teksty (surowe) w bieżącym języku; escapowane przy wypisaniu. */
$tabs = [
    'settings' => ['ri-settings-3-line', t('panel.tabs.settings')],
    'users'    => ['ri-user-line', t('panel.tabs.users')],
    'bans'     => ['ri-prohibited-line', t('panel.tabs.bans')],
    'logs'     => ['ri-file-list-line', t('panel.tabs.logs')],
    'fail2ban' => ['ri-fire-line', t('panel.tabs.fail2ban')],
    'security' => ['ri-lock-password-line', t('panel.tabs.security')],
];
$tab = (string) ($_GET['tab'] ?? 'settings');
$tab = isset($tabs[$tab]) ? $tab : 'settings';
$panel = static fn(string $id): string => 'id="' . $id . '" class="tab-content' . ($id === $tab ? ' active' : '') . '" role="tabpanel" aria-labelledby="tab-' . $id . '" tabindex="-1"';
$close = static fn(string $modal): string => '<button type="button" class="modal-close" data-action="modal-close" data-modal="' . $modal . '" aria-label="' . h('panel.modal.close') . '"><i class="ri-close-line" aria-hidden="true"></i></button>';
$perSel = static fn(string $id, string $nav): string => '<label for="' . $id . '">' . h('panel.per_page') . '</label><select id="' . $id . '" data-per="' . $nav . '">'
    . '<option value="10">10</option><option value="20" selected>20</option><option value="50">50</option><option value="100">100</option></select>';
/**
 * Nagłówek sortowalnej kolumny: etykieta w jednej linii + wskaźnik o stałej szerokości (ikona; numer priorytetu
 * tylko przy sortowaniu po kilku kolumnach). $full = pełna nazwa, gdy etykieta jest skrótem (podpowiedź <abbr>).
 * Etykiety podawać jako teksty SUROWE (t()) — funkcja sama je escapuje.
 * The sort control is a <button> in the cell (WAI-ARIA sortable table; aria-sort stays on the <th>), so $extra (the
 * message-count sort) sits next to it instead of inside a focusable cell.
 */
$th = static function (string $nav, string $col, string $label, string $sorted = '', string $extra = '', string $title = '', string $full = ''): string {
    $ind  = $sorted === '' ? '<i class="ri-arrow-up-down-line sort-placeholder"></i>'
        : '<i class="' . ($sorted === 'ASC' ? 'ri-arrow-up-line' : 'ri-arrow-down-line') . ' contrast-color"></i>';
    $aria = $sorted === '' ? '' : ' aria-sort="' . ($sorted === 'ASC' ? 'ascending' : 'descending') . '"';
    $text = $full !== '' ? '<abbr title="' . Html::e($full) . '">' . Html::e($label) . '</abbr>' : Html::e($label);
    return '<th class="sortable" scope="col" data-nav="' . $nav . '" data-col="' . $col . '" data-label="' . Html::e($full ?: $label) . '"' . $aria
        . '><span class="th-inner"><button type="button" class="th-sort"' . ($title !== '' ? ' title="' . Html::e($title) . '"' : '') . '><span class="th-label">' . $text
        . '</span><span class="sort-ind" aria-hidden="true">' . $ind . '</span></button>' . $extra . '</span></th>';
};
/* Użycie: klik w nagłówek = sortuj po zajętym miejscu; ikonka koperty = po liczbie wiadomości (obie w obrębie strony —
   dane z Dovecot). */
$qsort = '<button type="button" class="qsort-ico" data-qsort="msgs" aria-pressed="false" aria-label="' . h('panel.users.qsort.label') . '"'
    . ' title="' . h('panel.users.qsort.title') . '"><i class="ri-mail-line" aria-hidden="true"></i><span class="qsort-n"></span></button>';
/* Podpowiedź o limicie wysyłki (okna Quota i Nowe konto): liczbę podmienia JS (data-bind), dlatego <b> z atrybutem jest składany tutaj. */
$sendHelp = strtr(html('panel.modal.send_limit.help'), [':limit' => '<b data-bind="daily_send_limit">' . (int) $s['daily_send_limit'] . '</b>']);
?>
<!DOCTYPE html>
<html lang="<?= Html::e(Lang::current()) ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="color-scheme" content="dark light">
<title><?= h('panel.title') ?></title>
<link href="<?= $av('vendor/remixicon/remixicon.css') ?>" rel="stylesheet">
<link rel="stylesheet" href="<?= $av('design-system.css') ?>">
<link rel="stylesheet" href="<?= $av('panel.css') ?>">
</head>
<body class="<?= $theme === 'light' ? '' : 'dark-mode' ?>" data-csrf="<?= Html::e($csrf) ?>"
      data-pwd-min="<?= (int) $pwd['min'] ?>"
      data-pwd-upper="<?= (int) $pwd['upper'] ?>"
      data-pwd-digit="<?= (int) $pwd['digit'] ?>"
      data-pwd-spec="<?= (int) $pwd['spec'] ?>">

<a class="skip-link" href="#main"><?= h('panel.skip_link') ?></a>

<header class="header">
    <div class="header-content">
        <h1><i class="ri-shield-keyhole-line" aria-hidden="true"></i> <?= h('panel.title') ?></h1>
        <div class="header-actions">
            <span class="admin-info"><?= html('panel.header.logged_in', ['name' => $admin]) ?></span>
            <form method="post" action="logout.php" class="logout-form">
                <input type="hidden" name="_csrf" value="<?= Html::e($csrf) ?>">
                <button type="submit" class="logout-link"><?= h('panel.header.logout') ?></button>
            </form>
            <?php /* język tuż obok motywu; #langSwitchSlot odświeża panel.js po zmianach w "Języki interfejsu" */ ?>
            <div class="header-tools">
                <span id="langSwitchSlot" class="lang-switch-slot"><?php include __DIR__ . '/../partials/lang_switch.php'; ?></span>
                <button type="button" class="theme-toggle-btn" id="themeToggle" aria-label="<?= h('panel.header.theme_label') ?>" aria-pressed="<?= $theme === 'light' ? 'true' : 'false' ?>" title="<?= h('panel.header.theme_title') ?>"><i class="<?= $theme === 'light' ? 'ri-sun-line' : 'ri-moon-line' ?>" id="themeIcon" aria-hidden="true"></i></button>
            </div>
        </div>
    </div>
</header>

<div id="session-banner" class="session-banner" role="alert" hidden>
    <i class="ri-time-line" aria-hidden="true"></i> <span id="session-banner-text"><?= h('panel.session.expired') ?></span>
    <a href="../portal.php" class="btn btn-small"><?= h('panel.session.relogin') ?></a>
</div>

<main class="container" id="main">
    <noscript><div class="alert alert-error"><?= h('panel.noscript') ?></div></noscript>
    <?php /* komunikat po zapisie BEZ JavaScriptu (PRG); z JavaScriptem komunikaty pokazuje panel.js przy zapisanym formularzu */ ?>
    <?php if ($success !== ''): ?><div class="alert alert-success" role="status"><?= Html::e($success) ?></div><?php endif; ?>
    <?php if ($error !== ''): ?><div class="alert alert-error" role="alert"><?= Html::e($error) ?></div><?php endif; ?>

    <div class="stats">
        <div class="stat-card"><h3><?= h('panel.stats.users') ?></h3><div class="value" id="stat-users"><?= (int) $stats['total_users'] ?></div></div>
        <div class="stat-card"><h3><?= h('panel.stats.today') ?></h3><div class="value" id="stat-today"><?= (int) $stats['today_registrations'] ?></div></div>
        <div class="stat-card"><h3><?= h('panel.stats.banned_ips') ?></h3><div class="value" id="stat-banned-ips"><?= (int) $stats['banned_ips'] ?></div></div>
        <div class="stat-card"><h3><?= h('panel.stats.failed') ?></h3><div class="value" id="stat-failed"><?= (int) $stats['failed_logins_24h'] ?></div></div>
    </div>

    <div class="tabs" role="tablist" aria-label="<?= h('panel.tabs.label') ?>">
        <?php foreach ($tabs as $id => [$icon, $label]): ?>
            <button type="button" class="tab<?= $id === $tab ? ' active' : '' ?>" role="tab" id="tab-<?= $id ?>" data-tab="<?= $id ?>"
                    aria-controls="<?= $id ?>" aria-selected="<?= $id === $tab ? 'true' : 'false' ?>" tabindex="<?= $id === $tab ? '0' : '-1' ?>"><i class="<?= $icon ?>" aria-hidden="true"></i> <?= Html::e($label) ?></button>
        <?php endforeach; ?>
    </div>
    <!-- Komunikaty po akcjach z okien i list: wygląd alertu, nakładka przyklejona pod zakładkami
         (wysokość 0 — nie przesuwa treści), znika sama. Zapisy formularzy w kartach pokazują komunikat w swojej karcie. -->
    <div id="flash-dock" class="flash-dock" aria-live="polite"></div>
    <?php /* list totals for screen readers, only after a search, page, sort or refresh the admin asked for (panel.js announceList) */ ?>
    <div id="list-status" class="sr-only" role="status"></div>

    <!-- USTAWIENIA -->
    <section <?= $panel('settings') ?>>
        <h2><i class="ri-hard-drive-2-line" aria-hidden="true"></i> <?= h('panel.settings.title') ?></h2>
        <form method="POST" data-ajax data-confirm-check="apply_quota_to_all">
            <input type="hidden" name="_csrf" value="<?= Html::e($csrf) ?>">
            <input type="hidden" name="_tab" value="settings">
            <input type="hidden" name="update_settings" value="1">
            <div class="settings-grid">
                <div class="settings-card">
                    <div class="settings-card-header"><i class="ri-mail-settings-line" aria-hidden="true"></i> <?= h('panel.settings.mail.title') ?></div>
                    <div class="settings-card-body">
                        <div class="form-group">
                            <label for="set_quota_value"><?= h('panel.settings.quota.label') ?></label>
                            <div class="quota-input-group">
                                <input type="number" id="set_quota_value" name="mailbox_quota_value" step="0.01" min="0" value="<?= Html::e((string) $s['mailbox_quota_value']) ?>">
                                <select name="mailbox_quota_unit" aria-label="<?= h('panel.settings.quota.unit') ?>">
                                    <option value="KiB" <?= $s['mailbox_quota_unit'] === 'KiB' ? 'selected' : '' ?>>KiB</option>
                                    <option value="MiB" <?= $s['mailbox_quota_unit'] === 'MiB' ? 'selected' : '' ?>>MiB</option>
                                    <option value="GiB" <?= $s['mailbox_quota_unit'] === 'GiB' ? 'selected' : '' ?>>GiB</option>
                                </select>
                            </div>
                            <div class="checkbox-warning-wrapper">
                                <input type="checkbox" name="apply_quota_to_all" id="apply_quota_check">
                                <label for="apply_quota_check"><?= h('panel.settings.quota.apply_all') ?></label>
                            </div>
                        </div>
                        <div class="form-group">
                            <label for="set_daily_limit"><?= h('panel.settings.send_limit.label') ?></label>
                            <input type="number" id="set_daily_limit" name="daily_send_limit" min="0" value="<?= (int) $s['daily_send_limit'] ?>">
                            <div class="help-text"><?= html('panel.settings.send_limit.help') ?></div>
                        </div>
                    </div>
                </div>

                <div class="settings-card">
                    <div class="settings-card-header"><i class="ri-user-add-line" aria-hidden="true"></i> <?= h('panel.settings.reg.title') ?></div>
                    <div class="settings-card-body">
                        <div class="form-group flex-align-center">
                            <input type="checkbox" name="registration_enabled" id="reg_en" <?= $s['registration_enabled'] === '1' ? 'checked' : '' ?>>
                            <label for="reg_en" class="no-margin"><?= h('panel.settings.reg.enabled') ?></label>
                        </div>
                        <div class="form-group">
                            <label for="set_acc_ip"><?= h('panel.settings.reg.per_ip') ?></label>
                            <div class="flex-row-center">
                                <input type="number" id="set_acc_ip" name="accounts_per_ip" min="1" value="<?= (int) $s['accounts_per_ip'] ?>">
                                <span aria-hidden="true">/</span>
                                <input type="number" name="ip_window_hours" min="1" value="<?= (int) $s['ip_window_hours'] ?>" aria-label="<?= h('panel.settings.reg.window_hours') ?>">
                                <span><?= h('panel.settings.reg.hours_short') ?></span>
                            </div>
                        </div>
                        <div class="form-group">
                            <label for="set_ip_total"><?= h('panel.settings.reg.ip_total') ?></label>
                            <input type="number" id="set_ip_total" name="ip_total_limit" min="1" value="<?= (int) $s['ip_total_limit'] ?>">
                            <div class="help-text"><?= h('panel.settings.reg.ip_help') ?></div>
                        </div>
                        <div class="form-group">
                            <label for="set_reserved"><?= h('panel.settings.reserved.label') ?></label>
                            <textarea id="set_reserved" name="reserved_local_parts" rows="3" spellcheck="false"><?= Html::e((string) $s['reserved_local_parts']) ?></textarea>
                            <div class="help-text"><?= html('panel.settings.reserved.help', ['base' => implode(', ', $s['reserved_base'])]) ?></div>
                        </div>
                        <div class="form-group">
                            <label for="set_hold"><?= h('panel.settings.hold.label') ?></label>
                            <input type="number" id="set_hold" name="deleted_name_hold_days" min="0" max="3650" value="<?= (int) $s['deleted_name_hold_days'] ?>">
                            <div class="help-text"><?= html('panel.settings.hold.help') ?></div>
                        </div>
                    </div>
                </div>

                <?php
                $cap  = $s['captcha'];
                $kept = h('panel.captcha.kept');
                $docs = [
                    'recaptcha'    => ['https://www.google.com/recaptcha/admin', t('panel.captcha.docs.recaptcha')],
                    'recaptcha_v3' => ['https://www.google.com/recaptcha/admin', t('panel.captcha.docs.recaptcha_v3')],
                    'turnstile'    => ['https://dash.cloudflare.com/?to=/:account/turnstile', t('panel.captcha.docs.turnstile')],
                    'hcaptcha'     => ['https://dashboard.hcaptcha.com/sites', t('panel.captcha.docs.hcaptcha')],
                ];
                ?>
                <div class="settings-card">
                    <div class="settings-card-header"><i class="ri-shield-check-line" aria-hidden="true"></i> <?= h('panel.captcha.title') ?></div>
                    <div class="settings-card-body">
                        <div class="form-group flex-row-center">
                            <input type="checkbox" name="recaptcha_enabled" id="rc_en" <?= $cap['enabled'] ? 'checked' : '' ?>>
                            <label for="rc_en" class="no-margin"><?= h('panel.captcha.enabled') ?></label>
                        </div>
                        <div class="form-group">
                            <label for="set_captcha_provider"><?= h('panel.captcha.provider') ?></label>
                            <select id="set_captcha_provider" name="captcha_provider" data-captcha-switch>
                                <?php foreach (\App\Security\Captcha::PROVIDERS as $pid => $plabel): ?>
                                    <option value="<?= $pid ?>" <?= $cap['provider'] === $pid ? 'selected' : '' ?>><?= h($plabel) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <div class="help-text"><?= h('panel.captcha.provider_help') ?></div>
                        </div>
                        <?php foreach (\App\Security\Captcha::PROVIDERS as $pid => $plabel):
                            $k = $cap['keys'][$pid]; ?>
                        <div class="captcha-keys" data-captcha-for="<?= $pid ?>"<?= $cap['provider'] === $pid ? '' : ' data-inactive' ?>>
                            <div class="form-group">
                                <label for="set_<?= $pid ?>_site"><?= h('panel.captcha.site_key') ?></label>
                                <input type="text" id="set_<?= $pid ?>_site" name="<?= $pid ?>_site_key" value="<?= Html::e($k['site']) ?>" autocomplete="off" spellcheck="false">
                            </div>
                            <div class="form-group">
                                <label for="set_<?= $pid ?>_secret"><?= h('panel.captcha.secret_key') ?></label>
                                <input type="password" name="<?= $pid ?>_secret_key" value="" id="set_<?= $pid ?>_secret" autocomplete="new-password"
                                       data-placeholder-set="<?= $kept ?>" placeholder="<?= $k['secret_set'] ? $kept : h('panel.captcha.paste_secret') ?>">
                            </div>
                            <?php if ($pid === 'recaptcha_v3'): ?>
                            <div class="form-group">
                                <label for="set_v3_score"><?= h('panel.captcha.min_score') ?></label>
                                <input type="number" id="set_v3_score" name="recaptcha_v3_min_score" min="0" max="1" step="0.1" value="<?= Html::e($cap['min_score']) ?>">
                                <div class="help-text"><?= h('panel.captcha.min_score_help') ?></div>
                            </div>
                            <?php endif; ?>
                            <?php /* :link w tekście = odnośnik do konsoli dostawcy (target/rel składane tutaj — pliki języka dopuszczają w <a> tylko href) */ ?>
                            <div class="help-text"><?= strtr(h('panel.captcha.keys_help'), [':link' => '<a href="' . Html::e($docs[$pid][0]) . '" target="_blank" rel="noopener noreferrer">' . Html::e($docs[$pid][1]) . '</a>']) ?></div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
            <div class="form-actions mt-4"><button type="submit" name="update_settings" class="btn"><i class="ri-save-line" aria-hidden="true"></i> <?= h('panel.settings.save') ?></button></div>
        </form>
        <?php include __DIR__ . '/partials/lang_card.php'; ?>
        <?php include __DIR__ . '/partials/retention_card.php'; ?>
    </section>

    <!-- UŻYTKOWNICY -->
    <section <?= $panel('users') ?>>
        <div class="tab-head">
            <h2><i class="ri-user-line" aria-hidden="true"></i> <?= h('panel.users.title') ?></h2>
            <button type="button" class="btn" data-action="add-user"><i class="ri-user-add-line" aria-hidden="true"></i> <?= h('panel.users.add') ?></button>
        </div>
        <div class="search-bar">
            <div class="adv-search">
                <input type="search" id="user-search-input" autocomplete="off" aria-label="<?= h('panel.users.search.label') ?>" aria-describedby="user-search-hint"
                       placeholder="<?= h('panel.users.search.placeholder') ?>">
                <button type="button" class="adv-clear" data-adv-clear="user-search-input" aria-label="<?= h('panel.search.clear') ?>" hidden><i class="ri-close-line" aria-hidden="true"></i></button>
            </div>
        </div>
        <div class="help-text search-hint" id="user-search-hint"><?= html('panel.users.search.hint') ?></div>
        <div class="users-topbar">
            <div>
                <span id="total-results-text"><?= html('panel.users.total', ['n' => (int) $lists['users']['total']]) ?></span>
                <span id="users-page-filter" class="page-filter-note" aria-live="polite" hidden></span>
                <span class="sort-hint" title="<?= h('panel.users.sort_hint_title') ?>"><i class="ri-information-line" aria-hidden="true"></i> <?= h('panel.users.sort_hint') ?></span>
            </div>
            <div class="users-topbar-right"><?= $perSel('perPage', 'users') ?></div>
        </div>
        <div class="table-responsive" data-list="users">
            <table>
                <thead><tr>
                    <?= $th('users', 'id', t('panel.users.th.id'), 'DESC') ?>
                    <?= $th('users', 'email', t('panel.users.th.email')) ?>
                    <?= $th('users', 'created', t('panel.users.th.created')) ?>
                    <?= $th('users', 'last_login', t('panel.users.th.last_login'), '', '', '', t('panel.users.th.last_login_full')) ?>
                    <?= $th('users', 'reg_ip', t('panel.users.th.reg_ip'), '', '', '', t('panel.users.th.reg_ip_full')) ?>
                    <th scope="col"><span class="th-inner"><?= h('panel.users.th.last_ip') ?></span></th>
                    <?= $th('users', 'quota', t('panel.users.th.quota')) ?>
                    <?= $th('users', 'usage', t('panel.users.th.usage'), '', $qsort, t('panel.users.th.usage_title')) ?>
                    <th scope="col" class="col-actions"><span class="th-inner"><?= h('panel.users.th.actions') ?></span></th>
                </tr></thead>
                <tbody id="users-tbody"><?= $lists['users']['rows'] ?></tbody>
            </table>
        </div>
        <div id="users-pagination" class="pagination-container"><?= $lists['users']['pages'] ?></div>
    </section>

    <!-- BANY -->
    <section <?= $panel('bans') ?>>
        <div class="tab-head">
            <h2><i class="ri-prohibited-line" aria-hidden="true"></i> <?= h('panel.bans.title') ?></h2>
            <button type="button" class="btn btn-warning" data-action="open-scanner"><i class="ri-radar-line" aria-hidden="true"></i> <?= h('panel.bans.scanner') ?></button>
        </div>
        <?php if (!$f2bAvail): ?>
            <div class="ds-msg ds-msg--err error-container">
                <strong><i class="ri-alert-line" aria-hidden="true"></i> <?= h('panel.bans.unavailable.title') ?></strong><br>
                <?= html('panel.bans.unavailable.text') ?>
            </div>
        <?php endif; ?>

        <?php if ($f2bAvail && $jails !== []): ?>
        <div class="settings-card manual-ban-card">
            <div class="settings-card-header"><i class="ri-hammer-line" aria-hidden="true"></i> <?= h('panel.bans.manual.title') ?></div>
            <div class="settings-card-body">
                <form method="POST" class="manual-ban-form" data-ajax data-confirm="ban">
                    <input type="hidden" name="_csrf" value="<?= Html::e($csrf) ?>">
                    <input type="hidden" name="f2b_ban" value="1">
                    <input type="hidden" name="_tab" value="bans">
                    <div class="manual-ban-field grow">
                        <label for="f2b_ip"><?= h('panel.bans.manual.ip') ?></label>
                        <input type="text" id="f2b_ip" name="f2b_ip" required placeholder="<?= h('panel.bans.manual.ip_placeholder') ?>"
                               pattern="[0-9a-fA-F.:]+" autocomplete="off" spellcheck="false">
                    </div>
                    <div class="manual-ban-field">
                        <label for="f2b_jail"><?= h('panel.bans.manual.jail') ?></label>
                        <select id="f2b_jail" name="f2b_jail">
                            <?php foreach ($jails as $j): ?>
                                <option value="<?= Html::e($j) ?>" <?= $j === 'recidive' ? 'selected' : '' ?>><?= Html::e($j) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <button type="submit" class="btn btn-danger"><i class="ri-forbid-line" aria-hidden="true"></i> <?= h('panel.bans.manual.submit') ?></button>
                </form>
                <div class="help-text"><?= html('panel.bans.manual.help') ?></div>
            </div>
        </div>
        <?php endif; ?>

        <div class="search-bar">
            <div class="adv-search">
                <input type="search" id="ban-search-input" autocomplete="off" aria-label="<?= h('panel.bans.search.label') ?>"
                       placeholder="<?= h('panel.bans.search.placeholder') ?>">
                <button type="button" class="adv-clear" data-adv-clear="ban-search-input" aria-label="<?= h('panel.search.clear') ?>" hidden><i class="ri-close-line" aria-hidden="true"></i></button>
            </div>
        </div>
        <div class="table-responsive" data-list="bans">
            <table>
                <thead><tr>
                    <?= $th('bans', '0', t('panel.bans.th.jail')) ?>
                    <?= $th('bans', '1', t('panel.bans.th.ip')) ?>
                    <?= $th('bans', '2', t('panel.bans.th.reason')) ?>
                    <?= $th('bans', '3', t('panel.bans.th.expires')) ?>
                    <th scope="col"><span class="th-inner"><?= h('panel.bans.th.action') ?></span></th>
                </tr></thead>
                <tbody id="bans-tbody"><?= $bansRowsHtml ?></tbody>
            </table>
        </div>
    </section>

    <!-- LOGI -->
    <section <?= $panel('logs') ?>>
        <div class="search-bar">
            <div class="adv-search">
                <input type="search" id="log-search-input" autocomplete="off" aria-label="<?= h('panel.logs.search.label') ?>" aria-describedby="log-search-hint"
                       placeholder="<?= h('panel.logs.search.placeholder') ?>">
                <button type="button" class="adv-clear" data-adv-clear="log-search-input" aria-label="<?= h('panel.search.clear') ?>" hidden><i class="ri-close-line" aria-hidden="true"></i></button>
            </div>
        </div>
        <div class="help-text search-hint" id="log-search-hint"><?= html('panel.logs.search.hint') ?></div>

        <h2><i class="ri-history-line" aria-hidden="true"></i> <?= h('panel.logs.reg.title') ?></h2>
        <div class="users-topbar">
            <div id="reg-total-text"><?= html('panel.logs.reg.total', ['n' => (int) $lists['reg']['total']]) ?></div>
            <div class="users-topbar-right"><?= $perSel('regPerPage', 'reg') ?></div>
        </div>
        <div class="table-responsive" data-list="reg">
            <table><thead><tr>
                <?= $th('reg', 'created', t('panel.logs.th.time')) ?>
                <?= $th('reg', 'ip', t('panel.logs.th.ip')) ?>
                <?= $th('reg', 'email', t('panel.logs.th.email')) ?>
                <?= $th('reg', 'status', t('panel.logs.th.status')) ?>
                <th scope="col"><span class="th-inner"><?= h('panel.logs.th.info') ?></span></th>
            </tr></thead>
                <tbody id="reg-logs-tbody"><?= $lists['reg']['rows'] ?></tbody></table>
        </div>
        <div id="reg-logs-pagination" class="pagination-container"><?= $lists['reg']['pages'] ?></div>
        <p class="help-text log-retention-note"><i class="ri-time-line" aria-hidden="true"></i> <span><?= Html::e($retentionNote) ?></span></p>

        <hr class="safe-hr">
        <h2><i class="ri-shield-user-line" aria-hidden="true"></i> <?= h('panel.logs.admin.title') ?></h2>
        <div class="users-topbar">
            <div id="admin-total-text"><?= html('panel.logs.admin.total', ['n' => (int) $lists['admin']['total']]) ?></div>
            <div class="users-topbar-right"><?= $perSel('adminPerPage', 'admin') ?></div>
        </div>
        <div class="table-responsive" data-list="admin">
            <table><thead><tr>
                <?= $th('admin', 'created', t('panel.logs.th.time')) ?>
                <?= $th('admin', 'email', t('panel.logs.th.admin_email')) ?>
                <?= $th('admin', 'ip', t('panel.logs.th.ip')) ?>
                <?= $th('admin', 'status', t('panel.logs.th.status')) ?>
                <th scope="col"><span class="th-inner"><?= h('panel.logs.th.details') ?></span></th>
            </tr></thead>
                <tbody id="admin-logs-tbody"><?= $lists['admin']['rows'] ?></tbody></table>
        </div>
        <div id="admin-logs-pagination" class="pagination-container"><?= $lists['admin']['pages'] ?></div>
        <p class="help-text log-retention-note"><i class="ri-time-line" aria-hidden="true"></i> <span><?= Html::e($retentionNote) ?></span></p>

        <hr class="safe-hr">
        <h2><i class="ri-file-shield-2-line" aria-hidden="true"></i> <?= h('panel.logs.audit.title') ?></h2>
        <div class="users-topbar">
            <div id="audit-total-text"><?= html('panel.logs.audit.total', ['n' => (int) $lists['audit']['total']]) ?></div>
            <div class="users-topbar-right">
                <?= $perSel('auditPerPage', 'audit') ?>
                <button type="button" class="btn btn-small btn-secondary" data-action="refresh-audit"><i class="ri-refresh-line" aria-hidden="true"></i> <?= h('panel.logs.audit.refresh') ?></button>
            </div>
        </div>
        <div class="table-responsive" data-list="audit">
            <table><thead><tr>
                <?= $th('audit', 'created', t('panel.logs.th.date')) ?>
                <?= $th('audit', 'admin', t('panel.logs.th.administrator')) ?>
                <?= $th('audit', 'ip', t('panel.logs.th.admin_ip')) ?>
                <?= $th('audit', 'action', t('panel.logs.th.action')) ?>
                <th scope="col"><span class="th-inner"><?= h('panel.logs.th.details') ?></span></th>
            </tr></thead>
                <tbody id="audit-logs-tbody"><?= $lists['audit']['rows'] ?></tbody></table>
        </div>
        <div id="audit-logs-pagination" class="pagination-container"><?= $lists['audit']['pages'] ?></div>
        <p class="help-text log-retention-note"><i class="ri-time-line" aria-hidden="true"></i> <span><?= Html::e($retentionNote) ?></span></p>
    </section>

    <!-- FAIL2BAN -->
    <section <?= $panel('fail2ban') ?>>
        <h2><i class="ri-fire-line" aria-hidden="true"></i> <?= h('panel.f2b.title') ?></h2>
        <?php if (!$f2b['available']): ?>
            <div class="alert alert-error"><?= h('panel.f2b.unavailable') ?></div>
        <?php else: ?>
        <form method="POST" data-ajax data-busy-label="<?= h('panel.f2b.busy') ?>">
            <input type="hidden" name="_csrf" value="<?= Html::e($csrf) ?>">
            <input type="hidden" name="save_fail2ban" value="1">
            <input type="hidden" name="_tab" value="fail2ban">
            <div class="f2b-card f2b-card--default">
                <h3><i class="ri-settings-line" aria-hidden="true"></i> <?= h('panel.f2b.default.title') ?></h3>
                <div class="form-group"><label for="f2b_default_findtime">findtime</label><input type="text" id="f2b_default_findtime" name="f2b_default_findtime" value="<?= $fv('f2b_default_findtime') ?>"></div>
                <div class="form-group"><label for="f2b_default_bantime">bantime</label><input type="text" id="f2b_default_bantime" name="f2b_default_bantime" value="<?= $fv('f2b_default_bantime') ?>"></div>
                <div class="form-group"><label for="f2b_default_maxretry">maxretry</label><input type="number" id="f2b_default_maxretry" name="f2b_default_maxretry" value="<?= $fv('f2b_default_maxretry') ?>"></div>
                <div class="form-group"><label for="f2b_default_bantime_factor">bantime.factor</label><input type="text" id="f2b_default_bantime_factor" name="f2b_default_bantime_factor" value="<?= $fv('f2b_default_bantime_factor') ?>"></div>
                <div class="form-group"><label for="f2b_default_bantime_maxtime">bantime.maxtime</label><input type="text" id="f2b_default_bantime_maxtime" name="f2b_default_bantime_maxtime" value="<?= $fv('f2b_default_bantime_maxtime') ?>"></div>
                <div class="form-group checkbox-group f2b-inc-check">
                    <input type="checkbox" id="f2b_inc" name="f2b_default_bantime_increment" value="1" <?= $fc('f2b_default_bantime_increment') ? 'checked' : '' ?>>
                    <label for="f2b_inc"><?= h('panel.f2b.default.increment') ?></label>
                </div>
                <div class="form-group f2b-span"><label for="f2b_default_ignoreip"><?= h('panel.f2b.default.ignoreip') ?></label><textarea id="f2b_default_ignoreip" name="f2b_default_ignoreip" rows="2" class="textarea-full" spellcheck="false"><?= $fv('f2b_default_ignoreip') ?></textarea></div>
            </div>
            <div class="f2b-grid">
                <?php
                $jailCards = [
                    ['roundcube', 'ri-mail-line', 'Roundcube'],
                    ['dovecot',   'ri-inbox-line', 'Dovecot'],
                    ['postfix',   'ri-send-plane-line', 'Postfix'],
                    ['recidive',  'ri-alert-line', 'Recidive'],
                ];
                foreach ($jailCards as [$prefix, $icon, $title]): ?>
                <div class="f2b-card">
                    <h3><i class="<?= $icon ?>" aria-hidden="true"></i> <?= $title ?></h3>
                    <div class="form-group checkbox-group"><input type="checkbox" id="f2b_<?= $prefix ?>_en" name="f2b_<?= $prefix ?>_enabled" value="1" <?= $fc("f2b_{$prefix}_enabled") ? 'checked' : '' ?>><label for="f2b_<?= $prefix ?>_en"><?= h('panel.f2b.jail.enable') ?></label></div>
                    <div class="form-group"><label for="f2b_<?= $prefix ?>_findtime">findtime</label><input type="text" id="f2b_<?= $prefix ?>_findtime" name="f2b_<?= $prefix ?>_findtime" value="<?= $fv("f2b_{$prefix}_findtime") ?>"></div>
                    <div class="form-group"><label for="f2b_<?= $prefix ?>_maxretry">maxretry</label><input type="number" id="f2b_<?= $prefix ?>_maxretry" name="f2b_<?= $prefix ?>_maxretry" value="<?= $fv("f2b_{$prefix}_maxretry") ?>"></div>
                    <div class="form-group"><label for="f2b_<?= $prefix ?>_bantime">bantime</label><input type="text" id="f2b_<?= $prefix ?>_bantime" name="f2b_<?= $prefix ?>_bantime" value="<?= $fv("f2b_{$prefix}_bantime") ?>"></div>
                </div>
                <?php endforeach; ?>
            </div>
            <div class="form-actions mt-4"><button type="submit" class="btn btn-dark"><i class="ri-refresh-line" aria-hidden="true"></i> <?= h('panel.f2b.save') ?></button></div>
        </form>
        <?php endif; ?>
    </section>

    <!-- BEZPIECZEŃSTWO -->
    <section <?= $panel('security') ?>>
        <h2><i class="ri-lock-line" aria-hidden="true"></i> <?= h('panel.security.title') ?></h2>
        <div class="settings-grid">
            <div class="settings-card">
                <div class="settings-card-header"><i class="ri-spam-2-line" aria-hidden="true"></i> <?= h('panel.security.antispam.title') ?></div>
                <div class="settings-card-body">
                    <form method="POST" data-ajax data-busy-label="<?= h('panel.security.antispam.busy') ?>">
                        <input type="hidden" name="_csrf" value="<?= Html::e($csrf) ?>">
                        <input type="hidden" name="_tab" value="security">
                        <input type="hidden" name="update_antispam" value="1">
                        <h3 class="section-title"><?= h('panel.security.antispam.roundcube') ?></h3>
                        <div class="form-group"><label for="sec_rc_max"><?= h('panel.security.antispam.rc_max') ?></label><input type="number" id="sec_rc_max" name="rc_max_recipients" value="<?= (int) $s['rc_max_recipients'] ?>"></div>
                        <div class="form-group"><label for="sec_rc_delay"><?= h('panel.security.antispam.rc_delay') ?></label><input type="number" id="sec_rc_delay" name="rc_sendmail_delay" value="<?= (int) $s['rc_sendmail_delay'] ?>"></div>
                        <div class="form-group"><label for="sec_rc_login"><?= h('panel.security.antispam.rc_login') ?></label><input type="number" id="sec_rc_login" name="rc_login_rate_limit" value="<?= (int) $s['rc_login_rate_limit'] ?>"></div>
                        <h3 class="section-title"><?= h('panel.security.antispam.postfix') ?></h3>
                        <?php if (!$pfAvail): ?>
                            <div class="ds-msg ds-msg--err error-container"><strong><i class="ri-alert-line" aria-hidden="true"></i> <?= h('panel.security.antispam.no_sudo') ?></strong> <?= h('panel.security.antispam.no_sudo_text') ?></div>
                        <?php endif; ?>
                        <div class="form-group"><label for="sec_pf_msg"><?= h('panel.security.antispam.pf_msg') ?></label><input type="number" id="sec_pf_msg" name="pf_message_rate_limit" value="<?= (int) $s['pf_message_rate_limit'] ?>"></div>
                        <div class="form-group"><label for="sec_pf_conn"><?= h('panel.security.antispam.pf_conn') ?></label><input type="number" id="sec_pf_conn" name="pf_connection_rate_limit" value="<?= (int) $s['pf_connection_rate_limit'] ?>"></div>
                        <div class="form-group"><label for="sec_pf_cnt"><?= h('panel.security.antispam.pf_cnt') ?></label><input type="number" id="sec_pf_cnt" name="pf_connection_count_limit" value="<?= (int) $s['pf_connection_count_limit'] ?>"></div>
                        <div class="mt-4"><button type="submit" name="update_antispam" class="btn"><i class="ri-shield-check-line" aria-hidden="true"></i> <?= h('panel.security.antispam.save') ?></button></div>
                    </form>
                </div>
            </div>

            <div class="settings-card">
                <div class="settings-card-header"><i class="ri-shield-flash-line" aria-hidden="true"></i> <?= h('panel.security.panel.title') ?></div>
                <div class="settings-card-body">
                    <form method="POST" data-ajax>
                        <input type="hidden" name="_csrf" value="<?= Html::e($csrf) ?>">
                        <input type="hidden" name="_tab" value="security">
                        <input type="hidden" name="update_security" value="1">
                        <div class="form-group"><label for="sec_max_login"><?= h('panel.security.panel.max_login') ?></label><input type="number" id="sec_max_login" name="max_login_attempts" min="1" value="<?= (int) $s['max_login_attempts'] ?>"></div>
                        <div class="form-group"><label for="sec_ban_min"><?= h('panel.security.panel.ban_min') ?></label><input type="number" id="sec_ban_min" name="ban_duration_minutes" min="1" value="<?= (int) $s['ban_duration_minutes'] ?>">
                            <div class="help-text"><?= html('panel.security.panel.help') ?></div></div>
                        <div class="mt-4"><button type="submit" name="update_security" class="btn"><i class="ri-save-line" aria-hidden="true"></i> <?= h('panel.common.save') ?></button></div>
                    </form>
                </div>
            </div>

            <div class="settings-card">
                <div class="settings-card-header"><i class="ri-key-2-line" aria-hidden="true"></i> <?= h('panel.security.password.title') ?></div>
                <div class="settings-card-body">
                    <form method="POST" data-ajax>
                        <input type="hidden" name="_csrf" value="<?= Html::e($csrf) ?>">
                        <input type="hidden" name="_tab" value="security">
                        <input type="hidden" name="change_password" value="1">
                        <input type="text" name="username" value="<?= Html::e($admin) ?>" autocomplete="username" hidden readonly tabindex="-1">
                        <div class="form-group"><label for="cp_current"><?= h('panel.security.password.current') ?></label><input type="password" id="cp_current" name="current_password" required autocomplete="current-password"></div>
                        <div class="form-group"><label for="cp_new"><?= h('panel.security.password.new') ?></label><input type="password" id="cp_new" name="new_password" required minlength="12" autocomplete="new-password" aria-describedby="cp_help"><div class="help-text" id="cp_help"><?= h('panel.security.password.help', ['limit' => (int) $reauthLimit]) ?></div></div>
                        <div class="form-group"><label for="cp_confirm"><?= h('panel.security.password.confirm') ?></label><input type="password" id="cp_confirm" name="confirm_password" required autocomplete="new-password"></div>
                        <div class="mt-4"><button type="submit" name="change_password" class="btn"><i class="ri-refresh-line" aria-hidden="true"></i> <?= h('panel.security.password.submit') ?></button></div>
                    </form>
                </div>
            </div>
        </div>
    </section>
</main>
<?php $footerInPanel = true; include __DIR__ . '/../partials/footer.php'; ?>

<!-- MODAL: QUOTA -->
<div id="quotaModal" class="modal" role="dialog" aria-modal="true" aria-labelledby="quotaModalTitle" hidden>
    <div class="modal-content">
        <div class="modal-header"><h2 id="quotaModalTitle"><i class="ri-hard-drive-2-line" aria-hidden="true"></i> <?= h('panel.modal.quota.title') ?></h2><?= $close('quotaModal') ?></div>
        <form method="POST" data-ajax>
            <input type="hidden" name="_csrf" value="<?= Html::e($csrf) ?>">
            <input type="hidden" name="update_user_quota" value="1">
            <input type="hidden" name="_tab" value="users">
            <input type="hidden" name="user_id" id="quota_user_id">
            <div class="form-group"><label for="quota_user_email"><?= h('panel.modal.user') ?></label><input type="text" id="quota_user_email" readonly class="input-readonly"></div>
            <div class="form-group">
                <label for="quota_value"><?= h('panel.modal.quota.new_limit') ?></label>
                <div class="quota-input-group">
                    <input type="number" name="user_quota_value" id="quota_value" step="0.01" min="0" required>
                    <select name="user_quota_unit" id="quota_unit" aria-label="<?= h('panel.modal.unit') ?>"><option value="KiB">KiB</option><option value="MiB" selected>MiB</option><option value="GiB">GiB</option></select>
                </div>
            </div>
            <div class="form-group">
                <label for="quota_send_limit"><?= h('panel.modal.quota.send_limit') ?></label>
                <input type="number" name="user_send_limit" id="quota_send_limit" min="0" step="1" placeholder="<?= h('panel.modal.send_limit.placeholder', ['n' => (int) $s['daily_send_limit']]) ?>" data-placeholder-bind="daily_send_limit">
                <div class="help-text"><?= $sendHelp ?></div>
            </div>
            <div class="form-group">
                <span class="label-like"><?= h('panel.modal.quota.sent_today') ?></span>
                <div id="quota_sent_today" class="sent-today-box">…</div>
            </div>
            <div class="form-msg" aria-live="assertive"></div>
            <div class="modal-footer"><button type="button" class="btn btn-secondary" data-action="modal-close" data-modal="quotaModal"><?= h('panel.common.cancel') ?></button><button type="submit" class="btn"><i class="ri-save-line" aria-hidden="true"></i> <?= h('panel.common.save') ?></button></div>
        </form>
    </div>
</div>

<!-- MODAL: RESET HASŁA SKRZYNKI -->
<div id="resetPassModal" class="modal" role="dialog" aria-modal="true" aria-labelledby="resetPassTitle" hidden>
    <div class="modal-content">
        <div class="modal-header"><h2 id="resetPassTitle"><i class="ri-key-2-line" aria-hidden="true"></i> <?= h('panel.modal.reset.title') ?></h2><?= $close('resetPassModal') ?></div>
        <form method="POST" data-ajax>
            <input type="hidden" name="_csrf" value="<?= Html::e($csrf) ?>">
            <input type="hidden" name="reset_user_password" value="1">
            <input type="hidden" name="_tab" value="users">
            <input type="hidden" name="user_id" id="rp_user_id">
            <input type="hidden" name="user_email" id="rp_user_email_hidden">
            <div class="form-group"><label for="rp_user_email"><?= h('panel.modal.user') ?></label><input type="text" id="rp_user_email" readonly class="input-readonly" autocomplete="off"></div>
            <div class="form-group">
                <label for="rp_password"><?= h('panel.modal.reset.new_password') ?></label>
                <input type="password" name="new_password" id="rp_password" required minlength="<?= (int) $pwd['min'] ?>" autocomplete="new-password" placeholder="<?= h('panel.modal.reset.new_placeholder') ?>" aria-describedby="rp_pwd_reqs">
                <div class="pwd-meter" aria-hidden="true"><div class="pwd-meter-fill" id="rp_pwd_bar"></div></div>
                <ul class="pwd-reqs" id="rp_pwd_reqs">
                    <li data-req="len"><?= h('panel.pwd.min_chars', ['n' => (int) $pwd['min']]) ?></li>
                    <?php if ($pwd['upper']): ?><li data-req="up"><?= h('panel.pwd.upper') ?></li><?php endif; ?>
                    <?php if ($pwd['digit']): ?><li data-req="dig"><?= h('panel.pwd.digit') ?></li><?php endif; ?>
                    <?php if ($pwd['spec']): ?><li data-req="spec"><?= h('panel.pwd.special') ?></li><?php endif; ?>
                </ul>
            </div>
            <div class="form-group">
                <label for="rp_password2"><?= h('panel.modal.reset.repeat') ?></label>
                <input type="password" name="new_password2" id="rp_password2" required autocomplete="new-password" placeholder="<?= h('panel.modal.repeat_placeholder') ?>">
                <div id="rp_pass_match" class="avail-status" aria-live="polite"></div>
                <div class="help-text"><?= h('panel.modal.reset.help') ?></div>
            </div>
            <div class="password-confirm-group"><label for="rp_admin_pass"><?= h('panel.modal.reset.admin_password') ?></label><input type="password" id="rp_admin_pass" name="admin_confirm_password" required placeholder="<?= h('panel.modal.delete.password_placeholder') ?>" autocomplete="current-password" aria-describedby="rp_admin_help">
                <div class="help-text" id="rp_admin_help"><?= h('panel.modal.delete.password_help', ['limit' => (int) $reauthLimit]) ?></div></div>
            <div id="rp_form_msg" class="form-msg" aria-live="assertive"></div>
            <div class="modal-footer"><button type="button" class="btn btn-secondary" data-action="modal-close" data-modal="resetPassModal"><?= h('panel.common.cancel') ?></button><button type="submit" class="btn"><i class="ri-save-line" aria-hidden="true"></i> <?= h('panel.modal.reset.submit') ?></button></div>
        </form>
    </div>
</div>

<!-- MODAL: BAN -->
<div id="banModal" class="modal" role="dialog" aria-modal="true" aria-labelledby="banModalTitle" hidden>
    <div class="modal-content">
        <div class="modal-header"><h2 id="banModalTitle"><i class="ri-forbid-line" aria-hidden="true"></i> <?= h('panel.modal.ban.title') ?></h2><?= $close('banModal') ?></div>
        <form method="POST" data-ajax>
            <input type="hidden" name="_csrf" value="<?= Html::e($csrf) ?>">
            <input type="hidden" name="ban_user_ip" value="1">
            <input type="hidden" name="_tab" value="users">
            <div class="form-group"><label for="ban_user_email"><?= h('panel.modal.user') ?></label><input type="text" id="ban_user_email" readonly class="input-readonly"></div>
            <div class="form-group">
                <label for="ban_ip_select"><?= h('panel.modal.ban.select_ip') ?></label>
                <select name="ban_ip" id="ban_ip_select" required><option value=""><?= h('panel.modal.ban.select_ip_option') ?></option></select>
                <div class="help-text"><?= h('panel.modal.ban.select_ip_help') ?></div>
            </div>
            <div class="form-group">
                <label for="ban_reason_select"><?= h('panel.modal.ban.reason') ?></label>
                <?php /* powód trafia do bazy (lista blokad), więc zapisujemy go w języku domyślnym serwisu, a pokazujemy w bieżącym */ ?>
                <select id="ban_reason_select">
                    <option value="<?= Html::e(Lang::site('panel.modal.ban.reason_spam')) ?>"><?= h('panel.modal.ban.reason_spam') ?></option>
                    <option value="<?= Html::e(Lang::site('panel.modal.ban.reason_intrusion')) ?>"><?= h('panel.modal.ban.reason_intrusion') ?></option>
                    <option value="<?= Html::e(Lang::site('panel.modal.ban.reason_abuse')) ?>"><?= h('panel.modal.ban.reason_abuse') ?></option>
                    <option value="<?= Html::e(Lang::site('panel.modal.ban.reason_rules')) ?>"><?= h('panel.modal.ban.reason_rules') ?></option>
                    <option value="<?= Html::e(Lang::site('panel.modal.ban.reason_phishing')) ?>"><?= h('panel.modal.ban.reason_phishing') ?></option>
                    <option value="custom"><?= h('panel.modal.ban.reason_custom') ?></option>
                </select>
                <input type="hidden" name="ban_reason" id="ban_reason_input" value="<?= Html::e(Lang::site('panel.modal.ban.reason_spam')) ?>">
            </div>
            <div class="form-group" id="custom_reason_group" hidden><label for="ban_custom_reason"><?= h('panel.modal.ban.custom_reason') ?></label><input type="text" id="ban_custom_reason" placeholder="<?= h('panel.modal.ban.custom_placeholder') ?>"></div>
            <div class="form-group"><label for="ban_note"><?= h('panel.modal.ban.note') ?></label><textarea id="ban_note" name="ban_note" rows="2" placeholder="<?= h('panel.modal.ban.note_placeholder') ?>"></textarea></div>
            <div class="form-group"><label for="ban_duration"><?= h('panel.modal.ban.duration') ?></label><input type="number" id="ban_duration" name="ban_duration" value="60" min="1" max="129600"><div class="help-text"><?= html('panel.modal.ban.duration_help') ?></div></div>
            <div class="form-msg" aria-live="assertive"></div>
            <div class="modal-footer"><button type="button" class="btn btn-secondary" data-action="modal-close" data-modal="banModal"><?= h('panel.common.cancel') ?></button><button type="submit" class="btn btn-danger"><i class="ri-forbid-line" aria-hidden="true"></i> <?= h('panel.modal.ban.submit') ?></button></div>
        </form>
    </div>
</div>

<!-- MODAL: DELETE -->
<div id="deleteUserModal" class="modal" role="dialog" aria-modal="true" aria-labelledby="deleteUserTitle" aria-describedby="deleteUserWhat" hidden>
    <div class="modal-content">
        <div class="modal-header"><h2 id="deleteUserTitle" class="danger-title"><i class="ri-alarm-warning-line" aria-hidden="true"></i> <?= h('panel.modal.delete.title') ?></h2><?= $close('deleteUserModal') ?></div>
        <div class="delete-warning-box" id="deleteUserWhat">
            <?= strtr(html('panel.modal.delete.warning'), [':days' => '<b data-bind="deleted_name_hold_days">' . (int) $s['deleted_name_hold_days'] . '</b>']) ?>
        </div>
        <ul class="delete-details-list">
            <li><span><?= h('panel.modal.delete.id') ?></span> <strong id="del_user_id_display"></strong></li>
            <li><span><?= h('panel.modal.delete.email') ?></span> <strong id="del_user_email_display"></strong></li>
            <li><span><?= h('panel.modal.delete.files') ?></span>
                <?php if ($delAvail): ?><span class="badge badge-warning"><?= html('panel.modal.delete.quarantined') ?></span>
                    <div class="help-text"><?= html('panel.modal.delete.quarantine_help') ?></div>
                <?php else: ?><span class="badge badge-danger"><?= h('panel.modal.delete.stays') ?></span><?php endif; ?>
            </li>
        </ul>
        <form method="POST" data-ajax data-busy-label="<?= h('panel.modal.delete.busy') ?>">
            <input type="hidden" name="_csrf" value="<?= Html::e($csrf) ?>">
            <input type="hidden" name="delete_user_confirm" value="1">
            <input type="hidden" name="_tab" value="users">
            <input type="hidden" name="delete_user_id" id="del_user_id_input">
            <input type="hidden" name="delete_user_email" id="del_user_email_input">
            <input type="text" name="username" value="<?= Html::e($admin) ?>" autocomplete="username" hidden readonly tabindex="-1">
            <div class="password-confirm-group"><label for="del_admin_pass"><?= h('panel.modal.delete.confirm_password') ?></label><input type="password" id="del_admin_pass" name="admin_confirm_password" required placeholder="<?= h('panel.modal.delete.password_placeholder') ?>" autocomplete="current-password" aria-describedby="del_pass_help">
                <div class="help-text" id="del_pass_help"><?= h('panel.modal.delete.password_help', ['limit' => (int) $reauthLimit]) ?></div></div>
            <div class="form-msg" aria-live="assertive"></div>
            <div class="modal-footer"><button type="button" class="btn btn-secondary" data-action="modal-close" data-modal="deleteUserModal"><?= h('panel.common.cancel') ?></button><button type="submit" class="btn btn-danger"><i class="ri-delete-bin-2-line" aria-hidden="true"></i> <?= h('panel.modal.delete.submit') ?></button></div>
        </form>
    </div>
</div>

<!-- MODAL: DODAJ UŻYTKOWNIKA -->
<div id="addUserModal" class="modal" role="dialog" aria-modal="true" aria-labelledby="addUserTitle" hidden>
    <div class="modal-content">
        <div class="modal-header"><h2 id="addUserTitle"><i class="ri-user-add-line" aria-hidden="true"></i> <?= h('panel.modal.add.title') ?></h2><?= $close('addUserModal') ?></div>
        <form method="POST" data-ajax autocomplete="off">
            <input type="hidden" name="_csrf" value="<?= Html::e($csrf) ?>">
            <input type="hidden" name="create_user" value="1">
            <input type="hidden" name="_tab" value="users">
            <div class="form-group">
                <label for="nu_local"><?= h('panel.modal.add.email') ?></label>
                <div class="email-compose">
                    <input type="text" name="nu_local" id="nu_local" required placeholder="<?= h('panel.modal.add.local_placeholder') ?>" autocomplete="off" maxlength="64" spellcheck="false" autocapitalize="none"
                           pattern="[A-Za-z0-9]([A-Za-z0-9._\-]*[A-Za-z0-9])?" aria-describedby="nu_availability nu_reason_text">
                    <span aria-hidden="true">@</span>
                    <select name="nu_domain" id="nu_domain" aria-label="<?= h('panel.modal.add.domain') ?>">
                        <?php foreach ($domains as $dm): ?>
                            <option value="<?= Html::e($dm) ?>"><?= Html::e($dm) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div id="nu_availability" class="avail-status" aria-live="polite"></div>
                <div id="nu_reason" class="name-reason" hidden>
                    <p id="nu_reason_text" class="name-reason-text"></p>
                    <div id="nu_reason_actions" class="name-reason-actions"></div>
                </div>
                <div class="checkbox-warning-wrapper" id="nu_allow_wrap" hidden>
                    <input type="checkbox" name="nu_allow_reserved" id="nu_allow_reserved" value="1">
                    <label for="nu_allow_reserved"><?= h('panel.modal.add.allow_reserved') ?></label>
                </div>
                <?php /* shown, enabled and required only while the consent above is ticked (panel.js nuSyncAdmin) */ ?>
                <div class="password-confirm-group" id="nu_admin_wrap" hidden><label for="nu_admin_pass"><?= h('panel.modal.add.admin_password') ?></label><input type="password" id="nu_admin_pass" name="admin_confirm_password" disabled placeholder="<?= h('panel.modal.delete.password_placeholder') ?>" autocomplete="current-password" aria-describedby="nu_admin_help">
                    <div class="help-text" id="nu_admin_help"><?= h('panel.modal.delete.password_help', ['limit' => (int) $reauthLimit]) ?></div></div>
                <details class="name-rules">
                    <summary><?= h('panel.modal.add.rules.title') ?></summary>
                    <ul>
                        <li><?= html('panel.modal.add.rules.reserved') ?></li>
                        <li><?= html('panel.modal.add.rules.recent') ?></li>
                        <li><?= html('panel.modal.add.rules.alias') ?></li>
                        <li><?= html('panel.modal.add.rules.exists') ?></li>
                    </ul>
                </details>
            </div>
            <div class="form-group">
                <label for="nu_password"><?= h('panel.modal.add.password') ?></label>
                <input type="password" name="nu_password" id="nu_password" required minlength="<?= (int) $pwd['min'] ?>" autocomplete="new-password" placeholder="<?= h('panel.modal.add.password_placeholder') ?>" aria-describedby="nu_pwd_reqs">
                <div class="pwd-meter" aria-hidden="true"><div class="pwd-meter-fill" id="nu_pwd_bar"></div></div>
                <ul class="pwd-reqs" id="nu_pwd_reqs">
                    <li data-req="len"><?= h('panel.pwd.min_chars', ['n' => (int) $pwd['min']]) ?></li>
                    <?php if ($pwd['upper']): ?><li data-req="up"><?= h('panel.pwd.upper') ?></li><?php endif; ?>
                    <?php if ($pwd['digit']): ?><li data-req="dig"><?= h('panel.pwd.digit') ?></li><?php endif; ?>
                    <?php if ($pwd['spec']): ?><li data-req="spec"><?= h('panel.pwd.special') ?></li><?php endif; ?>
                </ul>
            </div>
            <div class="form-group">
                <label for="nu_password2"><?= h('panel.modal.add.repeat') ?></label>
                <input type="password" name="nu_password2" id="nu_password2" required autocomplete="new-password" placeholder="<?= h('panel.modal.repeat_placeholder') ?>">
                <div id="nu_pass_match" class="avail-status" aria-live="polite"></div>
                <div class="help-text"><?= h('panel.modal.add.password_help') ?></div>
            </div>
            <div class="form-group">
                <label for="nu_quota_value"><?= h('panel.modal.add.quota') ?></label>
                <div class="quota-input-group">
                    <input type="number" name="nu_quota_value" id="nu_quota_value" step="1" min="0" value="<?= (int) $s['mailbox_quota_value'] ?>">
                    <?php /* the default's own unit (1 GiB is "1" + GiB, not "1" + MiB); panel.js keeps both in step with Settings */ ?>
                    <select name="nu_quota_unit" id="nu_quota_unit" aria-label="<?= h('panel.modal.unit') ?>">
                        <?php foreach (['KiB', 'MiB', 'GiB'] as $nuUnit): ?>
                            <option value="<?= $nuUnit ?>" <?= $s['mailbox_quota_unit'] === $nuUnit ? 'selected' : '' ?>><?= $nuUnit ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div class="form-group">
                <label for="nu_send_limit"><?= h('panel.modal.add.send_limit') ?></label>
                <input type="number" name="nu_send_limit" id="nu_send_limit" min="0" step="1" placeholder="<?= h('panel.modal.send_limit.placeholder', ['n' => (int) $s['daily_send_limit']]) ?>" data-placeholder-bind="daily_send_limit">
                <div class="help-text"><?= $sendHelp ?></div>
            </div>
            <div id="nu_form_msg" class="form-msg" aria-live="assertive"></div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-action="modal-close" data-modal="addUserModal"><?= h('panel.common.cancel') ?></button>
                <button type="submit" class="btn"><i class="ri-user-add-line" aria-hidden="true"></i> <?= h('panel.modal.add.submit') ?></button>
            </div>
        </form>
    </div>
</div>

<!-- MODAL: ZARZĄDZANIE BANEM -->
<div id="manageBanModal" class="modal" role="dialog" aria-modal="true" aria-labelledby="manageBanTitle" hidden>
    <div class="modal-content">
        <div class="modal-header"><h2 id="manageBanTitle"><i class="ri-shield-keyhole-line" aria-hidden="true"></i> <?= h('panel.modal.manage.title') ?></h2><?= $close('manageBanModal') ?></div>
        <ul class="delete-details-list">
            <li><span><?= h('panel.modal.manage.ip') ?></span> <strong id="mb_ip"></strong></li>
            <li><span><?= h('panel.modal.manage.kind') ?></span> <strong id="mb_type"></strong></li>
        </ul>
        <div id="mb_change_wrap" class="form-group">
            <label for="mb_target_jail"><?= h('panel.modal.manage.change') ?></label>
            <div class="manage-ban-row">
                <select id="mb_target_jail">
                    <?php foreach ($jails as $j): ?><option value="<?= Html::e($j) ?>" <?= $j === 'panel-permaban' ? 'selected' : '' ?>><?= Html::e($j) ?><?= $j === 'panel-permaban' ? ' ' . h('panel.modal.manage.permanent') : ($j === 'panel-tempban' ? ' ' . h('panel.modal.manage.temporary') : '') ?></option><?php endforeach; ?>
                </select>
                <select id="mb_duration" aria-label="<?= h('panel.modal.manage.duration') ?>" hidden>
                    <option value="3600"><?= h('panel.modal.manage.d1h') ?></option>
                    <option value="21600"><?= h('panel.modal.manage.d6h') ?></option>
                    <option value="86400" selected><?= h('panel.modal.manage.d24h') ?></option>
                    <option value="259200"><?= h('panel.modal.manage.d3d') ?></option>
                    <option value="604800"><?= h('panel.modal.manage.d7d') ?></option>
                    <option value="2592000"><?= h('panel.modal.manage.d30d') ?></option>
                    <option value="7776000"><?= h('panel.modal.manage.d3m') ?></option>
                </select>
                <button type="button" class="btn btn-warning" id="mb_move_btn"><i class="ri-arrow-left-right-line" aria-hidden="true"></i> <?= h('panel.modal.manage.move') ?></button>
            </div>
            <div class="help-text"><?= html('panel.modal.manage.help') ?></div>
        </div>
        <div class="modal-footer modal-footer--center">
            <button type="button" class="btn btn-secondary" data-action="modal-close" data-modal="manageBanModal"><?= h('panel.common.close') ?></button>
            <button type="button" class="btn btn-success" id="mb_unban_btn"><i class="ri-check-line" aria-hidden="true"></i> <?= h('panel.modal.manage.unban') ?></button>
        </div>
    </div>
</div>

<!-- MODAL: SKANER PODEJRZANYCH IP -->
<div id="scannerModal" class="modal" role="dialog" aria-modal="true" aria-labelledby="scannerTitle" hidden>
    <div class="modal-content scanner-modal">
        <div class="modal-header"><h2 id="scannerTitle"><i class="ri-radar-line" aria-hidden="true"></i> <?= h('panel.scanner.title') ?></h2><?= $close('scannerModal') ?></div>
        <div class="scanner-params">
            <div class="scanner-field"><label for="scan_from"><?= h('panel.scanner.from') ?></label><input type="date" id="scan_from"></div>
            <div class="scanner-field"><label for="scan_to"><?= h('panel.scanner.to') ?></label><input type="date" id="scan_to"></div>
            <div class="scanner-field"><label for="scan_min"><?= h('panel.scanner.min') ?></label><input type="number" id="scan_min" value="10" min="1"></div>
            <button type="button" class="btn" id="scan_run"><i class="ri-search-line" aria-hidden="true"></i> <?= h('panel.scanner.run') ?></button>
        </div>
        <div class="help-text"><?= html('panel.scanner.help') ?></div>
        <div id="scan_status" class="scan-status" hidden>
            <div class="scan-status-row">
                <span class="scan-status-label" id="scan_status_label"><i class="ri-loader-4-line spin" aria-hidden="true"></i> <?= h('panel.scanner.preparing') ?></span>
                <span class="scan-status-count" id="scan_status_count" aria-live="polite"></span>
            </div>
            <div class="scan-progress-bar"><div class="scan-progress-fill" id="scan_progress_fill"></div></div>
            <div class="scan-steps" id="scan_steps"></div>
        </div>
        <div id="scan_ban_msg" aria-live="polite"></div>
        <div id="scan_results" class="scanner-results"><p class="empty-state-text"><?= html('panel.scanner.empty') ?></p></div>
    </div>
</div>

<!-- MODAL: POTWIERDZENIE (generyczny) -->
<div id="confirmModal" class="modal" role="alertdialog" aria-modal="true" aria-labelledby="confirm_title" aria-describedby="confirm_msg" hidden>
    <div class="modal-content confirm-modal">
        <div class="modal-header"><h2 id="confirm_title"><i class="ri-error-warning-line" aria-hidden="true"></i> <?= h('panel.modal.confirm.title') ?></h2><?= $close('confirmModal') ?></div>
        <p id="confirm_msg" class="confirm-msg"></p>
        <div class="form-msg" id="confirm_form_msg" aria-live="assertive"></div>
        <div class="modal-footer modal-footer--center">
            <button type="button" class="btn btn-secondary" id="confirm_cancel" data-action="modal-close" data-modal="confirmModal"><?= h('panel.common.cancel') ?></button>
            <button type="button" class="btn" id="confirm_ok"><i class="ri-check-line" aria-hidden="true"></i> <?= h('panel.modal.confirm.ok') ?></button>
        </div>
    </div>
</div>

<?= Lang::jsBridge('panel') ?>
<script src="<?= $av('panel.js') ?>" defer></script>
</body>
</html>
