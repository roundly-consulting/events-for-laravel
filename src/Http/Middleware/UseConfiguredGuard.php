<?php

declare(strict_types=1);

namespace RoundlyConsulting\Events\Http\Middleware;

use Closure;
use Illuminate\Auth\Middleware\Authenticate;
use Illuminate\Contracts\Auth\Factory as Auth;
use Illuminate\Http\Request;
use RoundlyConsulting\Events\Support\EventsConfig;
use Symfony\Component\HttpFoundation\Response;

/**
 * Read routes allow guests, but a caller signed in through a guard `events.routes.auth_middleware`
 * names (`auth:sanctum`) is still that user: the first of those guards that authenticates the
 * request becomes the request's guard, as Laravel's `auth` middleware does — without refusing
 * anyone. Plain `auth` (the default guard) and any other middleware change nothing here.
 *
 * @internal
 */
final readonly class UseConfiguredGuard
{
    public function __construct(
        private Auth $auth,
    ) {}

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        foreach (self::guards() as $guard) {
            if ($this->auth->guard($guard)->check()) {
                $this->auth->shouldUse($guard);

                break;
            }
        }

        return $next($request);
    }

    /**
     * The guards named by `auth:<guards>` (or `Authenticate::class.':<guards>'`) entries.
     *
     * @return list<string>
     */
    public static function guards(): array
    {
        $guards = [];

        foreach (EventsConfig::authMiddleware() as $middleware) {
            [$name, $parameters] = array_pad(explode(':', $middleware, 2), 2, '');

            if ($name === 'auth' || is_a($name, Authenticate::class, true)) {
                foreach (explode(',', $parameters) as $guard) {
                    $guard = trim($guard);

                    if ($guard !== '' && ! in_array($guard, $guards, true)) {
                        $guards[] = $guard;
                    }
                }
            }
        }

        return $guards;
    }
}
