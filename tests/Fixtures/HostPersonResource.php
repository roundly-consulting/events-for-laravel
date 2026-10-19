<?php

declare(strict_types=1);

namespace RoundlyConsulting\Events\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A host app's richer reference: its own fields, its own shape.
 *
 * @property Model $resource
 */
final class HostPersonResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return ['id' => $this->resource->getKey(), 'name' => $this->resource->getAttribute('name'), 'host' => true];
    }
}
