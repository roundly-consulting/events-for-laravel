<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event as EventBus;
use PHPUnit\Framework\ExpectationFailedException;
use RoundlyConsulting\Events\Enums\EventTagType;
use RoundlyConsulting\Events\Events\EventUpdated;
use RoundlyConsulting\Events\Exceptions\InvalidEventsConfigurationException;
use RoundlyConsulting\Events\Facades\Events;
use RoundlyConsulting\Events\Models\Event;
use RoundlyConsulting\Events\Tests\Fixtures\Models\TaggableVenue;
use RoundlyConsulting\Events\Tests\Fixtures\Models\Team;
use RoundlyConsulting\Events\Tests\Fixtures\Models\User;
use RoundlyConsulting\Events\Tests\Support\RecordsLocks;
use RoundlyConsulting\Events\Tests\Support\Scenarios;
use RoundlyConsulting\Tags\Exceptions\TagLimitExceededException;
use RoundlyConsulting\Tags\Exceptions\TagNotFoundException;
use RoundlyConsulting\Tags\Facades\Tags;
use RoundlyConsulting\Tags\Models\Tag;
use RoundlyConsulting\Tags\Types\TagTypeBuilder;

beforeEach(function (): void {
    Scenarios::freeze();
    $this->music = Events::categories()->make(['en' => 'Music', 'sk' => 'Hudba'])->findOrCreate();
    $this->jazz = Events::categories()->make(['en' => 'Jazz', 'sk' => 'Jazz'])->under($this->music)->findOrCreate();
    $this->talks = Events::categories()->make(['en' => 'Talks', 'sk' => 'Prednášky'])->findOrCreate();
});

afterEach(fn () => Scenarios::thaw());

test('tags_integration_declares_both_types', function (): void {
    $category = Events::categories()->definition();
    $label = Events::labels()->definition();

    expect($category->max)->toBe(1)
        ->and($category->curated)->toBeTrue()
        ->and($category->hierarchical)->toBeTrue()
        ->and($category->stableSlugs)->toBeTrue()
        ->and($category->globalOnly)->toBeTrue()
        ->and($category->allows(new Event))->toBeTrue()
        ->and($label->max)->toBe(10)
        ->and($label->curated)->toBeFalse()
        ->and($label->private)->toBeTrue()
        ->and($label->allowsClass(new TaggableVenue))->toBeFalse();

    app()->setLocale('sk');
    expect($category->label())->toBe('Kategória')
        ->and($label->label())->toBe('Štítok');
});

test('tags_integration_category_is_single_and_synced', function (): void {
    EventBus::fake([EventUpdated::class]);
    $event = Scenarios::event();

    Events::for($event)->categorize('jazz');
    Events::for($event)->categorize($this->talks);

    expect($event->fresh()?->category?->is($this->talks))->toBeTrue()
        ->and($event->fresh()?->tagsOf(EventTagType::Category))->toHaveCount(1);
    EventBus::assertDispatched(EventUpdated::class, static fn (EventUpdated $updated): bool => $updated->changed === ['category']);

    // The same category again changes nothing and says nothing.
    EventBus::fake([EventUpdated::class]);
    Events::for($event)->categorize($this->talks->getKey());
    EventBus::assertNotDispatched(EventUpdated::class);

    Events::for($event)->categorize(null);
    expect($event->fresh()?->category)->toBeNull();
});

test('tags_integration_curated_miss_is_refused', function (): void {
    $event = Scenarios::event();

    expect(fn () => Events::for($event)->categorize('polka'))->toThrow(TagNotFoundException::class)
        ->and(Events::categories()->find('polka'))->toBeNull()
        ->and($event->fresh()?->category)->toBeNull();
});

test('tags_integration_labels_are_free_form_and_capped', function (): void {
    $event = Scenarios::event();

    Events::for($event)->labels()->attach(['Live', 'Late night']);
    expect(Events::for($event)->labels()->all()->pluck('slug')->all())->toBe(['live', 'late-night']);

    Events::for($event)->labels()->detach('Live');
    expect($event->fresh()?->labels->pluck('slug')->all())->toBe(['late-night']);

    Events::for($event)->labels()->sync(['Outdoor']);
    expect($event->fresh()?->labels->pluck('slug')->all())->toBe(['outdoor']);

    $eleven = array_map(static fn (int $i): string => 'Label '.$i, range(1, 11));
    expect(fn () => Events::for($event)->labels()->sync($eleven))->toThrow(TagLimitExceededException::class)
        ->and($event->fresh()?->labels->pluck('slug')->all())->toBe(['outdoor']);
});

test('tags_integration_scopes_include_descendants', function (): void {
    $jazz = Event::factory()->published()->categorized('jazz')->labelled(['Live', 'Outdoor'])->create();
    $music = Event::factory()->published()->categorized('music')->labelled(['Live'])->create();
    $talk = Event::factory()->published()->categorized('talks')->create();
    $none = Event::factory()->published()->create();

    $ids = static fn ($query): array => $query->orderBy('id')->pluck('id')->all();

    expect($ids(Event::query()->inCategory('music')))->toBe([$jazz->getKey(), $music->getKey()])
        ->and($ids(Event::query()->inCategory('music', descendants: false)))->toBe([$music->getKey()])
        ->and($ids(Event::query()->inCategory(['jazz', 'talks'])))->toBe([$jazz->getKey(), $talk->getKey()])
        ->and($ids(Event::query()->labelled('live')))->toBe([$jazz->getKey(), $music->getKey()])
        ->and($ids(Event::query()->labelled(['live', 'outdoor'], all: true)))->toBe([$jazz->getKey()])
        ->and($ids(Event::query()->uncategorized()))->toBe([$none->getKey()])
        ->and($ids(Events::browse()->inCategory('jazz')))->toBe([$jazz->getKey()]);
});

test('tags_integration_builder_sets_category_and_labels', function (): void {
    $event = Events::make('Jam')
        ->organizedBy(User::factory()->create())
        ->startsAt('2026-11-20 20:00')
        ->category('jazz')
        ->labels(['Live', 'Late night'])
        ->create();

    expect($event->category?->is($this->jazz))->toBeTrue()
        ->and($event->labels->pluck('slug')->all())->toBe(['live', 'late-night']);

    // A curated miss rolls the whole create back.
    $before = Event::query()->count();
    expect(fn () => Events::make('Nope')->organizedBy(User::factory()->create())->startsAt('2026-11-20 20:00')->category('polka')->create())
        ->toThrow(TagNotFoundException::class)
        ->and(Event::query()->count())->toBe($before);
});

test('tags_integration_both_fakes_record', function (): void {
    $events = Events::fake();
    $tags = Tags::fake();
    $event = Scenarios::event();

    Events::for($event)->categorize('jazz');
    Events::for($event)->labels()->attach(['Live']);

    $events->assertCategorized($event, 'jazz');
    $events->assertLabelled($event, ['Live']);
    $tags->assertSynced($event, EventTagType::Category, ['jazz']);
    $tags->assertTagged($event, ['live'], EventTagType::Label);
    $tags->assertTagCreated();
});

test('tags_integration_host_override_is_respected', function (): void {
    // A host widens labels to its venues and lowers the cap; events keeps working within it.
    Tags::extend(EventTagType::Label, static fn (TagTypeBuilder $type) => $type->on(TaggableVenue::class)->max(2));
    $event = Scenarios::event();

    expect(Events::labels()->definition()->allows(new TaggableVenue))->toBeTrue()
        ->and(Events::labels()->definition()->allows(new Event))->toBeTrue()
        ->and(fn () => Events::for($event)->labels()->attach(['A', 'B', 'C']))->toThrow(TagLimitExceededException::class);

    Events::for($event)->labels()->attach(['A', 'B']);
    expect($event->fresh()?->labels)->toHaveCount(2);
});

test('tags_integration_labels_follow_the_organizer_scope', function (): void {
    config()->set('events.tags.scope', 'organizer');
    $team = Team::factory()->create();
    $other = Team::factory()->create();
    $event = Scenarios::event(organizer: User::factory()->create());
    $event->forceFill(['organizer_type' => $team->getMorphClass(), 'organizer_id' => $team->getKey()])->save();

    Events::for($event)->labels()->attach(['Members only']);
    Events::for($event)->categorize('jazz');

    expect(Events::labels()->within($team)->find('members-only'))->not->toBeNull()
        ->and(Events::labels()->find('members-only'))->toBeNull()
        ->and(Events::labels()->within($other)->find('members-only'))->toBeNull()
        // Categories stay one global tree.
        ->and($event->fresh()?->category?->is($this->jazz))->toBeTrue();

    config()->set('events.tags.scope', 'tenant');
    expect(fn () => $event->tagScope())->toThrow(InvalidEventsConfigurationException::class, 'events.tags.scope');
});

test('tags_integration_locks_the_event_row_before_tags', function (): void {
    $event = Scenarios::event();

    RecordsLocks::start();
    Events::for($event)->labels()->attach(['Live', 'Outdoor']);

    $tables = RecordsLocks::tables();

    expect($tables[0])->toBe('events')
        ->and(array_search('tags', $tables, true))->toBeGreaterThan(0);
})->skip(fn (): bool => ! RecordsLocks::available(), 'the lock recorder runs on sqlite');

test('tags_integration_category_seeding_is_idempotent', function (): void {
    $again = Events::categories()->make(['en' => 'Jazz', 'sk' => 'Jazz'])->under($this->music)->findOrCreate();

    expect($again->is($this->jazz))->toBeTrue()
        ->and(Events::categories()->all())->toHaveCount(3)
        ->and($this->jazz->fresh()?->depth)->toBe($this->music->depth + 1);
});

test('no_package_code_writes_through_the_tags_relation', function (): void {
    $writes = ['attach', 'sync', 'detach', 'toggle', 'syncWithoutDetaching', 'updateExistingPivot'];
    $offenders = [];

    foreach (['src', 'database/factories'] as $directory) {
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(__DIR__.'/../../../'.$directory, FilesystemIterator::SKIP_DOTS));

        foreach ($files as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            // Code tokens only, whitespace and comments dropped: `->tags()->attach(` in any layout.
            $code = '';

            foreach (PhpToken::tokenize((string) file_get_contents($file->getPathname())) as $token) {
                if (! $token->isIgnorable()) {
                    $code .= $token->text;
                }
            }

            foreach ($writes as $write) {
                if (str_contains($code, '->tags()->'.$write.'(')) {
                    $offenders[] = $file->getFilename().': tags()->'.$write;
                }
            }
        }
    }

    expect($offenders)->toBe([]);
});

test('factory_tag_states_use_after_creating', function (): void {
    // Made, not created: the states tag nothing until the row exists.
    $made = Event::factory()->categorized('jazz')->labelled(['Live'])->make();
    expect($made->exists)->toBeFalse()
        ->and(Tag::query()->where('type', EventTagType::Label->value)->count())->toBe(0);

    $created = Event::factory()->categorized('jazz')->labelled(['Live'])->create();
    expect($created->category?->is($this->jazz))->toBeTrue()
        ->and($created->labels->pluck('slug')->all())->toBe(['live']);
});

it('fails the category and label assertions with a clear message', function (): void {
    $fake = Events::fake();
    $event = Scenarios::event();
    $other = Scenarios::event();

    expect(fn () => $fake->assertCategorized())->toThrow(ExpectationFailedException::class, 'Expected a [categorize] event operation')
        ->and(fn () => $fake->assertLabelled())->toThrow(ExpectationFailedException::class, 'Expected a [attach_labels|sync_labels] event operation');

    Events::for($event)->categorize($this->jazz);
    $live = Events::labels()->findOrCreate('Live');
    Events::for($event)->labels()->sync([$live->getKey()]);

    $fake->assertCategorized($event, 'JAZZ');
    $fake->assertCategorized(category: $this->jazz->getKey());
    $fake->assertLabelled($event, [$live]);

    expect(fn () => $fake->assertCategorized($other))->toThrow(ExpectationFailedException::class, 'none matched')
        ->and(fn () => $fake->assertCategorized($event, 'talks'))->toThrow(ExpectationFailedException::class, 'none matched')
        ->and(fn () => $fake->assertLabelled($event, ['Outdoor']))->toThrow(ExpectationFailedException::class, 'none matched')
        ->and(fn () => $fake->assertNothingCategorized())->toThrow(ExpectationFailedException::class, 'Expected no [categorize] event operation')
        ->and(fn () => $fake->assertNothingLabelled())->toThrow(ExpectationFailedException::class, 'Expected no [');

    Events::for($other)->labels()->detach('nothing');
    expect(fn () => $fake->assertLabelled($other))->toThrow(ExpectationFailedException::class, 'none matched');
});

it('passes the nothing-assertions when no tag write ran', function (): void {
    $fake = Events::fake();

    $fake->assertNothingCategorized();
    $fake->assertNothingLabelled();
    expect($fake->recorded())->toBe([]);
});
