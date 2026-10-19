<?php

declare(strict_types=1);

namespace RoundlyConsulting\Events\Exceptions;

use RoundlyConsulting\Events\Support\Clock;
use RoundlyConsulting\Events\Support\Messages;
use RoundlyConsulting\Lifecycle\DataTransferObjects\Denial;
use RoundlyConsulting\Lifecycle\Exceptions\TransitionDeniedException;
use RoundlyConsulting\PackageToolkit\Contracts\HasRetryAfter;

/**
 * Lifecycle refused a status change (wrong state, a guard such as "no attendees", an ended event
 * for `publish`, a rate limit). It wraps lifecycle's exception: `denials()` lists the denial
 * codes, and a rate-limited denial answers 429 with a retry-after.
 */
final class EventTransitionDeniedException extends EventsException implements HasRetryAfter
{
    private ?TransitionDeniedException $denied = null;

    public static function wrap(TransitionDeniedException $denied): self
    {
        $retryAfter = self::retryAfterOf($denied);
        $exception = new self(Messages::get('transition_denied'), 'transition_denied', $retryAfter === null ? 422 : 429);
        $exception->denied = $denied;

        return $exception;
    }

    /**
     * The denial codes, e.g. `['not_allowed_from_state']` or `['no_attendees']`.
     *
     * @return list<string>
     */
    public function denials(): array
    {
        if ($this->denied === null) {
            return [];
        }

        return array_map(static fn (Denial $denial): string => $denial->code, $this->denied->denials());
    }

    public function previousDenial(): ?TransitionDeniedException
    {
        return $this->denied;
    }

    /**
     * Seconds until a rate-limited transition may be tried again (0 when not rate limited).
     */
    public function retryAfterSeconds(): int
    {
        return $this->denied === null ? 0 : (self::retryAfterOf($this->denied) ?? 0);
    }

    private static function retryAfterOf(TransitionDeniedException $denied): ?int
    {
        foreach ($denied->denials() as $denial) {
            if ($denial->retryAfter !== null) {
                return max(1, (int) ceil(Clock::now()->diffInSeconds($denial->retryAfter, false)));
            }
        }

        return null;
    }
}
