<?php

declare(strict_types=1);

namespace RoundlyConsulting\Events\Http\Controllers;

use Illuminate\Http\JsonResponse;
use RoundlyConsulting\Events\Http\Requests\InvitationResponseRequest;
use RoundlyConsulting\Events\Http\Resources\RsvpResource;

/**
 * Answering from an invitation link. The token is the credential; when the invitee is a user
 * account, the signed-in user must be that invitee (a forwarded mail cannot answer for
 * someone else). Every refusal is the same 404.
 *
 * @internal
 */
final class InvitationResponseController extends Controller
{
    public function accept(InvitationResponseRequest $request): JsonResponse
    {
        $rsvp = $this->manager()->invitations()->accept($request->token(), $request->response(), $this->user($request));

        return new JsonResponse(['data' => (new RsvpResource($rsvp))->asOwner()->resolve($request)]);
    }

    public function decline(InvitationResponseRequest $request): JsonResponse
    {
        $invitation = $this->manager()->invitations()->decline($request->token(), $this->user($request));

        return new JsonResponse(['data' => ['status' => $invitation->status->value]]);
    }
}
