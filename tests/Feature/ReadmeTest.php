<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use RoundlyConsulting\Events\Enums\EventPhase;
use RoundlyConsulting\Events\Models\Event;
use RoundlyConsulting\Events\Models\Rsvp;
use RoundlyConsulting\Events\Tests\Fixtures\Models\User;

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

    $user = User::factory()->create();
    $attendee = User::factory()->create();
    $visitor = null;
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
        ->and(PhpToken::tokenize('<?php '.$code))->not->toBeEmpty();

    eval($code);

    /** @var Event $event */
    expect($event->phase)->toBe(EventPhase::Scheduled)
        ->and($event->startsAtLocal()->format('Y-m-d H:i'))->toBe('2026-11-14 20:00')
        ->and($event->tiers()->pluck('key')->all())->toBe(['free', 'supporter'])
        ->and($rsvp->isGoing())->toBeTrue()
        ->and($result->firstCheckIn)->toBeTrue();

    CarbonImmutable::setTestNow();
});
