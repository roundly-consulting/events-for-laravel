<?php

declare(strict_types=1);

use RoundlyConsulting\Events\EventsServiceProvider;
use RoundlyConsulting\PackageToolkit\Enums\DatabaseDriver;
use RoundlyConsulting\Testing\Database\DriverMatrix;

$migrations = __DIR__.'/../../database/migrations';

/**
 * Seven FK edges: events.venue_id, tiers.event_id, rsvps.event_id, rsvps.tier_id,
 * hosts.event_id, invitations.event_id, reminder_deliveries.event_id. Table names are
 * configurable, so each expression is mapped to its default.
 */
$resolvers = [
    "EventsConfig::table('events')" => 'events',
    "EventsConfig::table('venues')" => 'event_venues',
    "EventsConfig::table('tiers')" => 'event_admission_tiers',
    "EventsConfig::table('rsvps')" => 'event_rsvps',
    "EventsConfig::table('hosts')" => 'event_hosts',
    "EventsConfig::table('invitations')" => 'event_invitations',
    "EventsConfig::table('reminder_deliveries')" => 'event_reminder_deliveries',
];

it('creates every foreign key target before the table that references it', function () use ($migrations, $resolvers): void {
    expect($migrations)->toHaveRunnableMigrationOrder(foreignKeys: 7, tableResolvers: $resolvers);
});

it('never auto-loads its migrations — the host publishes them', function (): void {
    expect(EventsServiceProvider::class)->toNotAutoLoadMigrations();
});

// Eight: the seven create migrations of 1.0 and 0008, which stores every instant as DATETIME on MySQL.
it('publishes its migrations timestamp-injected into the host', function (): void {
    expect(EventsServiceProvider::class)->toPublishMigrationsTimestamped('events-migrations', 8);
});

it('migrates forward only — no migration defines down()', function () use ($migrations): void {
    $files = glob($migrations.'/*.php') ?: [];

    expect($files)->toHaveCount(8);

    foreach ($files as $file) {
        expect((string) file_get_contents($file))->not->toContain('function down(');
    }
});

it('applies its migrations on postgres', function () use ($migrations): void {
    expect($migrations)->toApplyOnConnection('pgsql', migrations: 8);
})->skip(fn (): bool => ! test()->connectionAvailable('pgsql'), 'no postgres connection available')->group('pgsql');

it('rejects a child-before-parent order on postgres', function () use ($migrations): void {
    expect($migrations)->toRejectBrokenOrderOnConnection(fn (array $files): array => array_reverse($files), 'pgsql');
})->skip(fn (): bool => ! test()->connectionAvailable('pgsql'), 'no postgres connection available')->group('pgsql');

it('applies its migrations on mysql', function () use ($migrations): void {
    expect($migrations)->toApplyOnConnection('mysql', migrations: 8);
})->skip(fn (): bool => ! test()->connectionAvailable('mysql'), 'no mysql connection available')->group('mysql');

it('rejects a child-before-parent order on mysql', function () use ($migrations): void {
    expect($migrations)->toRejectBrokenOrderOnConnection(fn (array $files): array => array_reverse($files), 'mysql');
})->skip(fn (): bool => ! test()->connectionAvailable('mysql'), 'no mysql connection available')->group('mysql');

/**
 * The driver-truth pin: a leg that quietly stayed on SQLite fails here instead of passing as a
 * "postgres" or "mysql" run.
 */
it('runs on the driver the environment declares', function (): void {
    expect(DatabaseDriver::current()->value)->toBe(DriverMatrix::driver());
});
