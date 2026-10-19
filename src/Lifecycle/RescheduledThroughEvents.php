<?php

declare(strict_types=1);

namespace RoundlyConsulting\Events\Lifecycle;

use Illuminate\Support\Facades\App;
use RoundlyConsulting\Events\Models\Event;
use RoundlyConsulting\Events\Support\Messages;
use RoundlyConsulting\Events\Support\TransitionNotes;
use RoundlyConsulting\Lifecycle\Contracts\Guard;
use RoundlyConsulting\Lifecycle\DataTransferObjects\Denial;
use RoundlyConsulting\Lifecycle\DataTransferObjects\TransitionContext;

/**
 * Lets a reschedule through only from `Events::for($event)->reschedule()`, which moves the date in
 * the same transaction (denial code `rescheduled_through_events`). Lifecycle's own paths — an
 * `asSystem()` apply, `$event->transition()`, a lifecycle schedule, the generic
 * `Events::for($event)->transition()` — would mark the event rescheduled with its old date and
 * announce it. A host lifecycle with its own reschedule-like step adds this guard to it.
 */
final readonly class RescheduledThroughEvents implements Guard
{
    public const string CODE = 'rescheduled_through_events';

    public function check(TransitionContext $context): ?Denial
    {
        $subject = $context->subject;
        $note = $subject instanceof Event ? App::make(TransitionNotes::class)->peek($subject) : null;

        return $note?->reschedule === true
            ? null
            : Denial::of(self::CODE, message: Messages::get('denial_rescheduled_through_events'), source: 'guard');
    }
}
