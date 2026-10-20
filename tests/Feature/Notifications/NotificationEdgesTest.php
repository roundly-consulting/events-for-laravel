<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Event as EventBus;
use Illuminate\Support\Facades\Notification;
use RoundlyConsulting\Events\Enums\RsvpStatus;
use RoundlyConsulting\Events\Facades\Events;
use RoundlyConsulting\Events\Notifications\EventCancelledNotification;
use RoundlyConsulting\Events\Tests\Fixtures\Models\User;
use RoundlyConsulting\Events\Tests\Fixtures\Payments\FakePaymentCollector;
use RoundlyConsulting\Events\Tests\Support\Scenarios;
use RoundlyConsulting\Lifecycle\Events\LifecycleTransitioning;
use RoundlyConsulting\Lifecycle\LifecycleManager;
use RoundlyConsulting\Money\Money;

beforeEach(function (): void {
    FakePaymentCollector::reset();
    config()->set('events.payments.collector', FakePaymentCollector::class);
    Notification::fake();
});

afterEach(fn () => Scenarios::thaw());

test('holds_released_by_a_cancellation_are_told_across_a_second_boundary', function (): void {
    Scenarios::freeze('2027-01-01 12:00:00.900');
    $event = Scenarios::event(capacity: 10);
    Events::for($event)->tiers()->add('Supporter', price: Money::ofMajor('15', 'EUR'), key: 'supporter');
    $holder = User::factory()->create();
    $earlier = User::factory()->create();
    expect(Events::for($event)->rsvp($holder)->going(tier: 'supporter')->status)->toBe(RsvpStatus::Held);

    // An unrelated hold that lapsed long before is not told.
    Events::for($event)->rsvp($earlier)->going(tier: 'supporter');
    $earlier->rsvpFor($event)?->forceFill(['status' => RsvpStatus::Expired->value])->save();

    // The clock ticks past a second between the release and lifecycle's cancelled_at stamp.
    EventBus::listen(LifecycleTransitioning::class, static function (): void {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2027-01-01 12:00:01.050', 'UTC'));
    });

    Events::for($event)->cancel('Storm');

    Notification::assertSentTo($holder, EventCancelledNotification::class);
    Notification::assertNotSentTo($earlier, EventCancelledNotification::class);
});

test('holds_released_by_a_lifecycle_cancel_are_told_too', function (): void {
    Scenarios::freeze();
    $event = Scenarios::event(capacity: 10);
    Events::for($event)->tiers()->add('Supporter', price: Money::ofMajor('15', 'EUR'), key: 'supporter');
    $holder = User::factory()->create();
    Events::for($event)->rsvp($holder)->going(tier: 'supporter');

    // Applied through lifecycle itself, without events' own handle.
    app(LifecycleManager::class)->for($event, 'status')->asSystem()->apply('cancel');

    expect($holder->rsvpFor($event)?->status)->toBe(RsvpStatus::Expired);
    Notification::assertSentTo($holder, EventCancelledNotification::class);
});
