<?php

declare(strict_types=1);

namespace RoundlyConsulting\Events\Lifecycle;

use RoundlyConsulting\Events\Contracts\EventLifecycle as EventLifecycleContract;
use RoundlyConsulting\Events\Enums\EventStatus;
use RoundlyConsulting\Events\Enums\EventVerb;
use RoundlyConsulting\Lifecycle\Definition\LifecycleBuilder;
use RoundlyConsulting\Lifecycle\Definition\LifecycleDefinition;

/**
 * The shipped event lifecycle: draft → scheduled, then postponed / rescheduled / cancelled, and
 * completed automatically at `ends_at` (lifecycle's sweep applies `complete` once the event's
 * own `ends_at` passes; changing `ends_at` re-arms it).
 *
 * Hosts swap it with `events.lifecycle` — a subclass, or any definition implementing
 * {@see EventLifecycleContract} whose states implement `Contracts\EventState`. Not final on
 * purpose: extending it and adjusting `define()` is the smallest way to change a rule.
 */
class EventLifecycle extends LifecycleDefinition implements EventLifecycleContract
{
    public const string PUBLISH = 'publish';

    public const string UNPUBLISH = 'unpublish';

    public const string POSTPONE = 'postpone';

    public const string RESCHEDULE = 'reschedule';

    public const string CANCEL = 'cancel';

    public const string COMPLETE = 'complete';

    public function define(LifecycleBuilder $lifecycle): void
    {
        // Every transition allows the system: a call without an actor is trusted PHP code (a
        // seeder, a job, a console command). Authorization happens before the transition, in
        // the events policy, whenever a call names an actor with `by()`.
        $lifecycle->label('Event');

        $lifecycle->states(EventStatus::class)
            ->initial(EventStatus::Draft)
            ->terminal(EventStatus::Cancelled, EventStatus::Completed);

        $lifecycle->state(EventStatus::Scheduled)
            ->stamps('published_at')
            ->expiresAtAttribute('ends_at')
            ->expiresVia(self::COMPLETE);

        // A postponed event keeps its original date and never completes on its own.
        $lifecycle->state(EventStatus::Postponed)->stamps('postponed_at');

        $lifecycle->state(EventStatus::Rescheduled)
            ->stamps('rescheduled_at')
            ->expiresAtAttribute('ends_at')
            ->expiresVia(self::COMPLETE);

        $lifecycle->state(EventStatus::Cancelled)->stamps('cancelled_at');
        $lifecycle->state(EventStatus::Completed)->stamps('completed_at');

        $lifecycle->transition(self::PUBLISH)
            ->from(EventStatus::Draft)
            ->to(EventStatus::Scheduled)
            ->allowSystem()
            ->notAfter('ends_at');

        $lifecycle->transition(self::UNPUBLISH)
            ->from(EventStatus::Scheduled)
            ->to(EventStatus::Draft)
            ->allowSystem()
            ->when(NoAttendees::class);

        $lifecycle->transition(self::POSTPONE)
            ->from(EventStatus::Scheduled, EventStatus::Rescheduled)
            ->to(EventStatus::Postponed)
            ->allowSystem();

        $lifecycle->transition(self::RESCHEDULE)
            ->from(EventStatus::Scheduled, EventStatus::Postponed, EventStatus::Rescheduled)
            ->to(EventStatus::Rescheduled)
            ->allowSelf()
            ->allowSystem()
            // Only events' reschedule() moves the date; every other path is refused.
            ->when(RescheduledThroughEvents::class);

        $lifecycle->transition(self::CANCEL)
            ->from(EventStatus::Scheduled, EventStatus::Postponed, EventStatus::Rescheduled)
            ->to(EventStatus::Cancelled)
            ->allowSystem();

        $lifecycle->transition(self::COMPLETE)
            ->from(EventStatus::Scheduled, EventStatus::Rescheduled)
            ->to(EventStatus::Completed)
            ->allowSystem()
            ->notBefore('starts_at');
    }

    public function transitionFor(EventVerb $verb): ?string
    {
        return match ($verb) {
            EventVerb::Publish => self::PUBLISH,
            EventVerb::Unpublish => self::UNPUBLISH,
            EventVerb::Postpone => self::POSTPONE,
            EventVerb::Reschedule => self::RESCHEDULE,
            EventVerb::Cancel => self::CANCEL,
            EventVerb::Complete => self::COMPLETE,
        };
    }
}
