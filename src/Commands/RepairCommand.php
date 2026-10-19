<?php

declare(strict_types=1);

namespace RoundlyConsulting\Events\Commands;

use Illuminate\Console\Command;
use RoundlyConsulting\Events\DataTransferObjects\RepairOptions;
use RoundlyConsulting\Events\EventsManager;

/**
 * Recomputes counters and releases RSVPs whose attendee row is gone (after a mass delete).
 * Without a flag it does both.
 */
final class RepairCommand extends Command
{
    protected $signature = 'events:repair {--counts : Recompute the counters} {--orphans : Release RSVPs of deleted attendees} {--dry-run : Report without writing}';

    protected $description = 'Repair counters and orphaned RSVPs';

    public function handle(EventsManager $events): int
    {
        $counts = $this->option('counts') === true;
        $orphans = $this->option('orphans') === true;
        $both = ! $counts && ! $orphans;

        $report = $events->repair(new RepairOptions(
            counts: $both || $counts,
            orphans: $both || $orphans,
            dryRun: $this->option('dry-run') === true,
        ));

        $suffix = $report->dryRun ? ' (dry run: nothing written)' : '';
        $this->components->twoColumnDetail('Events checked', (string) $report->eventsChecked);
        $this->components->twoColumnDetail('Counters fixed'.$suffix, (string) $report->countersFixed);
        $this->components->twoColumnDetail('Orphaned RSVPs released'.$suffix, (string) $report->orphansReleased);

        return self::SUCCESS;
    }
}
