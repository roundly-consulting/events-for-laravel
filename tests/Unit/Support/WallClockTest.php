<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use RoundlyConsulting\Events\Exceptions\InvalidEventScheduleException;
use RoundlyConsulting\Events\Support\WallClock;

test('wall_clock_input_is_event_local', function (): void {
    // 20:00 in Bratislava (CET, +01:00) is 19:00 UTC; the same text in New York is 01:00 UTC
    // the next day.
    expect(WallClock::toUtc('2026-11-14 20:00', 'Europe/Bratislava')->toIso8601String())->toBe('2026-11-14T19:00:00+00:00')
        ->and(WallClock::toUtc('2026-11-14 20:00', 'America/New_York')->toIso8601String())->toBe('2026-11-15T01:00:00+00:00')
        ->and(WallClock::toUtc('2026-07-14 20:00', 'Europe/Bratislava')->toIso8601String())->toBe('2026-07-14T18:00:00+00:00')
        ->and(WallClock::toUtc('2026-11-14', 'Europe/Bratislava')->toIso8601String())->toBe('2026-11-13T23:00:00+00:00');
});

test('carbon_and_offset_strings_are_instants', function (string|CarbonImmutable $input): void {
    // Whatever zone the event is in, an instant stays the instant it names.
    foreach (['Europe/Bratislava', 'America/New_York', 'Asia/Tokyo'] as $timezone) {
        expect(WallClock::toUtc($input, $timezone)->toIso8601String())->toBe('2026-11-14T19:00:00+00:00');
    }
})->with([
    'JavaScript toISOString' => ['2026-11-14T19:00:00.000Z'],
    'Z suffix' => ['2026-11-14T19:00:00Z'],
    'explicit offset' => ['2026-11-14T20:00:00+01:00'],
    'negative offset' => ['2026-11-14 14:00:00-05:00'],
    'UTC Carbon' => [CarbonImmutable::parse('2026-11-14 19:00', 'UTC')],
    'Carbon in another zone' => [CarbonImmutable::parse('2026-11-15 04:00', 'Asia/Tokyo')],
]);

test('dst_gap_is_refused', function (): void {
    // 2026-03-29 02:00 → 03:00 in Bratislava: 02:30 never happens.
    expect(fn () => WallClock::toUtc('2026-03-29 02:30', 'Europe/Bratislava'))
        ->toThrow(InvalidEventScheduleException::class, 'The time 2026-03-29 02:30 does not exist in Europe/Bratislava');

    // The minutes either side of the gap are fine.
    expect(WallClock::toUtc('2026-03-29 01:59', 'Europe/Bratislava')->toIso8601String())->toBe('2026-03-29T00:59:00+00:00')
        ->and(WallClock::toUtc('2026-03-29 03:00', 'Europe/Bratislava')->toIso8601String())->toBe('2026-03-29T01:00:00+00:00');
});

test('dst_overlap_takes_the_earlier_offset', function (): void {
    // 2026-10-25 03:00 → 02:00 in Bratislava: 02:30 happens at 00:30 UTC (+02:00) and again
    // at 01:30 UTC (+01:00). The first one is taken; PHP alone would take the second.
    expect(WallClock::toUtc('2026-10-25 02:30', 'Europe/Bratislava')->toIso8601String())->toBe('2026-10-25T00:30:00+00:00')
        ->and(WallClock::toUtc('2026-11-01 01:30', 'America/New_York')->toIso8601String())->toBe('2026-11-01T05:30:00+00:00')
        // Lord Howe Island falls back by 30 minutes.
        ->and(WallClock::toUtc('2026-04-05 01:45', 'Australia/Lord_Howe')->toIso8601String())->toBe('2026-04-04T14:45:00+00:00');
});

it('refuses unreadable and impossible wall-clock input', function (string $input): void {
    expect(fn () => WallClock::toUtc($input, 'Europe/Bratislava'))->toThrow(InvalidEventScheduleException::class);
})->with(['', 'not a date', '2026-02-30 10:00', '2026-13-01 10:00']);

it('reads relative input in the event timezone', function (): void {
    CarbonImmutable::setTestNow('2026-05-01 12:00:00 UTC');

    expect(WallClock::toUtc('tomorrow 20:00', 'Europe/Bratislava')->toIso8601String())->toBe('2026-05-02T18:00:00+00:00');

    CarbonImmutable::setTestNow();
});

it('finds the first instant of a local day, even when midnight does not exist', function (): void {
    expect(WallClock::startOfDay('2026-07-17', 'Europe/Bratislava')->toIso8601String())->toBe('2026-07-16T22:00:00+00:00')
        // Havana springs forward at midnight: 2026-03-08 starts at 01:00 local (-04:00).
        ->and(WallClock::startOfDay('2026-03-08', 'America/Havana')->toIso8601String())->toBe('2026-03-08T05:00:00+00:00');

    expect(fn () => WallClock::startOfDay('2026-02-30', 'Europe/Bratislava'))->toThrow(InvalidEventScheduleException::class);
});
