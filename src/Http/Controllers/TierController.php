<?php

declare(strict_types=1);

namespace RoundlyConsulting\Events\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use RoundlyConsulting\Events\Http\Requests\ReorderTiersRequest;
use RoundlyConsulting\Events\Http\Requests\TierRequest;
use RoundlyConsulting\Events\Http\Resources\AdmissionTierResource;
use RoundlyConsulting\Events\Models\AdmissionTier;
use RoundlyConsulting\Events\Support\EventManagers;

/**
 * Ticket types. Hidden ones are listed for managers only; a tier id of another event is a 404.
 *
 * @internal
 */
final class TierController extends Controller
{
    public function index(Request $request, string $event): JsonResponse
    {
        $model = $this->event($request, $event);
        $manager = EventManagers::allows($model, $this->user($request));

        return new JsonResponse(['data' => $model->tiers
            ->filter(static fn (AdmissionTier $tier): bool => $manager || ! $tier->hidden)
            ->map(static fn (AdmissionTier $tier): array => (new AdmissionTierResource($tier->setRelation('event', $model)))->forManager($manager)->resolve($request))
            ->values()]);
    }

    public function store(TierRequest $request, string $event): JsonResponse
    {
        $model = $this->event($request, $event);
        $tier = $this->handle($request, $model)->tiers()->create($request->toData());

        return new JsonResponse(['data' => (new AdmissionTierResource($tier->setRelation('event', $model)))->forManager()->resolve($request)], 201);
    }

    public function update(TierRequest $request, string $event, string $tier): JsonResponse
    {
        $model = $this->event($request, $event);
        $updated = $this->handle($request, $model)->tiers()->update(self::tierKey($tier), $request->toUpdate());

        return new JsonResponse(['data' => (new AdmissionTierResource($updated->setRelation('event', $model)))->forManager()->resolve($request)]);
    }

    public function destroy(Request $request, string $event, string $tier): Response
    {
        $this->handle($request, $this->event($request, $event))->tiers()->remove(self::tierKey($tier));

        return new Response(status: 204);
    }

    public function reorder(ReorderTiersRequest $request, string $event): Response
    {
        $this->handle($request, $this->event($request, $event))->tiers()->reorder($request->order());

        return new Response(status: 204);
    }

    /**
     * A numeric segment is an id, anything else the tier's key.
     */
    private static function tierKey(string $tier): int|string
    {
        return ctype_digit($tier) && strlen($tier) <= 18 ? (int) $tier : $tier;
    }
}
