<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use RoundlyConsulting\Events\Support\EventsConfig;

/**
 * Every instant the package stores is a UTC wall-clock string. A MySQL / MariaDB `TIMESTAMP`
 * column reads that string in the session's time zone and ends on 2038-01-19, so a start after
 * that — or any value inside the session zone's spring-forward gap, `created_at` included — fails
 * to save. `DATETIME` stores the string as written. The values convert in this connection's
 * session time zone, the one that wrote them, so they come through unchanged.
 *
 * PostgreSQL and SQLite store both column types alike (`timestamp(0) without time zone`,
 * `datetime`): nothing to change there.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! in_array(Schema::getConnection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            return;
        }

        Schema::table(EventsConfig::table('venues'), function (Blueprint $table): void {
            $this->datetimes($table, ['geocoded_at', 'created_at', 'updated_at', 'deleted_at']);
        });

        Schema::table(EventsConfig::table('events'), function (Blueprint $table): void {
            $this->datetimes($table, ['starts_at', 'ends_at'], nullable: false);
            $this->datetimes($table, [
                'doors_open_at', 'previous_starts_at',
                'published_at', 'postponed_at', 'rescheduled_at', 'cancelled_at', 'completed_at', 'moved_online_at',
                'rsvp_opens_at', 'rsvp_closes_at',
                'created_at', 'updated_at', 'deleted_at',
            ]);
        });

        Schema::table(EventsConfig::table('tiers'), function (Blueprint $table): void {
            $this->datetimes($table, ['sale_starts_at', 'sale_ends_at', 'created_at', 'updated_at', 'deleted_at']);
        });

        Schema::table(EventsConfig::table('rsvps'), function (Blueprint $table): void {
            $this->datetimes($table, ['waitlisted_at', 'held_until', 'confirmed_at', 'responded_at', 'checked_in_at', 'created_at', 'updated_at']);
        });

        Schema::table(EventsConfig::table('hosts'), function (Blueprint $table): void {
            $this->datetimes($table, ['created_at']);
        });

        Schema::table(EventsConfig::table('invitations'), function (Blueprint $table): void {
            $this->datetimes($table, ['responded_at', 'created_at', 'updated_at']);
        });

        Schema::table(EventsConfig::table('reminder_deliveries'), function (Blueprint $table): void {
            $this->datetimes($table, ['starts_at'], nullable: false);
            $this->datetimes($table, ['dispatched_at']);
        });
    }

    /**
     * @param  list<string>  $columns
     */
    private function datetimes(Blueprint $table, array $columns, bool $nullable = true): void
    {
        foreach ($columns as $column) {
            $table->dateTime($column)->nullable($nullable)->change();
        }
    }
};
