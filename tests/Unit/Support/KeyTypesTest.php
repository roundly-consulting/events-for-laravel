<?php

declare(strict_types=1);

use RoundlyConsulting\Events\Exceptions\InvalidEventsConfigurationException;
use RoundlyConsulting\Events\Facades\Events;
use RoundlyConsulting\Events\Models\Event;
use RoundlyConsulting\Events\Models\Rsvp;
use RoundlyConsulting\Events\Support\KeyTypes;
use RoundlyConsulting\Events\Tests\Fixtures\Models\User;
use RoundlyConsulting\Events\Tests\Fixtures\Models\UuidUser;

test('a_mismatched_key_type_fails_fast_naming_the_config_key', function (): void {
    $user = User::factory()->create();
    $uuidUser = UuidUser::query()->create(['name' => 'Ada']);

    // bigint (the default) takes an integer key, never a uuid.
    KeyTypes::assertMorphable($user);
    expect(fn () => KeyTypes::assertMorphable($uuidUser))->toThrow(
        InvalidEventsConfigurationException::class,
        'The key of the UuidUser model does not match the configured key type [events.key_type = bigint].',
    );

    config()->set('events.key_type', 'uuid');
    KeyTypes::assertMorphable($uuidUser);
    expect(fn () => KeyTypes::assertMorphable($user))->toThrow(InvalidEventsConfigurationException::class, 'events.key_type = uuid');

    config()->set('events.key_type', 'ulid');
    expect(fn () => KeyTypes::assertMorphable($uuidUser))->toThrow(InvalidEventsConfigurationException::class, 'events.key_type = ulid');

    // The message names the basename only — never a namespaced class.
    try {
        KeyTypes::assertMorphable($user);
    } catch (InvalidEventsConfigurationException $exception) {
        expect($exception->getMessage())->not->toContain('\\');
    }

    KeyTypes::assertMorphable(null);

    // Through the package's writes: refused before any SQL reaches a bigint morph column.
    config()->set('events.key_type', 'bigint');
    $event = Event::factory()->published()->create();

    expect(fn () => Events::for($event)->rsvp($uuidUser)->going())->toThrow(InvalidEventsConfigurationException::class, 'events.key_type')
        ->and(fn () => Events::for($event)->invitations()->send($uuidUser))->toThrow(InvalidEventsConfigurationException::class, 'events.key_type')
        ->and(fn () => Events::venues()->make('X')->ownedBy($uuidUser)->create())->toThrow(InvalidEventsConfigurationException::class, 'events.key_type')
        ->and(Rsvp::query()->count())->toBe(0);
});
