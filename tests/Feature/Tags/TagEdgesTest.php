<?php

declare(strict_types=1);

use RoundlyConsulting\Events\Facades\Events;
use RoundlyConsulting\Events\Tests\Fixtures\Models\Team;
use RoundlyConsulting\Events\Tests\Fixtures\Models\User;
use RoundlyConsulting\Events\Tests\Support\Scenarios;

beforeEach(fn () => Scenarios::freeze());
afterEach(fn () => Scenarios::thaw());

test('organizer_scoped_labels_move_with_the_event', function (): void {
    config()->set('events.tags.scope', 'organizer');
    $team = Team::factory()->create();
    $other = Team::factory()->create();
    $event = Scenarios::event(organizer: User::factory()->create());
    $event->forceFill(['organizer_type' => $team->getMorphClass(), 'organizer_id' => $team->getKey()])->save();
    $members = Events::labels()->within($team)->make(['en' => 'Members only', 'sk' => 'Len pre členov'])->findOrCreate();
    Events::for($event)->labels()->attach([$members, 'Outdoor']);

    Events::for($event)->transferTo($other);
    $fresh = $event->fresh() ?? $event;

    // Re-homed into the new organizer's catalogue, translations kept.
    expect($fresh->labels->pluck('scope_id')->unique()->values()->all())->toBe([$other->getKey()])
        ->and($fresh->labels->map(static fn ($tag): array => $tag->getTranslations('name'))->sortBy('en')->values()->all())
        ->toBe([['en' => 'Members only', 'sk' => 'Len pre členov'], ['en' => 'Outdoor']]);

    // So the new organizer manages them by name.
    Events::for($fresh)->labels()->detach('Members only');
    expect($fresh->fresh()?->labels->pluck('name')->all())->toBe(['Outdoor']);
});
