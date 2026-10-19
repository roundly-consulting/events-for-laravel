<?php

declare(strict_types=1);

use Illuminate\Support\Arr;

/**
 * English and Slovak ship the same keys and placeholders in every file, and the Slovak lines
 * resolve through the package namespace.
 */
$files = ['messages', 'enums'];

/**
 * @return array<string, string>
 */
function eventLines(string $locale, string $file): array
{
    /** @var array<string, mixed> $lines */
    $lines = require __DIR__.'/../../resources/lang/'.$locale.'/'.$file.'.php';

    return array_map(strval(...), Arr::dot($lines));
}

/**
 * @return list<string>
 */
function eventPlaceholders(string $line): array
{
    preg_match_all('/:([a-z_]+)/', $line, $matches);
    $placeholders = array_values(array_unique($matches[1]));
    sort($placeholders);

    return $placeholders;
}

it('ships the same keys in english and slovak', function (string $file): void {
    expect(array_keys(eventLines('sk', $file)))->toBe(array_keys(eventLines('en', $file)));
})->with($files);

it('ships the same placeholders per key in english and slovak', function (string $file): void {
    $sk = eventLines('sk', $file);

    foreach (eventLines('en', $file) as $key => $line) {
        expect(eventPlaceholders($sk[$key]))->toBe(eventPlaceholders($line), "{$file}.{$key}");
    }
})->with($files);

it('ships every language file in both locales', function (): void {
    $names = static fn (string $locale): array => array_map(basename(...), glob(__DIR__.'/../../resources/lang/'.$locale.'/*.php') ?: []);

    expect($names('sk'))->toBe($names('en'))
        ->and($names('en'))->toBe(array_map(static fn (string $file): string => $file.'.php', ['enums', 'messages']));
});

it('resolves slovak through the package namespace', function (): void {
    app()->setLocale('sk');

    expect(__('events::messages.event_full'))->toBe('Podujatie je plne obsadené.')
        ->and(__('events::enums.rsvp_status.waitlisted'))->toBe('Na čakacej listine');
});
