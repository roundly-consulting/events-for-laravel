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
        $keyType = KeyType::fromConfig('events.key_type');

        Schema::create(EventsConfig::table('rsvps'), function (Blueprint $table) use ($keyType): void {
            $table->id();
            $table->ownerKey('event_id', KeyType::fromConfig('events.primary_key_type'), index: false);
            EventsSchema::morph($table, 'attendee', $keyType);
            $table->unsignedBigInteger('tier_id')->nullable();

            $table->string('status', 16);
            $table->unsignedInteger('guests')->default(0);
            $table->text('note')->nullable();

            $table->timestamp('waitlisted_at')->nullable();
            $table->timestamp('held_until')->nullable();
            // A fresh random id per hold (and per refresh): the payment idempotency key.
            $table->string('hold_key', 32)->nullable();
            $table->string('payment_reference', 191)->nullable()->unique('ev_rsvps_payment_unique');
            $table->text('payment_url')->nullable();
            $table->money('amount', nullable: true);

            $table->timestamp('confirmed_at')->nullable();
            $table->timestamp('responded_at')->nullable();

            // The check-in code: encrypted (the QR can be re-rendered) and hashed (the lookup key).
            $table->text('check_in_code')->nullable();
            $table->string('check_in_code_hash', 64)->nullable()->unique('ev_rsvps_code_unique');
            $table->timestamp('checked_in_at')->nullable();
            $table->unsignedInteger('checked_in_guests')->nullable();
            $table->string('check_in_method', 16)->nullable();
            EventsSchema::morph($table, 'checked_in_by', $keyType, nullable: true);

            EventsSchema::morph($table, 'added_by', $keyType, nullable: true);
            EventsSchema::morph($table, 'removed_by', $keyType, nullable: true);

            $table->json('meta')->nullable();
            $table->timestamps();

            // One RSVP per attendee and event; it backs the event row lock.
            $table->unique(['event_id', 'attendee_type', 'attendee_id'], 'ev_rsvps_attendee_unique');
            $table->index(['event_id', 'status', 'waitlisted_at', 'id'], 'ev_rsvps_queue_idx');
            $table->index(['attendee_type', 'attendee_id', 'status'], 'ev_rsvps_attendee_status_idx');
            $table->index(['status', 'held_until'], 'ev_rsvps_holds_idx');

            $table->foreign('event_id', 'ev_rsvps_event_fk')->references('id')->on(EventsConfig::table('events'))->cascadeOnDelete();
            $table->foreign('tier_id', 'ev_rsvps_tier_fk')->references('id')->on(EventsConfig::table('tiers'))->restrictOnDelete();
        });
    }
};
