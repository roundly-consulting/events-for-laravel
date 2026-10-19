<?php

declare(strict_types=1);

namespace RoundlyConsulting\Events\Tests\Support;

use Carbon\CarbonImmutable;
use RoundlyConsulting\Events\Facades\Events;
use RoundlyConsulting\Events\Models\Event;
use RoundlyConsulting\Events\Models\Venue;
use RoundlyConsulting\Events\Support\Clock;
use RoundlyConsulting\Events\Tests\Fixtures\Models\User;

/**
 * Shared set-ups: the clock fixed on 2027-01-01 12:00 UTC, a published event on 2027-02-10.
 */
final class Scenarios
{
    public const string NOW = '2027-01-01 12:00:00';

    public static function freeze(?string $at = null): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse($at ?? self::NOW, 'UTC'));
    }

    public static function thaw(): void
    {
        CarbonImmutable::setTestNow();
    }

    /**
     * A published event on 2027-02-10 20:00 (Bratislava), two hours long.
     */
    public static function event(?int $capacity = null, ?User $organizer = null, bool $waitlist = true, int $maxGuests = 0, bool $publish = true): Event
    {
        $builder = Events::make('Concert')->organizedBy($organizer ?? User::factory()->create())
            ->at(Venue::factory()->create(['timezone' => 'Europe/Bratislava']))
            ->startsAt('2027-02-10 20:00')->duration('2 hours')
            ->capacity($capacity)
            ->maxGuests($maxGuests)
            ->waitlist($waitlist);

        return $publish ? $builder->publish() : $builder->create();
    }

    public static function minutesBeforeStart(Event $event, int $minutes): void
    {
        CarbonImmutable::setTestNow($event->starts_at->subMinutes($minutes));
    }

    public static function now(): CarbonImmutable
    {
        return Clock::now();
    }
}
