<?php

declare(strict_types=1);

namespace RoundlyConsulting\Events\Http\Controllers;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use RoundlyConsulting\Events\Exceptions\EventNotFoundException;
use RoundlyConsulting\Events\Http\Queries\EventIndexQuery;
use RoundlyConsulting\Events\Http\Requests\IndexEventsRequest;
use RoundlyConsulting\Events\Http\Requests\StoreEventRequest;
use RoundlyConsulting\Events\Http\Requests\UpdateEventRequest;
use RoundlyConsulting\Events\Http\Resources\EventResource;
use RoundlyConsulting\Events\Http\Viewer;
use RoundlyConsulting\Events\Models\Event;
use RoundlyConsulting\Events\Support\EventManagers;
use RoundlyConsulting\Events\Support\EventModels;
use RoundlyConsulting\Events\Support\EventTransaction;
use RoundlyConsulting\Tags\TagsManager;

/**
 * Events: discovery, create, read, change, delete and restore.
 *
 * @internal
 */
final class EventController extends Controller
{
    public function index(IndexEventsRequest $request, TagsManager $tags): JsonResponse
    {
        $user = $this->user($request);
        $page = EventIndexQuery::for($request, $user, $request->mine(), $request->timezone())
            ->paginate($request->perPage())
            ->withQueryString();

        $events = $page->getCollection()->filter(static fn (mixed $event): bool => $event instanceof Event)->values();
        // The category path of a whole page in one query.
        $tags->loadAncestors($events->map(static fn (Event $event) => $event->category)->filter());
        $viewer = Viewer::of($request)->forList($events);

        return new JsonResponse($page->through(static fn (Model $event): array => (new EventResource($event))->for($viewer)->resolve($request)));
    }

    public function store(StoreEventRequest $request): JsonResponse
    {
        $user = $this->user($request) ?? throw EventNotFoundException::missing();
        $model = EventModels::event();

        $event = EventTransaction::run(new $model, function () use ($request, $user): Event {
            $venueData = $request->venueData($user);
            $venue = $venueData === null ? null : $this->manager()->venues()->by($user)->create($venueData);
            $event = $this->manager()->create($request->toData($user, $request->organizer($user), $venue?->getKey()));

            if ($request->publishes()) {
                $this->manager()->for($event)->by($user)->publish();
            }

            return $event;
        });

        return $this->show($request, $event->uuid)->setStatusCode(201);
    }

    public function show(Request $request, string $event): JsonResponse
    {
        $model = $this->event($request, $event);
        $model->loadMissing(['venue.owner', 'organizer', 'tags', 'media', 'tiers', 'hosts.host']);

        return new JsonResponse(['data' => (new EventResource($model))->for(Viewer::of($request, show: true))->resolve($request)]);
    }

    public function update(UpdateEventRequest $request, string $event): JsonResponse
    {
        $model = $this->event($request, $event);
        $handle = $this->handle($request, $model);
        $handle = $request->silent() ? $handle->silently() : $handle;

        // One transaction: the event row is locked first by the update, then tags takes its own.
        EventTransaction::run($model, static function () use ($handle, $request): void {
            $handle->update($request->toData());

            if ($request->changesCategory()) {
                $handle->categorize($request->category());
            }

            $labels = $request->labels();

            if ($labels !== null) {
                $handle->labels()->sync($labels);
            }
        });

        return $this->show($request, $model->uuid);
    }

    public function destroy(Request $request, string $event): Response
    {
        $this->handle($request, $this->event($request, $event))->delete();

        return new Response(status: 204);
    }

    public function restore(Request $request, string $event): JsonResponse
    {
        $model = $this->event($request, $event, withTrashed: true);

        if (! EventManagers::allows($model, $this->user($request))) {
            throw EventNotFoundException::missing();
        }

        $this->handle($request, $model)->restore();

        return $this->show($request, $model->uuid);
    }
}
