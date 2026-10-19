<?php

declare(strict_types=1);

namespace RoundlyConsulting\Events\Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use RoundlyConsulting\Events\Concerns\OrganizesEvents;
use RoundlyConsulting\Events\Contracts\Organizer;
use RoundlyConsulting\Events\Tests\Fixtures\Factories\TeamFactory;

/**
 * A team organizer: its members manage its events (the Organizer recipe).
 */
class Team extends Model implements Organizer
{
    /** @use HasFactory<TeamFactory> */
    use HasFactory;

    use OrganizesEvents;

    protected $table = 'teams';

    protected $guarded = [];

    /**
     * @return BelongsToMany<User, $this>
     */
    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'team_members')->withPivot('role');
    }

    /**
     * The recipe: members manage the team's events — in PHP …
     */
    public function allowsEventManagementBy(Model $user): bool
    {
        return $this->members()->whereKey($user->getKey())->exists();
    }

    /**
     * … and in SQL, so `managedBy()` and `visibleTo()` agree with the policy.
     *
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     */
    public function scopeManagingEventsFor(Builder $query, Model $user): void
    {
        $query->whereHas('members', static fn (Builder $members): Builder => $members->whereKey($user->getKey()));
    }

    public function eventOrganizerUrl(): ?string
    {
        return 'https://example.com/teams/'.$this->getKey();
    }

    protected static function newFactory(): TeamFactory
    {
        return TeamFactory::new();
    }
}
