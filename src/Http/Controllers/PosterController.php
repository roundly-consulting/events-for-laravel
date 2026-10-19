<?php

declare(strict_types=1);

namespace RoundlyConsulting\Events\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RoundlyConsulting\Events\Http\Requests\PosterRequest;
use RoundlyConsulting\Events\Http\Resources\EventResource;
use RoundlyConsulting\Events\Http\Viewer;

/**
 * The poster: set (an upload or a draft token) or remove.
 *
 * @internal
 */
final class PosterController extends Controller
{
    public function update(PosterRequest $request, string $event): JsonResponse
    {
        $model = $this->event($request, $event);
        $this->handle($request, $model)->poster($request->poster(), $request->draft());

        return new JsonResponse(['data' => (new EventResource($model->refresh()))->for(Viewer::of($request, show: true))->resolve($request)]);
    }

    public function destroy(Request $request, string $event): JsonResponse
    {
        $model = $this->event($request, $event);
        $this->handle($request, $model)->poster();

        return new JsonResponse(['data' => (new EventResource($model->refresh()))->for(Viewer::of($request, show: true))->resolve($request)]);
    }
}
