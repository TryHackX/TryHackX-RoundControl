<?php
declare(strict_types=1);

use App\Support\Html;

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
/** URL assetu z wersją (mtime) — wymusza pobranie świeżego pliku po zmianie. */
$av = static fn(string $f): string => '../assets/' . $f . '?v=' . (@filemtime(dirname(__DIR__, 3) . '/assets/' . $f) ?: '1');
$tabs = [
    'settings' => ['ri-settings-3-line', 'Ustawienia'],
    'users'    => ['ri-user-line', 'Użytkownicy'],
    'bans'     => ['ri-prohibited-line', 'Blokady IP'],
    'logs'     => ['ri-file-list-line', 'Logi'],
    'fail2ban' => ['ri-fire-line', 'Fail2Ban'],
    'security' => ['ri-lock-password-line', 'Bezpieczeństwo'],
];
$tab = (string) ($_GET['tab'] ?? 'settings');
$tab = isset($tabs[$tab]) ? $tab : 'settings';
$panel = static fn(string $id): string => 'id="' . $id . '" class="tab-content' . ($id === $tab ? ' active' : '') . '" role="tabpanel" aria-labelledby="tab-' . $id . '" tabindex="-1"';
$close = static fn(string $modal): string => '<button type="button" class="modal-close" data-action="modal-close" data-modal="' . $modal . '" aria-label="Zamknij okno"><i class="ri-close-line" aria-hidden="true"></i></button>';
$perSel = static fn(string $id, string $nav): string => '<label for="' . $id . '">Na stronę:</label><select id="' . $id . '" data-per="' . $nav . '">'
    . '<option value="10">10</option><option value="20" selected>20</option><option value="50">50</option><option value="100">100</option></select>';
/** Nagłówek sortowalnej kolumny: etykieta + wskaźnik o stałej szerokości (zmiana sortowania nie przesuwa kolumn). */
$th = static function (string $nav, string $col, string $label, string $sorted = '', string $extra = '', string $title = ''): string {
    $ind  = $sorted === '' ? '<span class="sort-placeholder">⇅</span>' : '<span class="contrast-color">' . ($sorted === 'ASC' ? '▲' : '▼') . '</span><sup class="contrast-color sub-style">1</sup>';
    $aria = $sorted === '' ? '' : ' aria-sort="' . ($sorted === 'ASC' ? 'ascending' : 'descending') . '"';
    return '<th class="sortable" scope="col" data-nav="' . $nav . '" data-col="' . $col . '" data-label="' . Html::e($label) . '" tabindex="0"' . $aria
        . ($title !== '' ? ' title="' . Html::e($title) . '"' : '') . '><span class="th-label">' . Html::e($label) . '</span> <span class="sort-ind" aria-hidden="true">' . $ind . '</span>' . $extra . '</th>';
};
$qsort = '<span class="qsort-wrap">'
    . '<button type="button" class="qsort-ico" data-qsort="msgs" title="Sortuj po liczbie wiadomości (bieżąca strona)" aria-label="Sortuj po liczbie wiadomości"><i class="fa-solid fa-message" aria-hidden="true"></i><span class="qsort-n"></span></button>'
    . '<button type="button" class="qsort-ico" data-qsort="size" title="Sortuj po zajętym rozmiarze (bieżąca strona)" aria-label="Sortuj po zajętym rozmiarze"><i class="fa-solid fa-floppy-disk" aria-hidden="true"></i><span class="qsort-n"></span></button>'
    . '</span>';
?>
<!DOCTYPE html>
<html lang="pl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="color-scheme" content="dark light">
<title>Panel Administratora</title>
<link href="<?= $av('vendor/remixicon/remixicon.css') ?>" rel="stylesheet">
<link href="<?= $av('vendor/fontawesome/css/all.min.css') ?>" rel="stylesheet">
<link rel="stylesheet" href="<?= $av('design-system.css') ?>">
<link rel="stylesheet" href="<?= $av('panel.css') ?>">
</head>
<body class="<?= $theme === 'light' ? '' : 'dark-mode' ?>" data-csrf="<?= Html::e($csrf) ?>"
      data-pwd-min="<?= (int) $pwd['min'] ?>"
      data-pwd-upper="<?= (int) $pwd['upper'] ?>"
      data-pwd-digit="<?= (int) $pwd['digit'] ?>"
      data-pwd-spec="<?= (int) $pwd['spec'] ?>"
      data-reauth-limit="<?= (int) $reauthLimit ?>">

<a class="skip-link" href="#main">Przejdź do treści</a>

<header class="header">
    <div class="header-content">
        <h1><i class="ri-shield-keyhole-line" aria-hidden="true"></i> Panel Administratora <span class="app-version" title="TryHackX RoundControl">v<?= Html::e(defined('APP_VERSION') ? APP_VERSION : '') ?></span></h1>
        <div class="header-actions">
            <span class="admin-info">Zalogowany: <b><?= Html::e($admin) ?></b></span>
            <form method="post" action="logout.php" class="logout-form">
                <input type="hidden" name="_csrf" value="<?= Html::e($csrf) ?>">
                <button type="submit" class="logout-link">Wyloguj</button>
            </form>
            <button type="button" class="theme-toggle-btn" id="themeToggle" aria-label="Jasny motyw" aria-pressed="<?= $theme === 'light' ? 'true' : 'false' ?>" title="Przełącz motyw (jasny / ciemny)"><i class="<?= $theme === 'light' ? 'ri-sun-line' : 'ri-moon-line' ?>" id="themeIcon" aria-hidden="true"></i></button>
        </div>
    </div>
</header>

<!-- Powiadomienia: nakładka (nie przesuwa treści). Komunikaty zawsze od serwera. -->
<div id="toasts" class="toasts" aria-live="polite">
    <?php if ($success !== ''): ?><div class="toast toast--ok" role="status"><i class="ri-checkbox-circle-line" aria-hidden="true"></i><div class="toast-text"><?= Html::e($success) ?></div><button type="button" class="toast-close" aria-label="Zamknij powiadomienie"><i class="ri-close-line" aria-hidden="true"></i></button></div><?php endif; ?>
    <?php if ($error !== ''): ?><div class="toast toast--err" role="alert"><i class="ri-error-warning-line" aria-hidden="true"></i><div class="toast-text"><?= Html::e($error) ?></div><button type="button" class="toast-close" aria-label="Zamknij powiadomienie"><i class="ri-close-line" aria-hidden="true"></i></button></div><?php endif; ?>
</div>
<div id="session-banner" class="session-banner" role="alert" hidden>
    <i class="ri-time-line" aria-hidden="true"></i> <span id="session-banner-text">Sesja administratora wygasła.</span>
    <a href="../portal.php" class="btn btn-small">Zaloguj się ponownie</a>
</div>

<main class="container" id="main">
    <noscript><div class="alert alert-error">JavaScript jest wyłączony: formularze działają, ale każda zmiana przeładuje stronę.</div></noscript>

    <div class="stats">
        <div class="stat-card"><h3>Użytkownicy</h3><div class="value" id="stat-users"><?= (int) $stats['total_users'] ?></div></div>
        <div class="stat-card"><h3>Rejestracje dzisiaj</h3><div class="value" id="stat-today"><?= (int) $stats['today_registrations'] ?></div></div>
        <div class="stat-card"><h3>Bany IP</h3><div class="value" id="stat-banned-ips"><?= (int) $stats['banned_ips'] ?></div></div>
        <div class="stat-card"><h3>Próby włamań (24h)</h3><div class="value" id="stat-failed"><?= (int) $stats['failed_logins_24h'] ?></div></div>
    </div>

    <div class="tabs" role="tablist" aria-label="Sekcje panelu">
        <?php foreach ($tabs as $id => [$icon, $label]): ?>
            <button type="button" class="tab<?= $id === $tab ? ' active' : '' ?>" role="tab" id="tab-<?= $id ?>" data-tab="<?= $id ?>"
                    aria-controls="<?= $id ?>" aria-selected="<?= $id === $tab ? 'true' : 'false' ?>" tabindex="<?= $id === $tab ? '0' : '-1' ?>"><i class="<?= $icon ?>" aria-hidden="true"></i> <?= $label ?></button>
        <?php endforeach; ?>
    </div>

    <!-- USTAWIENIA -->
    <section <?= $panel('settings') ?>>
        <h2><i class="ri-hard-drive-2-line" aria-hidden="true"></i> Konfiguracja Systemu</h2>
        <form method="POST" data-ajax data-confirm-check="apply_quota_to_all">
            <input type="hidden" name="_csrf" value="<?= Html::e($csrf) ?>">
            <input type="hidden" name="_tab" value="settings">
            <input type="hidden" name="update_settings" value="1">
            <div class="settings-grid">
                <div class="settings-card">
                    <div class="settings-card-header"><i class="ri-mail-settings-line" aria-hidden="true"></i> Limity Poczty</div>
                    <div class="settings-card-body">
                        <div class="form-group">
                            <label for="set_quota_value">Domyślny limit (Quota)</label>
                            <div class="quota-input-group">
                                <input type="number" id="set_quota_value" name="mailbox_quota_value" step="0.01" min="0" value="<?= Html::e((string) $s['mailbox_quota_value']) ?>">
                                <select name="mailbox_quota_unit" aria-label="Jednostka limitu">
                                    <option value="KiB" <?= $s['mailbox_quota_unit'] === 'KiB' ? 'selected' : '' ?>>KiB</option>
                                    <option value="MiB" <?= $s['mailbox_quota_unit'] === 'MiB' ? 'selected' : '' ?>>MiB</option>
                                    <option value="GiB" <?= $s['mailbox_quota_unit'] === 'GiB' ? 'selected' : '' ?>>GiB</option>
                                </select>
                            </div>
                            <div class="checkbox-warning-wrapper">
                                <input type="checkbox" name="apply_quota_to_all" id="apply_quota_check">
                                <label for="apply_quota_check">Zastosuj teraz dla wszystkich kont (oprócz admin@) — jednorazowo</label>
                            </div>
                        </div>
                        <div class="form-group">
                            <label for="set_daily_limit">Limit wysyłki (odbiorców na dzień)</label>
                            <input type="number" id="set_daily_limit" name="daily_send_limit" min="0" value="<?= (int) $s['daily_send_limit'] ?>">
                            <div class="help-text">Liczy ODBIORCÓW (wiadomość do 20 adresów = 20), egzekwowany przy wysyłce SMTP (587/465, także webmail). <code>0</code> = bez limitu. Limit indywidualny ustawisz w edycji quoty użytkownika.</div>
                        </div>
                    </div>
                </div>

                <div class="settings-card">
                    <div class="settings-card-header"><i class="ri-user-add-line" aria-hidden="true"></i> Rejestracja</div>
                    <div class="settings-card-body">
                        <div class="form-group flex-align-center">
                            <input type="checkbox" name="registration_enabled" id="reg_en" <?= $s['registration_enabled'] === '1' ? 'checked' : '' ?>>
                            <label for="reg_en" class="no-margin">Włącz rejestrację publiczną</label>
                        </div>
                        <div class="form-group">
                            <label for="set_acc_ip">Limit kont na IP</label>
                            <div class="flex-row-center">
                                <input type="number" id="set_acc_ip" name="accounts_per_ip" min="1" value="<?= (int) $s['accounts_per_ip'] ?>">
                                <span aria-hidden="true">/</span>
                                <input type="number" name="ip_window_hours" min="1" value="<?= (int) $s['ip_window_hours'] ?>" aria-label="Okno w godzinach">
                                <span>h</span>
                            </div>
                        </div>
                        <div class="form-group">
                            <label for="set_ip_total">Całkowity limit kont dla IP</label>
                            <input type="number" id="set_ip_total" name="ip_total_limit" min="1" value="<?= (int) $s['ip_total_limit'] ?>">
                            <div class="help-text">Limity liczone na adres IPv4 albo na prefiks /64 dla IPv6.</div>
                        </div>
                        <div class="form-group">
                            <label for="set_reserved">Zarezerwowane nazwy kont</label>
                            <textarea id="set_reserved" name="reserved_local_parts" rows="3" spellcheck="false"><?= Html::e((string) $s['reserved_local_parts']) ?></textarea>
                            <div class="help-text">
                                Rejestracja publiczna nie odda tych nazw (po przecinku, <code>*</code> = dowolny ciąg). Zawsze zarezerwowane, niezależnie od listy:
                                <code><?= Html::e(implode(', ', $s['reserved_base'])) ?></code>.
                                Administrator może założyć taką skrzynkę świadomie (Użytkownicy → Dodaj, z zaznaczoną zgodą). Nazwy, która jest adresem aliasu, nie da się zająć nawet ze zgodą — najpierw trzeba usunąć alias.
                            </div>
                        </div>
                        <div class="form-group">
                            <label for="set_hold">Blokada usuniętych nazw (dni)</label>
                            <input type="number" id="set_hold" name="deleted_name_hold_days" min="0" max="3650" value="<?= (int) $s['deleted_name_hold_days'] ?>">
                            <div class="help-text">Po usunięciu konta jego nazwa nie wróci do rejestracji publicznej przez tyle dni (nowy właściciel nie odziedziczy cudzej korespondencji). <code>0</code> = bez blokady.</div>
                        </div>
                    </div>
                </div>

                <div class="settings-card">
                    <div class="settings-card-header"><i class="ri-shield-check-line" aria-hidden="true"></i> Google reCAPTCHA</div>
                    <div class="settings-card-body">
                        <div class="form-group flex-row-center">
                            <input type="checkbox" name="recaptcha_enabled" id="rc_en" <?= $s['recaptcha_enabled'] === '1' ? 'checked' : '' ?>>
                            <label for="rc_en" class="no-margin">Aktywne</label>
                        </div>
                        <div class="form-group">
                            <label for="set_rc_site">Site Key</label>
                            <input type="text" id="set_rc_site" name="recaptcha_site_key" value="<?= Html::e((string) $s['recaptcha_site_key']) ?>" autocomplete="off" spellcheck="false">
                        </div>
                        <div class="form-group">
                            <label for="set_rc_secret">Secret Key</label>
                            <input type="password" name="recaptcha_secret_key" value="" id="set_rc_secret" autocomplete="new-password"
                                   data-placeholder-set="•••••••• zapisany — zostaw puste, aby nie zmieniać"
                                   placeholder="<?= $s['recaptcha_secret_set'] ? '•••••••• zapisany — zostaw puste, aby nie zmieniać' : 'wklej Secret Key' ?>">
                            <div class="help-text">Sekret nie jest wyświetlany po zapisaniu. Puste pole zostawia zapisany klucz.</div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="form-actions mt-4"><button type="submit" name="update_settings" class="btn"><i class="ri-save-line" aria-hidden="true"></i> Zapisz zmiany</button></div>
        </form>
    </section>

    <!-- UŻYTKOWNICY -->
    <section <?= $panel('users') ?>>
        <div class="tab-head">
            <h2><i class="ri-user-line" aria-hidden="true"></i> Lista Kont Pocztowych</h2>
            <button type="button" class="btn" data-action="add-user"><i class="ri-user-add-line" aria-hidden="true"></i> Dodaj użytkownika</button>
        </div>
        <div class="search-bar">
            <div class="adv-search">
                <input type="search" id="user-search-input" autocomplete="off" aria-label="Szukaj kont" aria-describedby="user-search-hint"
                       placeholder="Szukaj: e-mail, IP, data, użycie (np. >50%) — warunki łącz średnikiem ;">
                <button type="button" class="adv-clear" data-adv-clear="user-search-input" aria-label="Wyczyść wyszukiwanie" hidden><i class="ri-close-line" aria-hidden="true"></i></button>
            </div>
        </div>
        <div class="help-text search-hint" id="user-search-hint">Np. <code>jan; 192.168.; 2026-07; &gt;50%; użycie &gt; 20 KiB; quota &gt;= 1 GiB</code> — wiersz musi spełnić każdy warunek (rozdzielaj średnikiem). Warunki użycia/procentu filtrują bieżącą stronę (dane z Dovecot).</div>
        <div class="users-topbar">
            <div>
                <span id="total-results-text" aria-live="polite">Łącznie kont: <b><?= (int) $lists['users']['total'] ?></b></span>
                <span class="sort-hint" title="Kliknij nagłówek: sortuj. Przytrzymaj Shift i klikaj: sortowanie wielokolumnowe."><i class="ri-information-line" aria-hidden="true"></i> Shift+klik = sortuj po wielu kolumnach</span>
            </div>
            <div class="users-topbar-right"><?= $perSel('perPage', 'users') ?></div>
        </div>
        <div class="table-responsive" data-list="users">
            <table>
                <thead><tr>
                    <?= $th('users', 'id', 'ID', 'DESC') ?>
                    <?= $th('users', 'email', 'Email') ?>
                    <?= $th('users', 'created', 'Utworzono') ?>
                    <?= $th('users', 'last_login', 'Ostatnie logowanie') ?>
                    <?= $th('users', 'reg_ip', 'IP Rejestracji') ?>
                    <th scope="col">Ostatnie IP</th>
                    <?= $th('users', 'quota', 'Quota', '', $qsort) ?>
                    <?= $th('users', 'usage', 'Użycie', '', '', 'Sortowanie po użyciu działa w obrębie bieżącej strony (dane doczytywane z Dovecot)') ?>
                    <th scope="col">Akcje</th>
                </tr></thead>
                <tbody id="users-tbody"><?= $lists['users']['rows'] ?></tbody>
            </table>
        </div>
        <div id="users-pagination" class="pagination-container"><?= $lists['users']['pages'] ?></div>
    </section>

    <!-- BANY -->
    <section <?= $panel('bans') ?>>
        <div class="tab-head">
            <h2><i class="ri-prohibited-line" aria-hidden="true"></i> Zablokowane Adresy IP</h2>
            <button type="button" class="btn btn-warning" data-action="open-scanner"><i class="ri-radar-line" aria-hidden="true"></i> Skaner podejrzanych IP</button>
        </div>
        <?php if (!$f2bAvail): ?>
            <div class="ds-msg ds-msg--err error-container">
                <strong><i class="ri-alert-line" aria-hidden="true"></i> Brak dostępu do Fail2Ban</strong><br>
                Panel nie może pobrać listy banów z systemu (usługa nieuruchomiona lub brak uprawnień sudo dla użytkownika PHP panelu — domyślnie <code>mailweb</code>, osobna pula; sprawdź: <code>sudo -l -U mailweb</code>).
            </div>
        <?php endif; ?>

        <?php if ($f2bAvail && $jails !== []): ?>
        <div class="settings-card manual-ban-card">
            <div class="settings-card-header"><i class="ri-hammer-line" aria-hidden="true"></i> Ręczna blokada IP (Fail2Ban)</div>
            <div class="settings-card-body">
                <form method="POST" class="manual-ban-form" data-ajax data-confirm="ban">
                    <input type="hidden" name="_csrf" value="<?= Html::e($csrf) ?>">
                    <input type="hidden" name="f2b_ban" value="1">
                    <input type="hidden" name="_tab" value="bans">
                    <div class="manual-ban-field grow">
                        <label for="f2b_ip">Adres IP</label>
                        <input type="text" id="f2b_ip" name="f2b_ip" required placeholder="np. 203.0.113.7"
                               pattern="[0-9a-fA-F.:]+" autocomplete="off" spellcheck="false">
                    </div>
                    <div class="manual-ban-field">
                        <label for="f2b_jail">Jail</label>
                        <select id="f2b_jail" name="f2b_jail">
                            <?php foreach ($jails as $j): ?>
                                <option value="<?= Html::e($j) ?>" <?= $j === 'recidive' ? 'selected' : '' ?>><?= Html::e($j) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <button type="submit" class="btn btn-danger"><i class="ri-forbid-line" aria-hidden="true"></i> Zbanuj</button>
                </form>
                <div class="help-text">Ban trwa zgodnie z <code>bantime</code> danego jaila (recidive = najdłużej). Czas liczy Fail2Ban.</div>
            </div>
        </div>
        <?php endif; ?>

        <div class="search-bar">
            <div class="adv-search">
                <input type="search" id="ban-search-input" autocomplete="off" aria-label="Szukaj w blokadach"
                       placeholder="Szukaj w blokadach: IP, jail, powód, data wygaśnięcia — warunki łącz średnikiem ;">
                <button type="button" class="adv-clear" data-adv-clear="ban-search-input" aria-label="Wyczyść wyszukiwanie" hidden><i class="ri-close-line" aria-hidden="true"></i></button>
            </div>
        </div>
        <div class="table-responsive" data-list="bans">
            <table>
                <thead><tr>
                    <?= $th('bans', '0', 'Usługa / Jail') ?>
                    <?= $th('bans', '1', 'IP') ?>
                    <?= $th('bans', '2', 'Powód') ?>
                    <?= $th('bans', '3', 'Wygasa') ?>
                    <th scope="col">Akcja</th>
                </tr></thead>
                <tbody id="bans-tbody"><?= $bansRowsHtml ?></tbody>
            </table>
        </div>
    </section>

    <!-- LOGI -->
    <section <?= $panel('logs') ?>>
        <div class="search-bar">
            <div class="adv-search">
                <input type="search" id="log-search-input" autocomplete="off" aria-label="Szukaj we wszystkich logach" aria-describedby="log-search-hint"
                       placeholder="Szukaj we wszystkich logach: IP, e-mail, status, szczegóły, data — warunki łącz średnikiem ;">
                <button type="button" class="adv-clear" data-adv-clear="log-search-input" aria-label="Wyczyść wyszukiwanie" hidden><i class="ri-close-line" aria-hidden="true"></i></button>
            </div>
        </div>
        <div class="help-text search-hint" id="log-search-hint">Filtruje jednocześnie wszystkie trzy tabele. Np. <code>185.239.; błąd</code> = zdarzenia z tym IP <b>i</b> statusem błędu. Daty: <code>2026-07-10</code>. Nieudane potwierdzenia hasłem: <code>REAUTH</code>.</div>

        <h2><i class="ri-history-line" aria-hidden="true"></i> Ostatnie Zdarzenia Rejestracji</h2>
        <div class="users-topbar">
            <div id="reg-total-text" aria-live="polite">Łącznie zdarzeń: <b><?= (int) $lists['reg']['total'] ?></b></div>
            <div class="users-topbar-right"><?= $perSel('regPerPage', 'reg') ?></div>
        </div>
        <div class="table-responsive" data-list="reg">
            <table><thead><tr>
                <?= $th('reg', 'created', 'Czas') ?>
                <?= $th('reg', 'ip', 'IP') ?>
                <?= $th('reg', 'email', 'Email') ?>
                <?= $th('reg', 'status', 'Status') ?>
                <th scope="col">Info</th>
            </tr></thead>
                <tbody id="reg-logs-tbody"><?= $lists['reg']['rows'] ?></tbody></table>
        </div>
        <div id="reg-logs-pagination" class="pagination-container"><?= $lists['reg']['pages'] ?></div>

        <hr class="safe-hr">
        <h2><i class="ri-shield-user-line" aria-hidden="true"></i> Logi Logowania Administratorów</h2>
        <div class="users-topbar">
            <div id="admin-total-text" aria-live="polite">Łącznie logów: <b><?= (int) $lists['admin']['total'] ?></b></div>
            <div class="users-topbar-right"><?= $perSel('adminPerPage', 'admin') ?></div>
        </div>
        <div class="table-responsive" data-list="admin">
            <table><thead><tr>
                <?= $th('admin', 'created', 'Czas') ?>
                <?= $th('admin', 'email', 'Email (Admin)') ?>
                <?= $th('admin', 'ip', 'IP') ?>
                <?= $th('admin', 'status', 'Status') ?>
                <th scope="col">Szczegóły</th>
            </tr></thead>
                <tbody id="admin-logs-tbody"><?= $lists['admin']['rows'] ?></tbody></table>
        </div>
        <div id="admin-logs-pagination" class="pagination-container"><?= $lists['admin']['pages'] ?></div>

        <hr class="safe-hr">
        <h2><i class="ri-file-shield-2-line" aria-hidden="true"></i> Dziennik Audytowy</h2>
        <div class="users-topbar">
            <div id="audit-total-text" aria-live="polite">Łącznie wpisów: <b><?= (int) $lists['audit']['total'] ?></b></div>
            <div class="users-topbar-right">
                <?= $perSel('auditPerPage', 'audit') ?>
                <button type="button" class="btn btn-small btn-secondary" data-action="refresh-audit"><i class="ri-refresh-line" aria-hidden="true"></i> Odśwież</button>
            </div>
        </div>
        <div class="table-responsive" data-list="audit">
            <table><thead><tr>
                <?= $th('audit', 'created', 'Data') ?>
                <?= $th('audit', 'admin', 'Administrator') ?>
                <?= $th('audit', 'ip', 'IP Admina') ?>
                <?= $th('audit', 'action', 'Akcja') ?>
                <th scope="col">Szczegóły</th>
            </tr></thead>
                <tbody id="audit-logs-tbody"><?= $lists['audit']['rows'] ?></tbody></table>
        </div>
        <div id="audit-logs-pagination" class="pagination-container"><?= $lists['audit']['pages'] ?></div>
    </section>

    <!-- FAIL2BAN -->
    <section <?= $panel('fail2ban') ?>>
        <h2><i class="ri-fire-line" aria-hidden="true"></i> Konfiguracja Fail2Ban</h2>
        <?php if (!$f2b['available']): ?>
            <div class="alert alert-error">Plik jail.local nie istnieje albo brak uprawnień odczytu (integracja dostępna tylko na serwerze).</div>
        <?php else: ?>
        <form method="POST" data-ajax data-busy-label="Zapisywanie i przeładowanie Fail2Ban…">
            <input type="hidden" name="_csrf" value="<?= Html::e($csrf) ?>">
            <input type="hidden" name="save_fail2ban" value="1">
            <input type="hidden" name="_tab" value="fail2ban">
            <div class="f2b-card f2b-card--default">
                <h3><i class="ri-settings-line" aria-hidden="true"></i> Ustawienia główne [DEFAULT]</h3>
                <div class="form-group"><label for="f2b_default_findtime">findtime</label><input type="text" id="f2b_default_findtime" name="f2b_default_findtime" value="<?= $fv('f2b_default_findtime') ?>"></div>
                <div class="form-group"><label for="f2b_default_bantime">bantime</label><input type="text" id="f2b_default_bantime" name="f2b_default_bantime" value="<?= $fv('f2b_default_bantime') ?>"></div>
                <div class="form-group"><label for="f2b_default_maxretry">maxretry</label><input type="number" id="f2b_default_maxretry" name="f2b_default_maxretry" value="<?= $fv('f2b_default_maxretry') ?>"></div>
                <div class="form-group"><label for="f2b_default_bantime_factor">bantime.factor</label><input type="text" id="f2b_default_bantime_factor" name="f2b_default_bantime_factor" value="<?= $fv('f2b_default_bantime_factor') ?>"></div>
                <div class="form-group"><label for="f2b_default_bantime_maxtime">bantime.maxtime</label><input type="text" id="f2b_default_bantime_maxtime" name="f2b_default_bantime_maxtime" value="<?= $fv('f2b_default_bantime_maxtime') ?>"></div>
                <div class="form-group checkbox-group f2b-inc-check">
                    <input type="checkbox" id="f2b_inc" name="f2b_default_bantime_increment" value="1" <?= $fc('f2b_default_bantime_increment') ? 'checked' : '' ?>>
                    <label for="f2b_inc">Wydłużanie bana (recydywa)</label>
                </div>
                <div class="form-group f2b-span"><label for="f2b_default_ignoreip">ignoreip (oddziel spacją)</label><textarea id="f2b_default_ignoreip" name="f2b_default_ignoreip" rows="2" class="textarea-full" spellcheck="false"><?= $fv('f2b_default_ignoreip') ?></textarea></div>
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
                    <div class="form-group checkbox-group"><input type="checkbox" id="f2b_<?= $prefix ?>_en" name="f2b_<?= $prefix ?>_enabled" value="1" <?= $fc("f2b_{$prefix}_enabled") ? 'checked' : '' ?>><label for="f2b_<?= $prefix ?>_en">Włącz ochronę</label></div>
                    <div class="form-group"><label for="f2b_<?= $prefix ?>_findtime">findtime</label><input type="text" id="f2b_<?= $prefix ?>_findtime" name="f2b_<?= $prefix ?>_findtime" value="<?= $fv("f2b_{$prefix}_findtime") ?>"></div>
                    <div class="form-group"><label for="f2b_<?= $prefix ?>_maxretry">maxretry</label><input type="number" id="f2b_<?= $prefix ?>_maxretry" name="f2b_<?= $prefix ?>_maxretry" value="<?= $fv("f2b_{$prefix}_maxretry") ?>"></div>
                    <div class="form-group"><label for="f2b_<?= $prefix ?>_bantime">bantime</label><input type="text" id="f2b_<?= $prefix ?>_bantime" name="f2b_<?= $prefix ?>_bantime" value="<?= $fv("f2b_{$prefix}_bantime") ?>"></div>
                </div>
                <?php endforeach; ?>
            </div>
            <div class="form-actions mt-4"><button type="submit" class="btn btn-dark"><i class="ri-refresh-line" aria-hidden="true"></i> Zapisz i przeładuj Fail2Ban</button></div>
        </form>
        <?php endif; ?>
    </section>

    <!-- BEZPIECZEŃSTWO -->
    <section <?= $panel('security') ?>>
        <h2><i class="ri-lock-line" aria-hidden="true"></i> Bezpieczeństwo</h2>
        <div class="settings-grid">
            <div class="settings-card">
                <div class="settings-card-header"><i class="ri-spam-2-line" aria-hidden="true"></i> Ochrona Anty-Spam</div>
                <div class="settings-card-body">
                    <form method="POST" data-ajax data-busy-label="Zapisywanie i aplikowanie…">
                        <input type="hidden" name="_csrf" value="<?= Html::e($csrf) ?>">
                        <input type="hidden" name="_tab" value="security">
                        <input type="hidden" name="update_antispam" value="1">
                        <h3 class="section-title">Roundcube (Webmail)</h3>
                        <div class="form-group"><label for="sec_rc_max">Max odbiorców w 1 mailu</label><input type="number" id="sec_rc_max" name="rc_max_recipients" value="<?= (int) $s['rc_max_recipients'] ?>"></div>
                        <div class="form-group"><label for="sec_rc_delay">Opóźnienie wysyłki (s)</label><input type="number" id="sec_rc_delay" name="rc_sendmail_delay" value="<?= (int) $s['rc_sendmail_delay'] ?>"></div>
                        <div class="form-group"><label for="sec_rc_login">Limit prób logowania / min</label><input type="number" id="sec_rc_login" name="rc_login_rate_limit" value="<?= (int) $s['rc_login_rate_limit'] ?>"></div>
                        <h3 class="section-title">Postfix (Klienci zewnętrzni)</h3>
                        <?php if (!$pfAvail): ?>
                            <div class="ds-msg ds-msg--err error-container"><strong><i class="ri-alert-line" aria-hidden="true"></i> Brak uprawnień sudo</strong> — zmiany zapiszą się tylko w bazie.</div>
                        <?php endif; ?>
                        <div class="form-group"><label for="sec_pf_msg">Wiadomości / min (1 IP)</label><input type="number" id="sec_pf_msg" name="pf_message_rate_limit" value="<?= (int) $s['pf_message_rate_limit'] ?>"></div>
                        <div class="form-group"><label for="sec_pf_conn">Połączeń / min (1 IP)</label><input type="number" id="sec_pf_conn" name="pf_connection_rate_limit" value="<?= (int) $s['pf_connection_rate_limit'] ?>"></div>
                        <div class="form-group"><label for="sec_pf_cnt">Równoległych połączeń (1 IP)</label><input type="number" id="sec_pf_cnt" name="pf_connection_count_limit" value="<?= (int) $s['pf_connection_count_limit'] ?>"></div>
                        <div class="mt-4"><button type="submit" name="update_antispam" class="btn"><i class="ri-shield-check-line" aria-hidden="true"></i> Zapisz i Aplikuj</button></div>
                    </form>
                </div>
            </div>

            <div class="settings-card">
                <div class="settings-card-header"><i class="ri-shield-flash-line" aria-hidden="true"></i> Ochrona Panelu</div>
                <div class="settings-card-body">
                    <form method="POST" data-ajax>
                        <input type="hidden" name="_csrf" value="<?= Html::e($csrf) ?>">
                        <input type="hidden" name="_tab" value="security">
                        <input type="hidden" name="update_security" value="1">
                        <div class="form-group"><label for="sec_max_login">Max. nieudanych prób logowania</label><input type="number" id="sec_max_login" name="max_login_attempts" min="1" value="<?= (int) $s['max_login_attempts'] ?>"></div>
                        <div class="form-group"><label for="sec_ban_min">Okno / czas blokady (min)</label><input type="number" id="sec_ban_min" name="ban_duration_minutes" min="1" value="<?= (int) $s['ban_duration_minutes'] ?>">
                            <div class="help-text">Limit dotyczy pary (konto, adres klienta) oraz — na poziomie 4× większym — samego adresu klienta (IPv4 albo prefiks /64 IPv6). Konto nigdy nie jest blokowane globalnie, więc obcy nie zablokuje Ci panelu; adresy z <code>SCAN_TRUSTED_IPS</code> nie podlegają limitom.</div></div>
                        <div class="mt-4"><button type="submit" name="update_security" class="btn"><i class="ri-save-line" aria-hidden="true"></i> Zapisz</button></div>
                    </form>
                </div>
            </div>

            <div class="settings-card">
                <div class="settings-card-header"><i class="ri-key-2-line" aria-hidden="true"></i> Zmiana Hasła Admina</div>
                <div class="settings-card-body">
                    <form method="POST" data-ajax>
                        <input type="hidden" name="_csrf" value="<?= Html::e($csrf) ?>">
                        <input type="hidden" name="_tab" value="security">
                        <input type="hidden" name="change_password" value="1">
                        <input type="text" name="username" value="<?= Html::e($admin) ?>" autocomplete="username" hidden readonly tabindex="-1">
                        <div class="form-group"><label for="cp_current">Obecne hasło</label><input type="password" id="cp_current" name="current_password" required autocomplete="current-password"></div>
                        <div class="form-group"><label for="cp_new">Nowe hasło</label><input type="password" id="cp_new" name="new_password" required minlength="12" autocomplete="new-password" aria-describedby="cp_help"><div class="help-text" id="cp_help">Min. 12 znaków i klasy znaków jak dla skrzynek. Zmiana wylogowuje inne sesje tego konta. Błędne obecne hasło trafia do dziennika audytowego; po <?= (int) $reauthLimit ?> błędach sesja się kończy.</div></div>
                        <div class="form-group"><label for="cp_confirm">Powtórz nowe hasło</label><input type="password" id="cp_confirm" name="confirm_password" required autocomplete="new-password"></div>
                        <div class="mt-4"><button type="submit" name="change_password" class="btn"><i class="ri-refresh-line" aria-hidden="true"></i> Zmień hasło</button></div>
                    </form>
                </div>
            </div>
        </div>
    </section>
</main>

<!-- MODAL: QUOTA -->
<div id="quotaModal" class="modal" role="dialog" aria-modal="true" aria-labelledby="quotaModalTitle" hidden>
    <div class="modal-content">
        <div class="modal-header"><h2 id="quotaModalTitle"><i class="ri-hard-drive-2-line" aria-hidden="true"></i> Zmiana Quota</h2><?= $close('quotaModal') ?></div>
        <form method="POST" data-ajax data-modal-form="quotaModal">
            <input type="hidden" name="_csrf" value="<?= Html::e($csrf) ?>">
            <input type="hidden" name="update_user_quota" value="1">
            <input type="hidden" name="_tab" value="users">
            <input type="hidden" name="user_id" id="quota_user_id">
            <div class="form-group"><label for="quota_user_email">Użytkownik</label><input type="text" id="quota_user_email" readonly class="input-readonly"></div>
            <div class="form-group">
                <label for="quota_value">Nowy limit skrzynki</label>
                <div class="quota-input-group">
                    <input type="number" name="user_quota_value" id="quota_value" step="0.01" min="0" required>
                    <select name="user_quota_unit" id="quota_unit" aria-label="Jednostka"><option value="KiB">KiB</option><option value="MiB" selected>MiB</option><option value="GiB">GiB</option></select>
                </div>
            </div>
            <div class="form-group">
                <label for="quota_send_limit">Indywidualny limit wysyłki (odbiorców / dzień)</label>
                <input type="number" name="user_send_limit" id="quota_send_limit" min="0" step="1" placeholder="puste = globalny (<?= (int) $s['daily_send_limit'] ?>)" data-placeholder-bind="daily_send_limit">
                <div class="help-text">Puste = limit globalny (<b data-bind="daily_send_limit"><?= (int) $s['daily_send_limit'] ?></b> odbiorców/dzień). <code>0</code> = bez limitu.</div>
            </div>
            <div class="form-group">
                <span class="label-like">Wysłane dzisiaj</span>
                <div id="quota_sent_today" class="sent-today-box">…</div>
            </div>
            <div class="form-msg" aria-live="assertive"></div>
            <div class="modal-footer"><button type="button" class="btn btn-secondary" data-action="modal-close" data-modal="quotaModal">Anuluj</button><button type="submit" class="btn"><i class="ri-save-line" aria-hidden="true"></i> Zapisz</button></div>
        </form>
    </div>
</div>

<!-- MODAL: RESET HASŁA SKRZYNKI -->
<div id="resetPassModal" class="modal" role="dialog" aria-modal="true" aria-labelledby="resetPassTitle" hidden>
    <div class="modal-content">
        <div class="modal-header"><h2 id="resetPassTitle"><i class="ri-key-2-line" aria-hidden="true"></i> Zmiana hasła skrzynki</h2><?= $close('resetPassModal') ?></div>
        <form method="POST" data-ajax data-modal-form="resetPassModal">
            <input type="hidden" name="_csrf" value="<?= Html::e($csrf) ?>">
            <input type="hidden" name="reset_user_password" value="1">
            <input type="hidden" name="_tab" value="users">
            <input type="hidden" name="user_id" id="rp_user_id">
            <input type="hidden" name="user_email" id="rp_user_email_hidden">
            <div class="form-group"><label for="rp_user_email">Użytkownik</label><input type="text" id="rp_user_email" readonly class="input-readonly" autocomplete="off"></div>
            <div class="form-group">
                <label for="rp_password">Nowe hasło</label>
                <input type="password" name="new_password" id="rp_password" required minlength="<?= (int) $pwd['min'] ?>" autocomplete="new-password" placeholder="Nowe hasło skrzynki" aria-describedby="rp_pwd_reqs">
                <div class="pwd-meter" aria-hidden="true"><div class="pwd-meter-fill" id="rp_pwd_bar"></div></div>
                <ul class="pwd-reqs" id="rp_pwd_reqs">
                    <li data-req="len">Min. <?= (int) $pwd['min'] ?> znaków</li>
                    <?php if ($pwd['upper']): ?><li data-req="up">Duża litera</li><?php endif; ?>
                    <?php if ($pwd['digit']): ?><li data-req="dig">Cyfra</li><?php endif; ?>
                    <?php if ($pwd['spec']): ?><li data-req="spec">Znak specjalny</li><?php endif; ?>
                </ul>
            </div>
            <div class="form-group">
                <label for="rp_password2">Powtórz nowe hasło</label>
                <input type="password" name="new_password2" id="rp_password2" required autocomplete="new-password" placeholder="Powtórz hasło">
                <div id="rp_pass_match" class="avail-status" aria-live="polite"></div>
                <div class="help-text">Zmiana natychmiastowa — przekaż nowe hasło użytkownikowi bezpiecznym kanałem. Aktywne sesje webmaila mogą wymagać ponownego logowania.</div>
            </div>
            <div id="rp_form_msg" class="form-msg" aria-live="assertive"></div>
            <div class="modal-footer"><button type="button" class="btn btn-secondary" data-action="modal-close" data-modal="resetPassModal">Anuluj</button><button type="submit" class="btn"><i class="ri-save-line" aria-hidden="true"></i> Zmień hasło</button></div>
        </form>
    </div>
</div>

<!-- MODAL: BAN -->
<div id="banModal" class="modal" role="dialog" aria-modal="true" aria-labelledby="banModalTitle" hidden>
    <div class="modal-content">
        <div class="modal-header"><h2 id="banModalTitle"><i class="ri-forbid-line" aria-hidden="true"></i> Zbanuj adres IP</h2><?= $close('banModal') ?></div>
        <form method="POST" data-ajax data-modal-form="banModal">
            <input type="hidden" name="_csrf" value="<?= Html::e($csrf) ?>">
            <input type="hidden" name="ban_user_ip" value="1">
            <input type="hidden" name="_tab" value="users">
            <div class="form-group"><label for="ban_user_email">Użytkownik</label><input type="text" id="ban_user_email" readonly class="input-readonly"></div>
            <div class="form-group">
                <label for="ban_ip_select">Wybierz IP do zbanowania</label>
                <select name="ban_ip" id="ban_ip_select" required><option value="">-- Wybierz IP --</option></select>
                <div class="help-text">Lista IP, z których użytkownik się logował</div>
            </div>
            <div class="form-group">
                <label for="ban_reason_select">Powód bana</label>
                <select id="ban_reason_select">
                    <option value="Spam">Spam</option><option value="Próba włamania">Próba włamania</option>
                    <option value="Nadużycie zasobów">Nadużycie zasobów</option><option value="Naruszenie regulaminu">Naruszenie regulaminu</option>
                    <option value="Phishing">Phishing</option><option value="custom">Własny powód...</option>
                </select>
                <input type="hidden" name="ban_reason" id="ban_reason_input" value="Spam">
            </div>
            <div class="form-group" id="custom_reason_group" hidden><label for="ban_custom_reason">Własny powód</label><input type="text" id="ban_custom_reason" placeholder="Wpisz powód..."></div>
            <div class="form-group"><label for="ban_note">Dodatkowa uwaga</label><textarea id="ban_note" name="ban_note" rows="2" placeholder="Opcjonalnie..."></textarea></div>
            <div class="form-group"><label for="ban_duration">Czas bana (minuty)</label><input type="number" id="ban_duration" name="ban_duration" value="60" min="1" max="129600"><div class="help-text">1440 = 1 dzień, 10080 = 1 tydzień, maks. 129600 (90 dni).<br>Zakres: blokuje IP w portalu i panelu oraz — gdy Fail2Ban jest dostępny — w jailu <code>panel-tempban</code> na firewallu (webmail, IMAP, SMTP). Komunikat po zapisie mówi, które warstwy zadziałały.</div></div>
            <div class="form-msg" aria-live="assertive"></div>
            <div class="modal-footer"><button type="button" class="btn btn-secondary" data-action="modal-close" data-modal="banModal">Anuluj</button><button type="submit" class="btn btn-danger"><i class="ri-forbid-line" aria-hidden="true"></i> Zbanuj IP</button></div>
        </form>
    </div>
</div>

<!-- MODAL: DELETE -->
<div id="deleteUserModal" class="modal" role="dialog" aria-modal="true" aria-labelledby="deleteUserTitle" aria-describedby="deleteUserWhat" hidden>
    <div class="modal-content">
        <div class="modal-header"><h2 id="deleteUserTitle" class="danger-title"><i class="ri-alarm-warning-line" aria-hidden="true"></i> Usuwanie konta</h2><?= $close('deleteUserModal') ?></div>
        <div class="delete-warning-box" id="deleteUserWhat">
            <strong>Uwaga!</strong> Konto znika z bazy od razu: nie da się już na nie zalogować, aliasy kierujące na nie są usuwane,
            a nazwa nie wróci do rejestracji publicznej przez <?= (int) $s['deleted_name_hold_days'] ?> dni.
        </div>
        <ul class="delete-details-list">
            <li><span>ID:</span> <strong id="del_user_id_display"></strong></li>
            <li><span>Email:</span> <strong id="del_user_email_display"></strong></li>
            <li><span>Pliki:</span>
                <?php if ($delAvail): ?><span class="badge badge-warning">Przeniesione do kwarantanny <code>/var/mail/deleted</code></span>
                    <div class="help-text">Katalog poczty trafia do <code>/var/mail/deleted/&lt;domena&gt;__&lt;nazwa&gt;__&lt;data&gt;</code> i jest kasowany automatycznie po 30 dniach (timer <code>mailpanel-purge-deleted</code>). Do tego czasu można go odzyskać ręcznie.</div>
                <?php else: ?><span class="badge badge-danger">Zostaną na dysku — brak uprawnień sudo do kwarantanny</span><?php endif; ?>
            </li>
        </ul>
        <form method="POST" data-ajax data-modal-form="deleteUserModal" data-busy-label="Usuwanie…">
            <input type="hidden" name="_csrf" value="<?= Html::e($csrf) ?>">
            <input type="hidden" name="delete_user_confirm" value="1">
            <input type="hidden" name="_tab" value="users">
            <input type="hidden" name="delete_user_id" id="del_user_id_input">
            <input type="hidden" name="delete_user_email" id="del_user_email_input">
            <input type="text" name="username" value="<?= Html::e($admin) ?>" autocomplete="username" hidden readonly tabindex="-1">
            <div class="password-confirm-group"><label for="del_admin_pass">Podaj hasło administratora, aby potwierdzić:</label><input type="password" id="del_admin_pass" name="admin_confirm_password" required placeholder="Hasło admina" autocomplete="current-password" aria-describedby="del_pass_help">
                <div class="help-text" id="del_pass_help">Błędne hasło jest zapisywane w dzienniku audytowym; po <?= (int) $reauthLimit ?> błędach sesja panelu się kończy.</div></div>
            <div class="form-msg" aria-live="assertive"></div>
            <div class="modal-footer"><button type="button" class="btn btn-secondary" data-action="modal-close" data-modal="deleteUserModal">Anuluj</button><button type="submit" class="btn btn-danger"><i class="ri-delete-bin-2-line" aria-hidden="true"></i> Potwierdź usunięcie</button></div>
        </form>
    </div>
</div>

<!-- MODAL: DODAJ UŻYTKOWNIKA -->
<div id="addUserModal" class="modal" role="dialog" aria-modal="true" aria-labelledby="addUserTitle" hidden>
    <div class="modal-content">
        <div class="modal-header"><h2 id="addUserTitle"><i class="ri-user-add-line" aria-hidden="true"></i> Nowe konto pocztowe</h2><?= $close('addUserModal') ?></div>
        <form method="POST" data-ajax data-modal-form="addUserModal" autocomplete="off">
            <input type="hidden" name="_csrf" value="<?= Html::e($csrf) ?>">
            <input type="hidden" name="create_user" value="1">
            <input type="hidden" name="_tab" value="users">
            <div class="form-group">
                <label for="nu_local">Adres e-mail</label>
                <div class="email-compose">
                    <input type="text" name="nu_local" id="nu_local" required placeholder="nazwa" autocomplete="off" maxlength="64" spellcheck="false" autocapitalize="none"
                           pattern="[A-Za-z0-9]([A-Za-z0-9._\-]*[A-Za-z0-9])?" aria-describedby="nu_availability nu_reason_text">
                    <span aria-hidden="true">@</span>
                    <select name="nu_domain" id="nu_domain" aria-label="Domena">
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
                    <label for="nu_allow_reserved">Rozumiem — załóż tę skrzynkę świadomie (zgoda trafi do dziennika audytowego)</label>
                </div>
                <details class="name-rules">
                    <summary>Których nazw nie można użyć?</summary>
                    <ul>
                        <li><b>Zarezerwowane</b> — adresy ról (admin, postmaster, hostmaster, webmaster, abuse, noreply…) i lista z <i>Ustawienia → Rejestracja</i>. Rejestracja publiczna ich nie odda; Ty możesz je założyć <b>ze zgodą</b>.</li>
                        <li><b>Niedawno usunięte</b> i <b>cele aliasów</b> — też ze zgodą (poczta adresowana do poprzedniego właściciela albo z aliasu trafi do nowej skrzynki).</li>
                        <li><b>Adres aliasu</b> — nigdy: Postfix przekierowuje pocztę z aliasu, zanim dotrze do skrzynek, więc skrzynka nic by nie dostała. Najpierw usuń alias (przycisk pojawi się tutaj).</li>
                        <li><b>Istniejące konto</b> — nigdy.</li>
                    </ul>
                </details>
            </div>
            <div class="form-group">
                <label for="nu_password">Hasło</label>
                <input type="password" name="nu_password" id="nu_password" required minlength="<?= (int) $pwd['min'] ?>" autocomplete="new-password" placeholder="Silne hasło" aria-describedby="nu_pwd_reqs">
                <div class="pwd-meter" aria-hidden="true"><div class="pwd-meter-fill" id="nu_pwd_bar"></div></div>
                <ul class="pwd-reqs" id="nu_pwd_reqs">
                    <li data-req="len">Min. <?= (int) $pwd['min'] ?> znaków</li>
                    <?php if ($pwd['upper']): ?><li data-req="up">Duża litera</li><?php endif; ?>
                    <?php if ($pwd['digit']): ?><li data-req="dig">Cyfra</li><?php endif; ?>
                    <?php if ($pwd['spec']): ?><li data-req="spec">Znak specjalny</li><?php endif; ?>
                </ul>
            </div>
            <div class="form-group">
                <label for="nu_password2">Powtórz hasło</label>
                <input type="password" name="nu_password2" id="nu_password2" required autocomplete="new-password" placeholder="Powtórz hasło">
                <div id="nu_pass_match" class="avail-status" aria-live="polite"></div>
                <div class="help-text">Hasło skrzynki (Dovecot). Przekaż je użytkownikowi bezpiecznym kanałem.</div>
            </div>
            <div class="form-group">
                <label for="nu_quota_value">Limit skrzynki (Quota)</label>
                <div class="quota-input-group">
                    <input type="number" name="nu_quota_value" id="nu_quota_value" step="1" min="0" value="<?= (int) $s['mailbox_quota_value'] ?>">
                    <select name="nu_quota_unit" aria-label="Jednostka"><option value="MiB" selected>MiB</option><option value="GiB">GiB</option></select>
                </div>
            </div>
            <div class="form-group">
                <label for="nu_send_limit">Limit wysyłki (odbiorców / dzień)</label>
                <input type="number" name="nu_send_limit" id="nu_send_limit" min="0" step="1" placeholder="puste = globalny (<?= (int) $s['daily_send_limit'] ?>)" data-placeholder-bind="daily_send_limit">
                <div class="help-text">Puste = limit globalny (<b data-bind="daily_send_limit"><?= (int) $s['daily_send_limit'] ?></b> odbiorców/dzień). <code>0</code> = bez limitu.</div>
            </div>
            <div id="nu_form_msg" class="form-msg" aria-live="assertive"></div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-action="modal-close" data-modal="addUserModal">Anuluj</button>
                <button type="submit" class="btn"><i class="ri-user-add-line" aria-hidden="true"></i> Utwórz konto</button>
            </div>
        </form>
    </div>
</div>

<!-- MODAL: ZARZĄDZANIE BANEM -->
<div id="manageBanModal" class="modal" role="dialog" aria-modal="true" aria-labelledby="manageBanTitle" hidden>
    <div class="modal-content">
        <div class="modal-header"><h2 id="manageBanTitle"><i class="ri-shield-keyhole-line" aria-hidden="true"></i> Zarządzaj blokadą</h2><?= $close('manageBanModal') ?></div>
        <ul class="delete-details-list">
            <li><span>IP:</span> <strong id="mb_ip"></strong></li>
            <li><span>Rodzaj:</span> <strong id="mb_type"></strong></li>
        </ul>
        <div id="mb_change_wrap" class="form-group">
            <label for="mb_target_jail">Zmień typ bana / przenieś do jaila</label>
            <div class="manage-ban-row">
                <select id="mb_target_jail">
                    <?php foreach ($jails as $j): ?><option value="<?= Html::e($j) ?>" <?= $j === 'panel-permaban' ? 'selected' : '' ?>><?= Html::e($j) ?><?= $j === 'panel-permaban' ? ' (na stałe)' : ($j === 'panel-tempban' ? ' (czasowy)' : '') ?></option><?php endforeach; ?>
                </select>
                <select id="mb_duration" aria-label="Czas bana" hidden>
                    <option value="3600">1 godz.</option>
                    <option value="21600">6 godz.</option>
                    <option value="86400" selected>24 godz.</option>
                    <option value="259200">3 dni</option>
                    <option value="604800">7 dni</option>
                    <option value="2592000">30 dni</option>
                    <option value="7776000">3 miesiące</option>
                </select>
                <button type="button" class="btn btn-warning" id="mb_move_btn"><i class="ri-arrow-left-right-line" aria-hidden="true"></i> Przenieś</button>
            </div>
            <div class="help-text">Przeniesie IP do wybranego jaila (odbanuje z obecnego, zbanuje w nowym). Dla <code>panel-tempban</code> wybierz czas trwania.</div>
        </div>
        <div class="modal-footer modal-footer--center">
            <button type="button" class="btn btn-secondary" data-action="modal-close" data-modal="manageBanModal">Zamknij</button>
            <button type="button" class="btn btn-success" id="mb_unban_btn"><i class="ri-check-line" aria-hidden="true"></i> Odblokuj całkowicie</button>
        </div>
    </div>
</div>

<!-- MODAL: SKANER PODEJRZANYCH IP -->
<div id="scannerModal" class="modal" role="dialog" aria-modal="true" aria-labelledby="scannerTitle" hidden>
    <div class="modal-content scanner-modal">
        <div class="modal-header"><h2 id="scannerTitle"><i class="ri-radar-line" aria-hidden="true"></i> Skaner podejrzanych IP</h2><?= $close('scannerModal') ?></div>
        <div class="scanner-params">
            <div class="scanner-field"><label for="scan_from">Od (data)</label><input type="date" id="scan_from"></div>
            <div class="scanner-field"><label for="scan_to">Do (data)</label><input type="date" id="scan_to"></div>
            <div class="scanner-field"><label for="scan_min">Min. nieudanych</label><input type="number" id="scan_min" value="10" min="1"></div>
            <button type="button" class="btn" id="scan_run"><i class="ri-search-line" aria-hidden="true"></i> Przeskanuj</button>
        </div>
        <div class="help-text">Źródła: logi systemowe (SSH, Dovecot, Postfix SASL) + baza panelu (nieudane logowania admina, rejestracje). Liczone są wyłącznie <b>nieudane</b> próby uwierzytelnienia. Zaufane IP (serwer, Twoje) są pomijane. Parametry zostają po zamknięciu — zmień i skanuj ponownie.</div>
        <div id="scan_status" class="scan-status" hidden>
            <div class="scan-status-row">
                <span class="scan-status-label" id="scan_status_label"><i class="ri-loader-4-line spin" aria-hidden="true"></i> Przygotowanie…</span>
                <span class="scan-status-count" id="scan_status_count" aria-live="polite"></span>
            </div>
            <div class="scan-progress-bar"><div class="scan-progress-fill" id="scan_progress_fill"></div></div>
            <div class="scan-steps" id="scan_steps"></div>
        </div>
        <div id="scan_ban_msg" aria-live="polite"></div>
        <div id="scan_results" class="scanner-results"><p class="empty-state-text">Ustaw parametry i kliknij „Przeskanuj".</p></div>
    </div>
</div>

<!-- MODAL: POTWIERDZENIE (generyczny) -->
<div id="confirmModal" class="modal" role="alertdialog" aria-modal="true" aria-labelledby="confirm_title" aria-describedby="confirm_msg" hidden>
    <div class="modal-content confirm-modal">
        <div class="modal-header"><h2 id="confirm_title"><i class="ri-error-warning-line" aria-hidden="true"></i> Potwierdzenie</h2><?= $close('confirmModal') ?></div>
        <p id="confirm_msg" class="confirm-msg"></p>
        <div class="form-msg" id="confirm_form_msg" aria-live="assertive"></div>
        <div class="modal-footer modal-footer--center">
            <button type="button" class="btn btn-secondary" data-action="modal-close" data-modal="confirmModal">Anuluj</button>
            <button type="button" class="btn" id="confirm_ok"><i class="ri-check-line" aria-hidden="true"></i> Potwierdź</button>
        </div>
    </div>
</div>

<script src="<?= $av('panel.js') ?>" defer></script>
</body>
</html>
