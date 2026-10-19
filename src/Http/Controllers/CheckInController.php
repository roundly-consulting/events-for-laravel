<?php

declare(strict_types=1);

namespace RoundlyConsulting\Events\Http\Controllers;

use Illuminate\Http\JsonResponse;
use RoundlyConsulting\Events\Http\Requests\CheckInRequest;
use RoundlyConsulting\Events\Http\Resources\CheckInResultResource;

/**
 * A door scan, scoped to the event: another event's code is refused (`wrong_event`).
 *
 * @internal
 */
final class CheckInController extends Controller
{
    public function store(CheckInRequest $request, string $event): JsonResponse
    {
        $model = $this->event($request, $event);
        $result = $this->handle($request, $model)->checkIn($request->code(), $request->guests());

        return new JsonResponse(['data' => (new CheckInResultResource($result))->resolve($request)], $result->firstCheckIn ? 201 : 200);
    }
}
