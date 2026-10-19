<?php

declare(strict_types=1);

namespace RoundlyConsulting\Events\Support;

use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\App;
use RoundlyConsulting\Events\Contracts\Organizer;
use RoundlyConsulting\Events\Models\Event;
use RoundlyConsulting\Events\Models\EventHost;

/**
 * Who manages an event — one rule in two forms. In PHP (the policy): the organizer allows the
 * user, or a co-host allows them, or the user holds the `events.authorization.abilities.admin`
 * Gate ability (undefined = nobody: fail closed). In SQL (the `managedBy()` and `visibleTo()`
 * scopes): the same, through each organizer class's `scopeManagingEventsFor()`.
 *
 * A model that does not implement `Contracts\Organizer` manages only itself.
 *
 * @internal
 */
final class EventManagers
{
    public static function allows(Event $event, ?Model $user): bool
    {
        if ($user === null) {
            return false;
        }

        if (self::isAdmin($user) || self::organizerAllows($event, $user)) {
            return true;
        }

        $hosts = $event->relationLoaded('hosts') ? $event->hosts : $event->hosts()->with('host')->get();

        foreach ($hosts as $host) {
            if (self::modelAllows($host->host, $user)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The organizer (not a co-host) allows the user, or the user is an admin: who may add or
     * remove co-hosts and transfer the event.
     */
    public static function organizerAllows(Event $event, ?Model $user): bool
    {
        if ($user === null) {
            return false;
        }

        return self::isAdmin($user) || self::modelAllows($event->organizer, $user);
    }

    public static function modelAllows(?Model $organizer, Model $user): bool
    {
        if ($organizer === null) {
            return false;
        }

        if ($organizer instanceof Organizer) {
            return $organizer->allowsEventManagementBy($user);
        }

        return $organizer->is($user);
    }

    public static function isAdmin(?Model $user): bool
    {
        $ability = EventsConfig::adminAbility();

        if ($user === null || $ability === null || ! $user instanceof Authenticatable) {
            return false;
        }

        return App::make(Gate::class)->forUser($user)->allows($ability);
    }

    /**
     * Narrow an event query to the events `$user` manages — the SQL form of {@see self::allows()}.
     *
     * @template TEvent of Event
     *
     * @param  Builder<TEvent>  $query
     */
    public static function scope(Builder $query, Model $user): void
    {
        if (self::isAdmin($user)) {
            return;
        }

        $events = $query->getModel();
        $hosts = (new EventHost)->getTable();
        $classes = [...array_values(EventsConfig::organizers()), $user::class];

        $query->where(function (Builder $managed) use ($classes, $user, $events, $hosts): void {
            foreach (array_unique($classes) as $class) {
                $instance = new $class;
                $morph = $instance->getMorphClass();

                if ($instance instanceof Organizer) {
                    $keys = $instance->newQuery();
                    $instance->scopeManagingEventsFor($keys, $user);
                    $keys->select($instance->getQualifiedKeyName());
                } elseif ($instance::class === $user::class) {
                    $keys = $user->getKey();
                } else {
                    continue;
                }

                $managed->orWhere(static function (Builder $organizer) use ($events, $morph, $keys): void {
                    $organizer->where($events->qualifyColumn('organizer_type'), $morph);
                    $keys instanceof Builder
                        ? $organizer->whereIn($events->qualifyColumn('organizer_id'), $keys)
                        : $organizer->where($events->qualifyColumn('organizer_id'), $keys);
                });

                $managed->orWhereExists(static function (QueryBuilder $host) use ($events, $hosts, $morph, $keys): void {
                    $host->selectRaw('1')
                        ->from($hosts)
                        ->whereColumn($hosts.'.event_id', $events->getQualifiedKeyName())
                        ->where($hosts.'.host_type', $morph);
                    $keys instanceof Builder
                        ? $host->whereIn($hosts.'.host_id', $keys->toBase())
                        : $host->where($hosts.'.host_id', $keys);
                });
            }
        });
    }
}
