<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RoundlyConsulting\Events\Facades\Events;
use RoundlyConsulting\Events\Models\Event;
use RoundlyConsulting\Events\Support\EventsConfig;
use RoundlyConsulting\Events\Tests\Fixtures\Models\User;
use RoundlyConsulting\Events\Tests\Support\Scenarios;
use RoundlyConsulting\Testing\Database\DriverMatrix;

/**
 * Every instant is a UTC wall-clock string, stored as written on every engine — on MySQL in
 * DATETIME columns (0008), which neither end in 2038 nor read the string in the session's time
 * zone.
 */
beforeEach(fn () => Scenarios::freeze());
afterEach(fn () => Scenarios::thaw());

test('a_start_past_2038_is_stored', function (): void {
    $event = Events::make('Far future')->organizedBy(User::factory()->create())->timezone('UTC')
        ->startsAt('2040-06-01 20:00')->duration('2 hours')->publish();

    $stored = Event::query()->findOrFail($event->getKey());

    expect($stored->starts_at->toIso8601String())->toBe('2040-06-01T20:00:00+00:00')
        ->and($stored->ends_at->toIso8601String())->toBe('2040-06-01T22:00:00+00:00');
});

test('every_instant_column_is_a_datetime_on_mysql', function (): void {
    $columns = [
        'venues' => ['geocoded_at', 'created_at', 'updated_at', 'deleted_at'],
        'events' => ['starts_at', 'ends_at', 'doors_open_at', 'previous_starts_at', 'published_at', 'postponed_at', 'rescheduled_at',
            'cancelled_at', 'completed_at', 'moved_online_at', 'rsvp_opens_at', 'rsvp_closes_at', 'created_at', 'updated_at', 'deleted_at'],
        'tiers' => ['sale_starts_at', 'sale_ends_at', 'created_at', 'updated_at', 'deleted_at'],
        'rsvps' => ['waitlisted_at', 'held_until', 'confirmed_at', 'responded_at', 'checked_in_at', 'created_at', 'updated_at'],
        'hosts' => ['created_at'],
        'invitations' => ['responded_at', 'created_at', 'updated_at'],
        'reminder_deliveries' => ['starts_at', 'dispatched_at'],
    ];

    foreach ($columns as $name => $names) {
        $types = collect(Schema::getColumns(EventsConfig::table($name)))->pluck('type_name', 'name');

        foreach ($names as $column) {
            expect($types[$column] ?? null)->toBe('datetime', "{$name}.{$column}");
        }
    }

    // Nullability survived the change: the schedule stays required.
    $events = collect(Schema::getColumns(EventsConfig::table('events')))->keyBy('name');
    expect($events['starts_at']['nullable'])->toBeFalse()
        ->and($events['doors_open_at']['nullable'])->toBeTrue();
})->skip(fn (): bool => DriverMatrix::driver() !== 'mysql', 'MySQL only: elsewhere both column types are the same');

test('a_start_in_the_session_dst_gap_is_stored', function (): void {
    $connection = DB::connection();
    $zone = $connection->selectOne('select @@session.time_zone as zone')->zone;
    // A MySQL whose session runs in a DST zone: 02:30 on 2027-03-28 does not exist in Bratislava.
    $connection->statement("set time_zone = 'Europe/Bratislava'");

    try {
        $event = Events::make('Night run')->organizedBy(User::factory()->create())->timezone('UTC')
            ->startsAt('2027-03-28 02:30')->duration('2 hours')->create();

        expect(Event::query()->findOrFail($event->getKey())->starts_at->toIso8601String())->toBe('2027-03-28T02:30:00+00:00')
            ->and($connection->selectOne('select starts_at from '.EventsConfig::table('events').' where id = ?', [$event->getKey()])->starts_at)
            ->toStartWith('2027-03-28 02:30:00');
    } finally {
        $connection->statement('set time_zone = ?', [$zone]);
    }
})->skip(fn (): bool => DriverMatrix::driver() !== 'mysql', 'MySQL only: a session time zone with a DST gap');

test('upgrading_keeps_the_stored_instants', function (): void {
    $connection = DB::connection();
    $table = EventsConfig::table('reminder_deliveries');
    $zone = $connection->selectOne('select @@session.time_zone as zone')->zone;
    $event = Scenarios::event();

    // The 1.0 shape of one table, written by a session in a DST zone (the stored strings are UTC).
    $connection->statement("alter table `{$table}` modify `starts_at` timestamp not null, modify `dispatched_at` timestamp null");
    $connection->statement("set time_zone = 'Europe/Bratislava'");

    try {
        $connection->table($table)->insert(['event_id' => $event->getKey(), 'offset' => 'PT1H', 'starts_at' => '2027-07-01 10:00:00', 'dispatched_at' => '2027-01-15 08:30:00']);

        // The upgrade runs with the app's connection settings: the strings come through as written.
        (require __DIR__.'/../../database/migrations/0008_store_event_instants_as_datetime.php')->up();
    } finally {
        $connection->statement('set time_zone = ?', [$zone]);
    }

    $row = $connection->table($table)->where('event_id', $event->getKey())->first();
    $types = collect(Schema::getColumns($table))->pluck('type_name', 'name');

    expect((string) $row?->starts_at)->toStartWith('2027-07-01 10:00:00')
        ->and((string) $row?->dispatched_at)->toStartWith('2027-01-15 08:30:00')
        ->and($types['starts_at'])->toBe('datetime')
        ->and($types['dispatched_at'])->toBe('datetime');
})->skip(fn (): bool => DriverMatrix::driver() !== 'mysql', 'MySQL only: the TIMESTAMP columns of 1.0');

test('the_datetime_migration_changes_nothing_off_mysql', function (): void {
    $migration = require __DIR__.'/../../database/migrations/0008_store_event_instants_as_datetime.php';

    // PostgreSQL and SQLite already store both column types alike: not one statement.
    expect(DB::connection()->pretend(static fn () => $migration->up()))->toBe([]);
})->skip(fn (): bool => in_array(DriverMatrix::driver(), ['mysql', 'mariadb'], true), 'MySQL changes the columns');

test('the_doctor_flags_timestamp_instants_on_mysql', function (): void {
    $table = EventsConfig::table('events');
    config()->set('events.urls.event', 'https://example.com/events/{uuid}');
    expect(Artisan::call('events:doctor', ['--json' => true]))->toBe(0);

    // A host still on 1.0's schema.
    DB::statement("alter table `{$table}` modify `starts_at` timestamp not null");

    try {
        expect(Artisan::call('events:doctor', ['--json' => true]))->toBe(0);
        $findings = json_decode(trim(Artisan::output()), true);

        expect(array_column($findings, 'check'))->toBe(['instants'])
            ->and($findings[0]['severity'])->toBe('warning')
            ->and($findings[0]['message'])->toContain('0008');
    } finally {
        (require __DIR__.'/../../database/migrations/0008_store_event_instants_as_datetime.php')->up();
    }
})->skip(fn (): bool => DriverMatrix::driver() !== 'mysql', 'MySQL only: the TIMESTAMP columns of 1.0');
