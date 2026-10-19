<?php

declare(strict_types=1);

use RoundlyConsulting\Events\Enums\RsvpStatus;
use RoundlyConsulting\Events\Exceptions\RsvpHoldExpiredException;
use RoundlyConsulting\Events\Facades\Events;
use RoundlyConsulting\Events\Models\Event;
use RoundlyConsulting\Events\Models\Rsvp;
use RoundlyConsulting\Events\Support\EventCounters;
use RoundlyConsulting\Events\Tests\Fixtures\Models\User;
use RoundlyConsulting\Events\Tests\Fixtures\Payments\FakePaymentCollector;
use RoundlyConsulting\Events\Tests\Support\Racer;
use RoundlyConsulting\Events\Tests\Support\Scenarios;
use RoundlyConsulting\Money\Money;

/**
 * Real sessions racing on PostgreSQL and MySQL: the event row lock makes every seat decision
 * serial, so capacity, ticket type limits, promotions and hold expiry stay exact. SQLite has one
 * writer and skips these visibly.
 */
beforeEach(fn () => Scenarios::freeze());
afterEach(fn () => Scenarios::thaw());

/**
 * @param  list<Closure(): string>  $operations
 * @return list<string>
 */
function race(array $operations): array
{
    $racers = array_map(static fn (Closure $operation): Racer => (new Racer)->run($operation), $operations);

    return array_map(static fn (Racer $racer): string => $racer->outcome(), $racers);
}

function going(Event $event, User $user, ?string $tier = null): Closure
{
    $eventId = $event->getKey();
    $userId = $user->getKey();

    return static function () use ($eventId, $userId, $tier): string {
        $rsvp = Events::for(Event::query()->findOrFail($eventId))->rsvp(User::query()->findOrFail($userId))->going(tier: $tier);

        return $rsvp->status->value;
    };
}

test('capacity_is_race_free', function (): void {
    $event = Scenarios::event(capacity: 25);
    $users = User::factory()->count(40)->create();

    $outcomes = race($users->map(fn (User $user): Closure => going($event, $user))->all());
    $counts = array_count_values($outcomes);

    expect(array_keys($counts))->toEqualCanonicalizing(['going', 'waitlisted'])
        ->and($counts['going'] ?? 0)->toBe(25)
        ->and($counts['waitlisted'] ?? 0)->toBe(15)
        ->and($event->fresh()?->seats_taken)->toBe(25)
        ->and($event->fresh()?->waitlist_count)->toBe(15)
        ->and(EventCounters::stored($event))->toEqual(EventCounters::recount($event));
})->skip(fn (): bool => ! Racer::available(), 'needs a real engine and pcntl')->group('real-engine');

test('tier_capacity_is_race_free', function (): void {
    $event = Scenarios::event(capacity: 100);
    $tier = Events::for($event)->tiers()->make('Front row')->key('front')->capacity(5)->create();
    Events::for($event)->tiers()->add('Back', key: 'back');
    $users = User::factory()->count(20)->create();

    $outcomes = race($users->map(fn (User $user): Closure => going($event, $user, 'front'))->all());
    $counts = array_count_values($outcomes);

    expect(array_keys($counts))->toEqualCanonicalizing(['going', 'waitlisted'])
        ->and($counts['going'] ?? 0)->toBe(5)
        ->and($counts['waitlisted'] ?? 0)->toBe(15)
        ->and($tier->fresh()?->seats_taken)->toBe(5)
        ->and($event->fresh()?->seats_taken)->toBe(5)
        ->and(EventCounters::stored($event))->toEqual(EventCounters::recount($event));
})->skip(fn (): bool => ! Racer::available(), 'needs a real engine and pcntl')->group('real-engine');

test('decline_and_going_race_promotes_exactly', function (): void {
    $event = Scenarios::event(capacity: 5);
    $holders = User::factory()->count(5)->create();
    $waiting = User::factory()->count(5)->create();
    $newcomers = User::factory()->count(5)->create();

    foreach ($holders as $holder) {
        Events::for($event)->rsvp($holder)->going();
    }

    foreach ($waiting as $person) {
        Events::for($event)->rsvp($person)->going();
    }

    $eventId = $event->getKey();
    $declines = $holders->map(static fn (User $holder): Closure => static function () use ($eventId, $holder): string {
        return Events::for(Event::query()->findOrFail($eventId))->rsvp(User::query()->findOrFail($holder->getKey()))->notGoing()->status->value;
    })->all();

    $outcomes = race([...$declines, ...$newcomers->map(fn (User $user): Closure => going($event, $user))->all()]);
    expect(array_filter($outcomes, static fn (string $outcome): bool => str_starts_with($outcome, 'error')))->toBe([]);

    // Every freed seat went to someone who asked first, never to a newcomer, and none oversold.
    expect($event->fresh()?->seats_taken)->toBe(5)
        ->and(Rsvp::query()->where('status', RsvpStatus::Going->value)->whereIn('attendee_id', $waiting->modelKeys())->count())->toBe(5)
        ->and(Rsvp::query()->where('status', RsvpStatus::Going->value)->whereIn('attendee_id', $newcomers->modelKeys())->count())->toBe(0)
        ->and(EventCounters::stored($event))->toEqual(EventCounters::recount($event));
})->skip(fn (): bool => ! Racer::available(), 'needs a real engine and pcntl')->group('real-engine');

test('holds_expire_once', function (): void {
    FakePaymentCollector::reset();
    config()->set('events.payments.collector', FakePaymentCollector::class);
    $event = Scenarios::event(capacity: 1);
    Events::for($event)->tiers()->add('Supporter', price: Money::ofMajor('10', 'EUR'), key: 'supporter');
    $payer = User::factory()->create();
    $waiter = User::factory()->create();

    $held = Events::for($event)->rsvp($payer)->going();
    Events::for($event)->rsvp($waiter)->going();
    $reference = (string) $held->payment_reference;

    // The hold has lapsed: the sweep and a late webhook race for the seat.
    Scenarios::freeze(Scenarios::now()->addMinutes(31)->format('Y-m-d H:i:s'));

    [$sweep, $webhook] = race([
        static fn (): string => (string) Events::sweep()->expiredHolds,
        static function () use ($reference): string {
            try {
                return Events::rsvps()->confirmPayment($reference)->status->value;
            } catch (RsvpHoldExpiredException) {
                return 'refund';
            }
        },
    ]);

    $payerStatus = $payer->rsvpFor($event)?->status;
    $waiterStatus = $waiter->rsvpFor($event)?->status;

    // Exactly one outcome: the payer kept the seat, or the waiting attendee took it and the
    // payment is refunded — never both, never neither.
    expect([$payerStatus, $waiterStatus])->toBeIn([
        [RsvpStatus::Going, RsvpStatus::Waitlisted],
        [RsvpStatus::Expired, RsvpStatus::Held],
    ])
        ->and($webhook === 'refund')->toBe($payerStatus === RsvpStatus::Expired)
        ->and($event->fresh()?->seats_taken)->toBe(1)
        ->and(EventCounters::stored($event))->toEqual(EventCounters::recount($event));
    expect($sweep)->toBeIn(['0', '1']);
})->skip(fn (): bool => ! Racer::available(), 'needs a real engine and pcntl')->group('real-engine');
