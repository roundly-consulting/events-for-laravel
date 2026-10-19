<?php

declare(strict_types=1);

use RoundlyConsulting\Events\Facades\Events;
use RoundlyConsulting\Events\Models\AdmissionTier;
use RoundlyConsulting\Events\Models\Event;
use RoundlyConsulting\Events\Models\Rsvp;
use RoundlyConsulting\Events\Tests\Fixtures\Models\User;
use RoundlyConsulting\Events\Tests\Support\Scenarios;

beforeEach(fn () => Scenarios::freeze());
afterEach(fn () => Scenarios::thaw());

it('force deletes a draft with its ticket types, rsvps, hosts and invitations', function (): void {
    $event = Scenarios::event(capacity: 5);
    Events::for($event)->tiers()->add('Floor', key: 'floor');
    Events::for($event)->rsvp(User::factory()->create())->interested();
    Events::for($event)->hosts()->add(User::factory()->create());
    Events::for($event)->invitations()->send(User::factory()->create());
    Events::for($event)->unpublish();

    Events::for($event)->delete(force: true);

    expect(Event::withTrashed()->whereKey($event->getKey())->exists())->toBeFalse()
        ->and(AdmissionTier::withTrashed()->where('event_id', $event->getKey())->count())->toBe(0)
        ->and(Rsvp::query()->where('event_id', $event->getKey())->count())->toBe(0);
});

it('force deletes a cancelled event whose rsvps hold a ticket type', function (): void {
    $event = Scenarios::event(capacity: 5);
    Events::for($event)->tiers()->add('Floor', key: 'floor');
    Events::for($event)->rsvp(User::factory()->create())->going(tier: 'floor');
    Events::for($event)->cancel();

    Events::for($event)->delete(force: true);

    expect(Event::withTrashed()->whereKey($event->getKey())->exists())->toBeFalse()
        ->and(Rsvp::query()->where('event_id', $event->getKey())->count())->toBe(0);
});
