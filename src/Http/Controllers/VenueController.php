<?php

declare(strict_types=1);

namespace RoundlyConsulting\Events\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use RoundlyConsulting\Events\Exceptions\VenueNotFoundException;
use RoundlyConsulting\Events\Http\Requests\ListVenuesRequest;
use RoundlyConsulting\Events\Http\Requests\VenueRequest;
use RoundlyConsulting\Events\Http\Resources\VenueResource;
use RoundlyConsulting\Events\Models\Venue;
use RoundlyConsulting\Events\Support\EventModels;

/**
 * Venues: listed for everyone (`?mine=1` for the caller's own, `?near=lat,lng&radius=`); created
 * and changed as the venue policy allows.
 *
 * @internal
 */
final class VenueController extends Controller
{
    public function index(ListVenuesRequest $request): JsonResponse
    {
        $user = $this->user($request);
        $query = EventModels::venue()::query()->with('owner')->orderBy('id');

        if ($request->mine()) {
            $user === null ? $query->whereRaw('1 = 0') : $query->ownedBy($user);
        }

        $near = $request->near();

        if ($near !== null) {
            $query->near($near, $request->radius());
        }

        $page = $query->paginate($request->perPage())->withQueryString();

        return new JsonResponse($page->through(static fn (Venue $venue): array => (new VenueResource($venue))->resolve($request)));
    }

    public function store(VenueRequest $request): JsonResponse
    {
        $user = $this->actor($request);
        $venue = $this->manager()->venues()->by($user)->create($request->toData($user));

        return new JsonResponse(['data' => (new VenueResource($venue))->resolve($request)], 201);
    }

    public function show(Request $request, string $venue): JsonResponse
    {
        return new JsonResponse(['data' => (new VenueResource($this->venue($venue)))->resolve($request)]);
    }

    public function update(VenueRequest $request, string $venue): JsonResponse
    {
        $venues = $this->manager()->venues()->by($this->actor($request));
        $model = $this->venue($venue);
        $updated = $venues->update($model, $request->toUpdate($model));

        return new JsonResponse(['data' => (new VenueResource($updated))->resolve($request)]);
    }

    public function destroy(Request $request, string $venue): Response
    {
        $this->manager()->venues()->by($this->actor($request))->delete($this->venue($venue));

        return new Response(status: 204);
    }

    public function geocode(Request $request, string $venue): JsonResponse
    {
        $geocoded = $this->manager()->venues()->by($this->actor($request))->geocode($this->venue($venue), force: true);

        return new JsonResponse(['data' => (new VenueResource($geocoded))->resolve($request)]);
    }

    private function venue(string $id): Venue
    {
        if (! ctype_digit($id) || strlen($id) > 18) {
            throw VenueNotFoundException::missing();
        }

        return EventModels::venue()::query()->with('owner')->find((int) $id) ?? throw VenueNotFoundException::missing();
    }
}
