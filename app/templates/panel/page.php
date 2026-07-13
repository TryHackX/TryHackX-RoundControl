<?php
declare(strict_types=1);

use App\Support\Html;

/**
 * @var string $success
 * @var string $error
 * @var string $csrf
 * @var string $admin
 * @var array  $stats
 * @var array  $bans
 * @var array  $f2b       ['available'=>bool,'values'=>array]
 * @var bool   $f2bAvail
 * @var bool   $pfAvail
 * @var bool   $delAvail
 * @var array  $settings
 */
$s  = $settings;
$fv = static fn(string $sec, string $key, string $def): string => Html::e($f2b['values'][$sec][$key] ?? $def);
$fc = static fn(string $sec, string $key, bool $def): bool => (($f2b['values'][$sec][$key] ?? ($def ? 'true' : 'false')) === 'true');
/** URL assetu z wersją (mtime) — wymusza pobranie świeżego pliku po zmianie. */
$av = static fn(string $f): string => '../assets/' . $f . '?v=' . (@filemtime(dirname(__DIR__, 3) . '/assets/' . $f) ?: '1');
?>
<!DOCTYPE html>
<html lang="pl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Panel Administratora</title>
<link href="<?= $av('vendor/remixicon/remixicon.css') ?>" rel="stylesheet">
<link href="<?= $av('vendor/fontawesome/css/all.min.css') ?>" rel="stylesheet">
<link rel="stylesheet" href="<?= $av('design-system.css') ?>">
<link rel="stylesheet" href="<?= $av('panel.css') ?>">
</head>
<body class="dark-mode" data-csrf="<?= Html::e($csrf) ?>"
      data-pwd-min="<?= (int) $pwd['min'] ?>"
      data-pwd-upper="<?= (int) $pwd['upper'] ?>"
      data-pwd-digit="<?= (int) $pwd['digit'] ?>"
      data-pwd-spec="<?= (int) $pwd['spec'] ?>">

<div class="header">
    <div class="header-content">
        <h1><i class="ri-shield-keyhole-line"></i> Panel Administratora</h1>
        <div class="header-actions">
            <span class="admin-info">Zalogowany: <b><?= Html::e($admin) ?></b></span>
            <a href="logout.php" class="logout-link">Wyloguj</a>
            <button class="theme-toggle-btn" id="themeToggle" title="Przełącz motyw"><i class="ri-sun-line" id="themeIcon"></i></button>
        </div>
    </div>
</div>

<div class="container">
    <?php if ($success !== ''): ?><div class="alert alert-success"><?= Html::e($success) ?></div><?php endif; ?>
    <?php if ($error !== ''): ?><div class="alert alert-error"><?= Html::e($error) ?></div><?php endif; ?>

    <div class="stats">
        <div class="stat-card"><h3>Użytkownicy</h3><div class="value"><?= (int) $stats['total_users'] ?></div></div>
        <div class="stat-card"><h3>Rejestracje dzisiaj</h3><div class="value"><?= (int) $stats['today_registrations'] ?></div></div>
        <div class="stat-card"><h3>Bany IP</h3><div class="value" id="stat-banned-ips"><?= (int) $stats['banned_ips'] ?></div></div>
        <div class="stat-card"><h3>Próby włamań (24h)</h3><div class="value"><?= (int) $stats['failed_logins_24h'] ?></div></div>
    </div>

    <div class="tabs">
        <button class="tab active" data-tab="settings"><i class="ri-settings-3-line"></i> Ustawienia</button>
        <button class="tab" data-tab="users"><i class="ri-user-line"></i> Użytkownicy</button>
        <button class="tab" data-tab="bans"><i class="ri-prohibited-line"></i> Blokady IP</button>
        <button class="tab" data-tab="logs"><i class="ri-file-list-line"></i> Logi</button>
        <button class="tab" data-tab="fail2ban"><i class="ri-fire-line"></i> Fail2Ban</button>
        <button class="tab" data-tab="security"><i class="ri-lock-password-line"></i> Bezpieczeństwo</button>
    </div>

    <!-- USTAWIENIA -->
    <div id="settings" class="tab-content">
        <h2><i class="ri-hard-drive-2-line"></i> Konfiguracja Systemu</h2>
        <form method="POST">
            <input type="hidden" name="_csrf" value="<?= Html::e($csrf) ?>">
            <input type="hidden" name="_tab" value="settings">
            <div class="settings-grid">
                <div class="settings-card">
                    <div class="settings-card-header"><i class="ri-mail-settings-line"></i> Limity Poczty</div>
                    <div class="settings-card-body">
                        <div class="form-group">
                            <label>Domyślny limit (Quota)</label>
                            <div class="quota-input-group">
                                <input type="number" name="mailbox_quota_value" step="0.01" min="0" value="<?= Html::e((string) $s['mailbox_quota_value']) ?>">
                                <select name="mailbox_quota_unit">
                                    <option value="KiB" <?= $s['mailbox_quota_unit'] === 'KiB' ? 'selected' : '' ?>>KiB</option>
                                    <option value="MiB" <?= $s['mailbox_quota_unit'] === 'MiB' ? 'selected' : '' ?>>MiB</option>
                                    <option value="GiB" <?= $s['mailbox_quota_unit'] === 'GiB' ? 'selected' : '' ?>>GiB</option>
                                </select>
                            </div>
                            <div class="checkbox-warning-wrapper">
                                <input type="checkbox" name="apply_quota_to_all" id="apply_quota_check">
                                <label for="apply_quota_check">Zastosuj dla wszystkich (oprócz admin)</label>
                            </div>
                        </div>
                        <div class="form-group">
                            <label>Limit wysyłki (na dzień)</label>
                            <input type="number" name="daily_send_limit" min="0" value="<?= (int) $s['daily_send_limit'] ?>">
                            <div class="help-text">Egzekwowany przy wysyłce SMTP (587/465, także webmail). <code>0</code> = bez limitu. Limit indywidualny ustawisz w edycji quoty użytkownika.</div>
                        </div>
                    </div>
                </div>

                <div class="settings-card">
                    <div class="settings-card-header"><i class="ri-user-add-line"></i> Rejestracja</div>
                    <div class="settings-card-body">
                        <div class="form-group flex-align-center">
                            <input type="checkbox" name="registration_enabled" id="reg_en" <?= $s['registration_enabled'] === '1' ? 'checked' : '' ?>>
                            <label for="reg_en" class="no-margin">Włącz rejestrację publiczną</label>
                        </div>
                        <div class="form-group">
                            <label>Limit kont na IP</label>
                            <div class="flex-row-center">
                                <input type="number" name="accounts_per_ip" min="1" value="<?= (int) $s['accounts_per_ip'] ?>">
                                <span>/</span>
                                <input type="number" name="ip_window_hours" min="1" value="<?= (int) $s['ip_window_hours'] ?>">
                                <span>h</span>
                            </div>
                        </div>
                        <div class="form-group">
                            <label>Całkowity limit kont dla IP</label>
                            <input type="number" name="ip_total_limit" min="1" value="<?= (int) $s['ip_total_limit'] ?>">
                        </div>
                    </div>
                </div>

                <div class="settings-card">
                    <div class="settings-card-header"><i class="ri-shield-check-line"></i> Google reCAPTCHA</div>
                    <div class="settings-card-body">
                        <div class="form-group flex-row-center">
                            <input type="checkbox" name="recaptcha_enabled" id="rc_en" <?= $s['recaptcha_enabled'] === '1' ? 'checked' : '' ?>>
                            <label for="rc_en" class="no-margin">Aktywne</label>
                        </div>
                        <div class="form-group">
                            <label>Site Key</label>
                            <input type="text" name="recaptcha_site_key" value="<?= Html::e((string) $s['recaptcha_site_key']) ?>">
                        </div>
                        <div class="form-group">
                            <label>Secret Key</label>
                            <input type="text" name="recaptcha_secret_key" value="<?= Html::e((string) $s['recaptcha_secret_key']) ?>">
                        </div>
                    </div>
                </div>
            </div>
            <div class="mt-4"><button type="submit" name="update_settings" class="btn"><i class="ri-save-line"></i> Zapisz zmiany</button></div>
        </form>
    </div>

    <!-- UŻYTKOWNICY -->
    <div id="users" class="tab-content">
        <div class="tab-head">
            <h2><i class="ri-user-line"></i> Lista Kont Pocztowych</h2>
            <button type="button" class="btn" data-action="add-user"><i class="ri-user-add-line"></i> Dodaj użytkownika</button>
        </div>
        <div class="search-bar">
            <div class="adv-search">
                <input type="text" id="user-search-input" autocomplete="off"
                       placeholder="Szukaj: e-mail, IP, data, użycie (np. >50%) — warunki łącz średnikiem ;">
                <button type="button" class="adv-clear" data-adv-clear="user-search-input" title="Wyczyść (przytrzymany backspace)" style="display:none"><i class="ri-close-line"></i></button>
            </div>
        </div>
        <div class="help-text search-hint">Np. <code>jan; 192.168.; 2026-07; &gt;50%; użycie &gt; 20 KiB; quota &gt;= 1 GiB</code> — wiersz musi spełnić każdy warunek (rozdzielaj średnikiem). Warunki użycia/procentu filtrują bieżącą stronę (dane z Dovecot).</div>
        <div class="users-topbar">
            <div>
                <span id="total-results-text">Łącznie kont: ...</span>
                <span class="sort-hint" title="Kliknij nagłówek: sortuj. Przytrzymaj Shift i klikaj: sortowanie wielokolumnowe."><i class="ri-information-line"></i> Shift+klik = sortuj po wielu kolumnach</span>
            </div>
            <div class="users-topbar-right">
                <label for="perPage">Na stronę:</label>
                <select id="perPage" data-per="users">
                    <option value="10">10</option><option value="20" selected>20</option>
                    <option value="50">50</option><option value="100">100</option>
                </select>
            </div>
        </div>
        <div class="table-responsive">
            <table>
                <thead><tr>
                    <th class="sortable" data-col="id">ID</th>
                    <th class="sortable" data-col="email">Email</th>
                    <th class="sortable" data-col="created">Utworzono</th>
                    <th class="sortable" data-col="last_login">Ostatnie logowanie</th>
                    <th class="sortable" data-col="reg_ip">IP Rejestracji</th>
                    <th>Ostatnie IP</th>
                    <th class="sortable" data-col="quota">Quota</th>
                    <th class="sortable" data-col="usage" title="Sortowanie po użyciu działa w obrębie bieżącej strony (dane doczytywane z Dovecot)">Użycie</th>
                    <th>Akcje</th>
                </tr></thead>
                <tbody id="users-tbody"></tbody>
            </table>
        </div>
        <div id="users-pagination" class="pagination-container"></div>
    </div>

    <!-- BANY -->
    <div id="bans" class="tab-content">
        <div class="tab-head">
            <h2><i class="ri-prohibited-line"></i> Zablokowane Adresy IP</h2>
            <button type="button" class="btn btn-warning" data-action="open-scanner"><i class="ri-radar-line"></i> Skaner podejrzanych IP</button>
        </div>
        <?php if (!$f2bAvail): ?>
            <div class="ds-msg ds-msg--err error-container">
                <strong><i class="ri-alert-line"></i> Brak dostępu do Fail2Ban</strong><br>
                Panel nie może pobrać listy banów z systemu (usługa nieuruchomiona lub brak uprawnień sudo dla <code>www-data</code>).
            </div>
        <?php endif; ?>

        <?php if ($f2bAvail && $jails !== []): ?>
        <div class="settings-card manual-ban-card">
            <div class="settings-card-header"><i class="ri-hammer-line"></i> Ręczna blokada IP (Fail2Ban)</div>
            <div class="settings-card-body">
                <form method="POST" class="manual-ban-form">
                    <input type="hidden" name="_csrf" value="<?= Html::e($csrf) ?>">
                    <input type="hidden" name="f2b_ban" value="1">
                    <input type="hidden" name="_tab" value="bans">
                    <div class="manual-ban-field grow">
                        <label for="f2b_ip">Adres IP</label>
                        <input type="text" id="f2b_ip" name="f2b_ip" required placeholder="np. 203.0.113.7"
                               pattern="[0-9a-fA-F.:]+" autocomplete="off">
                    </div>
                    <div class="manual-ban-field">
                        <label for="f2b_jail">Jail</label>
                        <select id="f2b_jail" name="f2b_jail">
                            <?php foreach ($jails as $j): ?>
                                <option value="<?= Html::e($j) ?>" <?= $j === 'recidive' ? 'selected' : '' ?>><?= Html::e($j) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <button type="submit" class="btn btn-danger"><i class="ri-forbid-line"></i> Zbanuj</button>
                </form>
                <div class="help-text">Ban trwa zgodnie z <code>bantime</code> danego jaila (recidive = najdłużej). Czas liczy Fail2Ban.</div>
            </div>
        </div>
        <?php endif; ?>

        <div id="bans_ajax_msg"></div>
        <div class="search-bar">
            <div class="adv-search">
                <input type="text" id="ban-search-input" autocomplete="off"
                       placeholder="Szukaj w blokadach: IP, jail, powód, data wygaśnięcia — warunki łącz średnikiem ;">
                <button type="button" class="adv-clear" data-adv-clear="ban-search-input" title="Wyczyść (przytrzymany backspace)" style="display:none"><i class="ri-close-line"></i></button>
            </div>
        </div>
        <div class="table-responsive">
            <table>
                <thead><tr>
                    <th class="sortable" data-nav="bans" data-col="0">Usługa / Jail</th>
                    <th class="sortable" data-nav="bans" data-col="1">IP</th>
                    <th class="sortable" data-nav="bans" data-col="2">Powód</th>
                    <th class="sortable" data-nav="bans" data-col="3">Wygasa</th>
                    <th>Akcja</th>
                </tr></thead>
                <tbody id="bans-tbody"><?= $bansRowsHtml ?></tbody>
            </table>
        </div>
    </div>

    <!-- LOGI -->
    <div id="logs" class="tab-content">
        <div class="search-bar">
            <div class="adv-search">
                <input type="text" id="log-search-input" autocomplete="off"
                       placeholder="Szukaj we wszystkich logach: IP, e-mail, status, szczegóły, data — warunki łącz średnikiem ;">
                <button type="button" class="adv-clear" data-adv-clear="log-search-input" title="Wyczyść (przytrzymany backspace)" style="display:none"><i class="ri-close-line"></i></button>
            </div>
        </div>
        <div class="help-text search-hint">Filtruje jednocześnie wszystkie trzy tabele. Np. <code>185.239.; błąd</code> = zdarzenia z tym IP <b>i</b> statusem błędu. Daty: <code>2026-07-10</code>.</div>

        <h2><i class="ri-history-line"></i> Ostatnie Zdarzenia Rejestracji</h2>
        <div class="users-topbar">
            <div id="reg-total-text">Łącznie zdarzeń: ...</div>
            <div class="users-topbar-right">
                <label for="regPerPage">Na stronę:</label>
                <select id="regPerPage" data-per="reg">
                    <option value="10">10</option><option value="20" selected>20</option>
                    <option value="50">50</option><option value="100">100</option>
                </select>
            </div>
        </div>
        <div class="table-responsive">
            <table><thead><tr>
                <th class="sortable" data-nav="reg" data-col="created">Czas</th>
                <th class="sortable" data-nav="reg" data-col="ip">IP</th>
                <th class="sortable" data-nav="reg" data-col="email">Email</th>
                <th class="sortable" data-nav="reg" data-col="status">Status</th>
                <th>Info</th>
            </tr></thead>
                <tbody id="reg-logs-tbody"></tbody></table>
        </div>
        <div id="reg-logs-pagination" class="pagination-container"></div>

        <hr class="safe-hr">
        <h2><i class="ri-shield-user-line"></i> Logi Logowania Administratorów</h2>
        <div class="users-topbar">
            <div id="admin-total-text">Łącznie logów: ...</div>
            <div class="users-topbar-right">
                <label for="adminPerPage">Na stronę:</label>
                <select id="adminPerPage" data-per="admin">
                    <option value="10">10</option><option value="20" selected>20</option>
                    <option value="50">50</option><option value="100">100</option>
                </select>
            </div>
        </div>
        <div class="table-responsive">
            <table><thead><tr>
                <th class="sortable" data-nav="admin" data-col="created">Czas</th>
                <th class="sortable" data-nav="admin" data-col="email">Email (Admin)</th>
                <th class="sortable" data-nav="admin" data-col="ip">IP</th>
                <th class="sortable" data-nav="admin" data-col="status">Status</th>
                <th>Szczegóły</th>
            </tr></thead>
                <tbody id="admin-logs-tbody"></tbody></table>
        </div>
        <div id="admin-logs-pagination" class="pagination-container"></div>

        <hr class="safe-hr">
        <h2><i class="ri-file-shield-2-line"></i> Dziennik Audytowy</h2>
        <div class="users-topbar">
            <div class="users-topbar-right">
                <button class="btn btn-small btn-secondary" data-action="refresh-audit"><i class="ri-refresh-line"></i> Odśwież</button>
            </div>
        </div>
        <div class="table-responsive">
            <table><thead><tr>
                <th class="sortable" data-nav="audit" data-col="created">Data</th>
                <th class="sortable" data-nav="audit" data-col="admin">Administrator</th>
                <th class="sortable" data-nav="audit" data-col="ip">IP Admina</th>
                <th class="sortable" data-nav="audit" data-col="action">Akcja</th>
                <th>Szczegóły</th>
            </tr></thead>
                <tbody id="audit-logs-tbody"></tbody></table>
        </div>
        <div id="audit-logs-pagination" class="pagination-container"></div>
    </div>

    <!-- FAIL2BAN -->
    <div id="fail2ban" class="tab-content">
        <h2><i class="ri-fire-line"></i> Konfiguracja Fail2Ban</h2>
        <?php if (!$f2b['available']): ?>
            <div class="alert alert-error">Plik jail.local nie istnieje albo brak uprawnień odczytu (integracja dostępna tylko na serwerze).</div>
        <?php else: ?>
        <form method="POST">
            <input type="hidden" name="_csrf" value="<?= Html::e($csrf) ?>">
            <input type="hidden" name="save_fail2ban" value="1">
            <input type="hidden" name="_tab" value="fail2ban">
            <div class="f2b-card f2b-card--default">
                <h3><i class="ri-settings-line"></i> Ustawienia główne [DEFAULT]</h3>
                <div class="form-group"><label>findtime</label><input type="text" name="f2b_default_findtime" value="<?= $fv('DEFAULT', 'findtime', '10m') ?>"></div>
                <div class="form-group"><label>bantime</label><input type="text" name="f2b_default_bantime" value="<?= $fv('DEFAULT', 'bantime', '1h') ?>"></div>
                <div class="form-group"><label>maxretry</label><input type="number" name="f2b_default_maxretry" value="<?= $fv('DEFAULT', 'maxretry', '6') ?>"></div>
                <div class="form-group"><label>bantime.factor</label><input type="text" name="f2b_default_bantime_factor" value="<?= $fv('DEFAULT', 'bantime.factor', '1.5') ?>"></div>
                <div class="form-group"><label>bantime.maxtime</label><input type="text" name="f2b_default_bantime_maxtime" value="<?= $fv('DEFAULT', 'bantime.maxtime', '1w') ?>"></div>
                <div class="form-group checkbox-group f2b-inc-check">
                    <input type="checkbox" id="f2b_inc" name="f2b_default_bantime_increment" value="1" <?= $fc('DEFAULT', 'bantime.increment', true) ? 'checked' : '' ?>>
                    <label for="f2b_inc">Wydłużanie bana (recydywa)</label>
                </div>
                <div class="form-group f2b-span"><label>ignoreip (oddziel spacją)</label><textarea name="f2b_default_ignoreip" rows="2" class="textarea-full"><?= $fv('DEFAULT', 'ignoreip', '') ?></textarea></div>
            </div>
            <div class="f2b-grid">
                <?php
                $jailCards = [
                    ['roundcube', 'roundcube-auth', 'ri-mail-line', 'Roundcube'],
                    ['dovecot',   'dovecot',        'ri-inbox-line', 'Dovecot'],
                    ['postfix',   'postfix-sasl',   'ri-send-plane-line', 'Postfix'],
                    ['recidive',  'recidive',       'ri-alert-line', 'Recidive'],
                ];
                foreach ($jailCards as [$prefix, $sec, $icon, $title]): ?>
                <div class="f2b-card">
                    <h3><i class="<?= $icon ?>"></i> <?= $title ?></h3>
                    <div class="form-group checkbox-group"><input type="checkbox" id="f2b_<?= $prefix ?>_en" name="f2b_<?= $prefix ?>_enabled" value="1" <?= $fc($sec, 'enabled', true) ? 'checked' : '' ?>><label for="f2b_<?= $prefix ?>_en">Włącz ochronę</label></div>
                    <div class="form-group"><label>findtime</label><input type="text" name="f2b_<?= $prefix ?>_findtime" value="<?= $fv($sec, 'findtime', '10m') ?>"></div>
                    <div class="form-group"><label>maxretry</label><input type="number" name="f2b_<?= $prefix ?>_maxretry" value="<?= $fv($sec, 'maxretry', '6') ?>"></div>
                    <div class="form-group"><label>bantime</label><input type="text" name="f2b_<?= $prefix ?>_bantime" value="<?= $fv($sec, 'bantime', '2h') ?>"></div>
                </div>
                <?php endforeach; ?>
            </div>
            <div class="form-actions mt-4"><button type="submit" class="btn btn-dark"><i class="ri-refresh-line"></i> Zapisz i przeładuj Fail2Ban</button></div>
        </form>
        <?php endif; ?>
    </div>

    <!-- BEZPIECZEŃSTWO -->
    <div id="security" class="tab-content">
        <h2><i class="ri-lock-line"></i> Bezpieczeństwo</h2>
        <div class="settings-grid">
            <div class="settings-card">
                <div class="settings-card-header"><i class="ri-spam-2-line"></i> Ochrona Anty-Spam</div>
                <div class="settings-card-body">
                    <form method="POST">
                        <input type="hidden" name="_csrf" value="<?= Html::e($csrf) ?>">
                        <input type="hidden" name="_tab" value="security">
                        <h4 class="section-title">Roundcube (Webmail)</h4>
                        <div class="form-group"><label>Max odbiorców w 1 mailu</label><input type="number" name="rc_max_recipients" value="<?= (int) $s['rc_max_recipients'] ?>"></div>
                        <div class="form-group"><label>Opóźnienie wysyłki (s)</label><input type="number" name="rc_sendmail_delay" value="<?= (int) $s['rc_sendmail_delay'] ?>"></div>
                        <div class="form-group"><label>Limit prób logowania / min</label><input type="number" name="rc_login_rate_limit" value="<?= (int) $s['rc_login_rate_limit'] ?>"></div>
                        <h4 class="section-title">Postfix (Klienci zewnętrzni)</h4>
                        <?php if (!$pfAvail): ?>
                            <div class="ds-msg ds-msg--err error-container"><strong><i class="ri-alert-line"></i> Brak uprawnień sudo</strong> — zmiany zapiszą się tylko w bazie.</div>
                        <?php endif; ?>
                        <div class="form-group"><label>Wiadomości / min (1 IP)</label><input type="number" name="pf_message_rate_limit" value="<?= (int) $s['pf_message_rate_limit'] ?>"></div>
                        <div class="form-group"><label>Połączeń / min (1 IP)</label><input type="number" name="pf_connection_rate_limit" value="<?= (int) $s['pf_connection_rate_limit'] ?>"></div>
                        <div class="form-group"><label>Równoległych połączeń (1 IP)</label><input type="number" name="pf_connection_count_limit" value="<?= (int) $s['pf_connection_count_limit'] ?>"></div>
                        <div class="mt-4"><button type="submit" name="update_antispam" class="btn"><i class="ri-shield-check-line"></i> Zapisz i Aplikuj</button></div>
                    </form>
                </div>
            </div>

            <div class="settings-card">
                <div class="settings-card-header"><i class="ri-shield-flash-line"></i> Ochrona Panelu</div>
                <div class="settings-card-body">
                    <form method="POST">
                        <input type="hidden" name="_csrf" value="<?= Html::e($csrf) ?>">
                        <input type="hidden" name="_tab" value="security">
                        <div class="form-group"><label>Max. prób logowania</label><input type="number" name="max_login_attempts" value="<?= (int) $s['max_login_attempts'] ?>"></div>
                        <div class="form-group"><label>Czas blokady (min)</label><input type="number" name="ban_duration_minutes" value="<?= (int) $s['ban_duration_minutes'] ?>"></div>
                        <div class="mt-4"><button type="submit" name="update_security" class="btn"><i class="ri-save-line"></i> Zapisz</button></div>
                    </form>
                </div>
            </div>

            <div class="settings-card">
                <div class="settings-card-header"><i class="ri-key-2-line"></i> Zmiana Hasła Admina</div>
                <div class="settings-card-body">
                    <form method="POST">
                        <input type="hidden" name="_csrf" value="<?= Html::e($csrf) ?>">
                        <input type="hidden" name="_tab" value="security">
                        <div class="form-group"><label>Obecne hasło</label><input type="password" name="current_password" required></div>
                        <div class="form-group"><label>Nowe hasło</label><input type="password" name="new_password" required></div>
                        <div class="form-group"><label>Powtórz nowe hasło</label><input type="password" name="confirm_password" required></div>
                        <div class="mt-4"><button type="submit" name="change_password" class="btn"><i class="ri-refresh-line"></i> Zmień hasło</button></div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- MODAL: QUOTA -->
<div id="quotaModal" class="modal">
    <div class="modal-content">
        <div class="modal-header"><h2><i class="ri-hard-drive-2-line"></i> Zmiana Quota</h2><span class="modal-close" data-action="modal-close" data-modal="quotaModal">&times;</span></div>
        <form method="POST">
            <input type="hidden" name="_csrf" value="<?= Html::e($csrf) ?>">
            <input type="hidden" name="update_user_quota" value="1">
            <input type="hidden" name="_tab" value="users">
            <input type="hidden" name="user_id" id="quota_user_id">
            <div class="form-group"><label>Użytkownik</label><input type="text" id="quota_user_email" readonly class="input-readonly"></div>
            <div class="form-group">
                <label>Nowy limit skrzynki</label>
                <div class="quota-input-group">
                    <input type="number" name="user_quota_value" id="quota_value" step="0.01" min="0" required>
                    <select name="user_quota_unit" id="quota_unit"><option value="KiB">KiB</option><option value="MiB" selected>MiB</option><option value="GiB">GiB</option></select>
                </div>
            </div>
            <div class="form-group">
                <label>Indywidualny limit wysyłki (wiadomości / dzień)</label>
                <input type="number" name="user_send_limit" id="quota_send_limit" min="0" step="1" placeholder="puste = globalny (<?= (int) $s['daily_send_limit'] ?>)">
                <div class="help-text">Puste = limit globalny (<b><?= (int) $s['daily_send_limit'] ?></b>/dzień). <code>0</code> = bez limitu.</div>
            </div>
            <div class="form-group">
                <label>Wysłane dzisiaj</label>
                <div id="quota_sent_today" class="sent-today-box">…</div>
            </div>
            <div class="modal-footer"><button type="button" class="btn btn-secondary" data-action="modal-close" data-modal="quotaModal">Anuluj</button><button type="submit" class="btn"><i class="ri-save-line"></i> Zapisz</button></div>
        </form>
    </div>
</div>

<!-- MODAL: RESET HASŁA SKRZYNKI -->
<div id="resetPassModal" class="modal">
    <div class="modal-content">
        <div class="modal-header"><h2><i class="ri-key-2-line"></i> Zmiana hasła skrzynki</h2><span class="modal-close" data-action="modal-close" data-modal="resetPassModal">&times;</span></div>
        <form method="POST">
            <input type="hidden" name="_csrf" value="<?= Html::e($csrf) ?>">
            <input type="hidden" name="reset_user_password" value="1">
            <input type="hidden" name="_tab" value="users">
            <input type="hidden" name="user_id" id="rp_user_id">
            <input type="hidden" name="user_email" id="rp_user_email_hidden">
            <div class="form-group"><label>Użytkownik</label><input type="text" id="rp_user_email" readonly class="input-readonly"></div>
            <div class="form-group">
                <label>Nowe hasło</label>
                <input type="password" name="new_password" id="rp_password" required minlength="<?= (int) $pwd['min'] ?>" autocomplete="new-password" placeholder="Nowe hasło skrzynki">
                <div class="pwd-meter"><div class="pwd-meter-fill" id="rp_pwd_bar"></div></div>
                <ul class="pwd-reqs" id="rp_pwd_reqs">
                    <li data-req="len">Min. <?= (int) $pwd['min'] ?> znaków</li>
                    <?php if ($pwd['upper']): ?><li data-req="up">Duża litera</li><?php endif; ?>
                    <?php if ($pwd['digit']): ?><li data-req="dig">Cyfra</li><?php endif; ?>
                    <?php if ($pwd['spec']): ?><li data-req="spec">Znak specjalny</li><?php endif; ?>
                </ul>
            </div>
            <div class="form-group">
                <label>Powtórz nowe hasło</label>
                <input type="password" name="new_password2" id="rp_password2" required autocomplete="new-password" placeholder="Powtórz hasło">
                <div id="rp_pass_match" class="avail-status"></div>
                <div class="help-text">Zmiana natychmiastowa — przekaż nowe hasło użytkownikowi bezpiecznym kanałem. Aktywne sesje webmaila mogą wymagać ponownego logowania.</div>
            </div>
            <div id="rp_form_msg"></div>
            <div class="modal-footer"><button type="button" class="btn btn-secondary" data-action="modal-close" data-modal="resetPassModal">Anuluj</button><button type="submit" class="btn"><i class="ri-save-line"></i> Zmień hasło</button></div>
        </form>
    </div>
</div>

<!-- MODAL: BAN -->
<div id="banModal" class="modal">
    <div class="modal-content">
        <div class="modal-header"><h2><i class="ri-forbid-line"></i> Zbanuj użytkownika</h2><span class="modal-close" data-action="modal-close" data-modal="banModal">&times;</span></div>
        <form method="POST">
            <input type="hidden" name="_csrf" value="<?= Html::e($csrf) ?>">
            <input type="hidden" name="ban_user_ip" value="1">
            <input type="hidden" name="_tab" value="users">
            <div class="form-group"><label>Użytkownik</label><input type="text" id="ban_user_email" readonly class="input-readonly"></div>
            <div class="form-group">
                <label>Wybierz IP do zbanowania</label>
                <select name="ban_ip" id="ban_ip_select" required><option value="">-- Wybierz IP --</option></select>
                <div class="help-text">Lista IP, z których użytkownik się logował</div>
            </div>
            <div class="form-group">
                <label>Powód bana</label>
                <select id="ban_reason_select">
                    <option value="Spam">Spam</option><option value="Próba włamania">Próba włamania</option>
                    <option value="Nadużycie zasobów">Nadużycie zasobów</option><option value="Naruszenie regulaminu">Naruszenie regulaminu</option>
                    <option value="Phishing">Phishing</option><option value="custom">Własny powód...</option>
                </select>
                <input type="hidden" name="ban_reason" id="ban_reason_input" value="Spam">
            </div>
            <div class="form-group" id="custom_reason_group" style="display:none"><label>Własny powód</label><input type="text" id="ban_custom_reason" placeholder="Wpisz powód..."></div>
            <div class="form-group"><label>Dodatkowa uwaga</label><textarea name="ban_note" rows="2" placeholder="Opcjonalnie..."></textarea></div>
            <div class="form-group"><label>Czas bana (minuty)</label><input type="number" name="ban_duration" value="60" min="1"><div class="help-text">1440 = 1 dzień, 10080 = 1 tydzień</div></div>
            <div class="modal-footer"><button type="button" class="btn btn-secondary" data-action="modal-close" data-modal="banModal">Anuluj</button><button type="submit" class="btn btn-danger"><i class="ri-forbid-line"></i> Zbanuj IP</button></div>
        </form>
    </div>
</div>

<!-- MODAL: DELETE -->
<div id="deleteUserModal" class="modal">
    <div class="modal-content">
        <div class="modal-header"><h2 class="danger-title"><i class="ri-alarm-warning-line"></i> Usuwanie konta</h2><span class="modal-close" data-action="modal-close" data-modal="deleteUserModal">&times;</span></div>
        <div class="delete-warning-box"><strong>Uwaga!</strong> Ta operacja jest nieodwracalna.</div>
        <p>Czy na pewno usunąć użytkownika:</p>
        <ul class="delete-details-list">
            <li><span>ID:</span> <strong id="del_user_id_display"></strong></li>
            <li><span>Email:</span> <strong id="del_user_email_display"></strong></li>
            <li><span>Pliki:</span>
                <?php if ($delAvail): ?><span class="badge badge-success">Zostaną usunięte z dysku</span>
                <?php else: ?><span class="badge badge-danger">Tylko baza (brak uprawnień)</span><?php endif; ?>
            </li>
        </ul>
        <form method="POST">
            <input type="hidden" name="_csrf" value="<?= Html::e($csrf) ?>">
            <input type="hidden" name="delete_user_confirm" value="1">
            <input type="hidden" name="_tab" value="users">
            <input type="hidden" name="delete_user_id" id="del_user_id_input">
            <input type="hidden" name="delete_user_email" id="del_user_email_input">
            <div class="password-confirm-group"><label>Podaj hasło administratora, aby potwierdzić:</label><input type="password" name="admin_confirm_password" required placeholder="Hasło admina" autocomplete="new-password"></div>
            <div class="modal-footer"><button type="button" class="btn btn-secondary" data-action="modal-close" data-modal="deleteUserModal">Anuluj</button><button type="submit" class="btn btn-danger"><i class="ri-delete-bin-2-line"></i> Potwierdź usunięcie</button></div>
        </form>
    </div>
</div>

<!-- MODAL: DODAJ UŻYTKOWNIKA -->
<div id="addUserModal" class="modal">
    <div class="modal-content">
        <div class="modal-header"><h2><i class="ri-user-add-line"></i> Nowe konto pocztowe</h2><span class="modal-close" data-action="modal-close" data-modal="addUserModal">&times;</span></div>
        <form method="POST">
            <input type="hidden" name="_csrf" value="<?= Html::e($csrf) ?>">
            <input type="hidden" name="create_user" value="1">
            <input type="hidden" name="_tab" value="users">
            <div class="form-group">
                <label>Adres e-mail</label>
                <div class="email-compose">
                    <input type="text" name="nu_local" id="nu_local" required placeholder="nazwa" autocomplete="off" pattern="[a-zA-Z0-9._-]+">
                    <span>@</span>
                    <select name="nu_domain" id="nu_domain">
                        <?php foreach ($domains as $dm): ?>
                            <option value="<?= Html::e($dm) ?>"><?= Html::e($dm) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div id="nu_availability" class="avail-status"></div>
            </div>
            <div class="form-group">
                <label>Hasło</label>
                <input type="password" name="nu_password" id="nu_password" required minlength="<?= (int) $pwd['min'] ?>" autocomplete="new-password" placeholder="Silne hasło">
                <div class="pwd-meter"><div class="pwd-meter-fill" id="nu_pwd_bar"></div></div>
                <ul class="pwd-reqs" id="nu_pwd_reqs">
                    <li data-req="len">Min. <?= (int) $pwd['min'] ?> znaków</li>
                    <?php if ($pwd['upper']): ?><li data-req="up">Duża litera</li><?php endif; ?>
                    <?php if ($pwd['digit']): ?><li data-req="dig">Cyfra</li><?php endif; ?>
                    <?php if ($pwd['spec']): ?><li data-req="spec">Znak specjalny</li><?php endif; ?>
                </ul>
            </div>
            <div class="form-group">
                <label>Powtórz hasło</label>
                <input type="password" name="nu_password2" id="nu_password2" required autocomplete="new-password" placeholder="Powtórz hasło">
                <div id="nu_pass_match" class="avail-status"></div>
                <div class="help-text">Hasło skrzynki (Dovecot). Przekaż je użytkownikowi bezpiecznym kanałem.</div>
            </div>
            <div id="nu_form_msg"></div>
            <div class="form-group">
                <label>Limit skrzynki (Quota)</label>
                <div class="quota-input-group">
                    <input type="number" name="nu_quota_value" id="nu_quota_value" step="1" min="0" value="<?= (int) $s['mailbox_quota_value'] ?>">
                    <select name="nu_quota_unit"><option value="MiB" selected>MiB</option><option value="GiB">GiB</option></select>
                </div>
            </div>
            <div class="form-group">
                <label>Limit wysyłki (wiadomości / dzień)</label>
                <input type="number" name="nu_send_limit" id="nu_send_limit" min="0" step="1" placeholder="puste = globalny (<?= (int) $s['daily_send_limit'] ?>)">
                <div class="help-text">Puste = limit globalny (<b><?= (int) $s['daily_send_limit'] ?></b>/dzień). <code>0</code> = bez limitu.</div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-action="modal-close" data-modal="addUserModal">Anuluj</button>
                <button type="submit" class="btn"><i class="ri-user-add-line"></i> Utwórz konto</button>
            </div>
        </form>
    </div>
</div>

<!-- MODAL: ZARZĄDZANIE BANEM -->
<div id="manageBanModal" class="modal">
    <div class="modal-content">
        <div class="modal-header"><h2><i class="ri-shield-keyhole-line"></i> Zarządzaj blokadą</h2><span class="modal-close" data-action="modal-close" data-modal="manageBanModal">&times;</span></div>
        <ul class="delete-details-list">
            <li><span>IP:</span> <strong id="mb_ip"></strong></li>
            <li><span>Rodzaj:</span> <strong id="mb_type"></strong></li>
        </ul>
        <div id="mb_change_wrap" class="form-group">
            <label>Zmień typ bana / przenieś do jaila</label>
            <div class="manage-ban-row">
                <select id="mb_target_jail">
                    <?php foreach ($jails as $j): ?><option value="<?= Html::e($j) ?>" <?= $j === 'panel-permaban' ? 'selected' : '' ?>><?= Html::e($j) ?><?= $j === 'panel-permaban' ? ' (na stałe)' : ($j === 'panel-tempban' ? ' (czasowy)' : '') ?></option><?php endforeach; ?>
                </select>
                <select id="mb_duration" title="Czas bana" style="display:none">
                    <option value="3600">1 godz.</option>
                    <option value="21600">6 godz.</option>
                    <option value="86400" selected>24 godz.</option>
                    <option value="259200">3 dni</option>
                    <option value="604800">7 dni</option>
                    <option value="2592000">30 dni</option>
                    <option value="7776000">3 miesiące</option>
                </select>
                <button type="button" class="btn btn-warning" id="mb_move_btn"><i class="ri-arrow-left-right-line"></i> Przenieś</button>
            </div>
            <div class="help-text">Przeniesie IP do wybranego jaila (odbanuje z obecnego, zbanuje w nowym). Dla <code>panel-tempban</code> wybierz czas trwania.</div>
        </div>
        <div class="modal-footer" style="justify-content:center">
            <button type="button" class="btn btn-secondary" data-action="modal-close" data-modal="manageBanModal">Zamknij</button>
            <button type="button" class="btn btn-success" id="mb_unban_btn"><i class="ri-check-line"></i> Odblokuj całkowicie</button>
        </div>
    </div>
</div>

<!-- MODAL: SKANER PODEJRZANYCH IP -->
<div id="scannerModal" class="modal">
    <div class="modal-content scanner-modal">
        <div class="modal-header"><h2><i class="ri-radar-line"></i> Skaner podejrzanych IP</h2><span class="modal-close" data-action="modal-close" data-modal="scannerModal">&times;</span></div>
        <div class="scanner-params">
            <div class="scanner-field"><label>Od (data)</label><input type="date" id="scan_from"></div>
            <div class="scanner-field"><label>Do (data)</label><input type="date" id="scan_to"></div>
            <div class="scanner-field"><label>Min. nieudanych</label><input type="number" id="scan_min" value="10" min="1"></div>
            <button type="button" class="btn" id="scan_run"><i class="ri-search-line"></i> Przeskanuj</button>
        </div>
        <div class="help-text">Źródła: logi systemowe (SSH, Dovecot, Postfix SASL) + baza panelu (nieudane logowania admina, rejestracje). Liczone są wyłącznie <b>nieudane</b> próby uwierzytelnienia. Zaufane IP (serwer, Twoje) są pomijane. Parametry zostają po zamknięciu — zmień i skanuj ponownie.</div>
        <div id="scan_status" class="scan-status" style="display:none">
            <div class="scan-status-row">
                <span class="scan-status-label" id="scan_status_label"><i class="ri-loader-4-line spin"></i> Przygotowanie…</span>
                <span class="scan-status-count" id="scan_status_count"></span>
            </div>
            <div class="scan-progress-bar"><div class="scan-progress-fill" id="scan_progress_fill" style="width:0%"></div></div>
            <div class="scan-steps" id="scan_steps"></div>
        </div>
        <div id="scan_ban_msg"></div>
        <div id="scan_results" class="scanner-results"><p class="empty-state-text">Ustaw parametry i kliknij „Przeskanuj".</p></div>
    </div>
</div>

<!-- MODAL: POTWIERDZENIE (generyczny) -->
<div id="confirmModal" class="modal">
    <div class="modal-content confirm-modal">
        <div class="modal-header"><h2 id="confirm_title"><i class="ri-error-warning-line"></i> Potwierdzenie</h2><span class="modal-close" data-action="modal-close" data-modal="confirmModal">&times;</span></div>
        <p id="confirm_msg" class="confirm-msg"></p>
        <div class="modal-footer" style="justify-content:center">
            <button type="button" class="btn btn-secondary" data-action="modal-close" data-modal="confirmModal">Anuluj</button>
            <button type="button" class="btn" id="confirm_ok"><i class="ri-check-line"></i> Potwierdź</button>
        </div>
    </div>
</div>

<script src="<?= $av('panel.js') ?>" defer></script>
</body>
</html>
