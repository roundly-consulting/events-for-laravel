<?php

declare(strict_types=1);

namespace RoundlyConsulting\Events\Commands;

use Illuminate\Console\Command;
use RoundlyConsulting\Events\DataTransferObjects\SweepOptions;
use RoundlyConsulting\Events\EventsManager;

/**
 * Expires lapsed holds, moves waitlists up and sends due reminders. Schedule it every minute.
 */
final class SweepCommand extends Command
{
    protected $signature = 'events:sweep {--limit= : At most this many events per pass} {--chunk=100 : Events per batch}';

    protected $description = 'Expire held seats, promote waitlists and send due reminders';

    public function handle(EventsManager $events): int
    {
        $limit = $this->option('limit');
        $result = $events->sweep(new SweepOptions(
            limit: is_numeric($limit) ? (int) $limit : null,
            chunk: max(1, (int) $this->option('chunk')),
        ));

        $this->components->twoColumnDetail('Events with lapsed holds', (string) $result->events);
        $this->components->twoColumnDetail('Holds expired', (string) $result->expiredHolds);
        $this->components->twoColumnDetail('Promoted from the waitlist', (string) $result->promoted);
        $this->components->twoColumnDetail('Reminders sent', $result->reminders.' ('.$result->notified.' recipients)');

        return self::SUCCESS;
    }
}
