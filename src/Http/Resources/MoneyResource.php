<?php

declare(strict_types=1);

namespace RoundlyConsulting\Events\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use RoundlyConsulting\Money\Money;

/**
 * A price: `{minor, decimal, currency}` — `minor` as a string, so JavaScript never rounds it.
 *
 * @property Money $resource
 */
final class MoneyResource extends JsonResource
{
    /**
     * @return array{minor: string, decimal: string, currency: string}
     */
    public function toArray(Request $request): array
    {
        return $this->resource->toArray();
    }
}
