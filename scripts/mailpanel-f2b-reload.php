#!/usr/bin/env php
<?php

// Ścieżka do pliku, który panel modyfikuje
const JAIL_FILE  = '/etc/fail2ban/jail.local';

// Gdzie trzymamy stan (ostatni mtime)
const STATE_FILE = '/var/lib/mailpanel/f2b_state.json';

function load_state(): array {
    if (!is_file(STATE_FILE)) {
        return [];
    }
    $json = file_get_contents(STATE_FILE);
    if ($json === false) {
        return [];
    }
    $data = json_decode($json, true);
    return is_array($data) ? $data : [];
}

function save_state(array $state): void {
    $dir = dirname(STATE_FILE);
    if (!is_dir($dir)) {
        mkdir($dir, 0700, true);
    }
    file_put_contents(STATE_FILE, json_encode($state));
}

clearstatcache(true, JAIL_FILE);

// jak nie ma pliku – nic nie robimy
if (!is_file(JAIL_FILE)) {
    exit(0);
}

$mtime = filemtime(JAIL_FILE);
if ($mtime === false) {
    exit(0);
}

$state = load_state();
$last  = isset($state['mtime']) ? (int)$state['mtime'] : 0;

// jeżeli plik nie jest nowszy niż ostatnio – koniec
if ($mtime <= $last) {
    exit(0);
}

// jest zmiana – próbujemy reload
$cmd = '/usr/bin/fail2ban-client reload 2>&1';
$output = [];
$ret    = 0;
exec($cmd, $output, $ret);

if ($ret === 0) {
    // sukces – zapamiętaj nowy mtime
    $state['mtime'] = $mtime;
    save_state($state);
} else {
    // błąd – log do sysloga, ale nie kasujemy stanu, żeby nie kręcić się w pętli
    if (function_exists('openlog')) {
        openlog('mailpanel-f2b', LOG_PID, LOG_DAEMON);
        syslog(LOG_ERR, 'fail2ban reload failed: '.implode("\n", $output));
        closelog();
    }
    // można też ewentualnie wypisać na stdout, ale przy systemd i tak to trafi w journalctl
}
