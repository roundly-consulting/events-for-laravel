<?php

declare(strict_types=1);

use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Route;

test('route_list_is_pinned', function (): void {
    $routes = collect(Route::getRoutes()->getRoutes())
        ->filter(static fn (RoutingRoute $route): bool => str_starts_with((string) $route->getName(), 'events.'))
        ->map(static fn (RoutingRoute $route): string => implode('|', array_diff($route->methods(), ['HEAD'])).' '.$route->uri().' '.$route->getName())
        ->sort()->values()->all();

    expect($routes)->toBe([
        'DELETE api/event-venues/{venue} events.venues.destroy',
        'DELETE api/events/{event} events.destroy',
        'DELETE api/events/{event}/attendees/{rsvp} events.attendees.destroy',
        'DELETE api/events/{event}/attendees/{rsvp}/check-in events.attendees.undo-check-in',
        'DELETE api/events/{event}/hosts/{host} events.hosts.destroy',
        'DELETE api/events/{event}/invitations/{invitee} events.invitations.destroy',
        'DELETE api/events/{event}/poster events.poster.destroy',
        'DELETE api/events/{event}/rsvp events.rsvp.destroy',
        'DELETE api/events/{event}/tiers/{tier} events.tiers.destroy',
        'GET api/event-venues events.venues.index',
        'GET api/event-venues/{venue} events.venues.show',
        'GET api/events events.index',
        'GET api/events/feed.ics events.feed',
        'GET api/events/{event} events.show',
        'GET api/events/{event}/attendees events.attendees.index',
        'GET api/events/{event}/calendar.ics events.calendar',
        'GET api/events/{event}/hosts events.hosts.index',
        'GET api/events/{event}/invitations events.invitations.index',
        'GET api/events/{event}/rsvp events.rsvp.show',
        'GET api/events/{event}/rsvp/qr.svg events.rsvp.qr',
        'GET api/events/{event}/tiers events.tiers.index',
        'GET api/events/{event}/waitlist events.waitlist.index',
        'PATCH api/event-venues/{venue} events.venues.update',
        'PATCH api/events/{event} events.update',
        'PATCH api/events/{event}/attendees/{rsvp} events.attendees.update',
        'PATCH api/events/{event}/tiers/{tier} events.tiers.update',
        'POST api/event-invitations/accept events.invitations.accept',
        'POST api/event-invitations/decline events.invitations.decline',
        'POST api/event-venues events.venues.store',
        'POST api/event-venues/{venue}/geocode events.venues.geocode',
        'POST api/events events.store',
        'POST api/events/{event}/attendees events.attendees.store',
        'POST api/events/{event}/attendees/{rsvp}/check-in events.attendees.check-in',
        'POST api/events/{event}/cancel events.cancel',
        'POST api/events/{event}/check-ins events.check-ins.store',
        'POST api/events/{event}/complete events.complete',
        'POST api/events/{event}/hosts events.hosts.store',
        'POST api/events/{event}/invitations events.invitations.store',
        'POST api/events/{event}/move-online events.move-online',
        'POST api/events/{event}/postpone events.postpone',
        'POST api/events/{event}/publish events.publish',
        'POST api/events/{event}/reschedule events.reschedule',
        'POST api/events/{event}/restore events.restore',
        'POST api/events/{event}/tiers events.tiers.store',
        'POST api/events/{event}/tiers/reorder events.tiers.reorder',
        'POST api/events/{event}/unpublish events.unpublish',
        'POST api/events/{event}/waitlist/promote events.waitlist.promote',
        'PUT api/events/{event}/poster events.poster.update',
        'PUT api/events/{event}/rsvp events.rsvp.update',
    ]);

    // Writes need the auth middleware; reads allow guests.
    expect(Route::getRoutes()->getByName('events.store')?->gatherMiddleware())->toContain('auth')
        ->and(Route::getRoutes()->getByName('events.index')?->gatherMiddleware())->not->toContain('auth');
});
