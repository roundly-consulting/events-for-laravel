<?php

declare(strict_types=1);

namespace RoundlyConsulting\Events\Support;

use Illuminate\Support\Facades\App;
use RoundlyConsulting\Events\Contracts\EventUrls;
use RoundlyConsulting\Events\Enums\Visibility;
use RoundlyConsulting\Events\Models\Event;
use RoundlyConsulting\Events\Models\Rsvp;
use SensitiveParameter;

/**
 * The default {@see EventUrls}: the `events.urls.*` templates, each placeholder URL-encoded.
 *
 * A public event uses `urls.event` (`{uuid}`, `{slug}`, `{locale}`). An unlisted or private
 * event uses `urls.event_link` (its page resolves by uuid only); when that is blank,
 * `urls.event` is used only if it has no `{slug}`, else there is no link — a slug link would 404
 * for the very people it was sent to.
 */
final class TemplateEventUrls implements EventUrls
{
    public function event(Event $event): ?string
    {
        $template = $event->visibility === Visibility::Public
            ? EventsConfig::eventUrl()
            : EventsConfig::eventLinkUrl() ?? $this->withoutSlug(EventsConfig::eventUrl());

        if ($template === null) {
            return null;
        }

        $slug = $event->slug;

        if (str_contains($template, '{slug}') && ($slug === null || $slug === '')) {
            return null;
        }

        return strtr($template, [
            '{uuid}' => rawurlencode($event->uuid),
            '{slug}' => rawurlencode((string) $slug),
            '{locale}' => rawurlencode(App::getLocale()),
        ]);
    }

    public function invitation(#[SensitiveParameter] string $token): ?string
    {
        $template = EventsConfig::invitationUrl();

        return $template === null ? null : strtr($template, ['{token}' => rawurlencode($token), '{locale}' => rawurlencode(App::getLocale())]);
    }

    public function rsvp(Rsvp $rsvp): ?string
    {
        $template = EventsConfig::rsvpUrl();
        $event = $rsvp->event;

        if ($template === null) {
            return null;
        }

        return strtr($template, [
            '{uuid}' => rawurlencode($event->uuid),
            '{rsvp}' => rawurlencode((string) $rsvp->getKey()),
            '{locale}' => rawurlencode(App::getLocale()),
        ]);
    }

    private function withoutSlug(?string $template): ?string
    {
        return $template === null || str_contains($template, '{slug}') ? null : $template;
    }
}
