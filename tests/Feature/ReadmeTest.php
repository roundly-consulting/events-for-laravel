<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use RoundlyConsulting\Events\Enums\EventPhase;
use RoundlyConsulting\Events\Facades\Events;
use RoundlyConsulting\Events\Models\Event;
use RoundlyConsulting\Events\Models\Rsvp;
use RoundlyConsulting\Events\Tests\Fixtures\Models\User;
use RoundlyConsulting\Geolocation\DataTransferObjects\Coordinates;

/**
 * The README's usage example, run verbatim — every class, method and argument in it is real.
 */
function readmeExample(): string
{
    $readme = (string) file_get_contents(__DIR__.'/../../README.md');
    $usage = substr($readme, (int) strpos($readme, '## Usage'));
    preg_match('/```php\n(.*?)```/s', $usage, $match);

    return $match[1] ?? '';
}

test('readme_example_runs', function (): void {
    // An hour before the doors: publishing, answering and the door scan all fit.
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-11-14 18:00', 'UTC'));
    Storage::fake('local');

    $user = User::factory()->create();
    $doorStaff = $user;   // the organizer scans at the door
    $attendee = User::factory()->create();
    $here = new Coordinates(48.1486, 17.1077);
    $request = Request::create('/events', 'POST', files: ['poster' => UploadedFile::fake()->image('poster.jpg', 1200, 900)]);
    // The code the door app reads off the attendee's QR code.
    $scannedCode = new class
    {
        public function __toString(): string
        {
            return (string) Rsvp::query()->latest('id')->first()?->checkInCode();
        }
    };

    $code = readmeExample();
    expect($code)->toContain('Events::make(')
        ->and(substr_count($code, "\n"))->toBeLessThanOrEqual(45);

    eval($code);

    /** @var Event $event */
    expect($event->phase)->toBe(EventPhase::Scheduled)
        ->and($event->startsAtLocal()->format('Y-m-d H:i'))->toBe('2026-11-14 20:00')
        ->and($event->category?->slug)->toBe('jazz')
        ->and($event->labels->pluck('slug')->all())->toBe(['live', 'late-night'])
        ->and($event->poster)->not->toBeNull()
        ->and($event->tiers()->pluck('key')->all())->toBe(['free', 'supporter'])
        ->and($rsvp->isGoing())->toBeTrue()
        ->and($result->firstCheckIn)->toBeTrue()
        ->and($result->rsvp->checked_in_by_id)->toBe($doorStaff->getKey());

    // The discovery line, again with its result kept: the event is 1 km away.
    $nearby = Events::browse()->upcoming()->near($here, km: 25)->orderByDistance($here)->paginate();
    expect($nearby->pluck('id')->all())->toBe([$event->getKey()]);

    CarbonImmutable::setTestNow();
});
