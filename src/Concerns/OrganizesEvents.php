<?php

declare(strict_types=1);

namespace RoundlyConsulting\Events\Concerns;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use RoundlyConsulting\Events\Builders\EventBuilder;
use RoundlyConsulting\Events\Contracts\Organizer;
use RoundlyConsulting\Events\EventsManager;
use RoundlyConsulting\Events\Models\Event;
use RoundlyConsulting\Events\Models\EventHost;
use RoundlyConsulting\Events\Support\EventModels;

/**
 * Makes a host model an event organizer (and a possible co-host). The defaults: the `name`
 * attribute (else the key) is the display name, there is no public page, and the model manages
 * only itself. Override `allowsEventManagementBy()` and `scopeManagingEventsFor()` together for a
 * team — membership in PHP and in SQL.
 *
 * @phpstan-require-extends Model
 *
 * @phpstan-require-implements Organizer
 */
trait OrganizesEvents
{
    public function eventOrganizerName(): string
    {
        $name = $this->getAttribute('name');

        return is_string($name) && $name !== '' ? $name : (string) $this->getKey();
    }

    public function eventOrganizerUrl(): ?string
    {
        return null;
    }

    public function allowsEventManagementBy(Model $user): bool
    {
        return $this->is($user);
    }

    /**
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     */
    public function scopeManagingEventsFor(Builder $query, Model $user): void
    {
        $user instanceof static
            ? $query->whereKey($user->getKey())
            : $query->whereRaw('1 = 0');
    }

    /**
     * The events this model organizes.
     *
     * @return MorphMany<Event, $this>
     */
    public function organizedEvents(): MorphMany
    {
        return $this->morphMany(EventModels::event(), 'organizer');
    }

    /**
     * Its co-host rows (`->with('event')` for the events themselves).
     *
     * @return MorphMany<EventHost, $this>
     */
    public function coHostedEvents(): MorphMany
    {
        return $this->morphMany(EventHost::class, 'host');
    }

    /**
     * `Events::make($title)->organizedBy($this)`.
     *
     * @param  array<string, string>|string  $title
     */
    public function organizeEvent(array|string $title): EventBuilder
    {
        return app(EventsManager::class)->make($title)->organizedBy($this);
    }
}
