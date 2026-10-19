<?php

declare(strict_types=1);

namespace RoundlyConsulting\Events\Commands;

use Illuminate\Console\Command;
use RoundlyConsulting\Events\EventsManager;
use RoundlyConsulting\Events\Support\EventModels;
use Throwable;

/**
 * Geocodes venues from their address: the ones without coordinates (`--missing`, the default),
 * one venue (`--venue=`), or all of them again (`--force`).
 */
final class GeocodeCommand extends Command
{
    protected $signature = 'events:geocode {--missing : Only venues without coordinates (the default)} {--venue= : One venue id} {--force : Geocode venues that already have coordinates} {--chunk=100 : Venues per batch}';

    protected $description = 'Geocode venues from their addresses';

    public function handle(EventsManager $events): int
    {
        $force = $this->option('force') === true;
        $query = EventModels::venue()::query()->orderBy('id');
        $venue = $this->option('venue');

        if (is_string($venue) && $venue !== '') {
            $query->whereKey((int) $venue);
        } elseif (! $force) {
            $query->where(static fn ($missing) => $missing->whereNull('latitude')->orWhereNull('longitude'));
        }

        $done = 0;
        $failed = 0;

        $query->chunkById(max(1, (int) $this->option('chunk')), function ($venues) use ($events, $force, &$done, &$failed): void {
            foreach ($venues as $venue) {
                try {
                    $located = $events->venues()->geocode($venue, $force);
                    $located->coordinates !== null ? $done++ : $failed++;
                } catch (Throwable $exception) {
                    $failed++;
                    $this->components->warn(sprintf('Venue %s: %s', (string) $venue->getKey(), $exception->getMessage()));
                }
            }
        });

        $this->components->twoColumnDetail('Geocoded', (string) $done);
        $this->components->twoColumnDetail('Not found', (string) $failed);

        return self::SUCCESS;
    }
}
