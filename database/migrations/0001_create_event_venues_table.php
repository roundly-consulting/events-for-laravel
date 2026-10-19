<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use RoundlyConsulting\Events\Support\EventsConfig;
use RoundlyConsulting\Events\Support\EventsSchema;
use RoundlyConsulting\PackageToolkit\Enums\KeyType;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create(EventsConfig::table('venues'), function (Blueprint $table): void {
            $table->id();

            // The optional owner: an organizer's private venue list.
            EventsSchema::morph($table, 'owner', KeyType::fromConfig('events.key_type'), nullable: true, index: 'ev_venues_owner_idx');

            $table->translatable('name');
            $table->slug('slug', 160);
            $table->uniqueSlug('slug');

            $table->string('street', 191)->nullable();
            $table->string('city', 120)->nullable();
            $table->string('region', 120)->nullable();
            $table->string('postal_code', 32)->nullable();
            $table->string('country_code', 2)->nullable();
            $table->string('formatted_address', 500)->nullable();

            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();

            $table->string('timezone', 64);
            $table->unsignedInteger('capacity')->nullable();
            $table->timestamp('geocoded_at')->nullable();
            $table->json('meta')->nullable();

            $table->timestamps();
            $table->softDeletes();

            // Serves geolocation's bounding-box pre-filter.
            $table->index(['latitude', 'longitude'], 'ev_venues_coordinates_idx');
        });
    }
};
