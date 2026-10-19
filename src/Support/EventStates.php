<?php

declare(strict_types=1);

namespace RoundlyConsulting\Events\Support;

use BackedEnum;
use Illuminate\Support\Facades\App;
use RoundlyConsulting\Events\Contracts\EventState;
use RoundlyConsulting\Events\Enums\EventPhase;
use RoundlyConsulting\Events\Enums\EventVerb;
use RoundlyConsulting\Events\Exceptions\InvalidEventLifecycleException;
use RoundlyConsulting\Events\Exceptions\UnknownEventTransitionException;
use RoundlyConsulting\Events\Models\Event;
use RoundlyConsulting\Lifecycle\Definition\CompiledDefinition;
use RoundlyConsulting\Lifecycle\Definition\DefinitionRegistry;

/**
 * Reads the configured lifecycle in the package's terms: the phase of a state, the stored values
 * of a set of phases (for scopes), the transition behind a verb. Package logic never names a
 * concrete state, so a host lifecycle (an approval step, say) runs everything else unchanged.
 *
 * @internal
 */
final class EventStates
{
    public static function phaseOf(Event $event): EventPhase
    {
        $status = $event->status;

        if ($status === null) {
            return EventPhase::Draft;
        }

        if (! $status instanceof EventState) {
            throw InvalidEventLifecycleException::broken(EventsConfig::lifecycle(), 'its states must implement the EventState contract.');
        }

        return $status->phase();
    }

    /**
     * The stored values of every state in the given phases.
     *
     * @return list<string|int>
     */
    public static function valuesIn(EventPhase ...$phases): array
    {
        $definition = self::definition();
        $values = [];

        foreach ($definition->values() as $state) {
            if ($state instanceof EventState && in_array($state->phase(), $phases, true)) {
                $values[] = $definition->encode($state);
            }
        }

        return $values;
    }

    /**
     * The transition the lifecycle maps the verb to; throws when it has none.
     */
    public static function transitionFor(EventVerb $verb): string
    {
        $lifecycle = App::make(LifecycleCheck::class)->lifecycle();

        return $lifecycle->transitionFor($verb) ?? throw UnknownEventTransitionException::forVerb($verb->value);
    }

    public static function definition(): CompiledDefinition
    {
        App::make(LifecycleCheck::class)->assertValid();

        return App::make(DefinitionRegistry::class)->get(EventsConfig::lifecycle());
    }

    /**
     * A state's stored value, for raw queries.
     */
    public static function stored(BackedEnum|string $state): string|int
    {
        return $state instanceof BackedEnum ? $state->value : $state;
    }
}
