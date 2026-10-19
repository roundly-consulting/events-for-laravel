<?php

declare(strict_types=1);

namespace RoundlyConsulting\Events\Http\Controllers;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RoundlyConsulting\Events\Http\Requests\PromoteRequest;
use RoundlyConsulting\Events\Http\Resources\RsvpResource;
use RoundlyConsulting\Events\Models\Rsvp;

/**
 * The waitlist, in promotion order, and a manual move-up.
 *
 * @internal
 */
final class WaitlistController extends Controller
{
    public function index(Request $request, string $event): JsonResponse
    {
        $model = $this->event($request, $event);

        if (! $this->allows($request, 'viewWaitlist', $model)) {
            throw new AuthorizationException;
        }

        return new JsonResponse(['data' => $this->manager()->for($model)->waitlist()->all()
            ->map(static fn (Rsvp $rsvp): array => (new RsvpResource($rsvp->setRelation('event', $model)))->resolve($request))
            ->values()]);
    }

    public function promote(PromoteRequest $request, string $event): JsonResponse
    {
        $model = $this->event($request, $event);
        $promoted = $this->handle($request, $model)->waitlist()->promote($request->limit());

        return new JsonResponse(['data' => $promoted
            ->map(static fn (Rsvp $rsvp): array => (new RsvpResource($rsvp->setRelation('event', $model)))->resolve($request))
            ->values()]);
    }
}
