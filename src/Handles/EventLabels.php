<?php

declare(strict_types=1);

namespace RoundlyConsulting\Events\Handles;

use Illuminate\Database\Eloquent\Collection;
use RoundlyConsulting\Events\Actions\Tags\AttachLabelsAction;
use RoundlyConsulting\Events\Actions\Tags\DetachLabelsAction;
use RoundlyConsulting\Events\Actions\Tags\SyncLabelsAction;
use RoundlyConsulting\Events\Enums\EventOperation;
use RoundlyConsulting\Events\Enums\EventTagType;
use RoundlyConsulting\Tags\DataTransferObjects\TagChanges;
use RoundlyConsulting\Tags\Models\Tag;

/**
 * `Events::for($event)->labels()` — the event's free-form labels. Writes check `update` when the
 * handle names an actor and go through tags-for-laravel, so both `Events::fake()` and
 * `Tags::fake()` record them.
 */
final readonly class EventLabels
{
    public function __construct(
        private EventHandle $handle,
    ) {}

    /**
     * Add labels: names (created when new), slugs, keys or tags.
     *
     * @param  iterable<mixed>|Tag|int|string  $labels
     */
    public function attach(iterable|Tag|int|string $labels): self
    {
        $this->handle->authorize('update');
        $this->handle->manager()->perform(
            EventOperation::AttachLabels,
            AttachLabelsAction::class,
            fn (AttachLabelsAction $action): TagChanges => $action->execute($this->handle->event(), $labels, $this->handle->actor(), $this->handle->isSilent()),
            $this->handle->context(['labels' => $labels]),
        );

        return $this;
    }

    /**
     * @param  iterable<mixed>|Tag|int|string  $labels
     */
    public function detach(iterable|Tag|int|string $labels): self
    {
        $this->handle->authorize('update');
        $this->handle->manager()->perform(
            EventOperation::DetachLabels,
            DetachLabelsAction::class,
            fn (DetachLabelsAction $action): TagChanges => $action->execute($this->handle->event(), $labels, $this->handle->actor(), $this->handle->isSilent()),
            $this->handle->context(['labels' => $labels]),
        );

        return $this;
    }

    /**
     * Exactly these labels (an empty list clears them).
     *
     * @param  iterable<mixed>  $labels
     */
    public function sync(iterable $labels): self
    {
        $this->handle->authorize('update');
        $this->handle->manager()->perform(
            EventOperation::SyncLabels,
            SyncLabelsAction::class,
            fn (SyncLabelsAction $action): TagChanges => $action->execute($this->handle->event(), $labels, $this->handle->actor(), $this->handle->isSilent()),
            $this->handle->context(['labels' => $labels]),
        );

        return $this;
    }

    /**
     * The event's labels, in tag order.
     *
     * @return Collection<int, Tag>
     */
    public function all(): Collection
    {
        return $this->handle->event()->fresh(['tags'])?->tagsOf(EventTagType::Label) ?? new Collection;
    }
}
