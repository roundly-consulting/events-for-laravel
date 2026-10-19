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

        Schema::create(EventsConfig::table('invitations'), function (Blueprint $table) use ($keyType): void {
            $table->id();
            $table->ownerKey('event_id', KeyType::fromConfig('events.primary_key_type'), index: false);
            EventsSchema::morph($table, 'invitee', $keyType, index: 'ev_invitations_invitee_idx');
            EventsSchema::morph($table, 'invited_by', $keyType, nullable: true);

            $table->string('status', 16);
            $table->text('message')->nullable();
            // The token is a bearer secret: only its hash is stored.
            $table->string('token_hash', 64)->nullable()->unique('ev_invitations_token_unique');
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('responded_at')->nullable();
            $table->timestamps();

            $table->unique(['event_id', 'invitee_type', 'invitee_id'], 'ev_invitations_unique');
            $table->foreign('event_id', 'ev_invitations_event_fk')->references('id')->on(EventsConfig::table('events'))->cascadeOnDelete();
        });
    }
};
