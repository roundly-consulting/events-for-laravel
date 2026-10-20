<?php

declare(strict_types=1);

namespace RoundlyConsulting\Events\Tests\Fixtures;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use RoundlyConsulting\Events\Tests\Fixtures\Models\User;

/**
 * The JSON API behind a token guard that is not the app's default (`auth:api-token`, as
 * `auth:sanctum` would be): a request carrying `X-User` is that user, on that guard only.
 */
abstract class TokenGuardApiTestCase extends ApiTestCase
{
    /**
     * @return array<string, mixed>
     */
    protected function configBeforeBoot(): array
    {
        return [
            ...parent::configBeforeBoot(),
            'auth.guards.api-token' => ['driver' => 'events-test-token'],
            'events.routes.auth_middleware' => ['auth:api-token'],
        ];
    }

    protected function setUp(): void
    {
        parent::setUp();

        Auth::viaRequest('events-test-token', static fn (Request $request): ?User => $request->hasHeader('X-User')
            ? User::query()->find($request->header('X-User'))
            : null);
    }
}
