<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event as EventBus;
use RoundlyConsulting\Events\DataTransferObjects\TierData;
use RoundlyConsulting\Events\DataTransferObjects\UpdateEventData;
use RoundlyConsulting\Events\DataTransferObjects\UpdateTierData;
use RoundlyConsulting\Events\Enums\RsvpStatus;
use RoundlyConsulting\Events\Events\TierCreated;
use RoundlyConsulting\Events\Events\TierDeleted;
use RoundlyConsulting\Events\Events\TierRestored;
use RoundlyConsulting\Events\Events\TierUpdated;
use RoundlyConsulting\Events\Exceptions\InvalidTierException;
use RoundlyConsulting\Events\Exceptions\TierNotFoundException;
use RoundlyConsulting\Events\Facades\Events;
use RoundlyConsulting\Events\Models\AdmissionTier;
use RoundlyConsulting\Events\Models\Rsvp;
use RoundlyConsulting\Events\Tests\Fixtures\Models\User;
use RoundlyConsulting\Events\Tests\Support\Scenarios;
use RoundlyConsulting\Money\Money;

beforeEach(fn () => Scenarios::freeze());
afterEach(fn () => Scenarios::thaw());

it('builds ticket types with prices, windows and limits', function (): void {
    EventBus::fake([TierCreated::class]);
    $event = Scenarios::event();

    $early = Events::for($event)->tiers()
        ->make(['en' => 'Early bird', 'sk' => 'Včasná registrácia'])
        ->description(['en' => 'Until November'])
        ->price(Money::ofMajor('12', 'EUR'))
        ->capacity(50)
        ->onSale(until: '2027-02-01 23:59')
        ->perRsvp(min: 1, max: 4)
        ->create();

    expect($early->key)->toBe('early-bird')
        ->and($early->price?->toDecimal())->toBe('12.00')
        ->and($early->isFree())->toBeFalse()
        ->and($early->sale_ends_at?->toIso8601String())->toBe('2027-02-01T22:59:00+00:00')
        ->and($early->max_party)->toBe(4)
        ->and($early->position)->toBe(1);
    EventBus::assertDispatched(TierCreated::class);

    $vip = Events::for($event)->tiers()->add('VIP', price: Money::ofMajor('40', 'EUR'), capacity: 10);
    $free = Events::for($event)->tiers()->make('Supporters free')->price('0')->create();
    $plain = Events::for($event)->tiers()->make('Plain')->price('15')->create();

    expect($vip->position)->toBe(2)
        ->and($free->price)->toBeNull()
        ->and($plain->price?->currency()->code)->toBe('EUR')
        ->and(Events::for($event)->tiers()->all()->pluck('key')->all())->toBe(['early-bird', 'vip', 'supporters-free', 'plain']);

    Events::for($event)->tiers()->reorder(['plain', $vip, $early->getKey(), $free]);
    expect(Events::for($event)->tiers()->all()->pluck('key')->all())->toBe(['plain', 'vip', 'early-bird', 'supporters-free'])
        ->and(fn () => Events::for($event)->tiers()->reorder(['plain']))->toThrow(InvalidTierException::class);

    config()->set('events.tiers.default_currency', 'czk');
    expect(Events::for($event)->tiers()->make('Koruny')->price('300')->create()->price?->currency()->code)->toBe('CZK');

    expect(fn () => Events::for($event)->tiers()->make('Bad')->price('-1')->create())->toThrow(InvalidTierException::class)
        ->and(fn () => Events::for($event)->tiers()->make('')->create())->toThrow(InvalidTierException::class)
        ->and(fn () => Events::for($event)->tiers()->make('X')->key('No Spaces')->create())->toThrow(InvalidTierException::class)
        ->and(fn () => Events::for($event)->tiers()->make('X')->perRsvp(min: 3, max: 2)->create())->toThrow(InvalidTierException::class)
        ->and(fn () => Events::for($event)->tiers()->make('X')->capacity(-1)->create())->toThrow(InvalidTierException::class)
        ->and(fn () => Events::for($event)->tiers()->make('X')->onSale(from: '2027-02-01 10:00', until: '2027-01-01 10:00')->create())->toThrow(InvalidTierException::class);
});

test('tier_keys_never_suffix', function (): void {
    $event = Scenarios::event();
    Events::for($event)->tiers()->add('VIP');

    expect(fn () => Events::for($event)->tiers()->add('vip'))
        ->toThrow(InvalidTierException::class, 'The event already has a ticket type with the key "vip".');

    // A deleted ticket type keeps its key: the message names restore().
    $gone = Events::for($event)->tiers()->add('Gallery');
    Events::for($event)->tiers()->remove($gone);

    expect(fn () => Events::for($event)->tiers()->add('Gallery'))
        ->toThrow(InvalidTierException::class, 'A deleted ticket type of this event holds the key "gallery". Restore it instead.');

    expect(Events::for($event)->tiers()->restore('gallery')->trashed())->toBeFalse();

    // Another event may use the same key.
    expect(Events::for(Scenarios::event())->tiers()->add('VIP')->key)->toBe('vip');
});

test('tier_edits_never_bump_attendees', function (): void {
    EventBus::fake([TierUpdated::class]);
    $event = Scenarios::event(capacity: 10);
    $tier = Events::for($event)->tiers()->make('Floor')->key('floor')->capacity(3)->create();
    $users = User::factory()->count(4)->create();

    foreach ($users as $user) {
        Events::for($event)->rsvp($user)->going(tier: 'floor');
    }

    // Lowering the capacity below the seats taken bumps nobody; new requests wait.
    Events::for($event)->tiers()->update('floor', new UpdateTierData(capacity: 1, name: ['en' => 'Floor area']));

    expect(Rsvp::query()->where('status', 'going')->count())->toBe(3)
        ->and($tier->fresh()?->name)->toBe('Floor area')
        ->and(Events::for($event)->rsvp(User::factory()->create())->going(tier: 'floor')->status)->toBe(RsvpStatus::Waitlisted);
    EventBus::assertDispatched(TierUpdated::class);

    // A price change affects later holds only.
    Events::for($event)->tiers()->update($tier, new UpdateTierData(price: Money::ofMajor('5', 'EUR')));
    expect($users[0]->rsvpFor($event)?->status)->toBe(RsvpStatus::Going);

    // Raising the capacity moves the waitlist up.
    Events::for($event)->tiers()->update($tier->getKey(), new UpdateTierData(capacity: 6));
    expect($users[3]->rsvpFor($event)?->status)->toBe(RsvpStatus::Held);

    // Other attributes, and nothing to change.
    Events::for($event)->tiers()->update($tier, new UpdateTierData(key: 'main-floor', hidden: true, minParty: 1, maxParty: 3, saleStartsAt: '2027-01-01 00:00', saleEndsAt: '2027-02-10 00:00', description: 'Standing'));
    expect($tier->fresh()?->key)->toBe('main-floor')
        ->and($tier->fresh()?->hidden)->toBeTrue();
    Events::for($event)->tiers()->update($tier, new UpdateTierData(description: null));
    expect($tier->fresh()?->description)->toBeNull();

    // A ticket type of another event is never found.
    $other = Events::for(Scenarios::event())->tiers()->add('Other');
    expect(fn () => Events::for($event)->tiers()->update($other, new UpdateTierData(capacity: 1)))->toThrow(TierNotFoundException::class);
});

test('tiers_with_a_waitlist_cannot_be_deleted', function (): void {
    EventBus::fake([TierDeleted::class, TierRestored::class]);
    $event = Scenarios::event(capacity: 1);
    Events::for($event)->tiers()->add('Floor', key: 'floor');
    $first = User::factory()->create();
    $waiting = User::factory()->create();

    Events::for($event)->rsvp($first)->going();
    expect(fn () => Events::for($event)->tiers()->remove('floor'))->toThrow(InvalidTierException::class, 'People hold, wait for or have seats of this ticket type.');

    Events::for($event)->rsvp($waiting)->going();
    Events::for($event)->update(new UpdateEventData(waitlistAutoPromote: false));
    Events::for($event)->rsvp($first)->notGoing();

    // Only the waiting attendee is left: still refused (they could never be promoted).
    expect(fn () => Events::for($event)->tiers()->remove('floor'))->toThrow(InvalidTierException::class);

    Events::for($event)->rsvp($waiting)->interested();
    Events::for($event)->tiers()->remove('floor');

    expect(AdmissionTier::query()->count())->toBe(0);
    EventBus::assertDispatched(TierDeleted::class);
});

it('lists what an attendee can pick', function (): void {
    $event = Scenarios::event(capacity: 10, waitlist: false);
    Events::for($event)->tiers()->make('Full')->key('full')->capacity(1)->create();
    Events::for($event)->tiers()->make('Open')->key('open')->create();
    Events::for($event)->tiers()->make('Later')->key('later')->onSale(from: '2027-02-01 00:00')->create();
    Events::for($event)->tiers()->create(new TierData(name: 'Crew', key: 'crew', hidden: true));
    Events::for($event)->rsvp(User::factory()->create())->going(tier: 'full');

    expect(Events::for($event)->tiers()->available()->pluck('key')->all())->toBe(['open']);
});
