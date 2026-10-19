<?php

declare(strict_types=1);

use Illuminate\Support\Str;
use RoundlyConsulting\Events\Models\Event;
use RoundlyConsulting\Lifecycle\Facades\Lifecycles;

it('runs on ulid event keys', function (): void {
    $event = Event::factory()->create();

    expect(Str::isUlid((string) $event->getKey()))->toBeTrue()
        ->and(Str::isUuid($event->uuid))->toBeTrue();

    Lifecycles::for($event)->asSystem()->apply('publish');

    expect($event->fresh()?->phase->value)->toBe('scheduled');
});
