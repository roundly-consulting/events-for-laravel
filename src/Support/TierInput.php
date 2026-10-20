<?php

declare(strict_types=1);

namespace RoundlyConsulting\Events\Support;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Str;
use RoundlyConsulting\Events\Exceptions\InvalidTierException;
use RoundlyConsulting\Events\Models\Event;
use RoundlyConsulting\Money\Money;
use RoundlyConsulting\PackageToolkit\Support\Config;
use RoundlyConsulting\Translatable\Facades\Translatable;
use Throwable;

/**
 * Validation of ticket type input shared by create and update.
 *
 * @internal
 */
final class TierInput
{
    public const string KEY_PATTERN = '/^[a-z0-9][a-z0-9-]{0,63}$/';

    /**
     * @param  array<string, string>|string  $name
     * @return array<string, string>
     */
    public static function name(array|string $name): array
    {
        $map = Translatable::fromInput(Text::line($name));

        if ($map === []) {
            throw InvalidTierException::nameRequired();
        }

        return $map;
    }

    /**
     * @param  array<string, string>|string|null  $description
     * @return array<string, string>|null
     */
    public static function description(array|string|null $description): ?array
    {
        $map = $description === null ? [] : Translatable::fromInput(Text::block($description));

        return $map === [] ? null : $map;
    }

    /**
     * The given key, or the slug of the fallback-locale name.
     *
     * @param  array<string, string>  $name
     */
    public static function key(?string $key, array $name): string
    {
        $key ??= substr(Str::slug($name[Translatable::fallbackLocale()] ?? (string) reset($name)), 0, 64);

        if (preg_match(self::KEY_PATTERN, $key) !== 1) {
            throw InvalidTierException::invalidKey($key);
        }

        return $key;
    }

    public static function assertKeyFree(Event $event, string $key, ?int $except = null): void
    {
        $taken = EventModels::tier()::query()
            ->withTrashed()
            ->where('event_id', $event->getKey())
            ->where('key', $key)
            ->when($except !== null, static fn ($query) => $query->whereKeyNot($except))
            ->first();

        if ($taken !== null) {
            throw $taken->trashed() ? InvalidTierException::keyTakenByTrashed($key) : InvalidTierException::keyTaken($key);
        }
    }

    /**
     * A Money, or a numeric string in `events.tiers.default_currency` (blank: money's default).
     * Zero is free: stored as null.
     */
    public static function price(Money|string|null $price): ?Money
    {
        if ($price === null) {
            return null;
        }

        if (is_string($price)) {
            try {
                $price = Money::ofMajor(trim($price), self::defaultCurrency());
            } catch (Throwable) {
                throw InvalidTierException::invalidPrice();
            }
        }

        if ($price->isNegative()) {
            throw InvalidTierException::invalidPrice();
        }

        return $price->isZero() ? null : $price;
    }

    public static function defaultCurrency(): string
    {
        $currency = config('events.tiers.default_currency');

        return is_string($currency) && trim($currency) !== ''
            ? strtoupper(trim($currency))
            : Config::string('money.default_currency', 'EUR');
    }

    public static function position(int $position): int
    {
        if ($position < 0) {
            throw InvalidTierException::negativePosition();
        }

        return $position;
    }

    public static function capacity(?int $capacity): ?int
    {
        if ($capacity !== null && $capacity < 0) {
            throw InvalidTierException::negativeCapacity();
        }

        return $capacity;
    }

    public static function assertPartyLimits(int $min, ?int $max): void
    {
        if ($min < 1 || ($max !== null && $max < $min)) {
            throw InvalidTierException::invalidPartyLimits();
        }
    }

    /**
     * A sale-window bound: a wall-clock string in the event's timezone, or an instant.
     */
    public static function instant(CarbonInterface|string|null $value, string $timezone): ?CarbonImmutable
    {
        return $value === null ? null : WallClock::toUtc($value, $timezone);
    }

    public static function assertSaleWindow(?CarbonInterface $starts, ?CarbonInterface $ends): void
    {
        if ($starts !== null && $ends !== null && ! $starts->lessThan($ends)) {
            throw InvalidTierException::invalidSaleWindow();
        }
    }
}
