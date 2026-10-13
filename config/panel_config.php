<?php
/**
 * Wpięcie ustawień panelu do konfiguracji Roundcube.
 *
 * Ładuje klucze `rc_*` z tabeli app_settings (baza panelu) i mapuje je na
 * opcje Roundcube (np. rc_sendmail_delay -> $config['sendmail_delay']).
 *
 * Instalacja: dołącz na KOŃCU config/config.inc.php linię:
 *   require_once __DIR__ . '/panel_config.php';
 *
 * Dane dostępowe pochodzą z pliku .env w korzeniu instalacji (bez sekretów w kodzie).
 */

require_once dirname(__DIR__) . '/app/src/Support/Env.php';
\App\Support\Env::load(\App\Support\Env::defaultPath(dirname(__DIR__)));

// 1.1.0: only these keys are imported (rc_<key> in app_settings -> $config['<key>']), each as a bounded integer.
// Importing "any rc_* key" meant that write access to app_settings could set e.g. rc_imap_host / rc_smtp_host
// and capture every webmail password.
$panelAllowedKeys = [
    'max_recipients'           => [0, 100000],
    'sendmail_delay'           => [0, 86400],
    'login_rate_limit'         => [0, 1000],
    'max_disclosed_recipients' => [0, 1000],
    'max_group_members'        => [0, 1000],
];

try {
    $pdo_rc = new PDO(
        (string) \App\Support\Env::get('PANEL_DB_DSN', ''),
        (string) \App\Support\Env::get('PANEL_DB_USER', ''),
        (string) \App\Support\Env::get('PANEL_DB_PASS', ''),
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );

    $stmt = $pdo_rc->query("SELECT k, v FROM app_settings WHERE k LIKE 'rc\\_%'");
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $key = substr($row['k'], 3); // zdejmij prefiks rc_
        if (!isset($panelAllowedKeys[$key]) || !is_numeric($row['v'])) {
            continue;
        }
        [$min, $max] = $panelAllowedKeys[$key];
        $config[$key] = max($min, min($max, (int) $row['v']));
    }

    $pdo_rc = null;
} catch (Throwable $e) {
    // Niedostępność bazy panelu nie może blokować startu Roundcube.
}

$config['identities_level'] = 3;
$config['force_https'] = true;
$config['ip_check'] = false;
