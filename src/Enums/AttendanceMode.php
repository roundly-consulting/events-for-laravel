<?php

declare(strict_types=1);

namespace RoundlyConsulting\Events\Enums;

use RoundlyConsulting\Enums\Attributes\TranslatedLabels;
use RoundlyConsulting\Enums\Helpers;

/**
 * Where an event happens: at a venue, online, or both (schema.org `eventAttendanceMode`).
 */
#[TranslatedLabels('events::enums.attendance_mode')]
enum AttendanceMode: string
{
    use Helpers;

    case Offline = 'offline';
    case Online = 'online';
    case Mixed = 'mixed';

    /**
     * The schema.org enumeration member.
     */
    public function schemaOrg(): string
    {
        return match ($this) {
            self::Offline => 'https://schema.org/OfflineEventAttendanceMode',
            self::Online => 'https://schema.org/OnlineEventAttendanceMode',
            self::Mixed => 'https://schema.org/MixedEventAttendanceMode',
        };
    }
}
