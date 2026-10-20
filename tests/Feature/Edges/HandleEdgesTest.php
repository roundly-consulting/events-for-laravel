<?php

declare(strict_types=1);

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event as EventBus;
use PHPUnit\Framework\ExpectationFailedException;
use RoundlyConsulting\Events\DataTransferObjects\AddressData;
use RoundlyConsulting\Events\DataTransferObjects\UpdateEventData;
use RoundlyConsulting\Events\DataTransferObjects\UpdateVenueData;
use RoundlyConsulting\Events\DataTransferObjects\VenueData;
use RoundlyConsulting\Events\Enums\GuestList;
use RoundlyConsulting\Events\Events\EventMovedOnline;
use RoundlyConsulting\Events\Events\EventUpdated;
use RoundlyConsulting\Events\Exceptions\RsvpNotFoundException;
use RoundlyConsulting\Events\Facades\Events;
use RoundlyConsulting\Events\Models\Venue;
use RoundlyConsulting\Events\Tests\Fixtures\Models\Team;
use RoundlyConsulting\Events\Tests\Fixtures\Models\User;
use RoundlyConsulting\Events\Tests\Support\Scenarios;

beforeEach(fn () => Scenarios::freeze());
afterEach(fn () => Scenarios::thaw());

test('venues_by_actor_create_checks_the_policy', function (): void {
    $outsider = User::factory()->create();
    $team = Team::factory()->create();

    expect(fn () => Events::venues()->by($outsider)->create(new VenueData(name: 'Planted', timezone: 'Europe/Bratislava', owner: $team)))
        ->toThrow(AuthorizationException::class)
        ->and(fn () => Events::venues()->by($outsider)->create(new VenueData(name: 'Shared', timezone: 'Europe/Bratislava')))
        ->toThrow(AuthorizationException::class)
        ->and(fn () => Events::venues()->by($outsider)->create(new VenueData(name: 'Mine', timezone: 'Europe/Bratislava', owner: $outsider, actor: User::factory()->create())))
        ->toThrow(AuthorizationException::class);

    $mine = Events::venues()->by($outsider)->create(new VenueData(name: 'Mine', timezone: 'Europe/Bratislava', owner: $outsider));
    expect($mine->owner_id)->toBe($outsider->getKey());
});
