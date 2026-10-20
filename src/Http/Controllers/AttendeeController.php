<?php

declare(strict_types=1);

namespace RoundlyConsulting\Events\Http\Controllers;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use RoundlyConsulting\Events\Enums\RsvpStatus;
use RoundlyConsulting\Events\Exceptions\RsvpNotFoundException;
use RoundlyConsulting\Events\Http\Requests\AttendeeRequest;
use RoundlyConsulting\Events\Http\Requests\CheckInRequest;
use RoundlyConsulting\Events\Http\Requests\ListAttendeesRequest;
use RoundlyConsulting\Events\Http\Resources\CheckInResultResource;
use RoundlyConsulting\Events\Http\Resources\References;
use RoundlyConsulting\Events\Http\Resources\RsvpResource;
use RoundlyConsulting\Events\Models\Event;
use RoundlyConsulting\Events\Models\Rsvp;
use RoundlyConsulting\Events\Support\EventAuthorization;
use RoundlyConsulting\Events\Support\EventManagers;
use RoundlyConsulting\Events\Support\EventTransaction;

/**
 * The guest list. Managers see every RSVP with its check-in record (never the codes); others
 * see the going attendees as the event's `guest_list` allows. Interested people are managers',
 * and so are the `status`, `checked_in` and `tier` filters — for everyone else they are ignored
 * (they would tell who is at the venue, or who holds a hidden ticket type).
 *
 * @internal
 */
final class AttendeeController extends Controller
{
    public function index(ListAttendeesRequest $request, string $event): JsonResponse
    {
        $model = $this->event($request, $event);

        if (! $this->allows($request, 'viewAttendees', $model)) {
            throw new AuthorizationException;
        }

        $manager = EventManagers::allows($model, $this->user($request));
        $status = $request->status();

        if ($status === RsvpStatus::Interested && ! $manager) {
            throw new AuthorizationException;
        }

        // Managers list any status (going by default); everyone else sees who is going.
        $query = $model->rsvps()->with(['attendee', 'tier', 'checkedInBy'])->orderBy('id')
            ->where('status', ($manager ? ($status ?? RsvpStatus::Going) : RsvpStatus::Going)->value);

        if ($manager && $request->checkedIn() !== null) {
            $request->checkedIn() ? $query->whereNotNull('checked_in_at') : $query->whereNull('checked_in_at');
        }

        if ($manager && $request->tier() !== null) {
            $query->whereHas('tier', static fn ($tiers) => $tiers->where('key', $request->tier()));
        }

        $page = $query->paginate($request->perPage())->withQueryString();

        return new JsonResponse($page->through(static fn (Rsvp $rsvp): array => $manager
            ? (new RsvpResource($rsvp->setRelation('event', $model)))->resolve($request)
            : ['attendee' => References::attendee($rsvp->attendee, $request), 'guests' => $rsvp->guests]));
    }

    public function store(AttendeeRequest $request, string $event): JsonResponse
    {
        $model = $this->event($request, $event);
        $rsvp = $this->handle($request, $model)->attendees()->add($request->attendee(), $request->tier(), $request->guests() ?? 0, $request->overCapacity());

        return new JsonResponse(['data' => $this->render($request, $model, $rsvp)], 201);
    }

    public function update(AttendeeRequest $request, string $event, string $rsvp): JsonResponse
    {
        $model = $this->event($request, $event);
        // The policy first, whatever the body holds: an empty one changes nothing, but would
        // still answer with the managers' view of the RSVP.
        EventAuthorization::authorize($this->actor($request), 'manageAttendees', $model);
        $request->assertChangesSomething();
        $record = $this->rsvp($model, $rsvp);
        $attendees = $this->handle($request, $model)->attendees();

        // All or nothing: both changes lock the event row first, and nest as savepoints.
        $record = EventTransaction::run($model, static function () use ($request, $attendees, $record): Rsvp {
            if ($request->tier() !== null) {
                $record = $attendees->changeTier($record, $request->tier());
            }

            if ($request->guests() !== null) {
                $record = $attendees->changeGuests($record, $request->guests());
            }

            return $record;
        });

        return new JsonResponse(['data' => $this->render($request, $model, $record)]);
    }

    public function destroy(Request $request, string $event, string $rsvp): Response
    {
        $model = $this->event($request, $event);
        $this->handle($request, $model)->attendees()->remove($this->rsvp($model, $rsvp));

        return new Response(status: 204);
    }

    public function checkIn(CheckInRequest $request, string $event, string $rsvp): JsonResponse
    {
        $model = $this->event($request, $event);
        $result = $this->handle($request, $model)->attendees()->checkIn($this->rsvp($model, $rsvp), $request->guests());

        return new JsonResponse(['data' => (new CheckInResultResource($result))->resolve($request)]);
    }

    public function undoCheckIn(Request $request, string $event, string $rsvp): JsonResponse
    {
        $model = $this->event($request, $event);
        $record = $this->rsvp($model, $rsvp);
        $this->handle($request, $model)->attendees()->undoCheckIn($record);

        return new JsonResponse(['data' => $this->render($request, $model, $record->refresh())]);
    }

    /**
     * An RSVP of this event, or 404 — never another event's.
     */
    private function rsvp(Event $event, string $id): Rsvp
    {
        if (! ctype_digit($id) || strlen($id) > 18) {
            throw RsvpNotFoundException::missing();
        }

        return $event->rsvps()->whereKey((int) $id)->first() ?? throw RsvpNotFoundException::missing();
    }

    /**
     * @return array<string, mixed>
     */
    private function render(Request $request, Event $event, Rsvp $rsvp): array
    {
        return (new RsvpResource($rsvp->setRelation('event', $event)))->resolve($request);
    }
}
