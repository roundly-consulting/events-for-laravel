<?php

declare(strict_types=1);

use Illuminate\Foundation\Application;

/**
 * The config contract, both directions: every key the code reads is shipped, and every shipped
 * leaf is read. The alias maps are host-filled lists (read whole, never per leaf).
 */
it('ships exactly the config keys it reads', function (): void {
    expect(__DIR__.'/../../config/events.php')->toSatisfyConfigContract(
        [__DIR__.'/../../src', __DIR__.'/../../database'],
        ['allowUnread' => [
            // Host-filled maps, read whole (alias => class, variant => [width, height]).
            'events.organizers.user',
            'events.attendees.user',
            'events.poster.variants.thumb',
            'events.poster.variants.card',
            'events.poster.variants.square',
            'events.poster.variants.landscape',
            'events.poster.variants.wide',
            // Read per type, whole: type => class|false, type => [status, …].
            'events.notifications.types.invited',
            'events.notifications.types.confirmed',
            'events.notifications.types.waitlist_promoted',
            'events.notifications.types.reminder',
            'events.notifications.types.updated',
            'events.notifications.types.postponed',
            'events.notifications.types.rescheduled',
            'events.notifications.types.cancelled',
            'events.notifications.types.moved_online',
            'events.notifications.audiences.reminder',
            'events.notifications.audiences.updated',
            'events.notifications.audiences.postponed',
            'events.notifications.audiences.rescheduled',
            'events.notifications.audiences.cancelled',
            'events.notifications.audiences.moved_online',
        ]],
    );
});

it('never claims a config handle Laravel ships itself', function (): void {
    $framework = dirname((string) (new ReflectionClass(Application::class))->getFileName(), 4);

    expect($framework.'/config/app.php')->toBeFile()
        ->and($framework.'/config/events.php')->not->toBeFile();
});
