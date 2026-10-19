<?php

declare(strict_types=1);

namespace RoundlyConsulting\Events\DataTransferObjects;

/**
 * A venue's postal address. `country` is an ISO-3166 alpha-2 code (stored upper-case);
 * `formatted` defaults to the parts joined.
 */
final readonly class AddressData
{
    public function __construct(
        public ?string $street = null,
        public ?string $city = null,
        public ?string $region = null,
        public ?string $postalCode = null,
        public ?string $country = null,
        public ?string $formatted = null,
    ) {}
}
