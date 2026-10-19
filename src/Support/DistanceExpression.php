<?php

declare(strict_types=1);

namespace RoundlyConsulting\Events\Support;

use Illuminate\Database\Connection;
use Illuminate\Support\Facades\App;
use RoundlyConsulting\Events\DataTransferObjects\DistanceSql;
use RoundlyConsulting\Geolocation\DataTransferObjects\Coordinates;

/**
 * The great-circle distance in kilometres between a point and a row's latitude/longitude, as
 * bound SQL. The same Haversine formula, radius (6371.0 km) and clamp as geolocation's
 * `Coordinates::distanceTo()`, so PHP and SQL agree within a metre:
 *
 *  - `LEAST(1.0, …)` mirrors `min(1, √a)`: without it PostgreSQL throws on `asin(1.0000000002)`
 *    for antipodal points and MySQL returns NULL;
 *  - PostgreSQL reads `decimal` as `numeric`, so the columns are cast to double precision;
 *  - SQLite (tests, local development — production is MySQL or PostgreSQL) has no guaranteed
 *    trigonometric functions: a deterministic `events_distance_km()` function is registered on
 *    its connection instead.
 *
 * @internal
 */
final class DistanceExpression
{
    public const float EARTH_RADIUS_KM = 6371.0;

    public static function for(Connection $connection, string $latitudeColumn, string $longitudeColumn, Coordinates $point): DistanceSql
    {
        $grammar = $connection->getQueryGrammar();
        $latitude = $grammar->wrap($latitudeColumn);
        $longitude = $grammar->wrap($longitudeColumn);

        if ($connection->getDriverName() === 'sqlite') {
            App::make(SqliteGeometry::class)->register($connection);

            return new DistanceSql("events_distance_km({$latitude}, {$longitude}, ?, ?)", [$point->latitude, $point->longitude]);
        }

        $type = $connection->getDriverName() === 'pgsql' ? 'double precision' : 'DOUBLE';
        $latitude = "CAST({$latitude} AS {$type})";
        $longitude = "CAST({$longitude} AS {$type})";

        return new DistanceSql(
            '2 * '.self::EARTH_RADIUS_KM.' * ASIN(LEAST(1.0, SQRT('
            ."POWER(SIN(RADIANS({$latitude} - ?) / 2), 2) "
            ."+ COS(RADIANS(?)) * COS(RADIANS({$latitude})) * POWER(SIN(RADIANS({$longitude} - ?) / 2), 2))))",
            [$point->latitude, $point->latitude, $point->longitude],
        );
    }
}
