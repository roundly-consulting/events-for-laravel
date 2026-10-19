<?php

declare(strict_types=1);

namespace RoundlyConsulting\Events\Support;

use Illuminate\Database\Schema\Blueprint;
use RoundlyConsulting\PackageToolkit\Enums\KeyType;
use RoundlyConsulting\PackageToolkit\Support\BlueprintMacros;

/**
 * Schema helpers for the package's migrations. A morph pair is spelled by hand — a type column
 * and an id column through the toolkit's `ownerKey` key-type seam — so its index gets an explicit
 * short name: `morphKey()` names it after the table, which passes MySQL's 64-character limit once
 * a host renames the tables (`events.tables.*`).
 *
 * @internal
 */
final class EventsSchema
{
    public static function morph(Blueprint $table, string $name, KeyType $type, bool $nullable = false, ?string $index = null): void
    {
        $column = $table->string($name.'_type');

        if ($nullable) {
            $column->nullable();
        }

        BlueprintMacros::ownerKey($table, $name.'_id', $type, $nullable, index: false);

        if ($index !== null) {
            $table->index([$name.'_type', $name.'_id'], $index);
        }
    }

    /**
     * The event's primary key per `events.primary_key_type`. uuid/ulid keys get an explicit
     * primary() command: a fluent `->primary()` is added after every other command, which
     * PostgreSQL refuses once another table's foreign key points here in the same batch.
     */
    public static function primaryKey(Blueprint $table, KeyType $type): void
    {
        match ($type) {
            KeyType::BigInt => $table->id(),
            KeyType::Uuid => $table->uuid('id'),
            KeyType::Ulid => $table->ulid('id'),
        };

        if ($type !== KeyType::BigInt) {
            $table->primary('id');
        }
    }
}
