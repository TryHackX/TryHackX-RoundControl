#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * Postfix policy service: dzienny limit wysyłki per konto pocztowe.
 *
 * Wpięty w smtpd_data_restrictions usług submission/submissions (master.cf,
 * przez zmienną $mua_data_restrictions w main.cf), uruchamiany przez spawn(8)
 * jako użytkownik "nobody". Jedno zapytanie polityki = jedna wiadomość (etap
 * DATA), więc licznik zlicza wiadomości, nie odbiorców.
 *
 * Limit: virtual_users.daily_send_limit (NULL = globalny z app_settings
 * 'daily_send_limit'; 0 = bez limitu). Licznik: send_counters (email, day).
 * Awaria bazy = fail-open (DUNNO) — limiter nigdy nie może zablokować poczty
 * własnym błędem. Dzień liczony wg strefy z configu (domyślnie Europe/Warsaw),
 * spójnie z panelem administracyjnym.
 *
 * Konfiguracja: /etc/mailpanel-policy.cf (INI: dsn, user, password, timezone),
 * czytelna dla grupy nogroup (640 root:nogroup).
 *
 * Test ręczny:
 *   printf 'request=smtpd_access_policy\nsasl_username=user@domena\n\n' \
 *     | sudo -u nobody php /usr/local/bin/mailpanel-policy-daily.php
 */

const CONFIG_FILE = '/etc/mailpanel-policy.cf';

$cfg = @parse_ini_file(CONFIG_FILE);
if (!is_array($cfg)) {
    $cfg = [];
    error_log('mailpanel-policy: brak lub nieczytelny ' . CONFIG_FILE . ' — działam w trybie fail-open');
}
date_default_timezone_set((string) ($cfg['timezone'] ?? 'Europe/Warsaw'));

$db = null;

function db(array $cfg, ?PDO &$db): ?PDO
{
    if ($db instanceof PDO) {
        return $db;
    }
    try {
        $db = new PDO(
            (string) ($cfg['dsn'] ?? ''),
            (string) ($cfg['user'] ?? ''),
            (string) ($cfg['password'] ?? ''),
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 3]
        );
    } catch (Throwable $e) {
        error_log('mailpanel-policy: połączenie z bazą nieudane: ' . $e->getMessage());
        $db = null;
    }
    return $db;
}

function respond(string $action): void
{
    fwrite(STDOUT, 'action=' . $action . "\n\n");
    fflush(STDOUT);
}

/**
 * Grzecznościowy e-mail do nadawcy po PIERWSZYM odbitym mailu danego dnia
 * (kolejne odbicia już bez powiadomienia — flaga send_counters.notified).
 * Kopertowy nadawca pusty (<>) = brak ryzyka pętli zwrotek.
 */
function sendLimitNotice(string $user, int $limit, int $sent): void
{
    $domain  = substr((string) strrchr($user, '@'), 1) ?: 'localhost';
    $subject = '=?UTF-8?B?' . base64_encode('Wiadomość nie została wysłana — dzienny limit wyczerpany') . '?=';
    $body    = "Twoja ostatnia wiadomość NIE została wysłana.\n\n"
        . "Wykorzystano dzienny limit wysyłki: $sent z $limit wiadomości.\n"
        . "Licznik wyzeruje się o północy — wtedy wysyłka znów będzie możliwa.\n"
        . "Kolejne próby wysyłki dzisiaj również zostaną odrzucone (ta informacja\n"
        . "wysyłana jest tylko raz dziennie).\n\n"
        . "Jeśli potrzebujesz wyższego limitu, skontaktuj się z administratorem.\n\n"
        . "-- \nSerwer pocztowy $domain (wiadomość automatyczna, nie odpowiadaj)";
    $msg = "From: Serwer pocztowy <no-reply@$domain>\r\n"
        . "To: <$user>\r\n"
        . "Subject: $subject\r\n"
        . "MIME-Version: 1.0\r\n"
        . "Content-Type: text/plain; charset=UTF-8\r\n"
        . "Content-Transfer-Encoding: 8bit\r\n"
        . "Auto-Submitted: auto-replied\r\n"
        . "\r\n" . $body . "\n";

    $p = @popen('/usr/sbin/sendmail -oi -f "" -- ' . escapeshellarg($user), 'w');
    if (is_resource($p)) {
        fwrite($p, $msg);
        pclose($p);
    }
}

$attrs = [];
while (($line = fgets(STDIN)) !== false) {
    $line = rtrim($line, "\r\n");
    if ($line !== '') {
        $pos = strpos($line, '=');
        if ($pos !== false) {
            $attrs[substr($line, 0, $pos)] = substr($line, $pos + 1);
        }
        continue;
    }

    // Pusta linia kończy zapytanie — odpowiadamy i czekamy na następne.
    $request = $attrs['request'] ?? '';
    $user    = strtolower(trim($attrs['sasl_username'] ?? ''));
    $attrs   = [];

    if ($request !== 'smtpd_access_policy' || $user === '') {
        respond('DUNNO');
        continue;
    }

    try {
        $dbh = db($cfg, $db);
        if ($dbh === null) {
            respond('DUNNO');
            continue;
        }

        $st = $dbh->prepare('SELECT daily_send_limit FROM virtual_users WHERE email = ?');
        $st->execute([$user]);
        $row = $st->fetch(PDO::FETCH_NUM);
        if ($row === false) {
            respond('DUNNO');   // konto spoza virtual_users — nie nasza jurysdykcja
            continue;
        }

        $limit = $row[0] === null
            ? (int) ($dbh->query("SELECT v FROM app_settings WHERE k = 'daily_send_limit'")->fetchColumn() ?: 0)
            : (int) $row[0];

        if ($limit <= 0) {      // 0 lub brak = bez limitu
            respond('DUNNO');
            continue;
        }

        $day = date('Y-m-d');
        $st  = $dbh->prepare('SELECT cnt FROM send_counters WHERE email = ? AND day = ?');
        $st->execute([$user, $day]);
        $cnt = (int) ($st->fetchColumn() ?: 0);

        if ($cnt >= $limit) {
            error_log("mailpanel-policy: REJECT $user ($cnt/$limit)");
            if ((string) ($cfg['notify'] ?? '1') === '1') {
                try {
                    $up = $dbh->prepare('UPDATE send_counters SET notified = 1 WHERE email = ? AND day = ? AND notified = 0');
                    $up->execute([$user, $day]);
                    if ($up->rowCount() === 1) {
                        sendLimitNotice($user, $limit, $cnt);
                    }
                } catch (Throwable $e) {
                    error_log('mailpanel-policy: powiadomienie nieudane: ' . $e->getMessage());
                }
            }
            respond("REJECT Dzienny limit wysylki wyczerpany ($limit wiadomosci/dzien)");
            continue;
        }

        $dbh->prepare(
            'INSERT INTO send_counters (email, day, cnt) VALUES (?, ?, 1)
             ON DUPLICATE KEY UPDATE cnt = cnt + 1'
        )->execute([$user, $day]);
        respond('DUNNO');
    } catch (Throwable $e) {
        error_log('mailpanel-policy: ' . $e->getMessage());
        $db = null;             // wymuś reconnect przy kolejnym zapytaniu
        respond('DUNNO');       // fail-open
    }
}
