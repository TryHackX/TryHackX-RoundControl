<?php
declare(strict_types=1);

/* Panel: log retention (Settings → Log retention, notes under the log tables, audit log). */
return [
    'panel.retention.title'         => 'Log retention',
    'panel.retention.lead'          => 'Older log entries are removed automatically once a day (alongside a visit to the portal or the panel — no cron). Every cleanup that removed something leaves one entry in the audit log.',
    'panel.retention.label'         => 'Keep logs for',
    'panel.retention.opt_90'        => '90 days',
    'panel.retention.opt_180'       => '180 days (half a year)',
    'panel.retention.opt_365'       => '365 days (a year)',
    'panel.retention.opt_730'       => '730 days (2 years)',
    'panel.retention.opt_0'         => 'no limit (nothing is removed)',
    'panel.retention.save'          => 'Save',
    'panel.retention.rule_logs'     => 'Audit log, administrator logins, registrations: entries older than the window.',
    'panel.retention.rule_accounts' => 'Existing accounts keep their registration date and IP and their last login (the account list does not change).',
    'panel.retention.rule_send'     => 'Daily sending-limit counters: days older than the window (the limit only needs today).',
    'panel.retention.rule_deleted'  => 'Name holds of deleted accounts: only after the longest of the window, the name hold from Settings and the 30-day quarantine.',
    'panel.retention.rule_min'      => 'Entries younger than <b>90 days</b> are never removed (enforced by the database, not only by the panel).',
    'panel.retention.clean_now'     => 'Clean up now',
    'panel.retention.cleaning'      => 'Cleaning up…',
    'panel.retention.note'          => 'Entries older than :days days are removed automatically (Settings → Log retention).',
    'panel.retention.note_never'    => 'Logs are kept without a time limit (Settings → Log retention).',
    'panel.retention.last'          => 'Last cleanup: :date (removed: :n)',
    'panel.retention.last_none'     => 'No cleanup yet.',

    'msg.retention.bad_value'       => 'Invalid log retention period.',
    'msg.retention.saved'           => 'Log retention: :what.',
    'msg.retention.never'           => 'Logs are kept without a limit — nothing to remove.',
    'msg.retention.cleaned'         => 'Removed :total entries (audit: :audit, administrator logins: :admin, registrations: :reg, user logins: :user, sending counters: :send, name holds: :del).',

    'audit.retention_set'           => 'Log retention: :what',
    'audit.retention_auto'          => 'Automatic log cleanup (older than :days days): audit :audit, administrator logins :admin, registrations :reg, user logins :user, sending counters :send, name holds :del',
    'audit.retention_manual'        => 'Manual log cleanup (older than :days days): audit :audit, administrator logins :admin, registrations :reg, user logins :user, sending counters :send, name holds :del',
];
