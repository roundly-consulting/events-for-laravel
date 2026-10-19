<?php

declare(strict_types=1);

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event as EventBus;
use RoundlyConsulting\Events\Enums\CheckInMethod;
use RoundlyConsulting\Events\Enums\RsvpStatus;
use RoundlyConsulting\Events\Events\AttendeeCheckedIn;
use RoundlyConsulting\Events\Events\CheckInUndone;
use RoundlyConsulting\Events\Exceptions\CheckInRefusedException;
use RoundlyConsulting\Events\Exceptions\InvalidCheckInCodeException;
use RoundlyConsulting\Events\Exceptions\InvalidEventsConfigurationException;
use RoundlyConsulting\Events\Exceptions\InvalidGuestCountException;
use RoundlyConsulting\Events\Exceptions\InvalidRsvpTransitionException;
use RoundlyConsulting\Events\Facades\Events;
use RoundlyConsulting\Events\Models\Event;
use RoundlyConsulting\Events\Models\Rsvp;
use RoundlyConsulting\Events\Tests\Fixtures\Models\User;
use RoundlyConsulting\Events\Tests\Support\Scenarios;
use RoundlyConsulting\Lifecycle\Facades\Lifecycles;
use RoundlyConsulting\Qr\ValueObjects\Svg;

beforeEach(fn () => Scenarios::freeze());
afterEach(fn () => Scenarios::thaw());

function atTheDoor(Event $event, int $minutes = 30): void
{
    Scenarios::minutesBeforeStart($event, $minutes);
}

test('check_in_code_lifecycle', function (): void {
    $event = Scenarios::event(capacity: 5);
    $user = User::factory()->create();

    // Issued on entering going.
    $rsvp = Events::for($event)->rsvp($user)->going();
    $code = (string) $rsvp->checkInCode();
    expect($code)->toMatch('/^EV1-[A-Z2-7]{32}$/');

    // Rotated on demand: the old one dies at once.
    Events::rsvps()->rotateCode($rsvp);
    $rotated = (string) $rsvp->checkInCode();
    expect($rotated)->not->toBe($code)
        ->and(Events::rsvps()->findByCode($code))->toBeNull()
        ->and(Events::rsvps()->findByCode($rotated)?->is($rsvp))->toBeTrue();

    // Revoked on leaving going, and a new one on coming back.
    Events::for($event)->rsvp($user)->interested();
    expect($rsvp->fresh()?->checkInCode())->toBeNull()
        ->and($rsvp->fresh()?->check_in_code_hash)->toBeNull()
        ->and(fn () => Events::rsvps()->rotateCode($rsvp->fresh() ?? $rsvp))->toThrow(InvalidRsvpTransitionException::class);

    $back = Events::for($event)->rsvp($user)->going();
    expect($back->checkInCode())->not->toBe($rotated)
        ->and($back->checkInCode())->not->toBeNull();
});

test('qr_encodes_the_current_code', function (): void {
    $event = Scenarios::event(capacity: 5);
    $rsvp = Events::for($event)->rsvp(User::factory()->create())->going();

    $svg = $rsvp->checkInQr();
    expect($svg)->toBeInstanceOf(Svg::class)
        ->and((string) $svg)->toContain('<svg')
        ->and(Events::rsvps()->qr($rsvp)?->toHtml())->toBe((string) $svg);

    // A URL template carries the code in a link instead; check-in reads both.
    config()->set('events.check_in.url', 'https://example.com/check-in/{code}');
    expect((string) $rsvp->checkInQr())->not->toBe((string) $svg);

    // No code, no QR.
    Events::for($event)->rsvp(User::factory()->create())->interested();
    expect(Rsvp::query()->where('status', 'interested')->first()?->checkInQr())->toBeNull();

    config()->set('events.check_in.url', 'ftp://example.com/{code}');
    expect(fn () => $rsvp->checkInQr())->toThrow(InvalidEventsConfigurationException::class, 'events.check_in.url');
});

test('qr_is_never_cached', function (): void {
    $event = Scenarios::event(capacity: 5);
    $rsvp = Events::for($event)->rsvp(User::factory()->create())->going();

    $response = $rsvp->checkInQr()?->toResponse(Request::create('/qr.svg'));
    expect((string) $response?->headers->get('Cache-Control'))->toContain('no-store');

    // The package never renders a check-in code through qr's cacheable <x-qr-code> component:
    // no code (comments aside) nor view of the package uses it.
    $offenders = [];
    $roots = array_filter([__DIR__.'/../../../src', __DIR__.'/../../../resources'], is_dir(...));

    foreach ($roots as $root) {
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root)) as $file) {
            if (! $file->isFile()) {
                continue;
            }

            $source = (string) file_get_contents($file->getPathname());
            $code = str_ends_with($file->getFilename(), '.blade.php') || $file->getExtension() !== 'php'
                ? $source
                : implode('', array_map(static fn (mixed $token): string => is_array($token) ? (in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true) ? '' : $token[1]) : $token, token_get_all($source)));

            if (str_contains($code, 'x-qr-code')) {
                $offenders[] = $file->getFilename();
            }
        }
    }

    expect($offenders)->toBe([]);
});

test('check_in_codes_stay_secret', function (): void {
    EventBus::fake([AttendeeCheckedIn::class]);
    $event = Scenarios::event(capacity: 5);
    $rsvp = Events::for($event)->rsvp(User::factory()->create())->going();
    $code = (string) $rsvp->checkInCode();

    // 160 random bits, base32 (5 bits a character).
    expect(strlen(substr($code, 4)) * 5)->toBe(160);

    // Encrypted at rest and hashed for lookup; never in arrays or JSON.
    $raw = (array) DB::table('event_rsvps')->where('id', $rsvp->getKey())->first();
    expect((string) $raw['check_in_code'])->not->toContain(substr($code, 4))
        ->and($raw['check_in_code_hash'])->toHaveLength(64)
        ->and($rsvp->toArray())->not->toHaveKeys(['check_in_code', 'check_in_code_hash'])
        ->and($rsvp->toJson())->not->toContain(substr($code, 4));

    // Nor in the check-in event.
    atTheDoor($event);
    Events::for($event)->checkIn($code);
    EventBus::assertDispatched(AttendeeCheckedIn::class, static fn (AttendeeCheckedIn $checkedIn): bool => ! str_contains(serialize($checkedIn), substr($code, 4)));
});

test('check_in_is_scoped', function (): void {
    $event = Scenarios::event(capacity: 5);
    $other = Scenarios::event(capacity: 5);
    $mine = Events::for($event)->rsvp(User::factory()->create())->going();
    $theirs = Events::for($other)->rsvp(User::factory()->create())->going();
    atTheDoor($event);

    // Another event's code: refused, without naming the other event.
    try {
        Events::for($event)->checkIn((string) $theirs->checkInCode());
        $this->fail('Expected a refusal.');
    } catch (CheckInRefusedException $refused) {
        expect($refused->reason())->toBe('wrong_event')
            ->and($refused->getMessage())->toBe('This code is for another event.')
            ->and($refused->status())->toBe(409)
            ->and($refused->getMessage())->not->toContain((string) $other->title);
    }

    // Unknown, malformed and revoked codes read the same.
    $revoked = (string) $mine->checkInCode();
    Events::rsvps()->rotateCode($mine);

    foreach (['EV1-AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA', 'hello', $revoked] as $input) {
        expect(fn () => Events::for($event)->checkIn($input))->toThrow(InvalidCheckInCodeException::class, 'The check-in code is not valid.');
    }

    // A URL payload or a scanner suffix still reads.
    $result = Events::for($event)->checkIn('https://example.com/check-in/'.strtolower((string) $mine->fresh()?->checkInCode()).'?src=app');
    expect($result->firstCheckIn)->toBeTrue();
});

test('check_in_window', function (): void {
    $event = Scenarios::event(capacity: 5, maxGuests: 2);
    $rsvp = Events::for($event)->rsvp(User::factory()->create())->going(guests: 2);
    $code = (string) $rsvp->checkInCode();

    // Opens check_in.opens_before (3 h) the start, closes check_in.closes_after (2 h) the end.
    Scenarios::minutesBeforeStart($event, 181);
    expect(fn () => Events::for($event)->checkIn($code))->toThrow(CheckInRefusedException::class, 'Check-in is not open now.');

    // ignoreWindow lets a manager scan early.
    expect(Events::for($event)->checkIn($code, guests: 0, ignoreWindow: true)->firstCheckIn)->toBeTrue();

    // A later scan with more guests (a friend arrives late) raises the count, never lowers it.
    Scenarios::minutesBeforeStart($event, 10);
    $later = Events::for($event)->checkIn($code, guests: 2);
    expect($later->firstCheckIn)->toBeFalse()
        ->and($later->guests)->toBe(2)
        ->and(Events::for($event)->checkIn($code, guests: 1)->guests)->toBe(2)
        ->and(fn () => Events::for($event)->checkIn($code, guests: 3))->toThrow(InvalidGuestCountException::class);

    // Completed (it completes at its end) still checks in until the door closes.
    Scenarios::freeze($event->ends_at->addMinutes(119)->format('Y-m-d H:i:s'));
    Lifecycles::sweep();
    expect($event->fresh()?->phase->value)->toBe('completed')
        ->and(Events::for($event)->checkIn($code)->firstCheckIn)->toBeFalse();

    Scenarios::freeze($event->ends_at->addMinutes(121)->format('Y-m-d H:i:s'));
    expect(fn () => Events::for($event)->checkIn($code))->toThrow(CheckInRefusedException::class);

    // A postponed or cancelled event takes no check-in.
    Scenarios::freeze();
    $postponed = Scenarios::event(capacity: 5);
    $guest = Events::for($postponed)->rsvp(User::factory()->create())->going();
    Events::for($postponed)->postpone();
    atTheDoor($postponed);

    try {
        Events::for($postponed)->checkIn((string) $guest->checkInCode());
        $this->fail('Expected a refusal.');
    } catch (CheckInRefusedException $refused) {
        expect($refused->reason())->toBe('event_not_active');
    }
});

test('manual_check_in_and_undo', function (): void {
    EventBus::fake([AttendeeCheckedIn::class, CheckInUndone::class]);
    $event = Scenarios::event(capacity: 5, maxGuests: 1);
    $walkIn = User::factory()->create();
    $interested = User::factory()->create();
    $organizer = $event->organizer;

    // A walk-in is added first, then checked in by hand — any time, no code.
    Events::for($event)->attendees()->add($walkIn, guests: 1);
    $result = Events::for($event)->by($organizer)->attendees()->checkIn($walkIn, guests: 1);

    expect($result->firstCheckIn)->toBeTrue()
        ->and($result->method)->toBe(CheckInMethod::Manual)
        ->and($result->guests)->toBe(1)
        ->and($result->attendee->is($walkIn))->toBeTrue()
        ->and($result->rsvp->checked_in_by_id)->toBe($organizer?->getKey())
        ->and(Events::for($event)->attendees()->checkedIn()->pluck('attendee_id')->all())->toBe([$walkIn->getKey()]);
    EventBus::assertDispatched(AttendeeCheckedIn::class);

    // Not going: add them first.
    Events::for($event)->rsvp($interested)->interested();
    expect(fn () => Events::for($event)->attendees()->checkIn($interested))->toThrow(InvalidRsvpTransitionException::class, 'The attendee is not going.');

    Events::for($event)->attendees()->undoCheckIn($walkIn);
    expect($walkIn->rsvpFor($event)?->isCheckedIn())->toBeFalse()
        ->and($walkIn->rsvpFor($event)?->status)->toBe(RsvpStatus::Going);
    EventBus::assertDispatched(CheckInUndone::class);

    // Undoing nothing changes nothing.
    Events::for($event)->attendees()->undoCheckIn($walkIn);
    EventBus::assertDispatchedTimes(CheckInUndone::class, 1);
});

test('only_managers_check_in', function (): void {
    $event = Scenarios::event(capacity: 5);
    $rsvp = Events::for($event)->rsvp(User::factory()->create())->going();
    $stranger = User::factory()->create();
    atTheDoor($event);

    expect(fn () => Events::for($event)->by($stranger)->checkIn((string) $rsvp->checkInCode()))->toThrow(AuthorizationException::class)
        ->and(fn () => Events::for($event)->by($stranger)->attendees()->checkIn($rsvp))->toThrow(AuthorizationException::class)
        ->and(Events::for($event)->by($event->organizer)->checkIn((string) $rsvp->checkInCode())->firstCheckIn)->toBeTrue();
});

test('unscoped_check_in_authorizes_on_the_codes_event', function (): void {
    $festival = Scenarios::event(capacity: 5);
    $other = Scenarios::event(capacity: 5);
    $rsvp = Events::for($festival)->rsvp(User::factory()->create())->going();
    $foreign = Events::for($other)->rsvp(User::factory()->create())->going();
    $gate = $festival->organizer;
    atTheDoor($festival);

    // The code finds its event; the gate staff manages it.
    $result = Events::checkIn((string) $rsvp->checkInCode(), by: $gate);
    expect($result->firstCheckIn)->toBeTrue()
        ->and($result->rsvp->event_id)->toBe($festival->getKey());

    // A code of an event the staff does not manage reads like an unknown code: no oracle.
    expect(fn () => Events::checkIn((string) $foreign->checkInCode(), by: $gate))
        ->toThrow(InvalidCheckInCodeException::class, 'The check-in code is not valid.');
});
