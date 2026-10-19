<?php

declare(strict_types=1);

namespace RoundlyConsulting\Events\Handles;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\App;
use RoundlyConsulting\Events\Actions\CheckIns\CheckInByCodeAction;
use RoundlyConsulting\Events\Actions\Events\CancelEventAction;
use RoundlyConsulting\Events\Actions\Events\CompleteEventAction;
use RoundlyConsulting\Events\Actions\Events\DeleteEventAction;
use RoundlyConsulting\Events\Actions\Events\MoveEventOnlineAction;
use RoundlyConsulting\Events\Actions\Events\PostponeEventAction;
use RoundlyConsulting\Events\Actions\Events\PublishEventAction;
use RoundlyConsulting\Events\Actions\Events\RescheduleEventAction;
use RoundlyConsulting\Events\Actions\Events\RestoreEventAction;
use RoundlyConsulting\Events\Actions\Events\SetPosterAction;
use RoundlyConsulting\Events\Actions\Events\TransferOrganizerAction;
use RoundlyConsulting\Events\Actions\Events\TransitionEventAction;
use RoundlyConsulting\Events\Actions\Events\UnpublishEventAction;
use RoundlyConsulting\Events\Actions\Events\UpdateEventAction;
use RoundlyConsulting\Events\Actions\Tags\CategorizeEventAction;
use RoundlyConsulting\Events\Builders\EventEditor;
use RoundlyConsulting\Events\Builders\PendingRsvp;
use RoundlyConsulting\Events\Calendar\IcsCalendar;
use RoundlyConsulting\Events\Contracts\EventState;
use RoundlyConsulting\Events\Contracts\Organizer;
use RoundlyConsulting\Events\DataTransferObjects\CheckInResult;
use RoundlyConsulting\Events\DataTransferObjects\EventCounts;
use RoundlyConsulting\Events\DataTransferObjects\UpdateEventData;
use RoundlyConsulting\Events\Enums\EventOperation;
use RoundlyConsulting\Events\Enums\EventPhase;
use RoundlyConsulting\Events\Enums\Visibility;
use RoundlyConsulting\Events\EventsManager;
use RoundlyConsulting\Events\Exceptions\InvalidEventLifecycleException;
use RoundlyConsulting\Events\Models\Event;
use RoundlyConsulting\Events\Support\EventAuthorization;
use RoundlyConsulting\Events\Support\EventsConfig;
use RoundlyConsulting\Events\Support\Ics\IcsWriter;
use RoundlyConsulting\Lifecycle\DataTransferObjects\AvailableTransition;
use RoundlyConsulting\Lifecycle\DataTransferObjects\TransitionResult;
use RoundlyConsulting\Lifecycle\LifecycleHandle;
use RoundlyConsulting\Lifecycle\LifecycleManager;
use RoundlyConsulting\Tags\DataTransferObjects\TagChanges;
use RoundlyConsulting\Tags\Models\Tag;
use SensitiveParameter;

/**
 * `Events::for($event)` — everything scoped to one event.
 *
 * Immutable: `by($actor)`, `asSystem()` and `silently()` return a new handle. With an actor,
 * every call checks the policy first; without one it is trusted PHP code (a job, a seeder). A
 * mutator returns the handle, so calls chain (`->publish()->poster($file)`); a creator or query
 * returns its result. Actions update the bound event in place, so a chained call sees the
 * previous call's effect and `event()` returns it current.
 */
final readonly class EventHandle
{
    public function __construct(
        private EventsManager $manager,
        private Event $event,
        private ?Model $actor = null,
        private bool $system = false,
        private bool $silent = false,
    ) {}

    /**
     * Attribute the following calls to `$actor` and check the policy for them.
     */
    public function by(?Model $actor): self
    {
        return new self($this->manager, $this->event, $actor, false, $this->silent);
    }

    /**
     * Run the following calls as the system: no actor, no policy.
     */
    public function asSystem(): self
    {
        return new self($this->manager, $this->event, null, true, $this->silent);
    }

    /**
     * The following writes fire the domain events but send no notices.
     */
    public function silently(): self
    {
        return new self($this->manager, $this->event, $this->actor, $this->system, true);
    }

    public function event(): Event
    {
        return $this->event;
    }

    // Content and settings --------------------------------------------------------------------

    public function update(UpdateEventData $data): static
    {
        $this->authorize('update');
        $this->manager->perform(
            EventOperation::Update,
            UpdateEventAction::class,
            fn (UpdateEventAction $action): Event => $action->execute($this->event, $data, $this->actor, $this->silent),
            $this->context(['data' => $data]),
        );

        return $this;
    }

    /**
     * A fluent editor: `->edit()->title([...])->capacity(150)->save()`.
     */
    public function edit(): EventEditor
    {
        return new EventEditor($this);
    }

    /**
     * Set, replace or (with no file and no draft) remove the poster: an upload, a local file
     * path, or a draft token from an upload-first form.
     */
    public function poster(UploadedFile|string|null $file = null, #[SensitiveParameter] ?string $draft = null): static
    {
        $this->authorize('update');
        $this->manager->perform(
            EventOperation::SetPoster,
            SetPosterAction::class,
            fn (SetPosterAction $action): Event => $action->execute($this->event, $file, $draft, $this->actor),
            $this->context(['removed' => $file === null && $draft === null]),
        );

        return $this;
    }

    /**
     * Set the event's one category — a slug or name of a curated category, a tag key or a tag —
     * or clear it with null. Unknown categories throw tags' `TagNotFoundException`.
     */
    public function categorize(Tag|int|string|null $category): static
    {
        $this->authorize('update');
        $this->manager->perform(
            EventOperation::Categorize,
            CategorizeEventAction::class,
            fn (CategorizeEventAction $action): TagChanges => $action->execute($this->event, $category, $this->actor, $this->silent),
            $this->context(['category' => $category]),
        );

        return $this;
    }

    /**
     * The event's free-form labels: `attach`, `detach`, `sync`, `all`.
     */
    public function labels(): EventLabels
    {
        return new EventLabels($this);
    }

    /**
     * The event's ticket types: `make`, `create`, `add`, `update`, `remove`, `restore`, `reorder`,
     * `all`, `available`.
     */
    public function tiers(): EventTiers
    {
        return new EventTiers($this);
    }

    // Attendance ---------------------------------------------------------------------------------

    /**
     * An attendee's answer: `->rsvp($user)->going(tier: 'vip', guests: 1)`, `->interested()`,
     * `->notGoing()`.
     */
    public function rsvp(Model $attendee): PendingRsvp
    {
        return new PendingRsvp($this, $attendee);
    }

    /**
     * The managers' side of the list: `add`, `remove`, `changeTier`, `changeGuests`, `confirm`,
     * `release`, `find`, `going`, `interested`.
     */
    public function attendees(): EventAttendees
    {
        return new EventAttendees($this);
    }

    /**
     * The queue: `all`, `promote`, `position`.
     */
    public function waitlist(): EventWaitlist
    {
        return new EventWaitlist($this);
    }

    /**
     * Check in at the door by the attendee's code (or a URL holding it). Refuses another event's
     * code (`wrong_event`), an unknown or revoked one, outside the window unless `ignoreWindow`.
     * Idempotent: a second scan reports `firstCheckIn: false`.
     */
    public function checkIn(#[SensitiveParameter] string $code, ?int $guests = null, bool $ignoreWindow = false): CheckInResult
    {
        $this->authorize('checkIn');

        return $this->manager->perform(
            EventOperation::CheckIn,
            CheckInByCodeAction::class,
            fn (CheckInByCodeAction $action): CheckInResult => $action->execute($code, $this->event, $this->actor, $guests, $ignoreWindow),
            $this->context(['guests' => $guests]),
        );
    }

    /**
     * Co-hosts: `add`, `remove`, `all`, `has`.
     */
    public function hosts(): EventHosts
    {
        return new EventHosts($this);
    }

    /**
     * Invitations: `send`, `revoke`, `all`, `pending`.
     */
    public function invitations(): EventInvitations
    {
        return new EventInvitations($this);
    }

    /**
     * Hand the event to another organizer, who must allow the actor; `keepAsHost` keeps the old one
     * as a co-host.
     */
    public function transferTo(Model&Organizer $organizer, bool $keepAsHost = false): static
    {
        $this->authorize('transfer');
        $this->manager->perform(
            EventOperation::TransferOrganizer,
            TransferOrganizerAction::class,
            fn (TransferOrganizerAction $action): Event => $action->execute($this->event, $organizer, $keepAsHost, $this->actor),
            $this->context(['organizer' => $organizer, 'keep_as_host' => $keepAsHost]),
        );

        return $this;
    }

    /**
     * People going (guests included), RSVPs interested, people waiting, seats left.
     */
    public function counts(): EventCounts
    {
        $this->authorize('viewCounts');
        $event = $this->event->fresh() ?? $this->event;

        return new EventCounts($event->going_count, $event->interested_count, $event->waitlist_count, $event->seatsLeft());
    }

    // Status -------------------------------------------------------------------------------------

    public function publish(): static
    {
        $this->authorize('publish');
        $this->manager->perform(
            EventOperation::Publish,
            PublishEventAction::class,
            fn (PublishEventAction $action): TransitionResult => $action->execute($this->event, $this->actor, $this->system, $this->silent),
            $this->context(),
        );

        return $this;
    }

    public function unpublish(): static
    {
        $this->authorize('unpublish');
        $this->manager->perform(
            EventOperation::Unpublish,
            UnpublishEventAction::class,
            fn (UnpublishEventAction $action): TransitionResult => $action->execute($this->event, $this->actor, $this->system, $this->silent),
            $this->context(),
        );

        return $this;
    }

    public function postpone(?string $reason = null): static
    {
        $this->authorize('postpone');
        $this->manager->perform(
            EventOperation::Postpone,
            PostponeEventAction::class,
            fn (PostponeEventAction $action): TransitionResult => $action->execute($this->event, $this->actor, $this->system, $reason, $this->silent),
            $this->context(['reason' => $reason]),
        );

        return $this;
    }

    /**
     * A new date: wall-clock strings are read in the event's timezone; without an end the event
     * keeps its length. The announced start is kept as `previous_starts_at`.
     */
    public function reschedule(CarbonInterface|string $startsAt, CarbonInterface|string|null $endsAt = null, ?string $reason = null): static
    {
        $this->authorize('reschedule');
        $this->manager->perform(
            EventOperation::Reschedule,
            RescheduleEventAction::class,
            fn (RescheduleEventAction $action): Event => $action->execute($this->event, $startsAt, $endsAt, $reason, $this->actor, $this->system, $this->silent),
            $this->context(['starts_at' => $startsAt, 'ends_at' => $endsAt, 'reason' => $reason]),
        );

        return $this;
    }

    public function cancel(?string $reason = null): static
    {
        $this->authorize('cancel');
        $this->manager->perform(
            EventOperation::Cancel,
            CancelEventAction::class,
            fn (CancelEventAction $action): TransitionResult => $action->execute($this->event, $this->actor, $this->system, $reason, $this->silent),
            $this->context(['reason' => $reason]),
        );

        return $this;
    }

    /**
     * End the event early (it completes on its own at `ends_at`).
     */
    public function complete(): static
    {
        $this->authorize('complete');
        $this->manager->perform(
            EventOperation::Complete,
            CompleteEventAction::class,
            fn (CompleteEventAction $action): TransitionResult => $action->execute($this->event, $this->actor, $this->system, $this->silent),
            $this->context(),
        );

        return $this;
    }

    /**
     * Move online: the join link is set and the venue dropped — or kept, for a hybrid event.
     */
    public function moveOnline(string $url, bool $keepVenue = false): static
    {
        $this->authorize('moveOnline');
        $this->manager->perform(
            EventOperation::MoveOnline,
            MoveEventOnlineAction::class,
            fn (MoveEventOnlineAction $action): Event => $action->execute($this->event, $url, $keepVenue, $this->actor, $this->silent),
            $this->context(['keep_venue' => $keepVenue]),
        );

        return $this;
    }

    /**
     * Any transition of the configured lifecycle by name — a host lifecycle's own steps.
     *
     * @param  array<string, mixed>  $payload
     */
    public function transition(string $name, ?string $reason = null, array $payload = []): TransitionResult
    {
        $this->authorize('transition');

        return $this->manager->perform(
            EventOperation::Transition,
            TransitionEventAction::class,
            fn (TransitionEventAction $action): TransitionResult => $action->execute($this->event, $name, $this->actor, $this->system, $reason, $payload, $this->silent),
            $this->context(['transition' => $name, 'reason' => $reason]),
        );
    }

    /**
     * Whether the transition is allowed now (for the handle's actor, policy included).
     */
    public function can(string $transition): bool
    {
        if ($this->actor !== null && ! EventAuthorization::allows($this->actor, 'transition', $this->event)) {
            return false;
        }

        return $this->lifecycle()->can($transition);
    }

    /**
     * The names of the transitions allowed now.
     *
     * @return list<string>
     */
    public function allowedTransitions(): array
    {
        if ($this->actor !== null && ! EventAuthorization::allows($this->actor, 'transition', $this->event)) {
            return [];
        }

        return array_map(
            static fn (AvailableTransition $transition): string => $transition->name,
            $this->lifecycle()->allowedTransitions(),
        );
    }

    public function phase(): EventPhase
    {
        return $this->event->phase;
    }

    public function status(): EventState
    {
        $status = $this->event->status;

        return $status instanceof EventState
            ? $status
            : throw InvalidEventLifecycleException::broken(EventsConfig::lifecycle(), 'its states must implement the EventState contract.');
    }

    // Lifecycle of the record -----------------------------------------------------------------

    /**
     * Delete the event — refused while an announced, unfinished event has people going. With
     * `force`, the row and its poster are gone for good.
     */
    public function delete(bool $force = false): static
    {
        $this->authorize('delete');
        $this->manager->perform(
            EventOperation::Delete,
            DeleteEventAction::class,
            fn (DeleteEventAction $action) => $action->execute($this->event, $this->actor, $force),
            $this->context(['force' => $force]),
        );

        return $this;
    }

    public function restore(): static
    {
        $this->authorize('restore');
        $this->manager->perform(
            EventOperation::Restore,
            RestoreEventAction::class,
            fn (RestoreEventAction $action): Event => $action->execute($this->event, $this->actor),
            $this->context(),
        );

        return $this;
    }

    /**
     * The event as a calendar file (`return Events::for($event)->ics($request->user());` answers
     * `text/calendar`; `(string)` gives the text). The join link goes in only for a `$viewer`
     * who may see it; a draft has no entry. With an actor, `view` is checked.
     */
    public function ics(?Model $viewer = null): IcsCalendar
    {
        $this->authorize('view');
        $slug = $this->event->slug;
        $filename = ($this->event->visibility === Visibility::Public && is_string($slug) && $slug !== '' ? $slug : $this->event->uuid).'.ics';

        return new IcsCalendar(App::make(IcsWriter::class)->write([$this->event], $viewer ?? $this->actor), $filename);
    }

    /**
     * The event's schema.org structured data, or null (drafts, private events, unlisted ones
     * unless `events.seo.json_ld.unlisted`).
     *
     * @return array<string, mixed>|null
     */
    public function jsonLd(): ?array
    {
        return $this->manager->jsonLd($this->event);
    }

    /**
     * @internal for the handle's own sub-accessors
     */
    public function manager(): EventsManager
    {
        return $this->manager;
    }

    /**
     * @internal for the handle's own sub-accessors
     */
    public function actor(): ?Model
    {
        return $this->actor;
    }

    /**
     * @internal for the handle's own sub-accessors
     */
    public function isSystem(): bool
    {
        return $this->system;
    }

    /**
     * @internal for the handle's own sub-accessors
     */
    public function isSilent(): bool
    {
        return $this->silent;
    }

    /**
     * Check the policy when the handle names an actor.
     *
     * @internal for the handle's own sub-accessors
     *
     * @param  list<mixed>  $arguments
     */
    public function authorize(string $ability, array $arguments = []): void
    {
        EventAuthorization::authorize($this->actor, $ability, $this->event, $arguments);
    }

    /**
     * The recorder context every operation of this handle carries.
     *
     * @internal for the handle's own sub-accessors
     *
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    public function context(array $extra = []): array
    {
        return ['event' => $this->event, 'actor' => $this->actor, 'system' => $this->system, 'silent' => $this->silent, ...$extra];
    }

    private function lifecycle(): LifecycleHandle
    {
        $handle = App::make(LifecycleManager::class)->for($this->event, 'status');

        return $this->system ? $handle->asSystem() : $handle->by($this->actor);
    }
}
