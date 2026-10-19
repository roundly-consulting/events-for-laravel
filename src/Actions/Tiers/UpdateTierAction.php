<?php

declare(strict_types=1);

namespace RoundlyConsulting\Events\Actions\Tiers;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Events\Actions\Internal\AutoPromoteAction;
use RoundlyConsulting\Events\DataTransferObjects\UpdateTierData;
use RoundlyConsulting\Events\Events\TierUpdated;
use RoundlyConsulting\Events\Models\AdmissionTier;
use RoundlyConsulting\Events\Models\Event;
use RoundlyConsulting\Events\Support\EventTransaction;
use RoundlyConsulting\Events\Support\LockedEvent;
use RoundlyConsulting\Events\Support\NotGiven;
use RoundlyConsulting\Events\Support\SeatBook;
use RoundlyConsulting\Events\Support\TierInput;
use RoundlyConsulting\Events\Support\TierLookup;

/**
 * Changes a ticket type of the event (another event's is not found). A price change affects later
 * holds only; lowering the capacity below the seats taken bumps nobody — new requests wait; raising
 * it lets the waitlist move up.
 */
final readonly class UpdateTierAction
{
    public function __construct(
        private AutoPromoteAction $promote,
        private Dispatcher $events,
    ) {}

    public function execute(Event $event, AdmissionTier|int|string $tier, UpdateTierData $data, ?Model $actor = null): AdmissionTier
    {
        return EventTransaction::run($event, function () use ($event, $tier, $data, $actor): AdmissionTier {
            LockedEvent::lock($event);
            $tier = TierLookup::resolve($event, $tier);

            if (! $data->name instanceof NotGiven) {
                $tier->setAttribute('name', TierInput::name($data->name));
            }

            if (! $data->description instanceof NotGiven) {
                $description = TierInput::description($data->description);
                $description === null ? $tier->forgetAllTranslations('description') : $tier->setAttribute('description', $description);
            }

            if (! $data->key instanceof NotGiven && $data->key !== $tier->key) {
                $key = TierInput::key($data->key, []);
                TierInput::assertKeyFree($event, $key, $tier->getKey());
                $tier->key = $key;
            }

            if (! $data->price instanceof NotGiven) {
                $tier->price = TierInput::price($data->price);
            }

            if (! $data->capacity instanceof NotGiven) {
                $tier->capacity = TierInput::capacity($data->capacity);
            }

            if (! $data->saleStartsAt instanceof NotGiven) {
                $tier->sale_starts_at = TierInput::instant($data->saleStartsAt, $event->timezone);
            }

            if (! $data->saleEndsAt instanceof NotGiven) {
                $tier->sale_ends_at = TierInput::instant($data->saleEndsAt, $event->timezone);
            }

            TierInput::assertSaleWindow($tier->sale_starts_at, $tier->sale_ends_at);

            if (! $data->minParty instanceof NotGiven) {
                $tier->min_party = $data->minParty;
            }

            if (! $data->maxParty instanceof NotGiven) {
                $tier->max_party = $data->maxParty;
            }

            TierInput::assertPartyLimits($tier->min_party, $tier->max_party);

            if (! $data->hidden instanceof NotGiven) {
                $tier->hidden = $data->hidden;
            }

            $changed = array_keys($tier->getDirty());

            if ($changed !== []) {
                $tier->save();
                $event->unsetRelation('tiers');

                if (in_array('capacity', $changed, true)) {
                    $this->promote->execute(SeatBook::open($event));
                }

                $this->events->dispatch(new TierUpdated($tier, $changed, $actor));
            }

            return $tier;
        });
    }
}
