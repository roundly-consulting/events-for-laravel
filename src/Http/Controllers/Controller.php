<?php

declare(strict_types=1);

namespace RoundlyConsulting\Events\Http\Controllers;

use Illuminate\Container\Container;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use RoundlyConsulting\Events\EventsManager;
use RoundlyConsulting\Events\Exceptions\EventNotFoundException;
use RoundlyConsulting\Events\Handles\EventHandle;
use RoundlyConsulting\Events\Http\ErrorResponse;
use RoundlyConsulting\Events\Models\Event;
use RoundlyConsulting\Events\Support\EventAuthorization;
use RoundlyConsulting\Events\Support\EventModels;
use RoundlyConsulting\Events\Support\EventsConfig;
use RoundlyConsulting\Events\Support\EventVisibility;
use Throwable;

/**
 * The API's controllers resolve events themselves — by uuid, through the model seam, with the
 * visibility rule applied: an event the caller may not see answers 404 exactly like a missing
 * one. Writes go through the facade's handle with the caller as the actor, so the policy, the
 * fake and the domain events apply. Package exceptions become `{message, code}`; the host's
 * exception handler is never touched.
 *
 * @internal
 */
abstract class Controller
{
    /**
     * @param  array<array-key, mixed>  $parameters
     */
    public function callAction(string $method, array $parameters): mixed
    {
        try {
            return $this->{$method}(...array_values($parameters));
        } catch (Throwable $exception) {
            return ErrorResponse::from($exception) ?? throw $exception;
        }
    }

    protected function manager(): EventsManager
    {
        return Container::getInstance()->make(EventsManager::class);
    }

    protected function user(Request $request): ?Model
    {
        $user = $request->user();

        return $user instanceof Model ? $user : null;
    }

    /**
     * The event the caller may see. A malformed uuid, a missing, trashed or hidden event: 404.
     */
    protected function event(Request $request, string $uuid, bool $withTrashed = false): Event
    {
        if (! Str::isUuid($uuid)) {
            throw EventNotFoundException::missing();
        }

        $query = EventModels::event()::query()->where('uuid', $uuid);

        if ($withTrashed) {
            $query->withTrashed();
        }

        $event = $query->first();

        if ($event === null || ! EventVisibility::canView($event, $this->user($request))) {
            throw EventNotFoundException::missing();
        }

        return $event;
    }

    protected function handle(Request $request, Event $event): EventHandle
    {
        return $this->manager()->for($event)->by($this->user($request));
    }

    /**
     * Read abilities a guest may hold (`viewAttendees` on a public guest list): a guest is
     * checked against the policy directly, since the Gate denies guests up front.
     */
    protected function allows(Request $request, string $ability, Event $event): bool
    {
        $user = $this->user($request);

        if ($user !== null) {
            return EventAuthorization::allows($user, $ability, $event);
        }

        $policy = EventsConfig::policy();

        return $policy !== null && class_exists($policy) && method_exists($policy, $ability)
            && (bool) Container::getInstance()->make($policy)->{$ability}(null, $event);
    }
}
