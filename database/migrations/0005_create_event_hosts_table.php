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

        Schema::create(EventsConfig::table('hosts'), function (Blueprint $table) use ($keyType): void {
            $table->id();
            $table->ownerKey('event_id', KeyType::fromConfig('events.primary_key_type'), index: false);
            EventsSchema::morph($table, 'host', $keyType, index: 'ev_hosts_host_idx');
            EventsSchema::morph($table, 'added_by', $keyType, nullable: true);
            $table->timestamp('created_at')->nullable();

            $table->unique(['event_id', 'host_type', 'host_id'], 'ev_hosts_unique');
            $table->foreign('event_id', 'ev_hosts_event_fk')->references('id')->on(EventsConfig::table('events'))->cascadeOnDelete();
        });
    }
};
