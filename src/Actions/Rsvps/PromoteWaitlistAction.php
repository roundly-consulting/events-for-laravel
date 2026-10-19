<?php

declare(strict_types=1);

namespace RoundlyConsulting\Events\Actions\Rsvps;

use Illuminate\Database\Eloquent\Collection;
use RoundlyConsulting\Events\Actions\Internal\AutoPromoteAction;
use RoundlyConsulting\Events\Actions\Internal\ExpireHoldsAction;
use RoundlyConsulting\Events\Models\Event;
use RoundlyConsulting\Events\Models\Rsvp;
use RoundlyConsulting\Events\Support\EventTransaction;
use RoundlyConsulting\Events\Support\SeatBook;

/**
 * A manager moves the waitlist up into free seats by hand — after the automatic cutoff, with
 * automatic promotion off, or as a batch: first fit in queue order, at most `$limit`.
 */
final readonly class PromoteWaitlistAction
{
    public function __construct(
        private ExpireHoldsAction $holds,
        private AutoPromoteAction $promote,
    ) {}

    /**
     * @return Collection<int, Rsvp>
     */
    public function execute(Event $event, ?int $limit = null): Collection
    {
        return EventTransaction::run($event, function () use ($event, $limit): Collection {
            $book = SeatBook::open($event);
            $this->holds->execute($book);

            return new Collection($this->promote->execute($book, $limit, manual: true));
        });
    }
}
