<?php

declare(strict_types=1);

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use RoundlyConsulting\Events\Contracts\EventUrls;
use RoundlyConsulting\Events\DataTransferObjects\UpdateEventData;
use RoundlyConsulting\Events\Enums\Visibility;
use RoundlyConsulting\Events\Exceptions\InvalidCheckInCodeException;
use RoundlyConsulting\Events\Facades\Events;
use RoundlyConsulting\Events\Models\Event;
use RoundlyConsulting\Events\Models\EventInvitation;
use RoundlyConsulting\Events\Notifications\EventPostponedNotification;
use RoundlyConsulting\Events\Notifications\EventReminderNotification;
use RoundlyConsulting\Events\Tests\Fixtures\Models\User;
use RoundlyConsulting\Events\Tests\Support\Scenarios;

beforeEach(function (): void {
    Scenarios::freeze();
    config()->set('events.urls.event', 'https://example.com/events/{slug}');
    $this->organizer = User::factory()->create();
    $this->invitee = User::factory()->create();
    $this->stranger = User::factory()->create();
    $this->private = Events::make('Q3 layoffs town hall')->organizedBy($this->organizer)->startsAt('2027-02-12 10:00')
        ->online('https://meet.example.com/secret-room')->visibility(Visibility::Private)->labels(['Confidential'])->publish();
    EventInvitation::factory()->for($this->private)->invitee($this->invitee)->create();
    $this->rsvp = Events::for($this->private)->rsvp($this->invitee)->going();
    $this->public = Scenarios::event(organizer: $this->organizer);
});

afterEach(fn () => Scenarios::thaw());

test('private_events_never_leak', function (): void {
    $private = $this->private;
    $uuid = $private->uuid;
    $missing = (string) Str::uuid();

    // Notices reach only those who may see the event — checked again at delivery.
    Notification::fake();
    Events::for($private)->postpone('Moved');
    Notification::assertSentTo($this->invitee, EventPostponedNotification::class);
    Notification::assertNotSentTo($this->stranger, EventPostponedNotification::class);
    expect((new EventPostponedNotification($private))->shouldSend($this->stranger, 'mail'))->toBeFalse();

    // PHP surfaces.
    expect(Events::browse($this->stranger)->pluck('id')->all())->toBe([$this->public->getKey()])
        ->and(Events::browse()->search('layoffs')->count())->toBe(0)
        ->and(Events::visibleTo($this->stranger)->whereKey($private->getKey())->exists())->toBeFalse()
        ->and(Events::findBySlug((string) $private->slug, $this->stranger))->toBeNull()
        ->and(Events::jsonLd($private))->toBeNull()
        ->and((string) Events::feeds()->public())->not->toContain('layoffs')
        ->and((string) Events::ics(Event::query()->get(), $this->stranger))->not->toContain('layoffs')
        ->and(app(EventUrls::class)->event($private))->toBeNull()
        ->and(fn () => Events::for($private)->by($this->stranger)->counts())->toThrow(AuthorizationException::class)
        // An unscoped scan by a non-manager answers like an unknown code.
        ->and(fn () => Events::checkIn((string) $this->rsvp->checkInCode(), $this->stranger))->toThrow(InvalidCheckInCodeException::class);

    // The API answers a non-viewer exactly like a missing event.
    $notFound = $this->actingAs($this->stranger)->getJson("/api/events/{$missing}")->assertNotFound()->json();

    foreach (['', '/calendar.ics', '/tiers', '/attendees', '/hosts', '/rsvp', '/rsvp/qr.svg', '/waitlist', '/invitations'] as $path) {
        expect($this->actingAs($this->stranger)->getJson("/api/events/{$uuid}{$path}")->assertNotFound()->json())->toBe($notFound, $path);
    }

    foreach (['/cancel', '/check-ins', '/attendees', '/hosts', '/invitations', '/tiers'] as $path) {
        $this->actingAs($this->stranger)->postJson("/api/events/{$uuid}{$path}", ['code' => 'x', 'name' => 'x', 'person' => ['type' => 'user', 'id' => 1], 'attendee' => ['type' => 'user', 'id' => 1]])->assertNotFound();
    }

    $index = $this->actingAs($this->stranger)->getJson('/api/events?filter[search]=layoffs')->assertOk();
    expect($index->json('total'))->toBe(0)
        ->and($this->actingAs($this->stranger)->get('/api/events/feed.ics')->getContent())->not->toContain('layoffs')
        // The private event's label never reaches tags' own API either.
        ->and($this->actingAs($this->stranger)->getJson('/api/tags/events:label/suggest?q=conf')->status())->toBe(404);

    // The invitee sees it.
    $this->actingAs($this->invitee)->getJson("/api/events/{$uuid}")->assertOk()->assertJsonPath('data.online_url', 'https://meet.example.com/secret-room');

});

test('online_url_is_attendee_only', function (): void {
    $event = Events::make('Webinar')->organizedBy($this->organizer)->startsAt('2027-02-12 10:00')->online('https://meet.example.com/join-123')->publish();
    $going = User::factory()->create();
    Events::for($event)->rsvp($going)->going();
    $interested = User::factory()->create();
    Events::for($event)->rsvp($interested)->interested();
    $uri = "/api/events/{$event->uuid}";

    // At rest: encrypted.
    expect((string) DB::table($event->getTable())->where('id', $event->getKey())->value('online_url'))->not->toContain('join-123');

    app('auth')->forgetGuards();
    $this->getJson($uri)->assertOk()->assertJsonPath('data.online_url', null);
    $this->actingAs($interested)->getJson($uri)->assertJsonPath('data.online_url', null);
    $this->actingAs($going)->getJson($uri)->assertJsonPath('data.online_url', 'https://meet.example.com/join-123');
    $this->actingAs($this->organizer)->getJson($uri)->assertJsonPath('data.online_url', 'https://meet.example.com/join-123');

    expect((string) Events::for($event)->ics())->not->toContain('join-123')
        ->and((string) Events::for($event)->ics($going))->toContain('join-123')
        ->and($this->actingAs($interested)->get("{$uri}/calendar.ics")->getContent())->not->toContain('join-123')
        ->and((string) Events::feeds()->public())->not->toContain('join-123')
        ->and(json_encode(Events::jsonLd($event)))->not->toContain('join-123');

    // Notices: the join link only for those who may see it.
    $reminder = new EventReminderNotification($event);
    expect(implode(' ', $reminder->toMail($going)->introLines))->toContain('join-123')
        ->and(implode(' ', $reminder->toMail($interested)->introLines))->not->toContain('join-123')
        ->and(json_encode($reminder->toArray($going)))->not->toContain('join-123');
});

test('check_in_codes_stay_secret_over_the_api', function (): void {
    $code = (string) $this->rsvp->checkInCode();

    $list = $this->actingAs($this->organizer)->getJson("/api/events/{$this->private->uuid}/attendees")->assertOk()->getContent();
    $show = $this->actingAs($this->organizer)->getJson("/api/events/{$this->private->uuid}")->getContent();
    $waitlist = $this->actingAs($this->organizer)->getJson("/api/events/{$this->private->uuid}/waitlist")->getContent();

    expect($list)->not->toContain($code)
        ->and($show)->not->toContain($code)
        ->and($waitlist)->not->toContain($code)
        ->and($this->actingAs($this->invitee)->getJson("/api/events/{$this->private->uuid}/rsvp")->json('data.check_in_code'))->toBe($code);
});

test('private_event_labels_stay_out_of_the_tags_api', function (): void {
    foreach (['/api/tags/events:label', '/api/tags/events:label/suggest?q=con', '/api/tags/events:label/popular', '/api/tag-types/events:label'] as $uri) {
        expect($this->actingAs($this->organizer)->getJson($uri)->status())->toBe(404, $uri);
    }

    // The category tree is public.
    $this->getJson('/api/tags/events:category')->assertOk();
});

test('event_resources_render_no_tag_usage_counts', function (): void {
    Events::categories()->make('Music')->findOrCreate();
    Events::for($this->public)->categorize('music');
    Events::for($this->public)->labels()->attach(['Live']);

    $json = $this->getJson("/api/events/{$this->public->uuid}")->assertOk();
    $body = (string) $json->getContent();

    expect($json->json('data.category'))->toHaveKeys(['id', 'slug', 'name', 'color', 'icon', 'path'])
        ->and($body)->not->toContain('usage_count')
        ->and($body)->not->toContain('scope_type')
        ->and($json->json('data.labels.0'))->toBe(['id' => $json->json('data.labels.0.id'), 'slug' => 'live', 'name' => 'Live', 'color' => null, 'icon' => null]);

    Events::for($this->public)->update(new UpdateEventData(title: 'X'));
});

test('rejects_class_names_as_morph_types', function (): void {
    $uri = "/api/events/{$this->public->uuid}";

    foreach (['App\\Models\\User', User::class, 'User', 'admin', ''] as $type) {
        $response = $this->actingAs($this->organizer)->postJson("{$uri}/attendees", ['attendee' => ['type' => $type, 'id' => $this->stranger->getKey()]]);
        expect($response->status())->toBe(422, $type)
            ->and((string) $response->getContent())->not->toContain('Models');
    }

    $this->actingAs($this->organizer)->deleteJson("{$uri}/hosts/".rawurlencode(User::class.':1'))->assertStatus(422)->assertJsonPath('code', 'unknown_alias');
    $this->actingAs($this->organizer)->getJson('/api/events?filter[organizer]=App%5CModels%5CUser:1')->assertStatus(422)->assertJsonPath('code', 'unknown_alias');
});

test('malformed_keys_never_500', function (): void {
    $uri = "/api/events/{$this->public->uuid}";
    $as = $this->actingAs($this->organizer);

    $as->postJson("{$uri}/attendees", ['attendee' => ['type' => 'user', 'id' => 'not-a-number']])->assertStatus(422);
    $as->postJson("{$uri}/attendees", ['attendee' => ['type' => 'user', 'id' => '99999999999999999999999']])->assertStatus(422);
    $as->postJson("{$uri}/hosts", ['person' => ['type' => 'user', 'id' => ['nested']]])->assertStatus(422);
    $as->deleteJson("{$uri}/attendees/99999999999999999999999")->assertNotFound();
    $as->patchJson("{$uri}/tiers/99999999999999999999999", ['capacity' => 1])->assertNotFound();
    $as->getJson('/api/event-venues/99999999999999999999999')->assertNotFound();
    $as->getJson('/api/events/not-a-uuid')->assertNotFound();
    $as->getJson('/api/events?filter[organizer]=user:abc')->assertStatus(422);
    $as->getJson('/api/events?filter[venue]=abc')->assertStatus(422);
    $as->getJson('/api/events/feed.ics?organizer=user:99999999999999999999999')->assertStatus(422);
    $as->deleteJson("{$uri}/invitations/user")->assertStatus(422);
});
