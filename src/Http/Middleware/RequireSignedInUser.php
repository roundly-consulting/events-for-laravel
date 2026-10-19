<?php

declare(strict_types=1);

namespace RoundlyConsulting\Events\Http\Middleware;

use Closure;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The last middleware of the API's write group: no signed-in Eloquent user, no write — whatever
 * `events.routes.auth_middleware` holds (even `[]`, or a guard that lets guests through). It runs
 * before the form requests validate, so a guest gets 401, never a 422 that confirms the input
 * shape. The host's exception handler renders the 401.
 *
 * @internal
 */
final class RequireSignedInUser
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->user() instanceof Model) {
            throw new AuthenticationException;
        }

        return $next($request);
    }
}
