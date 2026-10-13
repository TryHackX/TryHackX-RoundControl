<?php
declare(strict_types=1);

/* Panel: przechowywanie logów (Ustawienia → Przechowywanie logów, notki pod tabelami logów, dziennik audytowy). */
return [
    'panel.retention.title'         => 'Przechowywanie logów',
    'panel.retention.lead'          => 'Starsze wpisy dzienników są usuwane automatycznie raz na dobę (przy okazji wizyty w portalu lub panelu — bez crona). Każde sprzątanie, które coś usunęło, zostawia jeden wpis w dzienniku audytowym.',
    'panel.retention.label'         => 'Przechowuj logi przez',
    'panel.retention.opt_90'        => '90 dni',
    'panel.retention.opt_180'       => '180 dni (pół roku)',
    'panel.retention.opt_365'       => '365 dni (rok)',
    'panel.retention.opt_730'       => '730 dni (2 lata)',
    'panel.retention.opt_0'         => 'bez limitu (nic nie jest usuwane)',
    'panel.retention.save'          => 'Zapisz',
    'panel.retention.rule_logs'     => 'Dziennik audytowy, logowania administratorów, rejestracje: wpisy starsze niż okno.',
    'panel.retention.rule_accounts' => 'Istniejące konta zachowują datę i IP rejestracji oraz ostatnie logowanie (lista kont się nie zmienia).',
    'panel.retention.rule_send'     => 'Liczniki dziennego limitu wysyłki: dni starsze niż okno (limit potrzebuje tylko dnia dzisiejszego).',
    'panel.retention.rule_deleted'  => 'Blokady nazw usuniętych kont: dopiero po dłuższym z okresów — okno, blokada nazw z ustawień, 30 dni kwarantanny.',
    'panel.retention.rule_min'      => 'Wpisy młodsze niż <b>90 dni</b> nigdy nie są usuwane (pilnuje tego baza danych, nie tylko panel).',
    'panel.retention.clean_now'     => 'Wyczyść teraz',
    'panel.retention.cleaning'      => 'Czyszczenie…',
    'panel.retention.note'          => 'Wpisy starsze niż :days dni są usuwane automatycznie (Ustawienia → Przechowywanie logów).',
    'panel.retention.note_never'    => 'Logi są przechowywane bez limitu czasu (Ustawienia → Przechowywanie logów).',
    'panel.retention.last'          => 'Ostatnie sprzątanie: :date (usunięto: :n)',
    'panel.retention.last_none'     => 'Jeszcze nie sprzątano.',

    'msg.retention.bad_value'       => 'Nieprawidłowy okres przechowywania logów.',
    'msg.retention.saved'           => 'Przechowywanie logów: :what.',
    'msg.retention.never'           => 'Logi są przechowywane bez limitu — nic do usunięcia.',
    'msg.retention.cleaned'         => 'Usunięto :total wpisów (audyt: :audit, logowania administratorów: :admin, rejestracje: :reg, logowania użytkowników: :user, liczniki wysyłki: :send, blokady nazw: :del).',

    'audit.retention_set'           => 'Przechowywanie logów: :what',
    'audit.retention_auto'          => 'Automatyczne sprzątanie logów (starsze niż :days dni): audyt :audit, logowania administratorów :admin, rejestracje :reg, logowania użytkowników :user, liczniki wysyłki :send, blokady nazw :del',
    'audit.retention_manual'        => 'Ręczne sprzątanie logów (starsze niż :days dni): audyt :audit, logowania administratorów :admin, rejestracje :reg, logowania użytkowników :user, liczniki wysyłki :send, blokady nazw :del',
];
