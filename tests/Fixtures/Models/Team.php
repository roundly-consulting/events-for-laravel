<?php

declare(strict_types=1);

namespace RoundlyConsulting\Events\Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use RoundlyConsulting\Events\Tests\Fixtures\Factories\TeamFactory;

/**
 * A team organizer: its members manage its events (the Organizer recipe).
 */
class Team extends Model
{
    /** @use HasFactory<TeamFactory> */
    use HasFactory;

    protected $table = 'teams';

    protected $guarded = [];

    /**
     * @return BelongsToMany<User, $this>
     */
    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'team_members')->withPivot('role');
    }

    protected static function newFactory(): TeamFactory
    {
        return TeamFactory::new();
    }
}
