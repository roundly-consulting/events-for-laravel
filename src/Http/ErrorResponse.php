<?php

declare(strict_types=1);

namespace RoundlyConsulting\Events\Http;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use RoundlyConsulting\Events\Exceptions\CheckInRefusedException;
use RoundlyConsulting\Events\Exceptions\EventsException;
use RoundlyConsulting\Events\Exceptions\EventTransitionDeniedException;
use RoundlyConsulting\Events\Support\Messages;
use RoundlyConsulting\Lifecycle\Exceptions\SubjectTrashedException;
use RoundlyConsulting\MediaLibrary\Exceptions\DraftMediaExpired;
use RoundlyConsulting\MediaLibrary\Exceptions\DraftMediaNotFound;
use RoundlyConsulting\MediaLibrary\Exceptions\FileUnacceptableForBucket;
use RoundlyConsulting\Tags\Exceptions\InvalidTagException;
use RoundlyConsulting\Tags\Exceptions\TagLimitExceededException;
use RoundlyConsulting\Tags\Exceptions\TagNotFoundException;
use RoundlyConsulting\Tags\Exceptions\TagScopeMismatchException;
use RoundlyConsulting\Tags\Exceptions\TagTypeMismatchException;
use RoundlyConsulting\Tags\Exceptions\TagTypeNotAllowedException;
use RoundlyConsulting\Tags\Exceptions\UnknownTagTypeException;
use Throwable;

/**
 * The API's error body — `{message, code}` (plus `reason`, `denials` where they help) with the
 * package's status — for the exceptions a client can cause. Messages are the package's own,
 * translated; none names a class. Anything else is rethrown to the host's handler, unchanged.
 *
 * @internal
 */
final class ErrorResponse
{
    public static function from(Throwable $exception): ?JsonResponse
    {
        return match (true) {
            $exception instanceof EventTransitionDeniedException => self::json($exception->status(), $exception->errorCode(), $exception->getMessage(), [
                'denials' => $exception->denials(),
            ], $exception->retryAfterSeconds() > 0 ? ['Retry-After' => (string) $exception->retryAfterSeconds()] : []),
            $exception instanceof CheckInRefusedException => self::json($exception->status(), $exception->errorCode(), $exception->getMessage(), ['reason' => $exception->reason()]),
            $exception instanceof EventsException => self::json($exception->status(), $exception->errorCode(), $exception->getMessage()),
            $exception instanceof AuthorizationException => self::json(403, 'forbidden', Messages::get('forbidden')),
            // lifecycle's message names the subject's class and key: the client gets ours.
            $exception instanceof SubjectTrashedException => self::json(409, 'event_trashed', Messages::get('event_trashed')),
            // Another scope's tag answers exactly like a missing one: no existence oracle.
            $exception instanceof TagNotFoundException, $exception instanceof TagScopeMismatchException => self::json(422, 'tag_not_found', Messages::get('tag_not_found')),
            $exception instanceof TagLimitExceededException => self::json(422, 'tag_limit_exceeded', Messages::get('tag_limit_exceeded')),
            $exception instanceof InvalidTagException, $exception instanceof TagTypeMismatchException,
            $exception instanceof TagTypeNotAllowedException, $exception instanceof UnknownTagTypeException => self::json(422, 'invalid_tag', Messages::get('invalid_tag')),
            $exception instanceof FileUnacceptableForBucket => self::json(422, 'invalid_poster', Messages::get('invalid_poster')),
            $exception instanceof DraftMediaNotFound, $exception instanceof DraftMediaExpired => self::json(422, 'invalid_poster', Messages::get('invalid_poster')),
            default => null,
        };
    }

    /**
     * @param  array<string, mixed>  $detail
     * @param  array<string, string>  $headers
     */
    public static function json(int $status, string $code, string $message, array $detail = [], array $headers = []): JsonResponse
    {
        return new JsonResponse(['message' => $message, 'code' => $code, ...$detail], $status, $headers);
    }
}
