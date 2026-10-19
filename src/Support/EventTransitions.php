<?php

declare(strict_types=1);

namespace RoundlyConsulting\Events\Support;

use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\App;
use RoundlyConsulting\Events\DataTransferObjects\TransitionNote;
use RoundlyConsulting\Events\Exceptions\EventTransitionDeniedException;
use RoundlyConsulting\Events\Exceptions\UnknownEventTransitionException;
use RoundlyConsulting\Events\Models\Event;
use RoundlyConsulting\Lifecycle\DataTransferObjects\Denial;
use RoundlyConsulting\Lifecycle\DataTransferObjects\TransitionResult;
use RoundlyConsulting\Lifecycle\Enums\DenialCode;
use RoundlyConsulting\Lifecycle\Exceptions\TransitionDeniedException;
use RoundlyConsulting\Lifecycle\LifecycleManager;

/**
 * Applies a status transition through lifecycle-for-laravel — the only way an event's status
 * changes. The event row is locked first (lifecycle's own lock then nests as a savepoint on the
 * row already held), a trashed event is refused, and lifecycle's denials become
 * {@see EventTransitionDeniedException} (an unknown name: {@see UnknownEventTransitionException}).
 *
 * @internal
 */
final class EventTransitions
{
    /**
     * @param  array<string, mixed>  $payload
     * @param  (Closure(Event): void)|null  $before  runs under the lock, before the transition
     */
    public static function apply(
        Event $event,
        string $transition,
        ?Model $actor,
        bool $system,
        ?string $reason = null,
        array $payload = [],
        bool $silent = false,
        ?Closure $before = null,
    ): TransitionResult {
        App::make(LifecycleCheck::class)->assertValid();

        return EventTransaction::run($event, static function () use ($event, $transition, $actor, $system, $reason, $payload, $silent, $before): TransitionResult {
            LockedEvent::lock($event);

            $note = new TransitionNote($silent, $event->starts_at);

            if ($before !== null) {
                $before($event);
            }

            App::make(TransitionNotes::class)->put($event, $note);

            $handle = App::make(LifecycleManager::class)->for($event, 'status');
            $handle = ($system ? $handle->asSystem() : $handle->by($actor))->because($reason)->with($payload);

            try {
                return $handle->apply($transition);
            } catch (TransitionDeniedException $exception) {
                App::make(TransitionNotes::class)->pull($event);
                $unknown = array_filter($exception->denials(), static fn (Denial $denial): bool => $denial->is(DenialCode::UnknownTransition));

                throw $unknown === [] ? EventTransitionDeniedException::wrap($exception) : UnknownEventTransitionException::named($transition);
            }
        });
    }
}
