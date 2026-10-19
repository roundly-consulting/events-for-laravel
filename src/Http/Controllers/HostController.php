<?php

declare(strict_types=1);

namespace RoundlyConsulting\Events\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use RoundlyConsulting\Events\Contracts\Organizer;
use RoundlyConsulting\Events\Exceptions\UnknownMorphAliasException;
use RoundlyConsulting\Events\Http\Requests\PersonRequest;
use RoundlyConsulting\Events\Http\Resources\References;
use RoundlyConsulting\Events\Models\EventHost;
use RoundlyConsulting\Events\Support\MorphAliases;

/**
 * Co-hosts: anyone who may see the event sees them; the organizer's users add and remove them.
 *
 * @internal
 */
final class HostController extends Controller
{
    public function index(Request $request, string $event): JsonResponse
    {
        $model = $this->event($request, $event);

        return new JsonResponse(['data' => $this->manager()->for($model)->hosts()->all()
            ->map(static fn (EventHost $host): ?array => References::host($host->host, $request))
            ->filter()->values()]);
    }

    public function store(PersonRequest $request, string $event): JsonResponse
    {
        $model = $this->event($request, $event);
        $host = $request->person();

        if (! $host instanceof Organizer) {
            throw UnknownMorphAliasException::unknown();
        }

        $this->handle($request, $model)->hosts()->add($host);

        return new JsonResponse(['data' => References::host($host, $request)], 201);
    }

    public function destroy(Request $request, string $event, string $host): Response
    {
        $model = $this->event($request, $event);
        [$alias, $key] = MorphAliases::split($host);
        $this->handle($request, $model)->hosts()->remove(MorphAliases::person($alias, $key));

        return new Response(status: 204);
    }
}
