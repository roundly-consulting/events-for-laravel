<?php

declare(strict_types=1);

use RoundlyConsulting\Events\Contracts\EventUrls;
use RoundlyConsulting\Events\DataTransferObjects\UpdateEventData;
use RoundlyConsulting\Events\Enums\Visibility;
use RoundlyConsulting\Events\Exceptions\InvalidEventsConfigurationException;
use RoundlyConsulting\Events\Facades\Events;
use RoundlyConsulting\Events\Models\Event;
use RoundlyConsulting\Events\Models\Rsvp;
use RoundlyConsulting\Events\Support\TemplateEventUrls;
use RoundlyConsulting\Events\Tests\Fixtures\Models\User;
use RoundlyConsulting\Events\Tests\Support\Scenarios;
use RoundlyConsulting\PackageToolkit\Exceptions\InvalidConfigurationException;

beforeEach(fn () => Scenarios::freeze());
afterEach(fn () => Scenarios::thaw());

test('urls_resolve_from_templates', function (): void {
    config()->set('events.urls.event', 'https://example.com/{locale}/events/{slug}');
    config()->set('events.urls.invitation', 'https://example.com/invitations/{token}');
    config()->set('events.urls.rsvp', 'https://example.com/events/{uuid}/tickets/{rsvp}');
    $event = Events::make(['en' => 'Jazz Night', 'sk' => 'Jazzový večer'])->organizedBy(User::factory()->create())->startsAt('2027-02-01 20:00')->publish();
    $rsvp = Events::for($event)->rsvp(User::factory()->create())->going();
    $urls = app(EventUrls::class);

    expect($urls)->toBeInstanceOf(TemplateEventUrls::class)
        ->and($urls->event($event))->toBe('https://example.com/en/events/jazz-night')
        ->and($urls->invitation('a b/c'))->toBe('https://example.com/invitations/a%20b%2Fc')
        ->and($urls->rsvp($rsvp))->toBe('https://example.com/events/'.$event->uuid.'/tickets/'.$rsvp->getKey());

    app()->setLocale('sk');
    expect($urls->event($event))->toBe('https://example.com/sk/events/jazzovy-vecer');

    // Blank templates give no link.
    config()->set('events.urls.event', '');
    config()->set('events.urls.invitation', null);
    config()->set('events.urls.rsvp', ' ');
    expect($urls->event($event))->toBeNull()
        ->and($urls->invitation('t'))->toBeNull()
        ->and($urls->rsvp($rsvp))->toBeNull();

    // Only http(s) templates.
    config()->set('events.urls.event', 'javascript:alert({uuid})');
    expect(fn () => $urls->event($event))->toThrow(InvalidEventsConfigurationException::class, 'events.urls.event');
});

test('unlisted_event_urls_never_use_the_slug', function (): void {
    config()->set('events.urls.event', 'https://example.com/events/{slug}');
    $event = Scenarios::event();
    Events::for($event)->update(new UpdateEventData(visibility: Visibility::Unlisted));
    $urls = app(EventUrls::class);
    $event = $event->fresh() ?? $event;

    // No link template: a slug link would 404 for the people it was sent to.
    expect($urls->event($event))->toBeNull();

    config()->set('events.urls.event_link', 'https://example.com/e/{uuid}');
    expect($urls->event($event))->toBe('https://example.com/e/'.$event->uuid);

    Events::for($event)->update(new UpdateEventData(visibility: Visibility::Private));
    expect($urls->event($event->fresh() ?? $event))->toBe('https://example.com/e/'.$event->uuid);

    // A uuid-only event template serves every visibility.
    config()->set('events.urls.event_link', null);
    config()->set('events.urls.event', 'https://example.com/events/{uuid}');
    expect($urls->event($event->fresh() ?? $event))->toBe('https://example.com/events/'.$event->uuid);
});

it('binds a host resolver from config and refuses a non-implementation', function (): void {
    $resolver = new class implements EventUrls
    {
        public function event(Event $event): ?string
        {
            return 'https://host.example/'.$event->uuid;
        }

        public function invitation(string $token): ?string
        {
            return null;
        }

        public function rsvp(Rsvp $rsvp): ?string
        {
            return null;
        }
    };
    app()->instance($resolver::class, $resolver);
    config()->set('events.urls.resolver', $resolver::class);
    $event = Scenarios::event();

    expect(app(EventUrls::class)->event($event))->toBe('https://host.example/'.$event->uuid)
        ->and(Events::jsonLd($event)['url'] ?? null)->toBe('https://host.example/'.$event->uuid);

    config()->set('events.urls.resolver', stdClass::class);
    expect(fn () => app(EventUrls::class))->toThrow(InvalidConfigurationException::class, 'events.urls.resolver');
});
