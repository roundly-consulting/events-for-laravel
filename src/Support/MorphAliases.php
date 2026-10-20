<?php

declare(strict_types=1);

namespace RoundlyConsulting\Events\Support;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use RoundlyConsulting\Events\Exceptions\UnknownMorphAliasException;

/**
 * API references to host models: an alias from `events.organizers` / `events.attendees` and a
 * key, never a class name. The key's shape is checked against the alias class's key type before
 * any query (a uuid in a PostgreSQL bigint column would answer 500). Every failure — an unknown
 * alias, a class name, a malformed key, a missing row — is the same 422. Read filters never look
 * the row up ({@see self::organizerReference()}): no existence oracle.
 *
 * @internal
 */
final class MorphAliases
{
    public static function organizer(string $alias, int|string $key): Model
    {
        return self::resolve(EventsConfig::organizers(), $alias, $key);
    }

    /**
     * A read filter's organizer: the alias and the key's shape checked, the row never looked
     * up — a missing one just matches nothing, so a public filter tells nobody which keys exist.
     */
    public static function organizerReference(string $alias, int|string $key): Model
    {
        $class = EventsConfig::organizers()[$alias] ?? throw UnknownMorphAliasException::unknown();
        $model = new $class;

        if (! self::keyFits($model, $key)) {
            throw UnknownMorphAliasException::unknown();
        }

        $model->setAttribute($model->getKeyName(), $key);

        return $model;
    }

    public static function attendee(string $alias, int|string $key): Model
    {
        return self::resolve(EventsConfig::attendees(), $alias, $key);
    }

    /**
     * Either map: a co-host or an invitee may be any organizer or attendee type.
     */
    public static function person(string $alias, int|string $key): Model
    {
        return self::resolve([...EventsConfig::attendees(), ...EventsConfig::organizers()], $alias, $key);
    }

    /**
     * `alias:key` as one path or filter segment.
     *
     * @return array{0: string, 1: string}
     */
    public static function split(string $reference): array
    {
        $parts = explode(':', $reference, 2);

        if (count($parts) !== 2 || $parts[0] === '' || $parts[1] === '') {
            throw UnknownMorphAliasException::unknown();
        }

        return [$parts[0], $parts[1]];
    }

    /**
     * @param  array<string, class-string<Model>>  $map
     */
    private static function resolve(array $map, string $alias, int|string $key): Model
    {
        $class = $map[$alias] ?? throw UnknownMorphAliasException::unknown();
        $model = new $class;

        if (! self::keyFits($model, $key)) {
            throw UnknownMorphAliasException::unknown();
        }

        return $class::query()->find($key) ?? throw UnknownMorphAliasException::unknown();
    }

    private static function keyFits(Model $model, int|string $key): bool
    {
        if ($model->getKeyType() === 'int') {
            // A signed 64-bit key at most: PostgreSQL answers 500 past it.
            return is_int($key) || (preg_match('/^[0-9]{1,18}$/', $key) === 1);
        }

        if (! $model->usesUniqueIds()) {
            return is_string($key) && $key !== '' && strlen($key) <= 255;
        }

        try {
            // A unique-id model refuses a malformed id here, as route model binding does.
            $model->resolveRouteBindingQuery($model->newQuery(), $key, $model->getKeyName());
        } catch (ModelNotFoundException) {
            return false;
        }

        return true;
    }
}
