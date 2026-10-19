<?php

declare(strict_types=1);

use PHPUnit\Framework\ExpectationFailedException;
use RoundlyConsulting\Events\Actions\Events\CreateEventAction;
use RoundlyConsulting\Events\DataTransferObjects\CreateEventData;
use RoundlyConsulting\Events\DataTransferObjects\ScheduleData;
use RoundlyConsulting\Events\DataTransferObjects\UpdateEventData;
use RoundlyConsulting\Events\EventsManager;
use RoundlyConsulting\Events\Facades\Events;
use RoundlyConsulting\Events\Models\Event;
use RoundlyConsulting\Events\Models\Venue;
use RoundlyConsulting\Events\Testing\EventsFake;
use RoundlyConsulting\Events\Tests\Fixtures\Models\User;

/**
 * The public API contract — Actions → Manager → Facade (+ fake) — pinned.
 */
it('pins the facade contract', function (): void {
    expect(Events::class)
        ->toDocumentItsRoot()
        ->toBeFakeable()
        ->toReachEveryAction(__DIR__.'/../../src/Actions')
        // Events::checkIn($code, …): the code never shows in the facade's trace frame.
        ->toRedactSensitiveArguments(1);
});

it('registers no global alias: Events is one letter from Laravel\'s Event facade (OQ-4)', function (): void {
    /** @var array{extra: array{laravel: array<string, mixed>}} $composer */
    $composer = json_decode((string) file_get_contents(__DIR__.'/../../composer.json'), true, flags: JSON_THROW_ON_ERROR);

    expect($composer['extra']['laravel'])->not->toHaveKey('aliases')
        ->and(class_exists('Illuminate\\Support\\Facades\\'.class_basename(Events::class)))->toBeFalse();
});

it('runs one API in three styles', function (): void {
    $user = User::factory()->create();
    $data = new CreateEventData(title: 'Meetup', organizer: $user, schedule: new ScheduleData(startsAt: '2027-03-01 18:00'));

    // Facade.
    $viaFacade = Events::create($data);
    // Dependency injection: the same singleton.
    $manager = app(EventsManager::class);
    expect($manager)->toBe(app(EventsManager::class));
    $viaManager = $manager->create($data);
    // The raw action.
    $viaAction = app(CreateEventAction::class)->execute($data);

    expect([$viaFacade, $viaManager, $viaAction])->each->toBeInstanceOf(Event::class)
        ->and(Event::query()->count())->toBe(3);
});

it('resolves actions through the container so a host override applies', function (): void {
    app()->bind(CreateEventAction::class, static fn (): never => throw new RuntimeException('overridden'));

    Events::make('Meetup')->organizedBy(User::factory()->create())->startsAt('2027-03-01 18:00')->create();
})->throws(RuntimeException::class, 'overridden');

test('fake_assertions_see_every_path', function (): void {
    $fake = Events::fake();
    $user = User::factory()->create();

    expect(app(EventsManager::class))->toBeInstanceOf(EventsFake::class);

    $event = Events::make('Meetup')->organizedBy($user)->startsAt('2027-03-01 18:00')->publish();
    Events::for($event)->update(new UpdateEventData(capacity: 10));
    Events::for($event)->postpone('Ill');
    Events::for($event)->reschedule('2027-03-08 18:00');
    Events::for($event)->cancel();
    Events::venues()->make('Hall')->create();

    // Every operation ran for real.
    expect($event->fresh()?->phase->value)->toBe('cancelled')
        ->and(Venue::query()->count())->toBe(1);

    $fake->assertCreated();
    $fake->assertCreated(static fn (Event $created): bool => $created->is($event));
    $fake->assertPublished($event);
    $fake->assertUpdated($event, ['capacity']);
    $fake->assertPostponed($event);
    $fake->assertRescheduled($event);
    $fake->assertCancelled($event);
    $fake->assertVenueCreated(static fn (Venue $venue): bool => $venue->name === 'Hall');
    $fake->assertNothingCompleted();
    Events::assertNothingCompleted();

    expect($fake->recorded())->toHaveCount(7);
});

it('records nothing for a call that throws', function (): void {
    $fake = Events::fake();
    $event = Event::factory()->published()->create();

    try {
        Events::for($event)->update(new UpdateEventData(startsAt: '2027-01-01 10:00'));
    } catch (Throwable) {
    }

    $fake->assertNothingUpdated();
    $fake->assertNothingHappened();
});

test('fake_assertions_fail_with_a_clear_message', function (Closure $assertion, string $message): void {
    $fake = Events::fake();
    $event = Event::factory()->create();

    expect(fn () => $assertion($fake, $event))->toThrow(ExpectationFailedException::class, $message);
})->with([
    'created' => [fn (EventsFake $fake) => $fake->assertCreated(), 'Expected a [create] event operation, but none was recorded.'],
    'published' => [fn (EventsFake $fake) => $fake->assertPublished(), 'Expected a [publish] event operation'],
    'updated' => [fn (EventsFake $fake) => $fake->assertUpdated(), 'Expected a [update] event operation'],
    'postponed' => [fn (EventsFake $fake) => $fake->assertPostponed(), 'Expected a [postpone] event operation'],
    'rescheduled' => [fn (EventsFake $fake) => $fake->assertRescheduled(), 'Expected a [reschedule] event operation'],
    'cancelled' => [fn (EventsFake $fake) => $fake->assertCancelled(), 'Expected a [cancel] event operation'],
    'completed' => [fn (EventsFake $fake) => $fake->assertCompleted(), 'Expected a [complete] event operation'],
    'venue created' => [fn (EventsFake $fake) => $fake->assertVenueCreated(), 'Expected a [create_venue] event operation'],
    'nothing created' => [function (EventsFake $fake): void {
        Events::make('X')->organizedBy(User::factory()->create())->startsAt('2027-03-01 18:00')->create();
        $fake->assertNothingCreated();
    }, 'Expected no [create] event operation, but 1 were recorded.'],
    'created, no match' => [function (EventsFake $fake): void {
        Events::make('X')->organizedBy(User::factory()->create())->startsAt('2027-03-01 18:00')->create();
        $fake->assertCreated(static fn (): bool => false);
    }, 'A [create] event operation was recorded, but none matched.'],
    'nothing happened' => [function (EventsFake $fake): void {
        Events::venues()->make('Hall')->create();
        $fake->assertNothingHappened();
    }, 'Expected no event operations, but 1 were recorded.'],
    'updated with other keys' => [function (EventsFake $fake, Event $event): void {
        Events::for($event)->update(new UpdateEventData(capacity: 5));
        $fake->assertUpdated($event, ['title']);
    }, 'none matched'],
    'updated another event' => [function (EventsFake $fake, Event $event): void {
        Events::for($event)->update(new UpdateEventData(capacity: 5));
        $fake->assertUpdated(Event::factory()->create());
    }, 'none matched'],
    'no venue created' => [function (EventsFake $fake): void {
        Events::venues()->make('Hall')->create();
        $fake->assertNoVenueCreated();
    }, 'Expected no [create_venue] event operation'],
    'nothing published' => [function (EventsFake $fake, Event $event): void {
        Events::for($event)->publish();
        $fake->assertNothingPublished();
    }, 'Expected no [publish] event operation'],
    'nothing postponed' => [function (EventsFake $fake, Event $event): void {
        Events::for($event)->publish()->postpone();
        $fake->assertNothingPostponed();
    }, 'Expected no [postpone] event operation'],
    'nothing rescheduled' => [function (EventsFake $fake, Event $event): void {
        Events::for($event)->reschedule('2027-05-01 10:00');
        $fake->assertNothingRescheduled();
    }, 'Expected no [reschedule] event operation'],
    'nothing cancelled' => [function (EventsFake $fake, Event $event): void {
        Events::for($event)->publish()->cancel();
        $fake->assertNothingCancelled();
    }, 'Expected no [cancel] event operation'],
    'nothing updated' => [function (EventsFake $fake, Event $event): void {
        Events::for($event)->update(new UpdateEventData(capacity: 5));
        $fake->assertNothingUpdated();
    }, 'Expected no [update] event operation'],
]);
