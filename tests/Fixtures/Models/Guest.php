<?php

declare(strict_types=1);

namespace RoundlyConsulting\Events\Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Notifications\Notifiable;
use RoundlyConsulting\Events\Tests\Fixtures\Factories\GuestFactory;

/**
 * The host's own guest model for people without accounts (the docs recipe).
 */
class Guest extends Model
{
    /** @use HasFactory<GuestFactory> */
    use HasFactory;

    use Notifiable;

    protected $table = 'guests';

    protected $guarded = [];

    protected static function newFactory(): GuestFactory
    {
        return GuestFactory::new();
    }
}
