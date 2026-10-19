<?php

declare(strict_types=1);

namespace RoundlyConsulting\Events\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;
use RoundlyConsulting\Events\Support\EventsConfig;

/**
 * Publishes the config and the migrations events and its dependencies need, then prints the
 * next steps. Refuses when a configured table already exists (`events` is a common table name):
 * rename it in `events.tables.*` first. Re-running is safe — published files are kept.
 */
final class InstallCommand extends Command
{
    /** @var list<string> */
    public const array TAGS = ['events-config', 'lifecycle-migrations', 'tags-migrations', 'media-migrations', 'events-migrations'];

    private const array TABLES = ['events', 'venues', 'tiers', 'rsvps', 'hosts', 'invitations', 'reminder_deliveries'];

    protected $signature = 'events:install';

    protected $description = 'Install events: publish the config and migrations, print the next steps';

    public function handle(): int
    {
        foreach (self::TABLES as $name) {
            $table = EventsConfig::table($name);

            if (Schema::hasTable($table)) {
                $this->components->error(sprintf('The table [%s] already exists. Set another name in config/events.php (events.tables.%s) before installing.', $table, $name));

                return self::FAILURE;
            }
        }

        $tags = [...self::TAGS, ...(EventsConfig::slugHistory() ? ['sluggable-migrations'] : [])];

        foreach ($tags as $tag) {
            $this->callSilently('vendor:publish', ['--tag' => $tag]);
            $this->components->task('Published '.$tag);
        }

        $this->newLine();
        $this->components->info('Key types — these must agree before you migrate:');

        foreach (['events.primary_key_type', 'lifecycle.key_type', 'tags.key_type', 'events.key_type', 'lifecycle.actor_key_type'] as $key) {
            $value = config($key);
            $this->components->twoColumnDetail($key, is_string($value) ? $value : 'bigint');
        }

        $this->newLine();
        $this->components->info('Next steps');
        $this->line('  1. php artisan migrate');
        $this->line('  2. Add AttendsEvents (and OrganizesEvents + the Organizer contract) to your User model.');
        $this->line('  3. Schedule the sweeps (routes/console.php):');
        $this->line("     Schedule::command('events:sweep')->everyMinute();");
        $this->line("     Schedule::command('lifecycle:sweep')->everyMinute();");
        $this->line('  4. Paid ticket types: bind your CollectsAdmissionPayment collector (events.payments.collector).');
        $this->line('  5. php artisan events:doctor');

        return self::SUCCESS;
    }
}
