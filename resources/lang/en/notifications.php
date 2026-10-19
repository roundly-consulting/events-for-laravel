<?php

declare(strict_types=1);

// Notices to attendees, invitees and organizers. :title is the event, :when its local time.
return [
    'view_event' => 'View the event',
    'reason' => 'Reason: :reason',
    'join' => 'Join online: :url',

    'invited' => [
        'subject' => 'Invitation: :title',
        'greeting' => 'You are invited!',
        'body' => 'You are invited to ":title" on :when.',
        'message' => 'Message from the organizer: :message',
        'action' => 'Answer the invitation',
    ],
    'confirmed' => [
        'subject' => 'You are going: :title',
        'greeting' => 'See you there!',
        'body' => 'Your place at ":title" on :when is confirmed.',
        'guests' => '{0} No guests.|{1} With :count guest.|[2,*] With :count guests.',
        'code' => 'Your check-in code: :code',
        'action' => 'Your ticket',
    ],
    'waitlist_promoted' => [
        'subject' => 'A place opened up: :title',
        'greeting' => 'Good news!',
        'body' => 'A place opened up for you at ":title" on :when.',
        'held' => 'It is held for you until :until — complete the payment to keep it.',
        'pay' => 'Complete the payment',
    ],
    'reminder' => [
        'subject' => 'Reminder: :title',
        'greeting' => 'Coming up soon',
        'body' => '":title" starts :when.',
    ],
    'updated' => [
        'subject' => 'Changed: :title',
        'greeting' => 'Something changed',
        'body' => 'The details of ":title" changed. It now takes place on :when.',
    ],
    'postponed' => [
        'subject' => 'Postponed: :title',
        'greeting' => 'The event is postponed',
        'body' => '":title" planned for :when is postponed. You will hear about the new date.',
    ],
    'rescheduled' => [
        'subject' => 'New date: :title',
        'greeting' => 'The event has a new date',
        'body' => '":title" now takes place on :when.',
        'previous' => 'It was planned for :previous.',
    ],
    'cancelled' => [
        'subject' => 'Cancelled: :title',
        'greeting' => 'The event is cancelled',
        'body' => '":title" planned for :when is cancelled.',
    ],
    'moved_online' => [
        'subject' => 'Moved online: :title',
        'greeting' => 'The event moved online',
        'body' => '":title" on :when now takes place online.',
    ],
];
