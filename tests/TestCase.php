<?php

declare(strict_types=1);

namespace RoundlyConsulting\Events\Tests;

use Illuminate\Support\ServiceProvider;
use RoundlyConsulting\Crypto\CryptoServiceProvider;
use RoundlyConsulting\Events\EventsServiceProvider;
use RoundlyConsulting\Events\Tests\Fixtures\Models\Guest;
use RoundlyConsulting\Events\Tests\Fixtures\Models\Team;
use RoundlyConsulting\Events\Tests\Fixtures\Models\User;
use RoundlyConsulting\Geolocation\GeolocationServiceProvider;
use RoundlyConsulting\HttpClientRateLimits\HttpClientRateLimitsServiceProvider;
use RoundlyConsulting\Lifecycle\LifecycleServiceProvider;
use RoundlyConsulting\MediaLibrary\MediaLibraryServiceProvider;
use RoundlyConsulting\Money\MoneyServiceProvider;
use RoundlyConsulting\Qr\QrServiceProvider;
use RoundlyConsulting\QueryBuilder\QueryBuilderServiceProvider;
use RoundlyConsulting\Sluggable\SluggableServiceProvider;
use RoundlyConsulting\Tags\TagsServiceProvider;
use RoundlyConsulting\Testing\PackageTestCase;
use RoundlyConsulting\Translatable\TranslatableServiceProvider;

abstract class TestCase extends PackageTestCase
{
    /**
     * Every provider events requires, in registration order. A host auto-discovers these; the
     * suite lists them so its environment is real — the migrations use the toolkit's,
     * translatable's, sluggable's and money's schema macros. Rate limits register before
     * geolocation, as geolocation's own suite does.
     *
     * @return list<class-string<ServiceProvider>>
     */
    protected function packageProviders(): array
    {
        return [
            CryptoServiceProvider::class,
            MoneyServiceProvider::class,
            SluggableServiceProvider::class,
            TranslatableServiceProvider::class,
            QueryBuilderServiceProvider::class,
            LifecycleServiceProvider::class,
            MediaLibraryServiceProvider::class,
            QrServiceProvider::class,
            HttpClientRateLimitsServiceProvider::class,
            GeolocationServiceProvider::class,
            TagsServiceProvider::class,
            EventsServiceProvider::class,
        ];
    }

    /**
     * Every package's migrations are publish-only, so the suite runs them the way a host does
     * after publishing — named by provider class, never by file.
     *
     * @return list<class-string<ServiceProvider>|string>
     */
    protected function migrationSources(): array
    {
        return [
            MediaLibraryServiceProvider::class,
            LifecycleServiceProvider::class,
            TagsServiceProvider::class,
            SluggableServiceProvider::class,
            EventsServiceProvider::class,
            __DIR__.'/Fixtures/migrations',
        ];
    }

    /**
     * Migrate once per schema signature: seven migration sources plus per-locale slug index DDL
     * would otherwise dominate the real-engine legs. Racers end with SIGKILL.
     */
    protected function keepsMigratedSchema(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    protected function configBeforeBoot(): array
    {
        return [
            'mail.default' => 'array',
            'translatable.locales' => ['en', 'sk'],
            'translatable.fallback_locale' => 'en',
            'media.disk' => 'local',
            'media.image_driver' => 'gd',
            'events.organizers' => ['user' => User::class, 'team' => Team::class],
            'events.attendees' => ['user' => User::class, 'guest' => Guest::class],
        ];
    }
}
