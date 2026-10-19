<?php

declare(strict_types=1);

namespace RoundlyConsulting\Events\Contracts;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * A host model that organizes or co-hosts events: a user, a team, a venue operator.
 * `Concerns\OrganizesEvents` supplies every method (a model is managed by itself).
 *
 * The two management methods are one rule in two forms — PHP for the policy, SQL for the
 * `managedBy()` / `visibleTo()` scopes — and must agree.
 */
interface Organizer
{
    /**
     * The name shown on the event (JSON-LD `organizer.name`, calendar files, notifications).
     */
    public function eventOrganizerName(): string;

    /**
     * A public page for the organizer (JSON-LD `organizer.url`), or null.
     */
    public function eventOrganizerUrl(): ?string;

    /**
     * Whether `$user` may manage the events this model organizes or co-hosts.
     */
    public function allowsEventManagementBy(Model $user): bool;

    /**
     * Narrow a query of this model's own table to the rows that let `$user` manage — the query
     * form of {@see self::allowsEventManagementBy()}.
     *
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     */
    public function scopeManagingEventsFor(Builder $query, Model $user): void;
}
