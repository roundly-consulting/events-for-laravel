<?php

declare(strict_types=1);

namespace RoundlyConsulting\Events\Enums;

use RoundlyConsulting\Enums\Attributes\TranslatedLabels;
use RoundlyConsulting\Enums\Helpers;

/**
 * Who finds and sees an event: `public` (listed), `unlisted` (link-only, by its uuid) or `private` (invite-only).
 */
#[TranslatedLabels('events::enums.visibility')]
enum Visibility: string
{
    use Helpers;

    case Public = 'public';
    case Unlisted = 'unlisted';
    case Private = 'private';
}
