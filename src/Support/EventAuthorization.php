<?php

declare(strict_types=1);

namespace RoundlyConsulting\Events\Support;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\App;
use RoundlyConsulting\Events\Models\Venue;

/**
 * The policy check a handle runs when a call names an actor (`by($user)`). A handle without an
 * actor is trusted PHP code (a job, a seeder) and checks nothing — the fleet's rule. The Gate
 * decides; when the host turned policy registration off and registered none, the configured
 * policy class is asked directly, so `by()` never silently allows.
 *
 * @internal
 */
final class EventAuthorization
{
    /**
     * @param  list<mixed>  $arguments  extra policy arguments after the subject
     */
    public static function authorize(?Model $actor, string $ability, Model|string $subject, array $arguments = []): void
    {
        if ($actor === null) {
            return;
        }

        if (! self::allows($actor, $ability, $subject, $arguments)) {
            throw new AuthorizationException;
        }
    }

    /**
     * @param  list<mixed>  $arguments
     */
    public static function allows(Model $actor, string $ability, Model|string $subject, array $arguments = []): bool
    {
        $gate = App::make(Gate::class)->forUser($actor);

        if ($gate->getPolicyFor($subject) !== null || $gate->has($ability)) {
            return $gate->allows($ability, [$subject, ...$arguments]);
        }

        $class = is_string($subject) ? $subject : $subject::class;
        $policy = is_a($class, Venue::class, true) ? EventsConfig::venuePolicy() : EventsConfig::policy();

        if ($policy === null || ! class_exists($policy) || ! method_exists($policy, $ability)) {
            return false;
        }

        return (bool) App::make($policy)->{$ability}($actor, ...(is_string($subject) ? $arguments : [$subject, ...$arguments]));
    }
}
