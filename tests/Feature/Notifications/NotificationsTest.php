<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use RoundlyConsulting\Events\DataTransferObjects\UpdateEventData;
use RoundlyConsulting\Events\Enums\Visibility;
use RoundlyConsulting\Events\Facades\Events;
use RoundlyConsulting\Events\Models\EventInvitation;
use RoundlyConsulting\Events\Notifications\EventCancelledNotification;
use RoundlyConsulting\Events\Notifications\EventInvitationNotification;
use RoundlyConsulting\Events\Notifications\EventMovedOnlineNotification;
use RoundlyConsulting\Events\Notifications\EventPostponedNotification;
use RoundlyConsulting\Events\Notifications\EventReminderNotification;
use RoundlyConsulting\Events\Notifications\EventRescheduledNotification;
use RoundlyConsulting\Events\Notifications\EventUpdatedNotification;
use RoundlyConsulting\Events\Notifications\RsvpConfirmedNotification;
use RoundlyConsulting\Events\Notifications\WaitlistPromotedNotification;
use RoundlyConsulting\Events\Tests\Fixtures\Models\Guest;
use RoundlyConsulting\Events\Tests\Fixtures\Models\User;
use RoundlyConsulting\Events\Tests\Fixtures\Notifications\EventCancelledWithRefund;
use RoundlyConsulting\Events\Tests\Fixtures\Notifications\ExplodingCancelledNotification;
use RoundlyConsulting\Events\Tests\Fixtures\Payments\FakePaymentCollector;
use RoundlyConsulting\Events\Tests\Support\Scenarios;
use RoundlyConsulting\Money\Money;
use RoundlyConsulting\PackageToolkit\Exceptions\InvalidConfigurationException;

beforeEach(function (): void {
    Scenarios::freeze();
    config()->set('events.urls.event', 'https://example.com/events/{uuid}');
    config()->set('events.urls.rsvp', 'https://example.com/tickets/{rsvp}');
    config()->set('events.urls.invitation', 'https://example.com/invitations/{token}');
    $this->organizer = User::factory()->create();
    $this->going = User::factory()->create();
    $this->interested = User::factory()->create();
    $this->event = Scenarios::event(capacity: 1, organizer: $this->organizer);
    Events::for($this->event)->rsvp($this->going)->going();
    Events::for($this->event)->rsvp($this->interested)->interested();
});

afterEach(fn () => Scenarios::thaw());

it('tells each audience about event-wide changes, the actor left out', function (): void {
    Notification::fake();
    $waiting = User::factory()->create();
    Events::for($this->event)->rsvp($waiting)->going();

    Events::for($this->event)->by($this->organizer)->update(new UpdateEventData(endsAt: '2027-02-10 23:30'));
    Notification::assertSentTo($this->going, EventUpdatedNotification::class);
    Notification::assertNotSentTo([$this->interested, $waiting], EventUpdatedNotification::class);

    // A title fix is no notice.
    Events::for($this->event)->update(new UpdateEventData(title: 'Typo fixed'));
    Notification::assertSentTimes(EventUpdatedNotification::class, 1);

    Events::for($this->event)->by($this->organizer)->postpone('Storm');
    Notification::assertSentTo([$this->going, $this->interested, $waiting], EventPostponedNotification::class);
    Notification::assertNotSentTo($this->organizer, EventPostponedNotification::class);

    Events::for($this->event)->by($this->organizer)->reschedule('2027-02-20 20:00', reason: 'New date');
    Notification::assertSentTo($this->going, EventRescheduledNotification::class, static fn (EventRescheduledNotification $notice): bool => $notice->data['reason'] === 'New date');

    Events::for($this->event)->moveOnline('https://meet.example.com/x');
    Notification::assertSentTo($this->going, EventMovedOnlineNotification::class);
    Notification::assertNotSentTo($this->interested, EventMovedOnlineNotification::class);

    Events::for($this->event)->cancel('Flood');
    Notification::assertSentTo([$this->going, $this->interested, $waiting], EventCancelledNotification::class);
});

it('confirms seats, promotions and invitations to the person concerned', function (): void {
    Notification::fake();
    $event = Scenarios::event(capacity: 1);
    $first = User::factory()->create();
    $second = User::factory()->create();

    Events::for($event)->rsvp($first)->going();
    Notification::assertSentTo($first, RsvpConfirmedNotification::class);

    Events::for($event)->rsvp($second)->going();
    Events::for($event)->rsvp($first)->notGoing();
    Notification::assertSentTo($second, WaitlistPromotedNotification::class);

    $invitee = User::factory()->create();
    Events::for($event)->invitations()->send($invitee, 'Come!');
    Notification::assertSentTo($invitee, EventInvitationNotification::class, static fn (EventInvitationNotification $notice): bool => $notice->data['message'] === 'Come!');

    // A guest account without notification routes is skipped, never an error.
    Events::for($event)->invitations()->send(Guest::factory()->create());
});

test('notification_swap_is_honoured', function (): void {
    Notification::fake();
    config()->set('events.notifications.types.cancelled', EventCancelledWithRefund::class);

    Events::for($this->event)->cancel();
    Notification::assertSentTo($this->going, EventCancelledWithRefund::class);
    $mail = (new EventCancelledWithRefund($this->event->fresh() ?? $this->event))->toMail($this->going);
    expect([...$mail->introLines, ...$mail->outroLines])->toContain('Your ticket is refunded within 5 days.');

    // Off: nothing.
    $other = Scenarios::event();
    Events::for($other)->rsvp($this->going)->going();
    config()->set('events.notifications.types.cancelled', false);
    Events::for($other)->cancel();
    Notification::assertSentTimes(EventCancelledWithRefund::class, 2);

    // A class that is not the package's own subtype is refused, naming the key.
    config()->set('events.notifications.types.cancelled', EventPostponedNotification::class);
    $third = Scenarios::event();
    Events::for($third)->rsvp($this->going)->going();
    expect(fn () => Events::for($third)->cancel())->toThrow(InvalidConfigurationException::class, 'events.notifications.types.cancelled');

    // All off.
    config()->set('events.notifications.types.cancelled', null);
    config()->set('events.notifications.enabled', 'off');
    $fourth = Scenarios::event();
    Events::for($fourth)->rsvp($this->going)->going();
    Events::for($fourth)->cancel();
    Notification::assertSentTimes(EventCancelledNotification::class, 0);
});

test('notifications_after_commit', function (): void {
    Notification::fake();

    // A rolled-back write sends nothing.
    try {
        DB::transaction(function (): void {
            Events::for($this->event)->cancel('Flood');

            throw new RuntimeException('roll back');
        });
    } catch (RuntimeException) {
    }

    Notification::assertNothingSent();
    expect($this->event->fresh()?->cancelled_at)->toBeNull();

    // A failing notice is reported; the cancellation stands.
    config()->set('events.notifications.types.cancelled', ExplodingCancelledNotification::class);
    Events::for($this->event)->cancel('Flood');
    expect($this->event->fresh()?->cancelled_at)->not->toBeNull();

    // Queued, after the commit, on the configured queue.
    config()->set('events.notifications.queue', 'mail');
    $notice = new EventCancelledNotification($this->event);
    expect($notice->afterCommit)->toBeTrue()
        ->and($notice->queue)->toBe('mail')
        ->and($notice->via($this->going))->toBe(['mail']);
});

test('notifications_respect_visibility', function (): void {
    $notice = new EventPostponedNotification($this->event, data: ['reason' => 'Storm']);

    expect($notice->shouldSend($this->going, 'mail'))->toBeTrue();

    // Declined before delivery: dropped.
    Events::for($this->event)->rsvp($this->going)->notGoing();
    expect($notice->shouldSend($this->going, 'mail'))->toBeFalse();

    // Made private: an interested stranger without an invitation may no longer see it.
    Events::for($this->event)->update(new UpdateEventData(visibility: Visibility::Private));
    $stranger = User::factory()->create();
    expect((new EventPostponedNotification($this->event))->shouldSend($stranger, 'mail'))->toBeFalse();

    // The invitation notice only while the invitation is pending; deleted events tell nobody.
    $invitee = User::factory()->create();
    EventInvitation::factory()->for($this->event)->invitee($invitee)->create();
    expect((new EventInvitationNotification($this->event))->shouldSend($invitee, 'mail'))->toBeTrue();
    Events::for($this->event)->delete(force: false);
    expect((new EventInvitationNotification($this->event))->shouldSend($invitee, 'mail'))->toBeFalse();

    // No other attendee's data in the payload.
    $payload = (new RsvpConfirmedNotification($this->event))->toArray($this->interested);
    expect(array_keys($payload))->toBe(['type', 'event', 'rsvp'])
        ->and(array_keys($payload['event']))->toBe(['id', 'title', 'starts_at', 'timezone', 'all_day'])
        ->and($payload['rsvp'])->toBeNull();
});

test('silent_writes_notify_nobody', function (): void {
    Notification::fake();

    Events::for($this->event)->silently()->update(new UpdateEventData(endsAt: '2027-02-10 23:30'));
    Events::for($this->event)->silently()->postpone();
    Events::for($this->event)->silently()->reschedule('2027-02-20 20:00');
    Events::for($this->event)->silently()->moveOnline('https://meet.example.com/x');
    Events::for($this->event)->silently()->cancel();

    Notification::assertNothingSent();
});

it('writes each notice in the recipient locale with the event local time', function (): void {
    $event = Events::make(['en' => 'Jazz Night', 'sk' => 'Jazzový večer'])->organizedBy($this->organizer)
        ->timezone('Europe/Bratislava')->startsAt('2027-02-12 20:00')->publish();
    $rsvp = Events::for($event)->rsvp($this->going)->going();

    $mail = (new RsvpConfirmedNotification($event, $rsvp))->toMail($this->going);
    expect($mail->subject)->toBe('You are going: Jazz Night')
        ->and($mail->introLines[0])->toContain('February 12, 2027 8:00 PM CET')
        ->and(implode(' ', $mail->introLines))->toContain((string) $rsvp->checkInCode())
        ->and($mail->actionUrl)->toBe('https://example.com/tickets/'.$rsvp->getKey());

    app()->setLocale('sk');
    $sk = (new RsvpConfirmedNotification($event, $rsvp))->toMail($this->going);
    expect($sk->subject)->toBe('Idete: Jazzový večer')
        ->and($sk->introLines[0])->toContain('2027')->toContain('20:00 CET');

    // The database channel never stores the code.
    expect(json_encode((new RsvpConfirmedNotification($event, $rsvp))->toArray($this->going)))->not->toContain((string) $rsvp->checkInCode());
});

it('links invitations, payments and join links only where they belong', function (): void {
    FakePaymentCollector::reset();
    config()->set('events.payments.collector', FakePaymentCollector::class);
    $event = Scenarios::event(capacity: 1);
    Events::for($event)->tiers()->add('Paid', price: Money::ofMajor('10', 'EUR'), key: 'paid');
    $held = Events::for($event)->rsvp($this->going)->going();
    Events::for($event)->rsvp($this->interested)->going();

    $invitation = Events::for($event)->invitations()->send(User::factory()->create(), 'Hi');
    $invite = (new EventInvitationNotification($event, data: ['token' => $invitation->plainToken, 'message' => 'Hi']));
    expect($invite->toMail($this->going)->actionUrl)->toBe('https://example.com/invitations/'.$invitation->plainToken)
        ->and(json_encode($invite->toArray($this->going)))->not->toContain((string) $invitation->plainToken);

    $promoted = (new WaitlistPromotedNotification($event, $held->fresh()))->toMail($this->going);
    expect($promoted->actionUrl)->toBe($held->fresh()?->payment_url)
        ->and(implode(' ', $promoted->introLines))->toContain('held for you');

    Events::for($event)->moveOnline('https://meet.example.com/secret');
    $moved = new EventMovedOnlineNotification($event->fresh() ?? $event);
    expect(implode(' ', $moved->toMail($event->organizer ?? $this->organizer)->introLines))->toContain('meet.example.com/secret')
        ->and(implode(' ', $moved->toMail($this->interested)->introLines))->not->toContain('meet.example.com/secret');
});

it('renders every notice in english and slovak without a raw key', function (string $locale): void {
    app()->setLocale($locale);
    Events::for($this->event)->moveOnline('https://meet.example.com/x');
    $event = $this->event->fresh() ?? $this->event;
    $rsvp = Events::for($event)->attendees()->find($this->going);

    $notices = [
        new EventInvitationNotification($event, data: ['token' => 'tok', 'message' => 'Hello']),
        new RsvpConfirmedNotification($event, $rsvp),
        new WaitlistPromotedNotification($event, $rsvp),
        new EventReminderNotification($event, data: ['offset' => 'P1D']),
        new EventUpdatedNotification($event, data: ['changed' => ['online_url', 'starts_at']]),
        new EventPostponedNotification($event, data: ['reason' => 'Storm']),
        new EventRescheduledNotification($event, data: ['previous_starts_at' => '2027-02-01T20:00:00+00:00', 'reason' => 'Band ill']),
        new EventCancelledNotification($event, data: ['reason' => 'Flood']),
        new EventMovedOnlineNotification($event),
    ];

    foreach ($notices as $notice) {
        $mail = $notice->toMail($this->going);
        $text = implode("\n", [$mail->subject, $mail->greeting, ...$mail->introLines, ...$mail->outroLines]);

        expect($text)->not->toContain('events::')
            ->and($text)->toContain((string) $event->title)
            ->and(json_encode($notice->toArray($this->going)))->not->toContain('\\\\')
            ->and($notice->toArray($this->going)['type'])->toBe($notice->type());
    }

    expect(implode(' ', $notices[4]->toMail($this->going)->introLines))->toContain('meet.example.com/x')
        ->and(implode(' ', $notices[6]->toMail($this->going)->introLines))->toContain('2027');
})->with(['en', 'sk']);
