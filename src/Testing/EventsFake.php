<?php

declare(strict_types=1);

namespace RoundlyConsulting\Events\Testing;

use Closure;
use Illuminate\Contracts\Container\Container;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use PHPUnit\Framework\Assert as PHPUnit;
use RoundlyConsulting\Events\DataTransferObjects\CheckInResult;
use RoundlyConsulting\Events\DataTransferObjects\UpdateEventData;
use RoundlyConsulting\Events\Enums\EventOperation;
use RoundlyConsulting\Events\Enums\RsvpStatus;
use RoundlyConsulting\Events\EventsManager;
use RoundlyConsulting\Events\Models\AdmissionTier;
use RoundlyConsulting\Events\Models\Event;
use RoundlyConsulting\Events\Models\Rsvp;
use RoundlyConsulting\Events\Models\Venue;
use RoundlyConsulting\Events\Support\NotGiven;
use RoundlyConsulting\Tags\Models\Tag;

/**
 * A recording, still-performing manager, installed by `Events::fake()`. Every operation runs for
 * real (rows, seats, events, policies) and each successful one is recorded — however it was
 * called: the facade, an injected manager, a handle, a sub-accessor, a builder or a model trait.
 * A call that throws records nothing.
 *
 * Assertions pair up: `assert<Verb>()` with `assertNothing<Verb>()`, `assert<Noun><Verb>()` with
 * `assertNo<Noun><Verb>()`.
 */
final class EventsFake extends EventsManager
{
    /** @var list<RecordedEventOperation> */
    private array $recorded = [];

    public function __construct(Container $container)
    {
        parent::__construct($container);
    }

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
        $this->assertRecordedAny([EventOperation::AttachLabels, EventOperation::SyncLabels], static function (RecordedEventOperation $recorded) use ($event, $labels): bool {
            if (! $recorded->concerns($event)) {
                return false;
            }

            $given = $recorded->context['labels'] ?? [];
            $given = is_iterable($given) ? [...$given] : [$given];

            foreach ($labels ?? [] as $label) {
                if (array_filter($given, static fn (mixed $passed): bool => self::sameTag($passed, $label)) === []) {
                    return false;
                }
            }

            return true;
        });
    }

    public function assertNothingLabelled(): void
    {
        $this->assertNotRecorded(EventOperation::AttachLabels);
        $this->assertNotRecorded(EventOperation::SyncLabels);
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
     * A manual waitlist promotion of `$event` that promoted someone.
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

    // Maintenance ----------------------------------------------------------------------------

    public function assertSwept(): void
    {
        $this->assertRecorded(EventOperation::Sweep, static fn (): bool => true);
    }

    public function assertNothingSwept(): void
    {
        $this->assertNotRecorded(EventOperation::Sweep);
    }

    // Plumbing -------------------------------------------------------------------------------

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
