#!/usr/bin/php
<?php
declare(strict_types=1);

/**
 * Postfix policy service: dzienny limit wysyłki per konto pocztowe.
 *
 * Wpięty w smtpd_data_restrictions usług submission/submissions (master.cf,
 * przez zmienną $mua_data_restrictions w main.cf), uruchamiany przez spawn(8)
 * jako użytkownik "nobody". Jedno zapytanie polityki = jedna wiadomość (etap
 * DATA).
 *
 * 1.1.0: licznik zlicza ODBIORCÓW, nie wiadomości. Na etapie DATA Postfix podaje atrybut recipient_count
 * (liczba zaakceptowanych RCPT TO); wiadomość do 20 adresów zużywa 20 jednostek limitu. Wcześniej jedna wiadomość
 * = 1, więc konto z limitem 50 mogło wysłać 50 wiadomości × smtpd_recipient_limit (domyślnie 1000) odbiorców.
 * Zwiększenie licznika jest jedną atomową instrukcją SQL (równoległe sesje nie przekroczą limitu).
 *
 * Limit: virtual_users.daily_send_limit (NULL = globalny z app_settings
 * 'daily_send_limit'; 0 = bez limitu). Licznik: send_counters (email, day).
 * Awaria bazy = fail-open (DUNNO) — limiter nigdy nie może zablokować poczty
 * własnym błędem. Dzień liczony wg strefy z configu (domyślnie Europe/Warsaw),
 * spójnie z panelem administracyjnym.
 *
 * Język odmów i powiadomienia: app_settings 'default_language' (pl = polski, każdy inny = angielski; brak = pl) —
 * ten sam język domyślny, który ustawia panel. Skrypt działa jako "nobody" poza kodem aplikacji, więc teksty ma u siebie.
 *
 * Konfiguracja: /etc/mailpanel-policy.cf (INI: dsn, user, password, timezone),
 * czytelna dla grupy nogroup (640 root:nogroup).
 *
 * Test ręczny:
 *   printf 'request=smtpd_access_policy\nsasl_username=user@domena\n\n' \
 *     | sudo -u nobody php /usr/local/bin/mailpanel-policy-daily.php
 */

if (PHP_SAPI !== 'cli') {      // nigdy nie przez HTTP, nawet gdyby serwer wydał ten plik
    http_response_code(404);
    exit;
}

defined('CONFIG_FILE') || define('CONFIG_FILE', '/etc/mailpanel-policy.cf');   // testy nadpisują przez auto_prepend_file

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

/**
 * Teksty dla użytkownika (odmowy SMTP — tylko ASCII — i powiadomienie e-mail): polski albo angielski.
 *
 * @return array<string,string>
 */
function texts(string $lang): array
{
    if ($lang === 'pl') {
        return [
            'reject_daily' => 'Dzienny limit wysylki wyczerpany (:limit odbiorcow/dzien)',
            'reject_big'   => 'Za wiele odbiorcow w jednej wiadomosci wzgledem dziennego limitu (:limit odbiorcow/dzien)',
            'from_name'    => 'Serwer pocztowy',
            'subject'      => 'Wiadomość nie została wysłana — dzienny limit wyczerpany',
            'body'         => "Twoja ostatnia wiadomość NIE została wysłana.\n\n"
                . "Wykorzystano dzienny limit wysyłki: :sent z :limit odbiorców (każdy adresat wiadomości liczy się osobno).\n"
                . "Licznik wyzeruje się o północy — wtedy wysyłka znów będzie możliwa.\n"
                . "Kolejne próby wysyłki dzisiaj również zostaną odrzucone (ta informacja\n"
                . "wysyłana jest tylko raz dziennie).\n\n"
                . "Jeśli potrzebujesz wyższego limitu, skontaktuj się z administratorem.\n\n"
                . "-- \nSerwer pocztowy :domain (wiadomość automatyczna, nie odpowiadaj)",
        ];
    }
    return [
        'reject_daily' => 'Daily sending limit reached (:limit recipients/day)',
        'reject_big'   => 'Too many recipients in one message for the daily limit (:limit recipients/day)',
        'from_name'    => 'Mail server',
        'subject'      => 'Your message was not sent — daily limit reached',
        'body'         => "Your last message was NOT sent.\n\n"
            . "The daily sending limit has been used up: :sent of :limit recipients (every recipient of a message counts separately).\n"
            . "The counter resets at midnight — you will be able to send again then.\n"
            . "Further attempts to send today will also be rejected (this notice\n"
            . "is sent only once a day).\n\n"
            . "If you need a higher limit, contact the administrator.\n\n"
            . "-- \nMail server :domain (automatic message, do not reply)",
    ];
}

/** Język z ustawień panelu (pl | en); błąd bazy = pl. */
function siteLanguage(PDO $dbh): string
{
    try {
        $v = $dbh->query("SELECT v FROM app_settings WHERE k = 'default_language'")->fetchColumn();
    } catch (Throwable) {
        $v = false;
    }
    return $v === false || $v === null || $v === '' || $v === 'pl' ? 'pl' : 'en';
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
function sendLimitNotice(string $user, int $limit, int $sent, string $lang = 'pl'): void
{
    $domain  = substr((string) strrchr($user, '@'), 1) ?: 'localhost';
    $tx      = texts($lang);
    $subject = '=?UTF-8?B?' . base64_encode($tx['subject']) . '?=';
    $body    = strtr($tx['body'], [':sent' => (string) $sent, ':limit' => (string) $limit, ':domain' => $domain]);
    $msg = 'From: ' . $tx['from_name'] . " <no-reply@$domain>\r\n"
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
    // Liczba odbiorców tej wiadomości (etap DATA); brak atrybutu = 1. Górna granica chroni przed absurdalną liczbą.
    $rcpt    = max(1, min(1000000, (int) ($attrs['recipient_count'] ?? 1)));
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

        if ($rcpt > $limit) {   // jedna wiadomość większa niż cały dzienny limit — nigdy nie przejdzie
            error_log("mailpanel-policy: REJECT $user ($rcpt odbiorcow w jednej wiadomosci, limit $limit)");
            respond('REJECT ' . strtr(texts(siteLanguage($dbh))['reject_big'], [':limit' => (string) $limit]));
            continue;
        }

        // Jedna atomowa instrukcja: zwiększ licznik o liczbę odbiorców tylko, gdy mieści się w limicie.
        // Zmienione wiersze: 1 = nowy wiersz dnia, 2 = zwiększony, 0 = bez zmiany (limit by został przekroczony).
        $inc = $dbh->prepare(
            'INSERT INTO send_counters (email, day, cnt) VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE cnt = IF(cnt + VALUES(cnt) <= ?, cnt + VALUES(cnt), cnt)'
        );
        $inc->execute([$user, $day, $rcpt, $limit]);

        if ($inc->rowCount() === 0) {
            $st = $dbh->prepare('SELECT cnt FROM send_counters WHERE email = ? AND day = ?');
            $st->execute([$user, $day]);
            $cnt = (int) ($st->fetchColumn() ?: 0);
            error_log("mailpanel-policy: REJECT $user ($cnt + $rcpt > $limit)");
            if ((string) ($cfg['notify'] ?? '1') === '1') {
                try {
                    $up = $dbh->prepare('UPDATE send_counters SET notified = 1 WHERE email = ? AND day = ? AND notified = 0');
                    $up->execute([$user, $day]);
                    if ($up->rowCount() === 1) {
                        sendLimitNotice($user, $limit, $cnt, siteLanguage($dbh));
                    }
                } catch (Throwable $e) {
                    error_log('mailpanel-policy: powiadomienie nieudane: ' . $e->getMessage());
                }
            }
            respond('REJECT ' . strtr(texts(siteLanguage($dbh))['reject_daily'], [':limit' => (string) $limit]));
            continue;
        }
        respond('DUNNO');
    } catch (Throwable $e) {
        error_log('mailpanel-policy: ' . $e->getMessage());
        $db = null;             // wymuś reconnect przy kolejnym zapytaniu
        respond('DUNNO');       // fail-open
    }
}
