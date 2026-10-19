<?php

declare(strict_types=1);

namespace RoundlyConsulting\Events\Support;

use Closure;
use Illuminate\Database\Eloquent\Model;

/**
 * Every mutating action runs here: on the event's connection, retried after a deadlock when this
 * is the outermost transaction (Laravel never retries a nested level). Each attempt re-reads the
 * event row through {@see LockedEvent}, so a retry starts from fresh state.
 *
 * @internal
 */
final class EventTransaction
{
    public const int ATTEMPTS = 3;

    /**
     * @template TReturn
     *
     * @param  Closure(): TReturn  $callback
     * @return TReturn
     */
    public static function run(Model $model, Closure $callback): mixed
    {
        return $model->getConnection()->transaction($callback, self::ATTEMPTS);
    }
}
