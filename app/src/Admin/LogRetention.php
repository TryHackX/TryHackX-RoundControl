<?php
declare(strict_types=1);

namespace App\Admin;

use App\I18n\Lang;
use App\Settings\Settings;
use App\Support\Database;
use App\Support\Ip;
use PDO;
use Throwable;

/**
 * Przechowywanie logów (1.3.2). Ustawienie log_retention_days: 90 / 180 / 365 / 730 dni albo 0 = bez limitu
 * (domyślnie 365). Sprzątanie robi procedura sp_prune_logs (SQL SECURITY DEFINER): konto panelu nie ma DELETE na
 * dziennikach, a procedura nigdy nie usuwa wpisów młodszych niż 90 dni — szczegóły reguł per tabela w panel_schema.sql.
 *
 * Bez crona: najwyżej raz na dobę "przy okazji" żądania portalu lub panelu (po wysłaniu odpowiedzi — afterResponse()),
 * z blokadą w app_settings (log_retention_last_run: kto pierwszy przestawi znacznik czasu warunkowym UPDATE-em, ten
 * sprząta; równoległe żądania odpuszczają). Plus przycisk "Wyczyść teraz" w panelu. Każde przebiegnięcie, które coś
 * usunęło (i każde ręczne), to JEDEN wpis w dzienniku audytowym z liczbami per tabela.
 */
final class LogRetention
{
    /** Dozwolone okna w dniach; 0 = bez limitu. */
    public const OPTIONS = [90, 180, 365, 730, 0];
    public const DEFAULT_DAYS = 365;
    /** Najwyżej tyle partii po 1000 wierszy na tabelę w jednym przebiegu (reszta jutro). */
    public const ROUNDS = 20;
    public const INTERVAL = 86400;

    public const TABLES = ['audit', 'admin_logins', 'registrations', 'user_logins', 'send_counters', 'deleted_accounts'];

    public static function days(): int
    {
        try {
            $raw = Settings::get('log_retention_days');
        } catch (Throwable) {
            $raw = null;
        }
        $d = $raw === null || $raw === '' ? self::DEFAULT_DAYS : (int) $raw;
        return in_array($d, self::OPTIONS, true) ? $d : self::DEFAULT_DAYS;
    }

    public static function lastRun(): int
    {
        try {
            return (int) Settings::get('log_retention_last_run', '0');
        } catch (Throwable) {
            return 0;
        }
    }

    /** Czy minęła doba od ostatniego przebiegu (i jest co sprzątać — "bez limitu" nigdy). */
    public static function due(int $now): bool
    {
        return self::days() > 0 && $now - self::lastRun() >= self::INTERVAL;
    }

    /**
     * Zajęcie przebiegu: warunkowy UPDATE znacznika czasu — prawdę dostaje dokładnie jedno z równoległych żądań.
     */
    public static function claim(PDO $pdo, int $now): bool
    {
        $pdo->exec("INSERT IGNORE INTO app_settings (k, v) VALUES ('log_retention_last_run', '0')");
        $st = $pdo->prepare("UPDATE app_settings SET v = ? WHERE k = 'log_retention_last_run' AND CAST(v AS UNSIGNED) <= ?");
        $st->execute([(string) $now, $now - self::INTERVAL]);
        if ($st->rowCount() !== 1) {
            return false;
        }
        Settings::flush();
        return true;
    }

    /**
     * Sprzątanie teraz. Zwraca liczby usuniętych wierszy per tabela (same zera przy "bez limitu").
     *
     * @return array<string,int>
     */
    public static function run(PDO $pdo, bool $manual, ?int $now = null): array
    {
        $now ??= time();
        $days = self::days();
        $out  = array_fill_keys(self::TABLES, 0);
        if ($days > 0) {
            $st = $pdo->prepare('CALL sp_prune_logs(?, ?)');
            $st->execute([$days, self::ROUNDS]);
            $row = $st->fetch(PDO::FETCH_ASSOC) ?: [];
            do {
                // pozostałe zbiory wyników wywołania procedury (status CALL)
            } while ($st->nextRowset());
            $st->closeCursor();
            foreach (self::TABLES as $t) {
                $out[$t] = (int) ($row[$t] ?? 0);
            }
        }
        if ($manual) {   // ręczny przebieg też przesuwa znacznik — automatyczny nie powtórzy go tego samego dnia
            $pdo->exec("INSERT IGNORE INTO app_settings (k, v) VALUES ('log_retention_last_run', '0')");
            $pdo->prepare("UPDATE app_settings SET v = ? WHERE k = 'log_retention_last_run'")->execute([(string) $now]);
        }
        $pdo->prepare("INSERT INTO app_settings (k, v) VALUES ('log_retention_last_result', ?) ON DUPLICATE KEY UPDATE v = VALUES(v)")
            ->execute([(string) json_encode(['at' => $now, 'days' => $days, 'manual' => $manual, 'deleted' => $out])]);
        Settings::flush();

        $total = array_sum($out);
        if ($total > 0 || $manual) {
            self::audit($pdo, $manual, $days, $out);
        }
        return $out;
    }

    /**
     * Wywoływane na końcu żądania portalu / panelu: jeśli minęła doba, odpowiedź idzie najpierw do przeglądarki
     * (fastcgi_finish_request), potem sprzątanie. Błędy tylko do dziennika serwera.
     */
    public static function afterResponse(): void
    {
        try {
            $now = time();
            if (!self::due($now)) {
                return;
            }
            $pdo = Database::pdo();
            if (!self::claim($pdo, $now)) {
                return;
            }
            if (session_status() === PHP_SESSION_ACTIVE) {
                session_write_close();
            }
            if (function_exists('fastcgi_finish_request')) {
                fastcgi_finish_request();
            }
            self::run($pdo, false, $now);
        } catch (Throwable $e) {
            error_log('mailpanel: log retention: the cleanup failed: ' . $e->getMessage());
        }
    }

    /** Wpis w dzienniku audytowym (jeden na przebieg). Automatyczny przebieg: administrator "system", IP serwera. */
    private static function audit(PDO $pdo, bool $manual, int $days, array $out): void
    {
        $admin = $manual && !empty($_SESSION['admin_username']) ? (string) $_SESSION['admin_username'] : 'system';
        $ip    = $manual ? Ip::bin() : (string) inet_pton('127.0.0.1');
        $details = Lang::site($manual ? 'audit.retention_manual' : 'audit.retention_auto', [
            'days'  => $days,
            'audit' => $out['audit'], 'admin' => $out['admin_logins'], 'reg' => $out['registrations'],
            'user'  => $out['user_logins'], 'send' => $out['send_counters'], 'del' => $out['deleted_accounts'],
        ]);
        try {
            $pdo->prepare('INSERT INTO admin_audit_logs (admin_email, ip, action_type, details, created_at) VALUES (?, ?, ?, ?, NOW())')
                ->execute([$admin, $ip, 'LOG_RETENTION', $details]);
        } catch (Throwable $e) {
            error_log('mailpanel: log retention: the audit entry failed: ' . $e->getMessage());
        }
    }

    /** Zdanie pod tabelami logów: aktywne okno przechowywania (w bieżącym języku). */
    public static function note(): string
    {
        $d = self::days();
        return $d > 0 ? Lang::t('panel.retention.note', ['days' => $d]) : Lang::t('panel.retention.note_never');
    }

    /** "Ostatnie sprzątanie: …" (w bieżącym języku). */
    public static function lastText(): string
    {
        $r = self::lastResult();
        if ($r === null) {
            return Lang::t('panel.retention.last_none');
        }
        return Lang::t('panel.retention.last', [
            'date' => date('Y-m-d H:i', (int) $r['at']),
            'n'    => array_sum(array_map('intval', (array) $r['deleted'])),
        ]);
    }

    /** @return array{at:int,days:int,manual:bool,deleted:array<string,int>}|null */
    public static function lastResult(): ?array
    {
        try {
            $j = json_decode((string) Settings::get('log_retention_last_result', ''), true);
        } catch (Throwable) {
            return null;
        }
        return is_array($j) && isset($j['at'], $j['deleted']) ? $j : null;
    }
}
