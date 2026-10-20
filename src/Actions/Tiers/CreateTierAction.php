<?php

declare(strict_types=1);

namespace RoundlyConsulting\Events\Actions\Tiers;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\UniqueConstraintViolationException;
use RoundlyConsulting\Events\DataTransferObjects\TierData;
use RoundlyConsulting\Events\Events\TierCreated;
use RoundlyConsulting\Events\Exceptions\InvalidTierException;
use RoundlyConsulting\Events\Models\AdmissionTier;
use RoundlyConsulting\Events\Models\Event;
use RoundlyConsulting\Events\Support\EventModels;
use RoundlyConsulting\Events\Support\EventTransaction;
use RoundlyConsulting\Events\Support\LockedEvent;
use RoundlyConsulting\Events\Support\TierInput;

/**
 * Adds a ticket type to an event. Its key is given or derived from the fallback-locale name and
 * is never suffixed: a key the event already uses (a deleted ticket type's included) throws,
 * naming `tiers()->restore()` when a deleted one holds it — code names tiers by key.
 */
final readonly class CreateTierAction
{
    public function __construct(
        private Dispatcher $events,
    ) {}

    public function execute(Event $event, TierData $data, ?Model $actor = null): AdmissionTier
    {
        $model = EventModels::tier();

        return EventTransaction::run($event, function () use ($event, $data, $actor, $model): AdmissionTier {
            LockedEvent::lock($event);

            $name = TierInput::name($data->name);
            $key = TierInput::key($data->key, $name);
            TierInput::assertKeyFree($event, $key);
            TierInput::assertPartyLimits($data->minParty, $data->maxParty);
            $saleStartsAt = TierInput::instant($data->saleStartsAt, $event->timezone);
            $saleEndsAt = TierInput::instant($data->saleEndsAt, $event->timezone);
            TierInput::assertSaleWindow($saleStartsAt, $saleEndsAt);

            $tier = new $model;
            $tier->fill([
                'event_id' => $event->getKey(),
                'key' => $key,
                'name' => $name,
                'description' => TierInput::description($data->description),
                'price' => TierInput::price($data->price),
                'capacity' => TierInput::capacity($data->capacity),
                'sale_starts_at' => $saleStartsAt,
                'sale_ends_at' => $saleEndsAt,
                'min_party' => $data->minParty,
                'max_party' => $data->maxParty,
                'hidden' => $data->hidden,
                'position' => $data->position !== null ? TierInput::position($data->position) : ((int) $model::query()->withTrashed()->where('event_id', $event->getKey())->max('position') + 1),
            ]);

            try {
                $tier->save();
            } catch (UniqueConstraintViolationException) {
                throw InvalidTierException::keyTaken($key);
            }

            $event->unsetRelation('tiers');
            $this->events->dispatch(new TierCreated($tier, $actor));

            return $tier;
        });
    }
}
