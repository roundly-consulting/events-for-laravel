<?php

declare(strict_types=1);

namespace RoundlyConsulting\Events\Http\Requests;

use Closure;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use RoundlyConsulting\Events\Models\Event;
use RoundlyConsulting\Events\Support\EventModels;
use RoundlyConsulting\Events\Support\MorphAliases;
use RoundlyConsulting\Geolocation\DataTransferObjects\Coordinates;
use RoundlyConsulting\Money\Money;

/**
 * The API's form requests: validation only — the policy runs in the handle, where PHP callers
 * get it too. A failure answers 422 with `{message, code: validation_failed, errors}`.
 *
 * @internal
 */
abstract class EventsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Field names in the caller's language (`events::validation.attributes`).
     *
     * @return array<string, string>
     */
    public function attributes(): array
    {
        $names = __('events::validation.attributes');

        return is_array($names) ? array_filter($names, 'is_string') : [];
    }

    protected function failedValidation(Validator $validator): void
    {
        throw new HttpResponseException(new JsonResponse([
            'message' => $validator->errors()->first(),
            'code' => 'validation_failed',
            'errors' => $validator->errors()->toArray(),
        ], 422));
    }

    /**
     * A one-line text: a string, or a map of locale → string, each at most `$max` characters.
     */
    protected static function text(int $max): Closure
    {
        return static function (string $attribute, mixed $value, Closure $fail) use ($max): void {
            $lines = is_array($value) ? $value : [$value];

            foreach ($lines as $locale => $line) {
                if ((is_array($value) && (! is_string($locale) || preg_match('/^[a-z]{2}([_-][A-Za-z]{2})?$/', $locale) !== 1))
                    || ($line !== null && ! is_string($line)) || mb_strlen((string) $line) > $max) {
                    $fail(__('validation.string', ['attribute' => $attribute]));

                    return;
                }
            }
        };
    }

    /**
     * Rules for a host-model reference `{type: alias, id}` — an alias, never a class name.
     *
     * @return array<string, list<mixed>>
     */
    protected static function reference(string $field, bool $required = false): array
    {
        return [
            $field => [$required ? 'required' : 'sometimes', 'array:type,id'],
            $field.'.type' => ['required_with:'.$field, 'string', 'regex:/^[a-z][a-z0-9_]{0,63}$/'],
            $field.'.id' => ['required_with:'.$field, 'max:255', static function (string $attribute, mixed $value, Closure $fail): void {
                if (! is_int($value) && ! is_string($value)) {
                    $fail(__('validation.string', ['attribute' => $attribute]));
                }
            }],
        ];
    }

    /**
     * The organizer or attendee a validated reference names.
     *
     * @param  array<string, mixed>  $reference
     */
    protected static function resolvePerson(array $reference, string $kind): Model
    {
        $type = (string) ($reference['type'] ?? '');
        $id = $reference['id'] ?? '';
        $id = is_int($id) ? $id : (string) $id;

        return match ($kind) {
            'organizer' => MorphAliases::organizer($type, $id),
            'attendee' => MorphAliases::attendee($type, $id),
            default => MorphAliases::person($type, $id),
        };
    }

    /**
     * A price: a decimal string in `events.tiers.default_currency`, or with its currency.
     */
    protected static function price(mixed $amount, mixed $currency): Money|string|null
    {
        if ($amount === null || $amount === '') {
            return null;
        }

        return is_string($currency) && $currency !== '' ? Money::ofMajor((string) $amount, $currency) : (string) $amount;
    }

    /**
     * Coordinates in the shapes geolocation's `CoordinatesRule` accepts: `"lat,lng"`,
     * `[lat, lng]` or `{latitude, longitude}` (`lat`/`lng` too).
     */
    protected static function coordinates(mixed $value): ?Coordinates
    {
        if (is_string($value) && str_contains($value, ',')) {
            $value = array_map('trim', explode(',', $value, 2));
        }

        if (! is_array($value)) {
            return null;
        }

        $latitude = $value['latitude'] ?? $value['lat'] ?? $value[0] ?? null;
        $longitude = $value['longitude'] ?? $value['lng'] ?? $value['lon'] ?? $value[1] ?? null;

        return is_numeric($latitude) && is_numeric($longitude) ? new Coordinates((float) $latitude, (float) $longitude) : null;
    }

    /**
     * The event of the route (by uuid), for rules that need it.
     */
    protected function routeEvent(): ?Event
    {
        $uuid = $this->route('event');

        return is_string($uuid) ? EventModels::event()::query()->withTrashed()->where('uuid', $uuid)->first() : null;
    }
}
