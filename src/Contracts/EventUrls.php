<?php

declare(strict_types=1);

namespace RoundlyConsulting\Events\Contracts;

use RoundlyConsulting\Events\Models\Event;
use RoundlyConsulting\Events\Models\Rsvp;
use SensitiveParameter;

/**
 * Where your app shows an event, an invitation and an attendee's ticket. Feeds JSON-LD `url`,
 * calendar `URL` and notification buttons; null means no link. The default reads the
 * `events.urls.*` templates; bind your own (`events.urls.resolver`) to call `route()`.
 *
 * An unlisted or private event must never get a slug URL: its page resolves by uuid only.
 */
interface EventUrls
{
    public function event(Event $event): ?string;

    public function invitation(#[SensitiveParameter] string $token): ?string;

    public function rsvp(Rsvp $rsvp): ?string;
}
