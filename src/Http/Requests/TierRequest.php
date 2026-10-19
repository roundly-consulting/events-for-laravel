<?php

declare(strict_types=1);

namespace RoundlyConsulting\Events\Http\Requests;

use RoundlyConsulting\Events\DataTransferObjects\TierData;
use RoundlyConsulting\Events\DataTransferObjects\UpdateTierData;
use RoundlyConsulting\Events\Support\NotGiven;
use RoundlyConsulting\Money\Rules\CurrencyCode;

/**
 * A ticket type: create (`POST`) or change (`PATCH`, only the fields sent). A price is a decimal
 * string, in `currency` or `events.tiers.default_currency`.
 *
 * @internal
 */
final class TierRequest extends EventsRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $create = $this->isMethod('POST');
        $sometimes = $create ? [] : ['sometimes'];

        return [
            'name' => [...$sometimes, 'required', self::text(200)],
            'description' => ['sometimes', 'nullable', self::text(2000)],
            'key' => [...$sometimes, $create ? 'nullable' : 'required', 'string', 'max:64'],
            'price' => ['sometimes', 'nullable', 'string', 'regex:/^\d{1,12}(\.\d{1,4})?$/'],
            'currency' => ['sometimes', 'nullable', 'string', new CurrencyCode],
            'capacity' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:10000000'],
            'sale_starts_at' => ['sometimes', 'nullable', 'string', 'max:64'],
            'sale_ends_at' => ['sometimes', 'nullable', 'string', 'max:64'],
            'min_party' => ['sometimes', 'integer', 'min:1', 'max:1000'],
            'max_party' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:1000'],
            'hidden' => ['sometimes', 'boolean'],
            'position' => ['sometimes', 'integer', 'min:0', 'max:100000'],
        ];
    }

    public function toData(): TierData
    {
        $data = $this->validated();

        return new TierData(
            name: $data['name'],
            key: $data['key'] ?? null,
            price: self::price($data['price'] ?? null, $data['currency'] ?? null),
            capacity: isset($data['capacity']) ? (int) $data['capacity'] : null,
            saleStartsAt: $data['sale_starts_at'] ?? null,
            saleEndsAt: $data['sale_ends_at'] ?? null,
            minParty: (int) ($data['min_party'] ?? 1),
            maxParty: isset($data['max_party']) ? (int) $data['max_party'] : null,
            hidden: (bool) ($data['hidden'] ?? false),
            position: isset($data['position']) ? (int) $data['position'] : null,
            description: $data['description'] ?? null,
        );
    }

    public function toUpdate(): UpdateTierData
    {
        $data = $this->validated();
        $given = static fn (string $key): bool => array_key_exists($key, $data);

        return new UpdateTierData(
            name: $given('name') ? $data['name'] : NotGiven::Value,
            key: $given('key') ? (string) $data['key'] : NotGiven::Value,
            price: $given('price') ? self::price($data['price'], $data['currency'] ?? null) : NotGiven::Value,
            capacity: $given('capacity') ? ($data['capacity'] === null ? null : (int) $data['capacity']) : NotGiven::Value,
            saleStartsAt: $given('sale_starts_at') ? $data['sale_starts_at'] : NotGiven::Value,
            saleEndsAt: $given('sale_ends_at') ? $data['sale_ends_at'] : NotGiven::Value,
            minParty: $given('min_party') ? (int) $data['min_party'] : NotGiven::Value,
            maxParty: $given('max_party') ? ($data['max_party'] === null ? null : (int) $data['max_party']) : NotGiven::Value,
            hidden: $given('hidden') ? (bool) $data['hidden'] : NotGiven::Value,
            description: $given('description') ? $data['description'] : NotGiven::Value,
        );
    }
}
