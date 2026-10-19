<?php

declare(strict_types=1);

namespace RoundlyConsulting\Events\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Events\Models\Event;
use RoundlyConsulting\MediaLibrary\Models\Media;

/**
 * An event's poster was set, replaced or removed (`poster` null).
 */
final readonly class PosterChanged implements ShouldDispatchAfterCommit
{
    public function __construct(
        public Event $event,
        public ?Media $poster,
        public ?Model $actor,
    ) {}
}
