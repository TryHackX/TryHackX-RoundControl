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
\App\Support\Env::load(dirname(__DIR__) . '/.env');

try {
    $pdo_rc = new PDO(
        (string) \App\Support\Env::get('PANEL_DB_DSN', ''),
        (string) \App\Support\Env::get('PANEL_DB_USER', ''),
        (string) \App\Support\Env::get('PANEL_DB_PASS', ''),
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );

    $stmt = $pdo_rc->query("SELECT k, v FROM app_settings WHERE k LIKE 'rc_%'");
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $key = substr($row['k'], 3); // zdejmij prefiks rc_
        $val = $row['v'];

        if (is_numeric($val)) {
            $val = (int) $val;
        } elseif ($val === 'true') {
            $val = true;
        } elseif ($val === 'false') {
            $val = false;
        }

        $config[$key] = $val;
    }

    $pdo_rc = null;
} catch (Throwable $e) {
    // Niedostępność bazy panelu nie może blokować startu Roundcube.
}

$config['identities_level'] = 3;
$config['force_https'] = true;
$config['ip_check'] = false;
