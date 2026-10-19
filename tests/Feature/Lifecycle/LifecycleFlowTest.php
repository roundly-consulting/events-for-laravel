<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Event as EventBus;
use RoundlyConsulting\Events\DataTransferObjects\UpdateEventData;
use RoundlyConsulting\Events\Enums\AttendanceMode;
use RoundlyConsulting\Events\Enums\EventPhase;
use RoundlyConsulting\Events\Enums\RsvpStatus;
use RoundlyConsulting\Events\Events\EventCancelled;
use RoundlyConsulting\Events\Events\EventCompleted;
use RoundlyConsulting\Events\Events\EventMovedOnline;
use RoundlyConsulting\Events\Events\EventPostponed;
use RoundlyConsulting\Events\Events\EventPublished;
use RoundlyConsulting\Events\Events\EventRescheduled;
use RoundlyConsulting\Events\Events\EventStatusChanged;
use RoundlyConsulting\Events\Events\EventUnpublished;
use RoundlyConsulting\Events\Events\EventUpdated;
use RoundlyConsulting\Events\Events\RsvpHoldExpired;
use RoundlyConsulting\Events\Exceptions\EventTransitionDeniedException;
use RoundlyConsulting\Events\Exceptions\InvalidEventException;
use RoundlyConsulting\Events\Exceptions\InvalidEventLifecycleException;
use RoundlyConsulting\Events\Exceptions\RsvpClosedException;
use RoundlyConsulting\Events\Exceptions\UnknownEventTransitionException;
use RoundlyConsulting\Events\Facades\Events;
use RoundlyConsulting\Events\Models\Event;
use RoundlyConsulting\Events\Models\Venue;
use RoundlyConsulting\Events\Support\Clock;
use RoundlyConsulting\Events\Tests\Fixtures\Lifecycles\BrokenLifecycle;
use RoundlyConsulting\Events\Tests\Fixtures\Lifecycles\CustomLifecycle;
use RoundlyConsulting\Events\Tests\Fixtures\Models\User;
use RoundlyConsulting\Lifecycle\Facades\Lifecycles;
use RoundlyConsulting\Money\Money;

beforeEach(function (): void {
    $this->user = User::factory()->create();
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2027-01-01 12:00:00', 'UTC'));
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

function publishedEvent(User $user): Event
{
    return Events::make('Jazz Night')->organizedBy($user)->at(Venue::factory()->create())
        ->startsAt('2027-02-10 20:00')->duration('2 hours')->publish();
}

test('named_transition_conveniences', function (): void {
    $event = publishedEvent($this->user);

    expect($event->phase)->toBe(EventPhase::Scheduled);
    Events::for($event)->postpone();
    expect($event->phase)->toBe(EventPhase::Postponed);
    Events::for($event)->reschedule('2027-03-10 20:00');
    expect($event->phase)->toBe(EventPhase::Rescheduled);
    Events::for($event)->cancel();
    expect($event->phase)->toBe(EventPhase::Cancelled);

    // A lifecycle without the verb: the message names the generic path.
    config()->set('events.lifecycle', CustomLifecycle::class);
    $custom = Event::factory()->published()->create();

    expect(fn () => Events::for($custom)->postpone())
        ->toThrow(UnknownEventTransitionException::class, 'The event lifecycle has no transition for "postpone". Use transition() with a transition name.')
        ->and(fn () => Events::for($custom)->transition('teleport'))
        ->toThrow(UnknownEventTransitionException::class, 'The event lifecycle has no transition named "teleport".');
});

test('auto_complete_follows_ends_at', function (): void {
    EventBus::fake([EventCompleted::class]);
    $event = publishedEvent($this->user);

    CarbonImmutable::setTestNow($event->ends_at->subMinute());
    Lifecycles::sweep();
    expect($event->fresh()?->phase)->toBe(EventPhase::Scheduled);

    CarbonImmutable::setTestNow($event->ends_at->addMinute());
    Lifecycles::sweep();

    expect($event->fresh()?->phase)->toBe(EventPhase::Completed)
        ->and($event->fresh()?->completed_at)->not->toBeNull();
    EventBus::assertDispatched(EventCompleted::class);

    // Completing early by hand needs the event to have started.
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2027-01-01 12:00:00', 'UTC'));
    $early = publishedEvent($this->user);
    CarbonImmutable::setTestNow($early->starts_at->subHour());
    expect(fn () => Events::for($early)->complete())->toThrow(EventTransitionDeniedException::class);
    CarbonImmutable::setTestNow($early->starts_at->addMinute());
    Events::for($early)->complete();
    expect($early->phase)->toBe(EventPhase::Completed);
});

test('reschedule_moves_the_auto_complete', function (): void {
    $event = publishedEvent($this->user);
    $originalEnd = $event->ends_at;

    Events::for($event)->reschedule('2027-02-20 20:00');

    expect(Lifecycles::for($event)->expiresAt()?->toIso8601String())->toBe($event->fresh()?->ends_at->toIso8601String());

    CarbonImmutable::setTestNow($originalEnd->addMinute());
    Lifecycles::sweep();
    expect($event->fresh()?->phase)->toBe(EventPhase::Rescheduled);

    CarbonImmutable::setTestNow($event->fresh()?->ends_at->addMinute());
    Lifecycles::sweep();
    expect($event->fresh()?->phase)->toBe(EventPhase::Completed);

    // A plain end change re-arms it too.
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2027-01-01 12:00:00', 'UTC'));
    $other = publishedEvent($this->user);
    Events::for($other)->edit()->endsAt('2027-02-11 01:00')->save();
    expect(Lifecycles::for($other)->expiresAt()?->toIso8601String())->toBe($other->fresh()?->ends_at->toIso8601String());
});

test('postponed_never_auto_completes', function (): void {
    $event = publishedEvent($this->user);
    Events::for($event)->postpone('Storm');

    expect(Lifecycles::for($event)->expiresAt())->toBeNull();

    CarbonImmutable::setTestNow($event->ends_at->addDays(3));
    Lifecycles::sweep();

    expect($event->fresh()?->phase)->toBe(EventPhase::Postponed);
});

test('ends_at_is_only_written_through_save', function (): void {
    // Lifecycle's sweep never re-reads ends_at; only an Eloquent save re-arms the automatic
    // completion. No package write path may change it quietly or through the query builder.
    $offenders = [];
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(__DIR__.'/../../../src'));

    foreach ($files as $file) {
        if (! $file->isFile() || $file->getExtension() !== 'php') {
            continue;
        }

        $source = (string) file_get_contents($file->getPathname());

        if (str_contains($source, 'saveQuietly(') || str_contains($source, 'updateQuietly(')) {
            $offenders[] = $file->getFilename().': quiet save';
        }

        foreach (explode("\n", $source) as $line) {
            if (str_contains($line, 'ends_at') && preg_match('/->(update|insert|upsert|increment|forceFill)\(/', $line) === 1) {
                $offenders[] = $file->getFilename().': '.trim($line);
            }
        }
    }

    expect($offenders)->toBe([]);
});

test('reschedule_is_atomic', function (): void {
    EventBus::fake([EventRescheduled::class]);
    $event = publishedEvent($this->user);
    $announced = $event->starts_at;
    $sequence = $event->fresh()?->sequence;

    Events::for($event)->reschedule('2027-02-20 20:00', reason: 'Band ill');

    $event->refresh();
    expect($event->phase)->toBe(EventPhase::Rescheduled)
        ->and($event->previous_starts_at?->equalTo($announced))->toBeTrue()
        ->and($event->startsAtLocal()->format('Y-m-d H:i'))->toBe('2027-02-20 20:00')
        // The length is kept without a new end.
        ->and($event->starts_at->diffInHours($event->ends_at))->toBe(2.0)
        ->and($event->sequence)->toBe($sequence + 1);

    EventBus::assertDispatched(EventRescheduled::class, static fn (EventRescheduled $rescheduled): bool => $rescheduled->previousStartsAt->equalTo($announced)
        && $rescheduled->reason === 'Band ill');

    // A second reschedule keeps the originally announced date.
    Events::for($event)->reschedule('2027-02-27 20:00', '2027-02-27 23:30');
    expect($event->fresh()?->previous_starts_at?->equalTo($announced))->toBeTrue()
        ->and($event->fresh()?->endsAtLocal()->format('H:i'))->toBe('23:30');

    // A denied transition rolls the times back.
    Events::for($event)->cancel();
    $before = $event->fresh();

    expect(fn () => Events::for($event)->reschedule('2027-04-01 20:00'))->toThrow(EventTransitionDeniedException::class);
    expect($event->fresh()?->starts_at->equalTo($before?->starts_at))->toBeTrue()
        ->and($event->fresh()?->ends_at->equalTo($before?->ends_at))->toBeTrue();
});

test('rescheduling_a_draft_edits_it', function (): void {
    EventBus::fake([EventRescheduled::class, EventUpdated::class]);
    $draft = Events::make('Draft')->organizedBy($this->user)->startsAt('2027-02-10 20:00')->create();

    Events::for($draft)->reschedule('2027-02-12 19:00');

    expect($draft->fresh()?->phase)->toBe(EventPhase::Draft)
        ->and($draft->fresh()?->startsAtLocal()->format('Y-m-d H:i'))->toBe('2027-02-12 19:00')
        ->and($draft->fresh()?->previous_starts_at)->toBeNull();

    EventBus::assertNotDispatched(EventRescheduled::class);
    EventBus::assertDispatched(EventUpdated::class);

    // An all-day draft keeps its number of days.
    $festival = Events::make('Festival')->organizedBy($this->user)->timezone('Europe/Bratislava')->allDay('2027-07-16', through: '2027-07-18')->create();
    Events::for($festival)->reschedule('2027-08-06');

    expect($festival->fresh()?->starts_on)->toBe('2027-08-06')
        ->and($festival->fresh()?->ends_on)->toBe('2027-08-08');
});

test('lifecycle_bridge_dispatches_phase_events', function (): void {
    EventBus::fake([EventPublished::class, EventUnpublished::class, EventPostponed::class, EventRescheduled::class, EventCancelled::class, EventStatusChanged::class]);

    $event = Events::make('Bridge')->organizedBy($this->user)->startsAt('2027-02-10 20:00')->create();
    Events::for($event)->publish();
    Events::for($event)->unpublish();
    Events::for($event)->publish();
    Events::for($event)->postpone('Storm');
    Events::for($event)->silently()->reschedule('2027-03-10 20:00');
    Events::for($event)->by($this->user)->cancel('Venue flooded');

    EventBus::assertDispatchedTimes(EventPublished::class, 2);
    EventBus::assertDispatchedTimes(EventUnpublished::class, 1);
    EventBus::assertDispatched(EventPostponed::class, static fn (EventPostponed $postponed): bool => $postponed->reason === 'Storm' && ! $postponed->silent);
    EventBus::assertDispatched(EventRescheduled::class, static fn (EventRescheduled $rescheduled): bool => $rescheduled->silent);
    EventBus::assertDispatched(EventCancelled::class, fn (EventCancelled $cancelled): bool => $cancelled->reason === 'Venue flooded' && $cancelled->actor?->is($this->user) === true);
    // One per history row, the initial one excluded.
    EventBus::assertDispatchedTimes(EventStatusChanged::class, 6);
});

test('cancellation_effects', function (): void {
    EventBus::fake([RsvpHoldExpired::class]);
    $event = publishedEvent($this->user);
    Events::for($event)->update(new UpdateEventData(capacity: 3));
    Events::for($event)->tiers()->add('Supporter', price: Money::ofMajor('10', 'EUR'), key: 'supporter');
    Events::for($event)->tiers()->add('Free', key: 'free');
    $held = Events::for($event)->rsvp(User::factory()->create())->going(tier: 'supporter');
    $going = Events::for($event)->rsvp(User::factory()->create())->going(tier: 'free');
    $sequence = $event->fresh()?->sequence;

    Events::for($event)->cancel('Venue flooded');

    // Holds are released (no payment is collected for a cancelled event); going attendees keep
    // their RSVPs (they are told; paid ones refunded by the host).
    expect($held->fresh()?->status)->toBe(RsvpStatus::Expired)
        ->and($going->fresh()?->status)->toBe(RsvpStatus::Going)
        ->and($event->fresh()?->seats_taken)->toBe(1);
    EventBus::assertDispatched(RsvpHoldExpired::class);

    // Auto-promotion stops: nobody moves up after a cancellation.
    expect(fn () => Events::for($event)->rsvp(User::factory()->create())->going(tier: 'free'))->toThrow(RsvpClosedException::class);

    expect($event->fresh()?->phase)->toBe(EventPhase::Cancelled)
        ->and($event->fresh()?->sequence)->toBe($sequence + 1)
        ->and($event->fresh()?->cancelled_at)->not->toBeNull()
        // The dates are kept (schema.org EventCancelled).
        ->and($event->fresh()?->starts_at->equalTo($event->starts_at))->toBeTrue();
});

test('move_online_effects', function (): void {
    EventBus::fake([EventMovedOnline::class]);
    $event = publishedEvent($this->user);
    $sequence = $event->fresh()?->sequence;

    Events::for($event)->moveOnline('https://stream.example.com/jazz');

    $moved = $event->fresh();
    expect($moved?->attendance_mode)->toBe(AttendanceMode::Online)
        ->and($moved?->venue_id)->toBeNull()
        ->and($moved?->online_url)->toBe('https://stream.example.com/jazz')
        ->and($moved?->moved_online_at)->not->toBeNull()
        ->and($moved?->sequence)->toBe($sequence + 1)
        ->and($moved?->phase)->toBe(EventPhase::Scheduled);
    EventBus::assertDispatched(EventMovedOnline::class);

    $hybrid = publishedEvent($this->user);
    Events::for($hybrid)->moveOnline('https://stream.example.com/h', keepVenue: true);
    expect($hybrid->fresh()?->attendance_mode)->toBe(AttendanceMode::Mixed)
        ->and($hybrid->fresh()?->venue_id)->not->toBeNull();

    Events::for($hybrid)->cancel();
    expect(fn () => Events::for($hybrid)->moveOnline('https://stream.example.com/x'))
        ->toThrow(InvalidEventException::class, 'The event is over; it can no longer change.');
});

test('swapped_lifecycle_is_supported', function (): void {
    EventBus::fake([EventPublished::class]);
    config()->set('events.lifecycle', CustomLifecycle::class);

    $event = Events::make('Community picnic')->organizedBy($this->user)->startsAt('2027-02-10 12:00')->create();
    expect($event->status?->value)->toBe('draft');

    Events::for($event)->transition('submit');
    expect($event->phase)->toBe(EventPhase::Draft)
        ->and($event->status?->value)->toBe('submitted');

    // publish() maps to the host's approval step.
    Events::for($event)->publish();
    expect($event->phase)->toBe(EventPhase::Scheduled);
    EventBus::assertDispatched(EventPublished::class);

    Events::for($event)->cancel();
    expect($event->phase)->toBe(EventPhase::Cancelled)
        ->and(Event::query()->inPhase(EventPhase::Cancelled)->pluck('id')->all())->toBe([$event->getKey()]);

    // A lifecycle whose states are not event states is refused on first use.
    config()->set('events.lifecycle', BrokenLifecycle::class);
    expect(fn () => Events::make('X')->organizedBy($this->user)->startsAt('2027-02-10 12:00')->publish())
        ->toThrow(InvalidEventLifecycleException::class, 'its states must implement the EventState contract');

    config()->set('events.lifecycle', Clock::class);
    expect(fn () => Event::query()->inPhase(EventPhase::Draft)->get())->toThrow(InvalidEventLifecycleException::class, 'events.lifecycle');
});
