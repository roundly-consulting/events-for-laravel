<?php

declare(strict_types=1);

namespace RoundlyConsulting\Events\Builders;

use Carbon\CarbonInterface;
use RoundlyConsulting\Events\DataTransferObjects\TierData;
use RoundlyConsulting\Events\Handles\EventTiers;
use RoundlyConsulting\Events\Models\AdmissionTier;
use RoundlyConsulting\Money\Money;

/**
 * A fluent builder for a ticket type; `create()` runs the same code as `tiers()->create()`.
 * Sale-window strings are wall-clock times in the event's timezone.
 */
final class TierBuilder
{
    private ?string $key = null;

    private Money|string|null $price = null;

    private ?int $capacity = null;

    private CarbonInterface|string|null $saleStartsAt = null;

    private CarbonInterface|string|null $saleEndsAt = null;

    private int $minParty = 1;

    private ?int $maxParty = null;

    private bool $hidden = false;

    private ?int $position = null;

    /** @var array<string, string>|string|null */
    private array|string|null $description = null;

    /**
     * @param  array<string, string>|string  $name
     */
    public function __construct(
        private readonly EventTiers $tiers,
        private readonly array|string $name,
    ) {}

    /**
     * How code names the ticket type (`going(tier: 'early')`); derived from the name otherwise.
     */
    public function key(string $key): self
    {
        $this->key = $key;

        return $this;
    }

    /**
     * A Money, or a plain number in `events.tiers.default_currency`.
     */
    public function price(Money|string $price): self
    {
        $this->price = $price;

        return $this;
    }

    public function free(): self
    {
        $this->price = null;

        return $this;
    }

    public function capacity(?int $capacity): self
    {
        $this->capacity = $capacity;

        return $this;
    }

    public function onSale(CarbonInterface|string|null $from = null, CarbonInterface|string|null $until = null): self
    {
        $this->saleStartsAt = $from;
        $this->saleEndsAt = $until;

        return $this;
    }

    /**
     * The party size per RSVP, guests included. A maximum replaces the event's `max_guests`.
     */
    public function perRsvp(int $min = 1, ?int $max = null): self
    {
        $this->minParty = $min;
        $this->maxParty = $max;

        return $this;
    }

    /**
     * Only organizers can assign it; never listed for attendees.
     */
    public function hidden(bool $hidden = true): self
    {
        $this->hidden = $hidden;

        return $this;
    }

    public function position(int $position): self
    {
        $this->position = $position;

        return $this;
    }

    /**
     * @param  array<string, string>|string  $description
     */
    public function description(array|string $description): self
    {
        $this->description = $description;

        return $this;
    }

    public function toData(): TierData
    {
        return new TierData(
            name: $this->name,
            key: $this->key,
            price: $this->price,
            capacity: $this->capacity,
            saleStartsAt: $this->saleStartsAt,
            saleEndsAt: $this->saleEndsAt,
            minParty: $this->minParty,
            maxParty: $this->maxParty,
            hidden: $this->hidden,
            position: $this->position,
            description: $this->description,
        );
    }

    public function create(): AdmissionTier
    {
        return $this->tiers->create($this->toData());
    }
}
