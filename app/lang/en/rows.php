<?php
declare(strict_types=1);

/*
 * Rows of the panel lists (app/templates/panel/partials/*_rows.php) — English.
 * Keys: rows.<name>. The texts go into attributes (title, aria-label) and into HTML through h() — fully escaped.
 */

return [
    // empty lists
    'rows.empty_entries'    => 'No entries.',
    'rows.empty_events'     => 'No events.',
    'rows.no_users'         => 'No accounts match the search.',
    'rows.no_bans'          => 'No active blocks (SQL and Fail2Ban).',

    // administrator logins, registrations
    'rows.ban_ip_title'     => 'Click to ban',
    'rows.success'          => 'Success',
    'rows.error'            => 'Error',
    'rows.ok'               => 'OK',
    'rows.search_ip_title'  => 'Find accounts with this IP',

    // blocks
    'rows.expires_now'      => '0 min',
    'rows.remaining'        => '(:time left)',
    'rows.unit_d'           => 'd',
    'rows.unit_h'           => 'h',
    'rows.unit_min'         => 'min',
    'rows.until_title'      => 'until :date',
    'rows.manage'           => 'Manage',
    'rows.manage_aria'      => 'Manage the block of :ip',

    // accounts
    'rows.ip_first'         => 'IP of the first login',
    'rows.ip_prev'          => 'IP of the previous login',
    'rows.ip_last'          => 'IP of the last login',
    'rows.ip_click_search'  => ':what — click to search',
    'rows.reg_ip_title'     => 'Registration IP — click to search (Shift: add to the search)',
    'rows.quota_title'      => 'Change quota and send limit',
    'rows.quota_aria'       => 'Change quota and send limit: :email',
    'rows.pass'             => 'Password',
    'rows.pass_aria'        => 'Change password: :email',
    'rows.ban'              => 'Ban',
    'rows.ban_aria'         => 'Ban the IP of account :email',
    'rows.delete'           => 'Delete',
    'rows.delete_aria'      => 'Delete account :email',
    'rows.no_reason'        => 'No reason given',
    'rows.panel_jail'       => 'Panel',
];
