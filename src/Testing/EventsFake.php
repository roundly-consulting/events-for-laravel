<?php

declare(strict_types=1);

namespace RoundlyConsulting\Events\Testing;

use Closure;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use PHPUnit\Framework\Assert as PHPUnit;
use RoundlyConsulting\Events\DataTransferObjects\CheckInResult;
use RoundlyConsulting\Events\DataTransferObjects\UpdateEventData;
use RoundlyConsulting\Events\Enums\EventOperation;
use RoundlyConsulting\Events\Enums\RsvpResponse;
use RoundlyConsulting\Events\Enums\RsvpStatus;
use RoundlyConsulting\Events\EventsManager;
use RoundlyConsulting\Events\Models\AdmissionTier;
use RoundlyConsulting\Events\Models\Event;
use RoundlyConsulting\Events\Models\Rsvp;
use RoundlyConsulting\Events\Models\Venue;
use RoundlyConsulting\Events\Support\EventModels;
use RoundlyConsulting\Events\Support\NotGiven;
use RoundlyConsulting\Tags\Models\Tag;

/**
 * A recording, still-performing manager, installed by `Events::fake()`. Every operation runs for
 * real (rows, seats, events, policies) and each successful one is recorded — however it was
 * called: the facade, an injected manager, a handle, a sub-accessor, a builder or a model trait.
 * A call that throws records nothing.
 *
 * Assertions pair up: `assert<Verb>()` with `assertNothing<Verb>()`, `assert<Noun><Verb>()` with
 * `assertNo<Noun><Verb>()` — one pair for every {@see EventOperation} — plus the generic
 * `assertPerformed()` / `assertNotPerformed()`.
 */
final class EventsFake extends EventsManager
{
    /** @var list<RecordedEventOperation> */
    private array $recorded = [];

    /**
     * @internal
     *
     * @template TAction of object
     * @template TResult
     *
     * @param  class-string<TAction>  $action
     * @param  Closure(TAction): TResult  $execute
     * @param  array<string, mixed>  $context
     * @return TResult
     */
    public function perform(EventOperation $operation, string $action, Closure $execute, array $context = []): mixed
    {
        $result = parent::perform($operation, $action, $execute, $context);

        $this->recorded[] = new RecordedEventOperation($operation, $context, $result);

        return $result;
    }

    /**
     * Every recorded operation, optionally of one kind, in call order.
     *
     * @return list<RecordedEventOperation>
     */
    public function recorded(?EventOperation $operation = null): array
    {
        return array_values(array_filter(
            $this->recorded,
            static fn (RecordedEventOperation $recorded): bool => $operation === null || $recorded->operation === $operation,
        ));
    }

    /**
     * An operation of this kind was recorded — and, with `$matches`, one it accepts.
     *
     * @param  (Closure(RecordedEventOperation): bool)|null  $matches
     */
    public function assertPerformed(EventOperation $operation, ?Closure $matches = null): void
    {
        $this->assertRecorded($operation, $matches ?? static fn (): bool => true);
    }

    public function assertNotPerformed(EventOperation $operation): void
    {
        $this->assertNotRecorded($operation);
    }

    public function assertNothingHappened(): void
    {
        PHPUnit::assertEmpty(
            $this->recorded,
            sprintf('Expected no event operations, but %d were recorded.', count($this->recorded)),
        );
    }

    // Events ---------------------------------------------------------------------------------

    /**
     * @param  (Closure(Event): bool)|null  $callback
     */
    public function assertCreated(?Closure $callback = null): void
    {
        $this->assertRecorded(EventOperation::Create, static function (RecordedEventOperation $recorded) use ($callback): bool {
            $event = $recorded->event();

            return $callback === null || ($event !== null && $callback($event));
        });
    }

    public function assertNothingCreated(): void
    {
        $this->assertNotRecorded(EventOperation::Create);
    }

    /**
     * An update of `$event` (null = any) that changed every one of `$keys` (UpdateEventData
     * property names: `title`, `capacity`, …).
     *
     * @param  list<string>|null  $keys
     */
    public function assertUpdated(?Event $event = null, ?array $keys = null): void
    {
        $this->assertRecorded(EventOperation::Update, static function (RecordedEventOperation $recorded) use ($event, $keys): bool {
            if (! $recorded->concerns($event)) {
                return false;
            }

            $data = $recorded->context['data'] ?? null;

            if ($keys === null || ! $data instanceof UpdateEventData) {
                return true;
            }

            foreach ($keys as $key) {
                if (! property_exists($data, $key) || $data->{$key} instanceof NotGiven) {
                    return false;
                }
            }

            return true;
        });
    }

    public function assertNothingUpdated(): void
    {
        $this->assertNotRecorded(EventOperation::Update);
    }

    public function assertPublished(?Event $event = null): void
    {
        $this->assertRecorded(EventOperation::Publish, static fn (RecordedEventOperation $recorded): bool => $recorded->concerns($event));
    }

    public function assertNothingPublished(): void
    {
        $this->assertNotRecorded(EventOperation::Publish);
    }

    public function assertPostponed(?Event $event = null): void
    {
        $this->assertRecorded(EventOperation::Postpone, static fn (RecordedEventOperation $recorded): bool => $recorded->concerns($event));
    }

    public function assertNothingPostponed(): void
    {
        $this->assertNotRecorded(EventOperation::Postpone);
    }

    public function assertRescheduled(?Event $event = null): void
    {
        $this->assertRecorded(EventOperation::Reschedule, static fn (RecordedEventOperation $recorded): bool => $recorded->concerns($event));
    }

    public function assertNothingRescheduled(): void
    {
        $this->assertNotRecorded(EventOperation::Reschedule);
    }

    public function assertCancelled(?Event $event = null): void
    {
        $this->assertRecorded(EventOperation::Cancel, static fn (RecordedEventOperation $recorded): bool => $recorded->concerns($event));
    }

    public function assertNothingCancelled(): void
    {
        $this->assertNotRecorded(EventOperation::Cancel);
    }

    public function assertCompleted(?Event $event = null): void
    {
        $this->assertRecorded(EventOperation::Complete, static fn (RecordedEventOperation $recorded): bool => $recorded->concerns($event));
    }

    public function assertNothingCompleted(): void
    {
        $this->assertNotRecorded(EventOperation::Complete);
    }

    public function assertDeleted(?Event $event = null): void
    {
        $this->assertRecorded(EventOperation::Delete, static fn (RecordedEventOperation $recorded): bool => $recorded->concerns($event));
    }

    public function assertNothingDeleted(): void
    {
        $this->assertNotRecorded(EventOperation::Delete);
    }

    public function assertRestored(?Event $event = null): void
    {
        $this->assertRecorded(EventOperation::Restore, static fn (RecordedEventOperation $recorded): bool => $recorded->concerns($event));
    }

    public function assertNothingRestored(): void
    {
        $this->assertNotRecorded(EventOperation::Restore);
    }

    public function assertUnpublished(?Event $event = null): void
    {
        $this->assertRecorded(EventOperation::Unpublish, static fn (RecordedEventOperation $recorded): bool => $recorded->concerns($event));
    }

    public function assertNothingUnpublished(): void
    {
        $this->assertNotRecorded(EventOperation::Unpublish);
    }

    /**
     * A transition of `$event` applied by name (`Events::for($event)->transition($name)`).
     */
    public function assertTransitioned(?Event $event = null, ?string $name = null): void
    {
        $this->assertRecorded(EventOperation::Transition, static fn (RecordedEventOperation $recorded): bool => $recorded->concerns($event)
            && ($name === null || ($recorded->context['transition'] ?? null) === $name));
    }

    public function assertNothingTransitioned(): void
    {
        $this->assertNotRecorded(EventOperation::Transition);
    }

    public function assertMovedOnline(?Event $event = null): void
    {
        $this->assertRecorded(EventOperation::MoveOnline, static fn (RecordedEventOperation $recorded): bool => $recorded->concerns($event));
    }

    public function assertNothingMovedOnline(): void
    {
        $this->assertNotRecorded(EventOperation::MoveOnline);
    }

    /**
     * `$event` handed to the organizer `$to` (each null means any).
     */
    public function assertTransferred(?Event $event = null, ?Model $to = null): void
    {
        $this->assertRecorded(EventOperation::TransferOrganizer, static fn (RecordedEventOperation $recorded): bool => $recorded->concerns($event)
            && ($to === null || $recorded->model('organizer')?->is($to) === true));
    }

    public function assertNothingTransferred(): void
    {
        $this->assertNotRecorded(EventOperation::TransferOrganizer);
    }

    /**
     * The poster of `$event` set or replaced (`$removed: false`) or removed (`true`); null = either.
     */
    public function assertPosterChanged(?Event $event = null, ?bool $removed = null): void
    {
        $this->assertRecorded(EventOperation::SetPoster, static fn (RecordedEventOperation $recorded): bool => $recorded->concerns($event)
            && ($removed === null || ($recorded->context['removed'] ?? null) === $removed));
    }

    public function assertNoPosterChanged(): void
    {
        $this->assertNotRecorded(EventOperation::SetPoster);
    }

    // Category & labels ------------------------------------------------------------------------

    /**
     * A categorization of `$event` (null = any), to `$category` when given (the value passed, or
     * a tag whose slug or key equals it).
     */
    public function assertCategorized(?Event $event = null, Tag|int|string|null $category = null): void
    {
        $this->assertRecorded(EventOperation::Categorize, static fn (RecordedEventOperation $recorded): bool => $recorded->concerns($event)
            && ($category === null || self::sameTag($recorded->context['category'] ?? null, $category)));
    }

    public function assertNothingCategorized(): void
    {
        $this->assertNotRecorded(EventOperation::Categorize);
    }

    /**
     * Labels attached to or synced on `$event` (null = any), including every one of `$labels`
     * when given (names, slugs, keys or tags, as passed).
     *
     * @param  list<Tag|int|string>|null  $labels
     */
    public function assertLabelled(?Event $event = null, ?array $labels = null): void
    {
        $this->assertRecordedAny([EventOperation::AttachLabels, EventOperation::SyncLabels], static fn (RecordedEventOperation $recorded): bool => $recorded->concerns($event)
            && self::passedEveryTag($recorded, $labels));
    }

    public function assertNothingLabelled(): void
    {
        $this->assertNotRecorded(EventOperation::AttachLabels);
        $this->assertNotRecorded(EventOperation::SyncLabels);
    }

    /**
     * Labels detached from `$event` (null = any), including every one of `$labels` when given.
     *
     * @param  list<Tag|int|string>|null  $labels
     */
    public function assertUnlabelled(?Event $event = null, ?array $labels = null): void
    {
        $this->assertRecorded(EventOperation::DetachLabels, static fn (RecordedEventOperation $recorded): bool => $recorded->concerns($event)
            && self::passedEveryTag($recorded, $labels));
    }

    public function assertNothingUnlabelled(): void
    {
        $this->assertNotRecorded(EventOperation::DetachLabels);
    }

    // Venues ---------------------------------------------------------------------------------

    /**
     * @param  (Closure(Venue): bool)|null  $callback
     */
    public function assertVenueCreated(?Closure $callback = null): void
    {
        $this->assertRecorded(EventOperation::CreateVenue, static fn (RecordedEventOperation $recorded): bool => $callback === null
            || ($recorded->result instanceof Venue && $callback($recorded->result)));
    }

    public function assertNoVenueCreated(): void
    {
        $this->assertNotRecorded(EventOperation::CreateVenue);
    }

    /**
     * @param  (Closure(Venue): bool)|null  $callback
     */
    public function assertVenueUpdated(?Closure $callback = null): void
    {
        $this->assertRecorded(EventOperation::UpdateVenue, self::venueMatches($callback));
    }

    public function assertNoVenueUpdated(): void
    {
        $this->assertNotRecorded(EventOperation::UpdateVenue);
    }

    /**
     * @param  (Closure(Venue): bool)|null  $callback
     */
    public function assertVenueDeleted(?Closure $callback = null): void
    {
        $this->assertRecorded(EventOperation::DeleteVenue, self::venueMatches($callback));
    }

    public function assertNoVenueDeleted(): void
    {
        $this->assertNotRecorded(EventOperation::DeleteVenue);
    }

    /**
     * @param  (Closure(Venue): bool)|null  $callback
     */
    public function assertVenueRestored(?Closure $callback = null): void
    {
        $this->assertRecorded(EventOperation::RestoreVenue, self::venueMatches($callback));
    }

    public function assertNoVenueRestored(): void
    {
        $this->assertNotRecorded(EventOperation::RestoreVenue);
    }

    /**
     * @param  (Closure(Venue): bool)|null  $callback
     */
    public function assertVenueGeocoded(?Closure $callback = null): void
    {
        $this->assertRecorded(EventOperation::GeocodeVenue, self::venueMatches($callback));
    }

    public function assertNoVenueGeocoded(): void
    {
        $this->assertNotRecorded(EventOperation::GeocodeVenue);
    }

    // Ticket types ---------------------------------------------------------------------------

    /**
     * @param  (Closure(AdmissionTier): bool)|null  $callback
     */
    public function assertTierCreated(?Closure $callback = null): void
    {
        $this->assertRecorded(EventOperation::CreateTier, static fn (RecordedEventOperation $recorded): bool => $callback === null
            || ($recorded->result instanceof AdmissionTier && $callback($recorded->result)));
    }

    public function assertNoTierCreated(): void
    {
        $this->assertNotRecorded(EventOperation::CreateTier);
    }

    /**
     * @param  (Closure(AdmissionTier): bool)|null  $callback
     */
    public function assertTierUpdated(?Closure $callback = null): void
    {
        $this->assertRecorded(EventOperation::UpdateTier, self::tierMatches($callback));
    }

    public function assertNoTierUpdated(): void
    {
        $this->assertNotRecorded(EventOperation::UpdateTier);
    }

    /**
     * @param  (Closure(AdmissionTier): bool)|null  $callback
     */
    public function assertTierDeleted(?Closure $callback = null): void
    {
        $this->assertRecorded(EventOperation::DeleteTier, self::tierMatches($callback));
    }

    public function assertNoTierDeleted(): void
    {
        $this->assertNotRecorded(EventOperation::DeleteTier);
    }

    /**
     * @param  (Closure(AdmissionTier): bool)|null  $callback
     */
    public function assertTierRestored(?Closure $callback = null): void
    {
        $this->assertRecorded(EventOperation::RestoreTier, self::tierMatches($callback));
    }

    public function assertNoTierRestored(): void
    {
        $this->assertNotRecorded(EventOperation::RestoreTier);
    }

    public function assertTiersReordered(?Event $event = null): void
    {
        $this->assertRecorded(EventOperation::ReorderTiers, static fn (RecordedEventOperation $recorded): bool => $recorded->concerns($event));
    }

    public function assertNoTiersReordered(): void
    {
        $this->assertNotRecorded(EventOperation::ReorderTiers);
    }

    // RSVPs ----------------------------------------------------------------------------------

    /**
     * An answer (through the handle, the manager or `$model->rsvp($event)`) for `$event` by
     * `$attendee` that ended in `$status` — each null means any.
     */
    public function assertRsvped(?Event $event = null, ?Model $attendee = null, ?RsvpStatus $status = null): void
    {
        $this->assertRecorded(EventOperation::Rsvp, static function (RecordedEventOperation $recorded) use ($event, $attendee, $status): bool {
            $rsvp = $recorded->result;

            return $rsvp instanceof Rsvp
                && $recorded->concerns($event)
                && ($attendee === null || ($rsvp->attendee_type === $attendee->getMorphClass() && (string) $rsvp->attendee_id === (string) $attendee->getKey()))
                && ($status === null || $rsvp->status === $status);
        });
    }

    public function assertNothingRsvped(): void
    {
        $this->assertNotRecorded(EventOperation::Rsvp);
    }

    /**
     * A manager added `$attendee` to `$event` (each null means any).
     */
    public function assertAttendeeAdded(?Event $event = null, ?Model $attendee = null): void
    {
        $this->assertRecorded(EventOperation::AddAttendee, static fn (RecordedEventOperation $recorded): bool => $recorded->concerns($event)
            && self::attendeeMatches($recorded, $attendee));
    }

    public function assertNoAttendeeAdded(): void
    {
        $this->assertNotRecorded(EventOperation::AddAttendee);
    }

    /**
     * A manager removed `$attendee` from `$event` (each null means any).
     */
    public function assertAttendeeRemoved(?Event $event = null, ?Model $attendee = null): void
    {
        $this->assertRecorded(EventOperation::RemoveAttendee, static fn (RecordedEventOperation $recorded): bool => $recorded->concerns($event)
            && self::attendeeMatches($recorded, $attendee));
    }

    public function assertNoAttendeeRemoved(): void
    {
        $this->assertNotRecorded(EventOperation::RemoveAttendee);
    }

    public function assertTierChanged(?Event $event = null): void
    {
        $this->assertRecorded(EventOperation::ChangeTier, static fn (RecordedEventOperation $recorded): bool => $recorded->concerns($event));
    }

    public function assertNoTierChanged(): void
    {
        $this->assertNotRecorded(EventOperation::ChangeTier);
    }

    public function assertGuestsChanged(?Event $event = null): void
    {
        $this->assertRecorded(EventOperation::ChangeGuests, static fn (RecordedEventOperation $recorded): bool => $recorded->concerns($event));
    }

    public function assertNoGuestsChanged(): void
    {
        $this->assertNotRecorded(EventOperation::ChangeGuests);
    }

    /**
     * A manager confirmed a hold by hand ("paid at the door") at `$event`.
     */
    public function assertRsvpConfirmed(?Event $event = null): void
    {
        $this->assertRecorded(EventOperation::ConfirmRsvp, static fn (RecordedEventOperation $recorded): bool => $recorded->concerns($event));
    }

    public function assertNoRsvpConfirmed(): void
    {
        $this->assertNotRecorded(EventOperation::ConfirmRsvp);
    }

    public function assertHoldReleased(?Event $event = null): void
    {
        $this->assertRecorded(EventOperation::ReleaseHold, static fn (RecordedEventOperation $recorded): bool => $recorded->concerns($event));
    }

    public function assertNoHoldReleased(): void
    {
        $this->assertNotRecorded(EventOperation::ReleaseHold);
    }

    /**
     * A checkout requested for a hold at `$event` (by hand or by the package's own job).
     */
    public function assertPaymentRequested(?Event $event = null): void
    {
        $this->assertRecorded(EventOperation::RequestPayment, static fn (RecordedEventOperation $recorded): bool => $recorded->concerns($event));
    }

    public function assertNoPaymentRequested(): void
    {
        $this->assertNotRecorded(EventOperation::RequestPayment);
    }

    public function assertCodeRotated(?Event $event = null): void
    {
        $this->assertRecorded(EventOperation::RotateCode, static fn (RecordedEventOperation $recorded): bool => $recorded->concerns($event));
    }

    public function assertNoCodeRotated(): void
    {
        $this->assertNotRecorded(EventOperation::RotateCode);
    }

    /**
     * A deleted attendee's RSVPs released (the `AttendsEvents` deletion hook) — of `$attendee`
     * when given.
     */
    public function assertAttendeeForgotten(?Model $attendee = null): void
    {
        $this->assertRecorded(EventOperation::ForgetAttendee, static fn (RecordedEventOperation $recorded): bool => $attendee === null
            || $recorded->model('attendee')?->is($attendee) === true);
    }

    public function assertNoAttendeeForgotten(): void
    {
        $this->assertNotRecorded(EventOperation::ForgetAttendee);
    }

    /**
     * A manual waitlist promotion of `$event` that promoted someone. Automatic promotions (after a
     * decline, a sweep or a capacity change) show as the operation that triggered them.
     */
    public function assertPromoted(?Event $event = null): void
    {
        $this->assertRecorded(EventOperation::PromoteWaitlist, static fn (RecordedEventOperation $recorded): bool => $recorded->concerns($event)
            && $recorded->result instanceof Collection && $recorded->result->isNotEmpty());
    }

    public function assertNothingPromoted(): void
    {
        $this->assertNotRecorded(EventOperation::PromoteWaitlist);
    }

    public function assertPaymentConfirmed(?string $reference = null): void
    {
        $this->assertRecorded(EventOperation::ConfirmPayment, static fn (RecordedEventOperation $recorded): bool => $reference === null || $recorded->context['reference'] === $reference);
    }

    public function assertNoPaymentConfirmed(): void
    {
        $this->assertNotRecorded(EventOperation::ConfirmPayment);
    }

    // Check-in -------------------------------------------------------------------------------

    /**
     * A first check-in (by code or by hand) of `$attendee` at `$event` — each null means any.
     */
    public function assertCheckedIn(?Event $event = null, ?Model $attendee = null): void
    {
        $this->assertRecordedAny([EventOperation::CheckIn, EventOperation::ManualCheckIn], static fn (RecordedEventOperation $recorded): bool => $recorded->result instanceof CheckInResult
            && $recorded->result->firstCheckIn
            && ($event === null || $recorded->result->rsvp->event_id == $event->getKey())
            && ($attendee === null || $recorded->result->attendee->is($attendee)));
    }

    public function assertNothingCheckedIn(): void
    {
        $this->assertNotRecorded(EventOperation::CheckIn);
        $this->assertNotRecorded(EventOperation::ManualCheckIn);
    }

    public function assertCheckInUndone(?Event $event = null): void
    {
        $this->assertRecorded(EventOperation::UndoCheckIn, static fn (RecordedEventOperation $recorded): bool => $recorded->concerns($event));
    }

    public function assertNoCheckInUndone(): void
    {
        $this->assertNotRecorded(EventOperation::UndoCheckIn);
    }

    // People ---------------------------------------------------------------------------------

    public function assertInvited(?Event $event = null, ?Model $invitee = null): void
    {
        $this->assertRecorded(EventOperation::SendInvitation, static fn (RecordedEventOperation $recorded): bool => $recorded->concerns($event)
            && ($invitee === null || $recorded->model('invitee')?->is($invitee) === true));
    }

    public function assertNothingInvited(): void
    {
        $this->assertNotRecorded(EventOperation::SendInvitation);
    }

    public function assertCoHostAdded(?Event $event = null, ?Model $host = null): void
    {
        $this->assertRecorded(EventOperation::AddCoHost, static fn (RecordedEventOperation $recorded): bool => $recorded->concerns($event)
            && ($host === null || $recorded->model('host')?->is($host) === true));
    }

    public function assertNoCoHostAdded(): void
    {
        $this->assertNotRecorded(EventOperation::AddCoHost);
    }

    public function assertCoHostRemoved(?Event $event = null, ?Model $host = null): void
    {
        $this->assertRecorded(EventOperation::RemoveCoHost, static fn (RecordedEventOperation $recorded): bool => $recorded->concerns($event)
            && ($host === null || $recorded->model('host')?->is($host) === true));
    }

    public function assertNoCoHostRemoved(): void
    {
        $this->assertNotRecorded(EventOperation::RemoveCoHost);
    }

    public function assertInvitationRevoked(?Event $event = null, ?Model $invitee = null): void
    {
        $this->assertRecorded(EventOperation::RevokeInvitation, static fn (RecordedEventOperation $recorded): bool => $recorded->concerns($event)
            && ($invitee === null || $recorded->model('invitee')?->is($invitee) === true));
    }

    public function assertNoInvitationRevoked(): void
    {
        $this->assertNotRecorded(EventOperation::RevokeInvitation);
    }

    /**
     * An invitation to `$event` answered from its link — accepted (going or interested) or
     * declined (`RsvpResponse::NotGoing`); each null means any.
     */
    public function assertInvitationAnswered(?Event $event = null, ?RsvpResponse $response = null): void
    {
        $this->assertRecorded(EventOperation::RespondToInvitation, static fn (RecordedEventOperation $recorded): bool => $recorded->concerns($event)
            && ($response === null || ($recorded->context['response'] ?? null) === $response));
    }

    public function assertNoInvitationAnswered(): void
    {
        $this->assertNotRecorded(EventOperation::RespondToInvitation);
    }

    // Maintenance ----------------------------------------------------------------------------

    public function assertSwept(): void
    {
        $this->assertRecorded(EventOperation::Sweep, static fn (): bool => true);
    }

    public function assertNothingSwept(): void
    {
        $this->assertNotRecorded(EventOperation::Sweep);
    }

    public function assertRepaired(): void
    {
        $this->assertRecorded(EventOperation::Repair, static fn (): bool => true);
    }

    public function assertNothingRepaired(): void
    {
        $this->assertNotRecorded(EventOperation::Repair);
    }

    // Plumbing -------------------------------------------------------------------------------

    /**
     * @param  list<Tag|int|string>|null  $labels
     */
    private static function passedEveryTag(RecordedEventOperation $recorded, ?array $labels): bool
    {
        $given = $recorded->context['labels'] ?? [];
        $given = is_iterable($given) ? [...$given] : [$given];

        foreach ($labels ?? [] as $label) {
            if (array_filter($given, static fn (mixed $passed): bool => self::sameTag($passed, $label)) === []) {
                return false;
            }
        }

        return true;
    }

    /**
     * The attendee of a recorded call — passed as a model or an RSVP, or the RSVP it returned.
     */
    private static function attendeeMatches(RecordedEventOperation $recorded, ?Model $attendee): bool
    {
        if ($attendee === null) {
            return true;
        }

        foreach ([$recorded->context['attendee'] ?? null, $recorded->context['rsvp'] ?? null, $recorded->result] as $candidate) {
            $matches = $candidate instanceof Rsvp
                ? $candidate->attendee_type === $attendee->getMorphClass() && (string) $candidate->attendee_id === (string) $attendee->getKey()
                : $candidate instanceof Model && $candidate->is($attendee);

            if ($matches) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  (Closure(Venue): bool)|null  $callback
     * @return Closure(RecordedEventOperation): bool
     */
    private static function venueMatches(?Closure $callback): Closure
    {
        return static function (RecordedEventOperation $recorded) use ($callback): bool {
            $venue = $recorded->result instanceof Venue ? $recorded->result : ($recorded->context['venue'] ?? null);

            return $callback === null || ($venue instanceof Venue && $callback($venue));
        };
    }

    /**
     * @param  (Closure(AdmissionTier): bool)|null  $callback
     * @return Closure(RecordedEventOperation): bool
     */
    private static function tierMatches(?Closure $callback): Closure
    {
        return static function (RecordedEventOperation $recorded) use ($callback): bool {
            if ($callback === null) {
                return true;
            }

            $tier = $recorded->result instanceof AdmissionTier ? $recorded->result : ($recorded->context['tier'] ?? null);

            if (! $tier instanceof AdmissionTier && (is_int($tier) || is_string($tier))) {
                $query = EventModels::tier()::withTrashed()->where('event_id', $recorded->event()?->getKey());
                $tier = is_int($tier) ? $query->whereKey($tier)->first() : $query->where('key', $tier)->first();
            }

            return $tier instanceof AdmissionTier && $callback($tier);
        };
    }

    private static function sameTag(mixed $passed, Tag|int|string $expected): bool
    {
        $values = static fn (mixed $tag): array => $tag instanceof Tag
            ? array_map('strval', [$tag->getKey(), $tag->slug])
            : (is_int($tag) || is_string($tag) ? [mb_strtolower((string) $tag)] : []);

        return array_intersect(array_map('mb_strtolower', $values($passed)), array_map('mb_strtolower', $values($expected))) !== [];
    }

    /**
     * @param  Closure(RecordedEventOperation): bool  $matches
     */
    private function assertRecorded(EventOperation $operation, Closure $matches): void
    {
        $candidates = $this->recorded($operation);

        PHPUnit::assertNotEmpty($candidates, sprintf('Expected a [%s] event operation, but none was recorded.', $operation->value));
        PHPUnit::assertNotEmpty(
            array_filter($candidates, $matches),
            sprintf('A [%s] event operation was recorded, but none matched.', $operation->value),
        );
    }

    /**
     * @param  list<EventOperation>  $operations
     * @param  Closure(RecordedEventOperation): bool  $matches
     */
    private function assertRecordedAny(array $operations, Closure $matches): void
    {
        $candidates = array_values(array_filter($this->recorded, static fn (RecordedEventOperation $recorded): bool => in_array($recorded->operation, $operations, true)));
        $names = implode('|', array_map(static fn (EventOperation $operation): string => $operation->value, $operations));

        PHPUnit::assertNotEmpty($candidates, sprintf('Expected a [%s] event operation, but none was recorded.', $names));
        PHPUnit::assertNotEmpty(array_filter($candidates, $matches), sprintf('A [%s] event operation was recorded, but none matched.', $names));
    }

    private function assertNotRecorded(EventOperation $operation): void
    {
        $count = count($this->recorded($operation));

        PHPUnit::assertSame(0, $count, sprintf('Expected no [%s] event operation, but %d were recorded.', $operation->value, $count));
    }
}
