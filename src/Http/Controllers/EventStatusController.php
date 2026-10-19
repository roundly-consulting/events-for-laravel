<?php

declare(strict_types=1);

namespace RoundlyConsulting\Events\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RoundlyConsulting\Events\Http\Requests\MoveOnlineRequest;
use RoundlyConsulting\Events\Http\Requests\ReasonRequest;
use RoundlyConsulting\Events\Http\Requests\RescheduleRequest;
use RoundlyConsulting\Events\Http\Resources\EventResource;
use RoundlyConsulting\Events\Http\Viewer;
use RoundlyConsulting\Events\Models\Event;

/**
 * The status verbs: publish, unpublish, postpone, reschedule, cancel, complete, move online.
 *
 * @internal
 */
final class EventStatusController extends Controller
{
    public function publish(Request $request, string $event): JsonResponse
    {
        $model = $this->event($request, $event);
        $this->handle($request, $model)->publish();

        return $this->respond($request, $model);
    }

    public function unpublish(Request $request, string $event): JsonResponse
    {
        $model = $this->event($request, $event);
        $this->handle($request, $model)->unpublish();

        return $this->respond($request, $model);
    }

    public function postpone(ReasonRequest $request, string $event): JsonResponse
    {
        $model = $this->event($request, $event);
        $this->handle($request, $model)->postpone($request->reason());

        return $this->respond($request, $model);
    }

    public function reschedule(RescheduleRequest $request, string $event): JsonResponse
    {
        $model = $this->event($request, $event);
        $this->handle($request, $model)->reschedule($request->startsAt(), $request->endsAt(), $request->reason());

        return $this->respond($request, $model);
    }

    public function cancel(ReasonRequest $request, string $event): JsonResponse
    {
        $model = $this->event($request, $event);
        $this->handle($request, $model)->cancel($request->reason());

        return $this->respond($request, $model);
    }

    public function complete(Request $request, string $event): JsonResponse
    {
        $model = $this->event($request, $event);
        $this->handle($request, $model)->complete();

        return $this->respond($request, $model);
    }

    public function moveOnline(MoveOnlineRequest $request, string $event): JsonResponse
    {
        $model = $this->event($request, $event);
        $this->handle($request, $model)->moveOnline($request->url(), $request->keepsVenue());

        return $this->respond($request, $model);
    }

    private function respond(Request $request, Event $event): JsonResponse
    {
        return new JsonResponse(['data' => (new EventResource($event->refresh()))->for(Viewer::of($request, show: true))->resolve($request)]);
    }
}
