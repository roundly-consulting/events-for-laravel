<?php

declare(strict_types=1);

namespace RoundlyConsulting\Events;

use Closure;
use Illuminate\Contracts\Container\Container;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use RoundlyConsulting\Events\Accessors\Invitations;
use RoundlyConsulting\Events\Accessors\Rsvps;
use RoundlyConsulting\Events\Accessors\Venues;
use RoundlyConsulting\Events\Actions\CheckIns\CheckInByCodeAction;
use RoundlyConsulting\Events\Actions\Events\CreateEventAction;
use RoundlyConsulting\Events\Actions\Internal\ReleaseAttendeeRsvpsAction;
use RoundlyConsulting\Events\Actions\Maintenance\SweepEventsAction;
use RoundlyConsulting\Events\Builders\EventBuilder;
use RoundlyConsulting\Events\Contracts\CheckInCodes;
use RoundlyConsulting\Events\DataTransferObjects\CheckInResult;
use RoundlyConsulting\Events\DataTransferObjects\CreateEventData;
use RoundlyConsulting\Events\DataTransferObjects\SweepOptions;
use RoundlyConsulting\Events\DataTransferObjects\SweepResult;
use RoundlyConsulting\Events\Enums\EventOperation;
use RoundlyConsulting\Events\Exceptions\EventNotFoundException;
use RoundlyConsulting\Events\Handles\EventHandle;
use RoundlyConsulting\Events\Models\Event;
use RoundlyConsulting\Events\Support\CheckInQr;
use RoundlyConsulting\Events\Support\EventModels;
use RoundlyConsulting\Events\Support\EventVisibility;
use RoundlyConsulting\Events\Support\InvitationTokens;
use SensitiveParameter;

/**
 * The package's public API: the root behind the `Events` facade, injectable by its own
 * class-string (a container singleton). Every method is thin — the behaviour lives in the
 * actions, each resolved through the container on every call, so host overrides and
 * `Events::fake()` apply.
 *
 * Not final on purpose: EventsFake extends it, so an injected manager still type-checks under
 * `Events::fake()`.
 */
class EventsManager
{
    public function __construct(
        protected readonly Container $container,
    ) {}

    /**
     * A fluent builder for a new event: `Events::make('Jazz Night')->organizedBy($user)->at($venue)
     * ->startsAt('2026-11-14 20:00')->publish()`.
     *
     * @param  array<string, string>|string  $title  a locale map, or a string in the current locale
     */
    public function make(array|string $title): EventBuilder
    {
        return new EventBuilder($this, $title);
    }

    /**
     * Create an event (a draft) from a DTO — the same code path as the builder.
     */
    public function create(CreateEventData $data): Event
    {
        return $this->perform(
            EventOperation::Create,
            CreateEventAction::class,
            static fn (CreateEventAction $action): Event => $action->execute($data),
            ['data' => $data, 'actor' => $data->actor],
        );
    }

    /**
     * The live event with this uuid, or null. No visibility rule applies: authorize `view`.
     */
    public function find(string $uuid): ?Event
    {
        if (! Str::isUuid($uuid)) {
            return null;
        }

        return EventModels::event()::query()->where('uuid', $uuid)->first();
    }

    /**
     * The live event with this uuid, or an {@see EventNotFoundException}.
     */
    public function findOrFail(string $uuid): Event
    {
        return $this->find($uuid) ?? throw EventNotFoundException::missing();
    }

    /**
     * The event with this slug in the current locale (then the fallback chain), or null — and
     * null for an unlisted, private or draft event unless `$viewer` may see it without the uuid
     * link (a manager, an invitee, an attendee). Unlisted events are link-only.
     */
    public function findBySlug(string $slug, ?Model $viewer = null): ?Event
    {
        $event = EventModels::event()::findBySlug($slug);

        return $event !== null && EventVisibility::canViewWithoutLink($event, $viewer) ? $event : null;
    }

    /**
     * A handle scoped to one event, by model or uuid. An unknown or trashed uuid throws
     * {@see EventNotFoundException}.
     */
    public function for(Event|string $event): EventHandle
    {
        return new EventHandle($this, is_string($event) ? $this->findOrFail($event) : $event);
    }

    /**
     * Discovery: the announced, listed events `$viewer` may see (cancelled and completed ones
     * included — flag them by `phase`), with what a list renders eager-loaded. Chain the scopes:
     * `->upcoming()->inPerson()->paginate()`.
     *
     * @return Builder<Event>
     */
    public function browse(?Model $viewer = null): Builder
    {
        return EventModels::event()::query()
            ->visibleTo($viewer)
            ->listed()
            ->published()
            ->with(['venue', 'organizer', 'tiers', 'media']);
    }

    /**
     * Every event `$viewer` may see (null = a guest) — the policy's `view` as a query, drafts and
     * private events of the events they manage included.
     *
     * @return Builder<Event>
     */
    public function visibleTo(?Model $viewer): Builder
    {
        return EventModels::event()::query()->visibleTo($viewer);
    }

    /**
     * Venues: `make`, `create`, `update`, `delete`, `restore`, `geocode`, `find`, `ownedBy`, `near`.
     */
    public function venues(): Venues
    {
        return new Venues($this);
    }

    /**
     * RSVP operations that start from the RSVP: `confirmPayment` (your webhook), `confirm`,
     * `release`, `requestPayment`.
     */
    public function rsvps(): Rsvps
    {
        return new Rsvps($this, $this->container->make(CheckInCodes::class), $this->container->make(CheckInQr::class));
    }

    /**
     * Answering an invitation from its link: `accept`, `decline`, `find`.
     */
    public function invitations(): Invitations
    {
        return new Invitations($this, $this->container->make(InvitationTokens::class));
    }

    /**
     * Check in by code without knowing the event (a festival gate scanning several): the code
     * finds its event, and the `checkIn` policy runs on it for `$by` — a non-manager is answered
     * like an unknown code.
     */
    public function checkIn(#[SensitiveParameter] string $code, Model $by, ?int $guests = null, bool $ignoreWindow = false): CheckInResult
    {
        return $this->perform(
            EventOperation::CheckIn,
            CheckInByCodeAction::class,
            static fn (CheckInByCodeAction $action): CheckInResult => $action->execute($code, null, $by, $guests, $ignoreWindow),
            ['actor' => $by, 'guests' => $guests],
        );
    }

    /**
     * The periodic sweep (`events:sweep`, every minute): lapsed holds released, waitlists moved up.
     */
    public function sweep(?SweepOptions $options = null): SweepResult
    {
        return $this->perform(
            EventOperation::Sweep,
            SweepEventsAction::class,
            static fn (SweepEventsAction $action): SweepResult => $action->execute($options),
            ['options' => $options],
        );
    }

    /**
     * A deleted attendee's RSVPs give their seats back and are removed — the `AttendsEvents`
     * deletion hook.
     *
     * @internal
     */
    public function forgetAttendee(Model $attendee): int
    {
        return $this->container->make(ReleaseAttendeeRsvpsAction::class)->execute($attendee);
    }

    /**
     * Run one state-changing operation. Every handle, sub-accessor, builder and model trait funnels
     * its terminal call through here, resolving the action from the container, so host overrides
     * apply and the fake records the call.
     *
     * @internal
     *
     * @template TAction of object
     * @template TResult
     *
     * @param  class-string<TAction>  $action
     * @param  Closure(TAction): TResult  $execute
     * @param  array<string, mixed>  $context
     * @return TResult
     */
    public function perform(EventOperation $operation, string $action, Closure $execute, array $context = []): mixed
    {
        return $execute($this->container->make($action));
    }
}
