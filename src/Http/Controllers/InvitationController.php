<?php

declare(strict_types=1);

namespace RoundlyConsulting\Events\Http\Controllers;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use RoundlyConsulting\Events\Http\Requests\PersonRequest;
use RoundlyConsulting\Events\Http\Resources\InvitationResource;
use RoundlyConsulting\Events\Models\EventInvitation;
use RoundlyConsulting\Events\Support\MorphAliases;

/**
 * Invitations, for the event's managers. Tokens never appear here: they go out by mail.
 *
 * @internal
 */
final class InvitationController extends Controller
{
    public function index(Request $request, string $event): JsonResponse
    {
        $model = $this->event($request, $event);

        if (! $this->allows($request, 'invite', $model)) {
            throw new AuthorizationException;
        }

        return new JsonResponse(['data' => $this->manager()->for($model)->invitations()->all()
            ->map(static fn (EventInvitation $invitation): array => (new InvitationResource($invitation->setRelation('event', $model)))->resolve($request))
            ->values()]);
    }

    public function store(PersonRequest $request, string $event): JsonResponse
    {
        $model = $this->event($request, $event);
        $invitation = $this->handle($request, $model)->invitations()->send($request->person(), $request->message());

        return new JsonResponse(['data' => (new InvitationResource($invitation->setRelation('event', $model)))->resolve($request)], 201);
    }

    public function destroy(Request $request, string $event, string $invitee): Response
    {
        $model = $this->event($request, $event);
        [$alias, $key] = MorphAliases::split($invitee);
        $this->handle($request, $model)->invitations()->revoke(MorphAliases::person($alias, $key));

        return new Response(status: 204);
    }
}
