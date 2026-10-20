<?php

declare(strict_types=1);

namespace RoundlyConsulting\Events\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;
use RoundlyConsulting\Events\Support\EventsConfig;

/**
 * Publishes the config and the migrations events and its dependencies need, then prints the
 * next steps. Refuses when a configured table already exists and is not the package's own
 * (`events` is a common table name): rename it in `events.tables.*` first. Re-running is safe —
 * published files are kept, and the package's own tables (after `migrate`) are recognised by
 * their columns, so a later run publishes what is new (slug history's migrations, say).
 */
final class InstallCommand extends Command
{
    /** @var list<string> */
    public const array TAGS = ['events-config', 'lifecycle-migrations', 'tags-migrations', 'media-migrations', 'events-migrations'];

    /**
     * Each table's columns no host table of that name is likely to have together.
     *
     * @var array<string, list<string>>
     */
    private const array TABLES = [
        'events' => ['uuid', 'organizer_type', 'organizer_id', 'starts_at', 'ends_at', 'sequence', 'seats_taken'],
        'venues' => ['owner_type', 'owner_id', 'formatted_address', 'timezone', 'geocoded_at'],
        'tiers' => ['event_id', 'key', 'seats_taken', 'min_party', 'max_party', 'position'],
        'rsvps' => ['event_id', 'attendee_type', 'attendee_id', 'hold_key', 'check_in_code_hash'],
        'hosts' => ['event_id', 'host_type', 'host_id', 'added_by_type'],
        'invitations' => ['event_id', 'invitee_type', 'invitee_id', 'token_hash'],
        'reminder_deliveries' => ['event_id', 'offset', 'starts_at', 'dispatched_at', 'recipients'],
    ];

    protected $signature = 'events:install';

    protected $description = 'Install events: publish the config and migrations, print the next steps';

    public function handle(): int
    {
        foreach (self::TABLES as $name => $columns) {
            $table = EventsConfig::table($name);

            if (Schema::hasTable($table) && ! Schema::hasColumns($table, $columns)) {
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
