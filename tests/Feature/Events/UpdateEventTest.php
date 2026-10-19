<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event as EventBus;
use RoundlyConsulting\Events\DataTransferObjects\UpdateEventData;
use RoundlyConsulting\Events\Enums\AttendanceMode;
use RoundlyConsulting\Events\Enums\EventPhase;
use RoundlyConsulting\Events\Enums\GuestList;
use RoundlyConsulting\Events\Events\EventDeleted;
use RoundlyConsulting\Events\Events\EventRestored;
use RoundlyConsulting\Events\Events\EventUpdated;
use RoundlyConsulting\Events\Exceptions\EventNotFoundException;
use RoundlyConsulting\Events\Exceptions\EventTrashedException;
use RoundlyConsulting\Events\Exceptions\InvalidEventException;
use RoundlyConsulting\Events\Exceptions\InvalidEventScheduleException;
use RoundlyConsulting\Events\Facades\Events;
use RoundlyConsulting\Events\Handles\EventHandle;
use RoundlyConsulting\Events\Models\Event;
use RoundlyConsulting\Events\Models\Venue;
use RoundlyConsulting\Events\Tests\Fixtures\Models\User;
use RoundlyConsulting\Lifecycle\DataTransferObjects\TransitionResult;

beforeEach(function (): void {
    $this->user = User::factory()->create();
    $this->event = Events::make('Jazz Night')->organizedBy($this->user)
        ->at(Venue::factory()->create())
        ->startsAt('2027-02-10 20:00')->duration('2 hours')
        ->publish();
});

test('update_refuses_a_new_start_on_published_events', function (): void {
    expect(fn () => Events::for($this->event)->update(new UpdateEventData(startsAt: '2027-02-11 20:00')))
        ->toThrow(InvalidEventException::class, 'A published event gets a new start by rescheduling it.')
        ->and(fn () => Events::for($this->event)->edit()->allDay('2027-02-11')->save())
        ->toThrow(InvalidEventException::class, 'rescheduling');

    // An end, a doors time and a timezone change are ordinary updates.
    $sequence = $this->event->fresh()?->sequence;
    Events::for($this->event)->edit()->endsAt('2027-02-10 23:00')->doorsOpenAt('2027-02-10 19:30')->save();

    expect($this->event->fresh()?->endsAtLocal()->format('H:i'))->toBe('23:00')
        ->and($this->event->fresh()?->sequence)->toBe($sequence + 1);

    $startsAt = $this->event->fresh()?->starts_at;
    Events::for($this->event)->edit()->timezone('America/New_York')->save();

    expect($this->event->fresh()?->starts_at->equalTo($startsAt))->toBeTrue()
        ->and($this->event->fresh()?->starts_on)->toBe('2027-02-10');

    // A draft takes a new start through update().
    $draft = Events::make('Draft')->organizedBy($this->user)->startsAt('2027-02-10 20:00')->create();
    Events::for($draft)->update(new UpdateEventData(startsAt: '2027-03-01 18:00'));

    expect($draft->fresh()?->startsAtLocal()->format('Y-m-d H:i'))->toBe('2027-03-01 18:00')
        ->and($draft->fresh()?->starts_at->diffInHours($draft->fresh()?->ends_at))->toBe(2.0);
});

it('changes only the fields given and reports them', function (): void {
    EventBus::fake([EventUpdated::class]);
    $other = Venue::factory()->create();

    Events::for($this->event)->update(new UpdateEventData(
        title: ['en' => 'Late Jazz Night'],
        description: null,
        venue: $other,
        onlineUrl: 'https://meet.example.com/x',
        capacity: 80,
        maxGuests: 1,
        guestList: GuestList::Public,
        showCounts: false,
        reminders: ['P1D'],
        meta: ['source' => 'import'],
    ));

    $event = $this->event->fresh();
    expect($event?->title)->toBe('Late Jazz Night')
        ->and($event?->venue_id)->toBe($other->getKey())
        ->and($event?->attendance_mode)->toBe(AttendanceMode::Mixed)
        ->and($event?->capacity)->toBe(80)
        ->and($event?->reminders)->toBe(['P1D'])
        ->and($event?->meta)->toBe(['source' => 'import']);

    EventBus::assertDispatched(EventUpdated::class, static fn (EventUpdated $updated): bool => in_array('venue_id', $updated->changed, true)
        && in_array('capacity', $updated->changed, true)
        && ! in_array('sequence', $updated->changed, true));

    // Nothing to change: no event.
    EventBus::fake([EventUpdated::class]);
    Events::for($this->event)->update(new UpdateEventData(capacity: 80));
    EventBus::assertNotDispatched(EventUpdated::class);

    expect(fn () => Events::for($this->event)->update(new UpdateEventData(timezone: 'Nowhere/Land')))
        ->toThrow(InvalidEventScheduleException::class)
        ->and(fn () => Events::for($this->event)->update(new UpdateEventData(rsvpOpensAt: '2027-02-09 10:00', rsvpClosesAt: '2027-02-08 10:00')))
        ->toThrow(InvalidEventScheduleException::class);
});

test('handle_mutators_chain', function (): void {
    $draft = Events::make('Chain')->organizedBy($this->user)->startsAt('2027-02-10 20:00')->create();

    $handle = Events::for($draft)->by($this->user)->publish()->postpone('Weather');

    // Mutators return the handle; each call sees the previous one's effect.
    expect($handle)->toBeInstanceOf(EventHandle::class)
        ->and($handle->event()->phase)->toBe(EventPhase::Postponed)
        ->and($handle->phase())->toBe(EventPhase::Postponed)
        ->and($handle->status()->phase())->toBe(EventPhase::Postponed);

    $handle = $handle->reschedule('2027-03-10 20:00')->edit()->capacity(40)->save();
    expect($handle->capacity)->toBe(40);

    // Creators and queries return their result.
    expect(Events::for($draft)->transition('cancel', 'Called off'))->toBeInstanceOf(TransitionResult::class)
        ->and(Events::for($draft)->allowedTransitions())->toBe([])
        ->and(Events::for($this->event)->allowedTransitions())->toContain('postpone', 'cancel')
        ->and(Events::for($this->event)->can('postpone'))->toBeTrue()
        ->and(Events::for($this->event)->can('publish'))->toBeFalse();
});

test('trashed_event_is_read_only', function (): void {
    EventBus::fake([EventDeleted::class, EventRestored::class]);
    $draft = Events::make('Trash')->organizedBy($this->user)->startsAt('2027-02-10 20:00')->create();

    Events::for($draft)->delete();
    EventBus::assertDispatched(EventDeleted::class);

    expect($draft->fresh()?->trashed())->toBeTrue()
        ->and(fn () => Events::for($draft->uuid))->toThrow(EventNotFoundException::class);

    foreach ([
        fn () => Events::for($draft)->publish(),
        fn () => Events::for($draft)->update(new UpdateEventData(capacity: 3)),
        fn () => Events::for($draft)->reschedule('2027-03-01 10:00'),
        fn () => Events::for($draft)->moveOnline('https://meet.example.com/x'),
        fn () => Events::for($draft)->poster(),
    ] as $mutation) {
        expect($mutation)->toThrow(EventTrashedException::class, 'The event is deleted. Restore it to change it.');
    }

    Events::for($draft)->restore();
    EventBus::assertDispatched(EventRestored::class);

    expect($draft->fresh()?->trashed())->toBeFalse();
    Events::for($draft)->publish();
    expect($draft->fresh()?->phase)->toBe(EventPhase::Scheduled);

    // A force delete removes the row for good.
    Events::for($draft)->unpublish()->delete(force: true);
    expect(Event::withTrashed()->find($draft->getKey()))->toBeNull();
});

it('finds events by uuid and refuses malformed ones', function (): void {
    expect(Events::find($this->event->uuid)?->is($this->event))->toBeTrue()
        ->and(Events::find('not-a-uuid'))->toBeNull()
        ->and(Events::findOrFail($this->event->uuid)->is($this->event))->toBeTrue()
        ->and(Events::for($this->event->uuid)->event()->is($this->event))->toBeTrue()
        ->and(fn () => Events::findOrFail('01a155e7-70d9-727f-ab52-1bcbe1010883'))->toThrow(EventNotFoundException::class, 'The event could not be found.');
});
