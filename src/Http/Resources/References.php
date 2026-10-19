<?php

declare(strict_types=1);

namespace RoundlyConsulting\Events\Http\Resources;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use RoundlyConsulting\Events\Support\EventsConfig;

/**
 * Renders a related host model through the resource configured for its role
 * (`events.resources.<role>`), in place. A missing model renders null.
 *
 * @internal
 */
final class References
{
    /**
     * @return array<array-key, mixed>|null
     */
    public static function organizer(?Model $model, Request $request): ?array
    {
        return self::render(EventsConfig::organizerResource(), $model, $request);
    }

    /**
     * @return array<array-key, mixed>|null
     */
    public static function host(?Model $model, Request $request): ?array
    {
        return self::render(EventsConfig::hostResource(), $model, $request);
    }

    /**
     * @return array<array-key, mixed>|null
     */
    public static function attendee(?Model $model, Request $request): ?array
    {
        return self::render(EventsConfig::attendeeResource(), $model, $request);
    }

    /**
     * @return array<array-key, mixed>|null
     */
    public static function invitee(?Model $model, Request $request): ?array
    {
        return self::render(EventsConfig::inviteeResource(), $model, $request);
    }

    /**
     * @return array<array-key, mixed>|null
     */
    public static function actor(?Model $model, Request $request): ?array
    {
        return self::render(EventsConfig::actorResource(), $model, $request);
    }

    /**
     * @return array<array-key, mixed>|null
     */
    public static function venueOwner(?Model $model, Request $request): ?array
    {
        return self::render(EventsConfig::venueOwnerResource(), $model, $request);
    }

    /**
     * @param  class-string<JsonResource>  $class
     * @return array<array-key, mixed>|null
     */
    private static function render(string $class, ?Model $model, Request $request): ?array
    {
        return $model === null ? null : (new $class($model))->resolve($request);
    }
}
