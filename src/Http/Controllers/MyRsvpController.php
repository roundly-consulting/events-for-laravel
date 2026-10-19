<?php

declare(strict_types=1);

namespace RoundlyConsulting\Events\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use RoundlyConsulting\Events\Enums\RsvpResponse;
use RoundlyConsulting\Events\Exceptions\RsvpNotFoundException;
use RoundlyConsulting\Events\Http\Requests\RsvpRequest;
use RoundlyConsulting\Events\Http\Resources\RsvpResource;
use RoundlyConsulting\Events\Models\Event;
use RoundlyConsulting\Events\Models\Rsvp;

/**
 * The caller's own RSVP: read it (with the check-in code), answer, withdraw, and the QR image
 * (never cached).
 *
 * @internal
 */
final class MyRsvpController extends Controller
{
    public function show(Request $request, string $event): JsonResponse
    {
        $model = $this->event($request, $event);

        return new JsonResponse(['data' => $this->render($request, $model, $this->mine($request, $model))]);
    }

    public function update(RsvpRequest $request, string $event): JsonResponse
    {
        $model = $this->event($request, $event);
        $user = $this->user($request) ?? throw RsvpNotFoundException::missing();
        $pending = $this->manager()->for($model)->rsvp($user)->by($user);

        $rsvp = match ($request->response()) {
            RsvpResponse::Going => $pending->going($request->tier(), $request->guests(), $request->note()),
            RsvpResponse::Interested => $pending->interested(),
            RsvpResponse::NotGoing => $pending->notGoing(),
        };

        return new JsonResponse(['data' => $this->render($request, $model, $rsvp)]);
    }

    public function destroy(Request $request, string $event): JsonResponse
    {
        $model = $this->event($request, $event);
        $user = $this->user($request) ?? throw RsvpNotFoundException::missing();

        if ($this->mine($request, $model) === null) {
            throw RsvpNotFoundException::missing();
        }

        $rsvp = $this->manager()->for($model)->rsvp($user)->by($user)->notGoing();

        return new JsonResponse(['data' => $this->render($request, $model, $rsvp)]);
    }

    public function qr(Request $request, string $event): Response
    {
        $model = $this->event($request, $event);
        $rsvp = $this->mine($request, $model) ?? throw RsvpNotFoundException::missing();
        $svg = $this->manager()->rsvps()->qr($rsvp) ?? throw RsvpNotFoundException::missing();

        return new Response((string) $svg, 200, [
            'Content-Type' => 'image/svg+xml',
            // A bearer secret: never stored by a browser or a proxy.
            'Cache-Control' => 'no-store, private',
        ]);
    }

    private function mine(Request $request, Event $event): ?Rsvp
    {
        $user = $this->user($request);

        return $user === null ? null : $this->manager()->for($event)->attendees()->find($user);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function render(Request $request, Event $event, ?Rsvp $rsvp): ?array
    {
        return $rsvp === null ? null : (new RsvpResource($rsvp->setRelation('event', $event)))->asOwner()->resolve($request);
    }
}
