<?php

declare(strict_types=1);

namespace RoundlyConsulting\Events\Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * A host user keyed by uuid (the key-type alignment cases).
 */
class UuidUser extends Model
{
    use HasUuids;

    protected $table = 'uuid_users';

    protected $guarded = [];
}
