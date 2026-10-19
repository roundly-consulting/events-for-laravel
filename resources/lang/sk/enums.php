<?php

declare(strict_types=1);

return [
    'event_status' => [
        'draft' => 'Koncept',
        'scheduled' => 'Naplánované',
        'postponed' => 'Odložené',
        'rescheduled' => 'Presunuté',
        'cancelled' => 'Zrušené',
        'completed' => 'Ukončené',
    ],

    'event_phase' => [
        'draft' => 'Koncept',
        'scheduled' => 'Naplánované',
        'postponed' => 'Odložené',
        'rescheduled' => 'Presunuté',
        'cancelled' => 'Zrušené',
        'completed' => 'Ukončené',
    ],

    'event_verb' => [
        'publish' => 'Zverejniť',
        'unpublish' => 'Stiahnuť zo zverejnenia',
        'postpone' => 'Odložiť',
        'reschedule' => 'Presunúť',
        'cancel' => 'Zrušiť',
        'complete' => 'Ukončiť',
    ],

    'visibility' => [
        'public' => 'Verejné',
        'unlisted' => 'Len cez odkaz',
        'private' => 'Súkromné',
    ],

    'attendance_mode' => [
        'offline' => 'Osobne',
        'online' => 'Online',
        'mixed' => 'Osobne aj online',
    ],

    'guest_list' => [
        'hidden' => 'Skrytý',
        'attendees' => 'Viditeľný pre účastníkov',
        'public' => 'Verejný',
    ],

    'rsvp_status' => [
        'going' => 'Zúčastní sa',
        'held' => 'Čaká na platbu',
        'waitlisted' => 'Na čakacej listine',
        'interested' => 'Má záujem',
        'not_going' => 'Nezúčastní sa',
        'expired' => 'Vypršané',
    ],

    'rsvp_response' => [
        'going' => 'Zúčastním sa',
        'interested' => 'Mám záujem',
        'not_going' => 'Nezúčastním sa',
    ],

    'check_in_method' => [
        'code' => 'Naskenovaný kód',
        'manual' => 'Ručne',
    ],

    'invitation_status' => [
        'pending' => 'Čaká na odpoveď',
        'accepted' => 'Prijaté',
        'declined' => 'Odmietnuté',
        'revoked' => 'Zrušené',
    ],
];
