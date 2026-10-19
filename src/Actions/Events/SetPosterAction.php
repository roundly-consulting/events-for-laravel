<?php

declare(strict_types=1);

namespace RoundlyConsulting\Events\Actions\Events;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use RoundlyConsulting\Events\Events\PosterChanged;
use RoundlyConsulting\Events\Models\Event;
use RoundlyConsulting\Events\Support\EventsConfig;
use RoundlyConsulting\Events\Support\EventTransaction;
use RoundlyConsulting\Events\Support\LockedEvent;
use RoundlyConsulting\Events\Support\Posters;
use RoundlyConsulting\MediaLibrary\MediaLibraryManager;
use SensitiveParameter;

/**
 * Sets, replaces or removes the poster: an upload, a local file path (URLs are not fetched), a
 * draft token from an upload-first form (`MediaLibrary::draft()`), or null to remove it. The
 * bucket keeps a single file, validates type, size and dimensions, and queues its variants.
 */
final readonly class SetPosterAction
{
    public function __construct(
        private MediaLibraryManager $media,
        private Dispatcher $events,
    ) {}

    public function execute(Event $event, UploadedFile|string|null $file = null, #[SensitiveParameter] ?string $draft = null, ?Model $actor = null): Event
    {
        return EventTransaction::run($event, function () use ($event, $file, $draft, $actor): Event {
            LockedEvent::lock($event);
            $bucket = EventsConfig::posterBucket();

            if ($draft !== null) {
                $this->media->bindDraft($draft, $event, $bucket);
            } elseif ($file !== null) {
                $event->addMedia($file)->toMediaBucket($bucket);
            } else {
                Posters::clear($event);
            }

            $event->unsetRelation('media');
            $this->events->dispatch(new PosterChanged($event, Posters::of($event), $actor));

            return $event;
        });
    }
}
