<?php

declare(strict_types=1);

use RoundlyConsulting\Events\Facades\Events;
use RoundlyConsulting\Events\Models\Event;
use RoundlyConsulting\Events\Models\Venue;
use RoundlyConsulting\Events\Tests\Fixtures\Models\CustomEvent;
use RoundlyConsulting\Events\Tests\Fixtures\Models\CustomRsvp;
use RoundlyConsulting\Events\Tests\Fixtures\Models\CustomTier;
use RoundlyConsulting\Events\Tests\Fixtures\Models\CustomVenue;
use RoundlyConsulting\Events\Tests\Fixtures\Models\User;
use RoundlyConsulting\Events\Tests\Support\Scenarios;

beforeEach(fn () => Scenarios::freeze());
afterEach(fn () => Scenarios::thaw());

it('creates events as the configured event model', function (): void {
    expect('events.models.event')->toHonourModelSwap(CustomEvent::class, function (): array {
        $event = Events::make('Swap')->organizedBy(User::factory()->create())->startsAt('2027-02-10 20:00')->publish();

        return [$event, Events::for($event->uuid)->event(), Event::factory()->create(), Events::browse()->first()];
    });
});

it('creates venues as the configured venue model', function (): void {
    expect('events.models.venue')->toHonourModelSwap(CustomVenue::class, function (): array {
        $venue = Events::venues()->make('Hall')->create();
        $event = Event::factory()->at($venue)->create();

        return [$venue, $event->venue, Venue::factory()->create()];
    });
});

it('creates ticket types as the configured tier model', function (): void {
    expect('events.models.tier')->toHonourModelSwap(CustomTier::class, function (): array {
        $event = Event::factory()->published()->create();
        $tier = Events::for($event)->tiers()->add('Floor');

        return [$tier, ...Events::for($event)->tiers()->all()];
    });
});

it('creates RSVPs as the configured rsvp model', function (): void {
    expect('events.models.rsvp')->toHonourModelSwap(CustomRsvp::class, function (): array {
        $event = Event::factory()->published()->create();
        $user = User::factory()->create();
        $rsvp = Events::for($event)->rsvp($user)->going();

        return [$rsvp, $user->rsvpFor($event), Events::for($event)->attendees()->find($user)];
    });
});
