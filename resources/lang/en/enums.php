<?php

declare(strict_types=1);

return [
    'event_status' => [
        'draft' => 'Draft',
        'scheduled' => 'Scheduled',
        'postponed' => 'Postponed',
        'rescheduled' => 'Rescheduled',
        'cancelled' => 'Cancelled',
        'completed' => 'Completed',
    ],

    'event_phase' => [
        'draft' => 'Draft',
        'scheduled' => 'Scheduled',
        'postponed' => 'Postponed',
        'rescheduled' => 'Rescheduled',
        'cancelled' => 'Cancelled',
        'completed' => 'Completed',
    ],

    'event_verb' => [
        'publish' => 'Publish',
        'unpublish' => 'Unpublish',
        'postpone' => 'Postpone',
        'reschedule' => 'Reschedule',
        'cancel' => 'Cancel',
        'complete' => 'Complete',
    ],

    'visibility' => [
        'public' => 'Public',
        'unlisted' => 'Unlisted',
        'private' => 'Private',
    ],

    'attendance_mode' => [
        'offline' => 'In person',
        'online' => 'Online',
        'mixed' => 'In person and online',
    ],

    'guest_list' => [
        'hidden' => 'Hidden',
        'attendees' => 'Visible to attendees',
        'public' => 'Public',
    ],

    'rsvp_status' => [
        'going' => 'Going',
        'held' => 'Awaiting payment',
        'waitlisted' => 'On the waitlist',
        'interested' => 'Interested',
        'not_going' => 'Not going',
        'expired' => 'Expired',
    ],

    'rsvp_response' => [
        'going' => 'Going',
        'interested' => 'Interested',
        'not_going' => 'Not going',
    ],

    'check_in_method' => [
        'code' => 'Code scan',
        'manual' => 'By hand',
    ],

    'invitation_status' => [
        'pending' => 'Pending',
        'accepted' => 'Accepted',
        'declined' => 'Declined',
        'revoked' => 'Revoked',
    ],
];
