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
        Schema::create(EventsConfig::table('reminder_deliveries'), function (Blueprint $table): void {
            $table->id();
            $table->ownerKey('event_id', KeyType::fromConfig('events.primary_key_type'), index: false);
            $table->string('offset', 32);
            $table->timestamp('starts_at');
            $table->timestamp('dispatched_at')->nullable();
            $table->unsignedInteger('recipients')->default(0);

            // The insert is the claim: one delivery per event, offset and start time.
            $table->unique(['event_id', 'offset', 'starts_at'], 'ev_reminders_unique');
            $table->foreign('event_id', 'ev_reminders_event_fk')->references('id')->on(EventsConfig::table('events'))->cascadeOnDelete();
        });
    }
};
