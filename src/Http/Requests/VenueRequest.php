<?php

declare(strict_types=1);

namespace RoundlyConsulting\Events\Http\Requests;

use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Events\DataTransferObjects\AddressData;
use RoundlyConsulting\Events\DataTransferObjects\UpdateVenueData;
use RoundlyConsulting\Events\DataTransferObjects\VenueData;
use RoundlyConsulting\Events\Models\Venue;
use RoundlyConsulting\Events\Support\NotGiven;
use RoundlyConsulting\Geolocation\Rules\CoordinatesRule;

/**
 * A venue: create (`POST`) or change (`PATCH`, only the fields sent — each address part on its
 * own). A new venue is owned by the caller unless the body names an `owner` (who must allow the
 * caller) or asks for a `shared` one (admins and the `shared_venues` ability).
 *
 * @internal
 */
final class VenueRequest extends EventsRequest
{
    private const array ADDRESS = ['street', 'city', 'region', 'postal_code', 'country', 'formatted_address'];

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $sometimes = $this->isMethod('POST') ? [] : ['sometimes'];

        return [
            'name' => [...$sometimes, 'required', self::text(200)],
            'street' => ['sometimes', 'nullable', 'string', 'max:255'],
            'city' => ['sometimes', 'nullable', 'string', 'max:255'],
            'region' => ['sometimes', 'nullable', 'string', 'max:255'],
            'postal_code' => ['sometimes', 'nullable', 'string', 'max:32'],
            'country' => ['sometimes', 'nullable', 'string', 'size:2'],
            'formatted_address' => ['sometimes', 'nullable', 'string', 'max:500'],
            'coordinates' => ['sometimes', 'nullable', new CoordinatesRule],
            'timezone' => ['sometimes', 'timezone:all'],
            'capacity' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:10000000'],
            ...self::reference('owner'),
            'shared' => ['sometimes', 'boolean', 'prohibits:owner'],
            'geocode' => ['sometimes', 'boolean'],
        ];
    }

    public function toData(Model $user): VenueData
    {
        $data = $this->validated();
        /** @var array<string, mixed>|null $owner */
        $owner = $data['owner'] ?? null;

        return new VenueData(
            name: $data['name'],
            address: $this->address($data),
            coordinates: self::coordinates($data['coordinates'] ?? null),
            timezone: $data['timezone'] ?? null,
            capacity: isset($data['capacity']) ? (int) $data['capacity'] : null,
            owner: $owner === null ? ((bool) ($data['shared'] ?? false) ? null : $user) : self::resolvePerson($owner, 'organizer'),
            geocode: (bool) ($data['geocode'] ?? false),
            actor: $user,
        );
    }

    /**
     * The change; address parts not sent keep the venue's stored values.
     */
    public function toUpdate(Venue $venue): UpdateVenueData
    {
        $data = $this->validated();
        $given = static fn (string $key): bool => array_key_exists($key, $data);

        return new UpdateVenueData(
            name: $given('name') ? $data['name'] : NotGiven::Value,
            address: array_intersect(self::ADDRESS, array_keys($data)) !== [] ? $this->address([...self::stored($venue), ...$data]) : NotGiven::Value,
            coordinates: $given('coordinates') ? self::coordinates($data['coordinates']) : NotGiven::Value,
            timezone: $given('timezone') ? (string) $data['timezone'] : NotGiven::Value,
            capacity: $given('capacity') ? ($data['capacity'] === null ? null : (int) $data['capacity']) : NotGiven::Value,
        );
    }

    /**
     * @return array<string, string|null>
     */
    private static function stored(Venue $venue): array
    {
        return [
            'street' => $venue->street,
            'city' => $venue->city,
            'region' => $venue->region,
            'postal_code' => $venue->postal_code,
            'country' => $venue->country_code,
            'formatted_address' => $venue->formatted_address,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function address(array $data): AddressData
    {
        return new AddressData(
            street: $data['street'] ?? null,
            city: $data['city'] ?? null,
            region: $data['region'] ?? null,
            postalCode: $data['postal_code'] ?? null,
            country: $data['country'] ?? null,
            formatted: $data['formatted_address'] ?? null,
        );
    }
}
