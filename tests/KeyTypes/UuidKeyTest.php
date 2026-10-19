<?php

declare(strict_types=1);

use Illuminate\Support\Str;
use RoundlyConsulting\Events\Models\Event;
use RoundlyConsulting\Lifecycle\Facades\Lifecycles;

test('uuid_installation_works', function (): void {
    $event = Event::factory()->create();

    expect(Str::isUuid((string) $event->getKey()))->toBeTrue()
        ->and($event->getKey())->not->toBe($event->uuid)
        ->and(Event::query()->find($event->getKey())?->is($event))->toBeTrue();

    // Lifecycle points at the uuid key (lifecycle.key_type = uuid).
    Lifecycles::for($event)->asSystem()->apply('publish');

    expect($event->fresh()?->phase->value)->toBe('scheduled')
        ->and(Lifecycles::for($event)->history())->toHaveCount(2);
});
