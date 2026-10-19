<?php

declare(strict_types=1);

namespace RoundlyConsulting\Events\Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use RoundlyConsulting\Events\Concerns\OrganizesEvents;
use RoundlyConsulting\Events\Contracts\Organizer;
use RoundlyConsulting\Events\Tests\Fixtures\Factories\UserFactory;

/**
 * The host's user: an attendee and an organizer.
 */
class User extends Authenticatable implements Organizer
{
    /** @use HasFactory<UserFactory> */
    use HasFactory;

    use Notifiable;
    use OrganizesEvents;
    use SoftDeletes;

    protected $table = 'users';

    protected $guarded = [];

    protected static function newFactory(): UserFactory
    {
        return UserFactory::new();
    }
}
