<?php

declare(strict_types=1);

namespace RoundlyConsulting\Events\Support;

use Illuminate\Database\Connection;
use PDO;
use Pdo\Sqlite;
use RoundlyConsulting\Geolocation\DataTransferObjects\Coordinates;
use WeakMap;

/**
 * Registers `events_distance_km(lat1, lng1, lat2, lng2)` on a SQLite connection: the distance in
 * kilometres through geolocation's own `Coordinates::distanceTo()`, so SQLite (tests and local
 * development) answers exactly like PHP. Lazily, once per PDO. A container singleton — never a
 * static — so a long-lived worker holds no stale state.
 *
 * @internal
 */
final class SqliteGeometry
{
    /** @var WeakMap<PDO, true> */
    private WeakMap $registered;

    public function __construct()
    {
        $this->registered = new WeakMap;
    }

    public function register(Connection $connection): void
    {
        $pdo = $connection->getPdo();

        if (isset($this->registered[$pdo])) {
            return;
        }

        $distance = static function (mixed $lat1, mixed $lng1, mixed $lat2, mixed $lng2): ?float {
            if (! is_numeric($lat1) || ! is_numeric($lng1) || ! is_numeric($lat2) || ! is_numeric($lng2)) {
                return null;
            }

            return (new Coordinates((float) $lat1, (float) $lng1))->distanceTo(new Coordinates((float) $lat2, (float) $lng2)) / 1000;
        };

        $pdo instanceof Sqlite
            ? $pdo->createFunction('events_distance_km', $distance, 4, PDO::SQLITE_DETERMINISTIC)
            : $pdo->sqliteCreateFunction('events_distance_km', $distance, 4, PDO::SQLITE_DETERMINISTIC);

        $this->registered[$pdo] = true;
    }
}
