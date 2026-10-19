<?php

declare(strict_types=1);

namespace RoundlyConsulting\Events\Support;

use Illuminate\Contracts\Container\Container;
use RoundlyConsulting\Events\Contracts\EventLifecycle as EventLifecycleContract;
use RoundlyConsulting\Events\Contracts\EventState;
use RoundlyConsulting\Events\Enums\EventPhase;
use RoundlyConsulting\Events\Exceptions\InvalidEventLifecycleException;
use RoundlyConsulting\Lifecycle\Definition\DefinitionRegistry;
use RoundlyConsulting\Lifecycle\Definition\LifecycleDefinition;

/**
 * Checks the configured lifecycle once per definition class (a container singleton, never a
 * static): it compiles, the event model maps it to `status`, every state implements
 * `Contracts\EventState`, and the initial state is a Draft. The check is lazy — a broken
 * lifecycle throws on first use, never at boot, so artisan keeps working.
 *
 * @internal
 */
final class LifecycleCheck
{
    /** @var array<class-string, true> */
    private array $checked = [];

    public function __construct(
        private readonly Container $container,
    ) {}

    /**
     * @return LifecycleDefinition&EventLifecycleContract
     */
    public function lifecycle(): LifecycleDefinition
    {
        $this->assertValid();
        $class = EventsConfig::lifecycle();

        return new $class;
    }

    public function assertValid(): void
    {
        $class = EventsConfig::lifecycle();

        if (isset($this->checked[$class])) {
            return;
        }

        $problem = $this->problem($class);

        if ($problem !== null) {
            throw InvalidEventLifecycleException::broken($class, $problem);
        }

        $this->checked[$class] = true;
    }

    /**
     * The first reason the lifecycle cannot run events, or null.
     *
     * @param  class-string<LifecycleDefinition&EventLifecycleContract>  $class
     */
    public function problem(string $class): ?string
    {
        $registry = $this->container->make(DefinitionRegistry::class);
        $report = $registry->validate($class);

        if (! $report->isValid()) {
            $errors = $report->errors();

            return 'it does not compile ('.($errors === [] ? 'invalid' : $errors[0]->message()).').';
        }

        $model = EventModels::event();

        if (array_key_first((new $model)->lifecycleDefinitions()) !== 'status') {
            return 'the event model must map it to the status attribute.';
        }

        $definition = $registry->get($class);

        foreach ($definition->values() as $state) {
            if (! $state instanceof EventState) {
                return 'its states must implement the EventState contract.';
            }
        }

        $initial = $definition->value($definition->initial);

        if (! $initial instanceof EventState || $initial->phase() !== EventPhase::Draft) {
            return 'its initial state must be in the Draft phase.';
        }

        return null;
    }
}
