<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use RoundlyConsulting\Events\Support\EventsConfig;
use RoundlyConsulting\PackageToolkit\Enums\KeyType;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create(EventsConfig::table('tiers'), function (Blueprint $table): void {
            $table->id();
            $table->ownerKey('event_id', KeyType::fromConfig('events.primary_key_type'), index: false);

            // Unique per event, trashed tiers included: a taken key throws (never a -2 suffix).
            $table->string('key', 64);
            $table->translatable('name');
            $table->translatable('description')->nullable();

            $table->money('price', nullable: true);

            $table->unsignedInteger('capacity')->nullable();
            $table->unsignedInteger('seats_taken')->default(0);
            $table->timestamp('sale_starts_at')->nullable();
            $table->timestamp('sale_ends_at')->nullable();
            $table->unsignedInteger('min_party')->default(1);
            $table->unsignedInteger('max_party')->nullable();
            $table->boolean('hidden')->default(false);
            $table->unsignedInteger('position')->default(0);

            $table->timestamps();
            $table->softDeletes();

            $table->unique(['event_id', 'key'], 'ev_tiers_key_unique');
            $table->foreign('event_id', 'ev_tiers_event_fk')->references('id')->on(EventsConfig::table('events'))->cascadeOnDelete();
        });
    }
};
