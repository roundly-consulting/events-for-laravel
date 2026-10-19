<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use RoundlyConsulting\PackageToolkit\Exceptions\InvalidConfigurationException;

/**
 * The `php artisan about` section, pinned with testing-for-laravel's render check: it proves
 * the capture is not empty before anything else is trusted. Add the real secrets to the
 * first argument once the package reads any (API keys, signing keys, DSNs).
 */
it('renders its about section', function (): void {
    expect('events')->toLeakNoSecrets([], mustRender: ['Enabled', 'YES', 'Timeout', '30s', 'Mode', 'sync']);
});

it('reads the enabled flag the way an env string means it', function (string|bool $value, string $shown): void {
    // env() only maps true/false/(true)/(false); `EVENTS_ENABLED=off` arrives as the
    // string 'off', which a bare truthiness check reported as YES.
    config()->set('events.enabled', $value);

    Artisan::call('about', ['--only' => 'events']);

    expect(Artisan::output())->toMatch('/Enabled\W+'.$shown.'\b/');
})->with([
    ['off', 'NO'],
    ['no', 'NO'],
    ['0', 'NO'],
    [false, 'NO'],
    ['on', 'YES'],
    ['yes', 'YES'],
    ['1', 'YES'],
    [true, 'YES'],
]);

it('refuses an enabled flag it cannot read (strict config)', function (): void {
    // A typo must fail loudly, naming the key, instead of quietly reading as a default.
    config()->set('events.enabled', 'disabled');

    expect(fn () => Artisan::call('about', ['--only' => 'events']))->toThrow(
        InvalidConfigurationException::class,
        'Configuration value [events.enabled] must be a boolean',
    );
});

it('reads an integer setting from an env string (strict config)', function (): void {
    // Every env value is a string: a canonical integer string is the number it spells.
    config()->set('events.timeout', '45');

    Artisan::call('about', ['--only' => 'events']);

    expect(Artisan::output())->toMatch('/Timeout\W+45s\b/');
});

it('refuses a junk or out-of-range integer instead of casting it (strict config)', function (mixed $value, string $expected): void {
    // `(int) 'five'` is 0 — a timeout of none. Config::integer() throws instead, naming the key.
    config()->set('events.timeout', $value);

    expect(fn () => Artisan::call('about', ['--only' => 'events']))->toThrow(
        InvalidConfigurationException::class,
        "Configuration value [events.timeout] must be {$expected}",
    );
})->with([
    'word' => ['five', 'an integer'],
    'decimal' => ['5.5', 'an integer'],
    'suffix' => ['30s', 'an integer'],
    'bool' => [true, 'an integer'],
    'zero' => ['0', 'between 1 and 300'],
    'too large' => [301, 'between 1 and 300'],
]);

it('refuses a value outside a fixed vocabulary instead of using the default (strict config)', function (mixed $value): void {
    // Config::oneOf() (or Config::enum() once the package has an enum for it) lists the
    // allowed values; a typo never falls back to the default.
    config()->set('events.mode', $value);

    expect(fn () => Artisan::call('about', ['--only' => 'events']))->toThrow(
        InvalidConfigurationException::class,
        'Configuration value [events.mode] must be one of [sync, queue]',
    );
})->with(['typo' => ['queued'], 'casing' => ['Sync'], 'number' => [1]]);

it('uses the defaults when a key is not set (strict config)', function (?string $value): void {
    // Blank means not set: an absent key, null and a host's `KEY=` (empty or whitespace
    // only) all take the default — on a switch too, where a blank is never read as false.
    config()->set('events.enabled', $value);
    config()->set('events.timeout', $value);
    config()->set('events.mode', $value);

    Artisan::call('about', ['--only' => 'events']);

    expect(Artisan::output())
        ->toMatch('/Enabled\W+YES\b/')
        ->toMatch('/Timeout\W+30s\b/')
        ->toMatch('/Mode\W+sync\b/');
})->with(['null' => [null], 'empty env' => [''], 'whitespace' => ['  ']]);
