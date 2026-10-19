<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use RoundlyConsulting\Events\Support\EventsConfig;
use RoundlyConsulting\Events\Support\EventsSchema;
use RoundlyConsulting\PackageToolkit\Enums\KeyType;
use RoundlyConsulting\Sluggable\DataTransferObjects\SlugIndexSpec;
use RoundlyConsulting\Sluggable\Schema\SlugIndexes;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create(EventsConfig::table('events'), function (Blueprint $table): void {
            // Identity: the key other packages point at (lifecycle, tags, media, slug history),
            // and the uuid that is the route key, the share key of unlisted events and the ICS UID.
            EventsSchema::primaryKey($table, KeyType::fromConfig('events.primary_key_type'));
            $table->uuid('uuid')->unique('ev_events_uuid_unique');
            $table->localizedSlug('slug')->nullable();

            $table->translatable('title');
            $table->translatable('description')->nullable();

            EventsSchema::morph($table, 'organizer', KeyType::fromConfig('events.key_type'));
            $table->unsignedBigInteger('venue_id')->nullable();
            $table->text('online_url')->nullable();

            $table->string('status', 64)->nullable()->index('ev_events_status_idx');
            $table->string('visibility', 16);
            $table->string('attendance_mode', 16);

            $table->string('timezone', 64);
            $table->timestamp('starts_at');
            $table->timestamp('ends_at');
            $table->timestamp('doors_open_at')->nullable();
            $table->timestamp('previous_starts_at')->nullable();
            $table->date('starts_on')->nullable();
            $table->date('ends_on')->nullable();
            $table->boolean('all_day')->default(false);

            $table->timestamp('published_at')->nullable();
            $table->timestamp('postponed_at')->nullable();
            $table->timestamp('rescheduled_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('moved_online_at')->nullable();

            $table->unsignedInteger('capacity')->nullable();
            $table->unsignedInteger('max_guests')->default(0);
            $table->boolean('waitlist')->default(true);
            $table->boolean('waitlist_auto_promote')->default(true);
            $table->timestamp('rsvp_opens_at')->nullable();
            $table->timestamp('rsvp_closes_at')->nullable();
            $table->string('guest_list', 16)->default('hidden');
            $table->boolean('show_counts')->default(true);
            $table->json('reminders')->nullable();

            // Denormalised counters, changed only under the event's row lock.
            $table->unsignedInteger('seats_taken')->default(0);
            $table->unsignedInteger('going_count')->default(0);
            $table->unsignedInteger('interested_count')->default(0);
            $table->unsignedInteger('waitlist_count')->default(0);

            $table->unsignedInteger('sequence')->default(0);
            $table->json('meta')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['visibility', 'status', 'starts_at'], 'ev_events_listing_idx');
            $table->index('starts_at', 'ev_events_starts_idx');
            $table->index('ends_at', 'ev_events_ends_idx');
            $table->index(['starts_on', 'ends_on'], 'ev_events_days_idx');
            $table->index(['venue_id', 'starts_at'], 'ev_events_venue_idx');
            $table->index(['organizer_type', 'organizer_id', 'starts_at'], 'ev_events_organizer_idx');

            $table->foreign('venue_id', 'ev_events_venue_fk')->references('id')->on(EventsConfig::table('venues'))->restrictOnDelete();
        });

        // One unique index per supported locale over slug->locale, trashed events included: the
        // shape the slug definition probes. Add a later locale's index with
        // `php artisan sluggable:indexes "RoundlyConsulting\Events\Models\Event"`.
        SlugIndexes::ensure(SlugIndexSpec::localeMap(
            EventsConfig::table('events'),
            'slug',
            includeTrashed: true,
            maxLength: EventsConfig::slugMaxLength(),
        ));
    }
};
