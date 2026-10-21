<?php
declare(strict_types=1);

/*
 * Wiersze list panelu (app/templates/panel/partials/*_rows.php) — polski (wzorzec).
 * Klucze: rows.<nazwa>. Teksty idą do atrybutów (title, aria-label) i do HTML przez h() — całość escapowana.
 */

return [
    // puste listy
    'rows.empty_entries'    => 'Brak wpisów.',
    'rows.empty_events'     => 'Brak zdarzeń.',
    'rows.no_users'         => 'Brak kont spełniających warunki wyszukiwania.',
    'rows.no_bans'          => 'Brak aktywnych blokad (SQL oraz Fail2Ban).',

    // logowania administratora, rejestracje
    'rows.ban_ip_title'     => 'Kliknij, aby zbanować',
    'rows.success'          => 'Sukces',
    'rows.error'            => 'Błąd',
    'rows.ok'               => 'OK',
    'rows.search_ip_title'  => 'Szukaj kont z tym IP',

    // blokady
    'rows.expires_now'      => '0 min',
    'rows.remaining'        => '(pozostało :time)',
    'rows.unit_d'           => 'd',
    'rows.unit_h'           => 'h',
    'rows.unit_min'         => 'min',
    'rows.until_title'      => 'do :date',
    'rows.manage'           => 'Zarządzaj',
    'rows.manage_aria'      => 'Zarządzaj blokadą :ip',

    // konta
    'rows.ip_first'         => 'IP pierwszego logowania',
    'rows.ip_prev'          => 'IP poprzedniego logowania',
    'rows.ip_last'          => 'IP ostatniego logowania',
    'rows.ip_click_search'  => ':what — kliknij, aby wyszukać',
    'rows.reg_ip_title'     => 'IP rejestracji — kliknij, aby wyszukać (Shift: dodaj do wyszukiwania)',
    'rows.quota_title'      => 'Zmień quota i limit wysyłki',
    'rows.quota_aria'       => 'Zmień quota i limit wysyłki: :email',
    'rows.pass'             => 'Hasło',
    'rows.pass_aria'        => 'Zmień hasło: :email',
    'rows.ban'              => 'Ban',
    'rows.ban_aria'         => 'Zbanuj IP konta :email',
    'rows.delete'           => 'Usuń',
    'rows.delete_aria'      => 'Usuń konto :email',
    'rows.no_reason'        => 'Brak powodu',
    'rows.panel_jail'       => 'Panel',
];
