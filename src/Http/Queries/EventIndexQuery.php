<?php

declare(strict_types=1);

namespace RoundlyConsulting\Events\Http\Queries;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use RoundlyConsulting\Events\Enums\AttendanceMode;
use RoundlyConsulting\Events\Enums\EventPhase;
use RoundlyConsulting\Events\Enums\EventTagType;
use RoundlyConsulting\Events\Exceptions\InvalidEventQueryException;
use RoundlyConsulting\Events\Support\EventModels;
use RoundlyConsulting\Events\Support\EventsConfig;
use RoundlyConsulting\Events\Support\MorphAliases;
use RoundlyConsulting\Events\Support\WallClock;
use RoundlyConsulting\Geolocation\DataTransferObjects\Coordinates;
use RoundlyConsulting\QueryBuilder\AllowedFilter;
use RoundlyConsulting\QueryBuilder\AllowedSort;
use RoundlyConsulting\QueryBuilder\Contracts\Sort;
use RoundlyConsulting\QueryBuilder\Enums\SortDirection;
use RoundlyConsulting\QueryBuilder\QueryBuilder;
use RoundlyConsulting\Tags\Http\Filters\TagsFilter;

/**
 * `GET /events`: every `filter[…]` is one of the model's scopes, so PHP and HTTP filter
 * identically. query-builder splits every value on commas, so each filter takes the list form.
 *
 * The base is `Events::browse($viewer)` (visible, listed, announced) — or, with
 * `filter[mine]`, the viewer's own events of every visibility (`organizing` includes drafts).
 *
 * @internal
 */
final class EventIndexQuery
{
    public static function for(Request $request, ?Model $viewer, ?string $mine, string $timezone): QueryBuilder
    {
        $near = self::near($request);

        $builder = QueryBuilder::for(EventModels::event(), $request)
            ->allowedFilters(
                AllowedFilter::callback('mine', static fn (): null => null),
                AllowedFilter::callback('when', static function (Builder $query, mixed $value): void {
                    match (self::first($value, 'when')) {
                        'upcoming', 'ongoing', 'past' => $query->scopes([self::first($value, 'when') => []]),
                        default => throw InvalidEventQueryException::invalidValue('when'),
                    };
                }),
                AllowedFilter::callback('from', static function (Builder $query, mixed $value) use ($timezone): void {
                    $query->where($query->qualifyColumn('ends_at'), '>', WallClock::toUtc(self::joined($value), $timezone));
                }),
                AllowedFilter::callback('to', static function (Builder $query, mixed $value) use ($timezone): void {
                    $query->where($query->qualifyColumn('starts_at'), '<', WallClock::toUtc(self::joined($value), $timezone));
                }),
                AllowedFilter::callback('date', static function (Builder $query, mixed $value): void {
                    $date = self::first($value, 'date');

                    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1) {
                        throw InvalidEventQueryException::invalidValue('date');
                    }

                    $query->scopes(['onLocalDate' => [CarbonImmutable::parse($date)]]);
                }),
                AllowedFilter::callback('near', static function (Builder $query) use ($near): void {
                    if ($near !== null) {
                        $query->scopes(['near' => [$near[0], $near[1]]]);
                    }
                }),
                AllowedFilter::custom('category', TagsFilter::any(EventTagType::Category)->withDescendants()),
                AllowedFilter::custom('labels', TagsFilter::all(EventTagType::Label)),
                AllowedFilter::callback('organizer', static function (Builder $query, mixed $value): void {
                    [$alias, $key] = MorphAliases::split(self::joined($value));
                    $query->scopes(['organizedBy' => [MorphAliases::organizer($alias, $key)]]);
                }),
                AllowedFilter::callback('venue', static function (Builder $query, mixed $value): void {
                    $ids = array_map(static fn (mixed $id): int => is_numeric($id) && (int) $id > 0 ? (int) $id : throw InvalidEventQueryException::invalidValue('venue'), (array) $value);
                    $query->scopes(['atVenue' => $ids]);
                }),
                AllowedFilter::callback('mode', static function (Builder $query, mixed $value): void {
                    $query->scopes(['withAttendanceMode' => array_map(static fn (mixed $mode): AttendanceMode => AttendanceMode::tryFrom((string) $mode) ?? throw InvalidEventQueryException::invalidValue('mode'), (array) $value)]);
                }),
                AllowedFilter::callback('free', static function (Builder $query, mixed $value): void {
                    if (self::truthy($value, 'free')) {
                        $query->scopes(['free' => []]);
                    }
                }, booleans: true),
                AllowedFilter::callback('available', static function (Builder $query, mixed $value): void {
                    if (self::truthy($value, 'available')) {
                        $query->scopes(['withSeatsAvailable' => []]);
                    }
                }, booleans: true),
                AllowedFilter::callback('search', static function (Builder $query, mixed $value): void {
                    $query->scopes(['search' => [self::joined($value)]]);
                }),
                AllowedFilter::callback('status', static function (Builder $query, mixed $value): void {
                    $query->scopes(['inPhase' => array_map(static fn (mixed $phase): EventPhase => EventPhase::tryFrom((string) $phase) ?? throw InvalidEventQueryException::invalidValue('status'), (array) $value)]);
                }),
            )
            ->allowedSorts(
                AllowedSort::custom('starts_at', self::column('starts_at')),
                AllowedSort::custom('created_at', self::column('created_at')),
                AllowedSort::custom('popularity', new class implements Sort
                {
                    public function apply(Builder $query, SortDirection $direction, string $property): void
                    {
                        $query->orderBy($query->qualifyColumn('going_count'), $direction === SortDirection::Ascending ? 'asc' : 'desc')
                            ->orderBy($query->qualifyColumn('starts_at'));
                    }
                }),
                AllowedSort::custom('distance', new class($near === null ? null : $near[0]) implements Sort
                {
                    public function __construct(private ?Coordinates $point) {}

                    public function apply(Builder $query, SortDirection $direction, string $property): void
                    {
                        if ($this->point === null) {
                            throw InvalidEventQueryException::distanceNeedsNear();
                        }

                        $query->scopes(['withDistance' => [$this->point], 'orderByDistance' => [$this->point, $direction === SortDirection::Ascending ? 'asc' : 'desc']]);
                    }
                }),
            )
            ->defaultSort('starts_at');

        // The base after the allow-list: the first forwarded call applies the request.
        if ($mine === null) {
            $builder->scopes(['visibleTo' => [$viewer], 'listed' => [], 'published' => []]);
        } elseif ($viewer === null) {
            $builder->whereRaw('1 = 0');
        } else {
            $builder->scopes(['visibleTo' => [$viewer], match ($mine) {
                'organizing' => 'managedBy',
                'attending' => 'attendedBy',
                default => 'interestedBy',
            } => [$viewer]]);
        }

        $builder->with(self::eagerLoads($viewer));

        return $builder;
    }

    /**
     * A column, then the key: a stable order, so pages never repeat or skip a row on a tie.
     */
    private static function column(string $column): Sort
    {
        return new class($column) implements Sort
        {
            public function __construct(private string $column) {}

            public function apply(Builder $query, SortDirection $direction, string $property): void
            {
                $order = $direction === SortDirection::Ascending ? 'asc' : 'desc';

                $query->orderBy($query->qualifyColumn($this->column), $order)->orderBy($query->qualifyColumn('id'), $order);
            }
        };
    }

    /**
     * The eager loads every list renders without a query per row, the viewer's own RSVP among them.
     *
     * @return array<string|int, mixed>
     */
    public static function eagerLoads(?Model $viewer): array
    {
        return [
            'venue.owner', 'organizer', 'tags', 'media', 'tiers',
            'rsvps' => static function ($rsvps) use ($viewer): void {
                $viewer === null
                    ? $rsvps->whereRaw('1 = 0')
                    : $rsvps->where('attendee_type', $viewer->getMorphClass())->where('attendee_id', $viewer->getKey());
            },
        ];
    }

    /**
     * `filter[near]=lat,lng[,km]` as a point and a radius.
     *
     * @return array{0: Coordinates, 1: float}|null
     */
    private static function near(Request $request): ?array
    {
        $filter = $request->query('filter');
        $value = is_array($filter) ? ($filter['near'] ?? null) : null;

        if ($value === null || $value === '') {
            return null;
        }

        $parts = is_array($value) ? array_values($value) : explode(',', (string) $value);

        if (count($parts) < 2 || count($parts) > 3 || ! is_numeric($parts[0]) || ! is_numeric($parts[1]) || (isset($parts[2]) && ! is_numeric($parts[2]))) {
            throw InvalidEventQueryException::invalidNear();
        }

        [$latitude, $longitude] = [(float) $parts[0], (float) $parts[1]];
        $radius = isset($parts[2]) ? (float) $parts[2] : EventsConfig::defaultRadiusKm();

        if ($latitude < -90 || $latitude > 90 || $longitude < -180 || $longitude > 180 || $radius <= 0 || $radius > EventsConfig::maxRadiusKm()) {
            throw InvalidEventQueryException::invalidNear();
        }

        return [new Coordinates($latitude, $longitude), $radius];
    }

    private static function first(mixed $value, string $filter): string
    {
        $value = is_array($value) ? ($value[0] ?? null) : $value;

        return is_scalar($value) ? (string) $value : throw InvalidEventQueryException::invalidValue($filter);
    }

    /**
     * A free-text value query-builder split on its commas, joined back.
     */
    private static function joined(mixed $value): string
    {
        return is_array($value) ? implode(',', array_map('strval', $value)) : (string) $value;
    }

    private static function truthy(mixed $value, string $filter): bool
    {
        $value = is_array($value) ? ($value[0] ?? null) : $value;

        return is_bool($value) ? $value : throw InvalidEventQueryException::invalidValue($filter);
    }
}
