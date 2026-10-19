<?php

declare(strict_types=1);

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Event as EventBus;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use RoundlyConsulting\Events\Events\PosterChanged;
use RoundlyConsulting\Events\Facades\Events;
use RoundlyConsulting\Events\Models\Event;
use RoundlyConsulting\Events\Support\EventModels;
use RoundlyConsulting\Events\Tests\Fixtures\Models\User;
use RoundlyConsulting\MediaLibrary\Exceptions\FileUnacceptableForBucket;
use RoundlyConsulting\MediaLibrary\Facades\MediaLibrary;
use RoundlyConsulting\MediaLibrary\Models\Media;

beforeEach(function (): void {
    Storage::fake('local');
    $this->user = User::factory()->create();
});

test('poster_uploads_are_validated', function (): void {
    $event = Events::make('Gig')->organizedBy($this->user)->startsAt('2027-02-10 20:00')
        ->poster(UploadedFile::fake()->image('poster.jpg', 1200, 800))
        ->create();

    expect($event->poster)->toBeInstanceOf(Media::class)
        ->and($event->poster?->mime_type)->toBe('image/jpeg');

    // Wrong type, too small, too large: refused by the bucket.
    expect(fn () => Events::for($event)->poster(UploadedFile::fake()->create('notes.txt', 10, 'text/plain')))->toThrow(FileUnacceptableForBucket::class, 'is not accepted by media bucket [poster]')
        ->and(fn () => Events::for($event)->poster(UploadedFile::fake()->image('tiny.png', 100, 100)))->toThrow(FileUnacceptableForBucket::class, 'The image dimensions (100x100)');

    // The API validates with media's own rules for the bucket.
    $rules = implode('|', MediaLibrary::rulesFor(EventModels::event(), 'poster'));
    expect($rules)->toContain('image/jpeg')
        ->toContain('max:10240')
        ->toContain('dimensions:min_width=600,min_height=315');

    config()->set('events.poster.max_kb', 1);
    expect(fn () => Events::for(Event::factory()->create())->poster(UploadedFile::fake()->image('big.jpg', 2000, 2000)->size(500)))->toThrow(FileUnacceptableForBucket::class, 'larger than the 1024-byte limit');

    // Still the first poster: a refused upload changes nothing.
    expect($event->fresh()?->poster?->file_name)->toBe('poster.jpg');
});

test('poster_url_falls_back', function (): void {
    EventBus::fake([PosterChanged::class]);
    $event = Event::factory()->create();

    // No poster: the configured fallback, else null.
    expect($event->posterUrl())->toBeNull();
    config()->set('events.poster.fallback_url', 'https://cdn.example.com/default.png');
    expect($event->posterUrl('card'))->toBe('https://cdn.example.com/default.png');

    // Variants are queued: until one is generated, the original answers instead of a throw.
    Queue::fake();
    Events::for($event)->poster(UploadedFile::fake()->image('poster.png', 1600, 900));
    EventBus::assertDispatched(PosterChanged::class);

    $original = $event->posterUrl();
    expect($original)->toBeString()
        ->and($event->posterUrl('card'))->toBe($original)
        ->and($event->poster?->hasGeneratedVariant('card'))->toBeFalse();

    // Removing it.
    Events::for($event)->poster();
    expect($event->fresh()?->poster)->toBeNull();
});

it('generates the configured variants', function (): void {
    $event = Event::factory()->create();
    Events::for($event)->poster(UploadedFile::fake()->image('poster.jpg', 1920, 1080));

    $poster = $event->fresh()?->poster;

    expect($poster?->hasGeneratedVariant('card'))->toBeTrue()
        ->and($poster?->hasGeneratedVariant('square'))->toBeTrue()
        ->and($event->fresh()?->posterUrl('card'))->not->toBe($event->fresh()?->posterUrl());
});

it('binds an upload-first draft', function (): void {
    $draft = MediaLibrary::draft(UploadedFile::fake()->image('draft.jpg', 1200, 700))->toMediaBucket('poster');
    $token = (string) $draft->getAttribute('draft_token');

    $event = Events::make('Drafted')->organizedBy($this->user)->startsAt('2027-02-10 20:00')->poster(draft: $token)->create();

    expect($event->poster?->is($draft))->toBeTrue();
})->skip(fn (): bool => ! method_exists(Media::class, 'scopeDrafts'), 'media-library drafts unavailable');

test('force_deleting_an_event_removes_its_poster', function (): void {
    $event = Event::factory()->create();
    Events::for($event)->poster(UploadedFile::fake()->image('poster.jpg', 1200, 800));
    $poster = $event->fresh()?->poster;
    $path = (string) $poster?->getPath();

    // A soft delete keeps it for a restore.
    Events::for($event)->delete();
    expect(Media::query()->whereKey($poster?->getKey())->exists())->toBeTrue();

    Events::for($event)->restore()->delete(force: true);

    expect(Media::query()->whereKey($poster?->getKey())->exists())->toBeFalse()
        ->and(Storage::disk('local')->exists($path))->toBeFalse();
});
