<?php

declare(strict_types=1);

namespace RoundlyConsulting\Events\Support;

use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Events\Models\Event;

/**
 * The join link is for managers and going attendees only (the policy's `viewOnlineUrl`): never a
 * guest, never a feed, never search engines.
 *
 * @internal
 */
final class OnlineUrls
{
    public static function for(Event $event, ?Model $viewer): ?string
    {
        if ($event->online_url === null || $viewer === null) {
            return null;
        }

        return EventAuthorization::allows($viewer, 'viewOnlineUrl', $event) ? $event->online_url : null;
    }
}
