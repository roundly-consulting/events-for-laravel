<?php

declare(strict_types=1);

namespace RoundlyConsulting\Events\Testing;

use Closure;
use Illuminate\Contracts\Container\Container;
use PHPUnit\Framework\Assert as PHPUnit;
use RoundlyConsulting\Events\DataTransferObjects\UpdateEventData;
use RoundlyConsulting\Events\Enums\EventOperation;
use RoundlyConsulting\Events\EventsManager;
use RoundlyConsulting\Events\Models\Event;
use RoundlyConsulting\Events\Models\Venue;
use RoundlyConsulting\Events\Support\NotGiven;

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

    // Plumbing -------------------------------------------------------------------------------

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

    private function assertNotRecorded(EventOperation $operation): void
    {
        $count = count($this->recorded($operation));

        PHPUnit::assertSame(0, $count, sprintf('Expected no [%s] event operation, but %d were recorded.', $operation->value, $count));
    }
}
