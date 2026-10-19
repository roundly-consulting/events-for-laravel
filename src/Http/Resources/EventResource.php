<?php

declare(strict_types=1);

namespace RoundlyConsulting\Events\Http\Resources;

use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Route;
use RoundlyConsulting\Events\Contracts\EventUrls;
use RoundlyConsulting\Events\Http\Viewer;
use RoundlyConsulting\Events\Models\AdmissionTier;
use RoundlyConsulting\Events\Models\Event;
use RoundlyConsulting\Events\Models\EventHost;
use RoundlyConsulting\Events\Seo\EventJsonLd;
use RoundlyConsulting\Events\Support\EventAuthorization;
use RoundlyConsulting\Events\Support\EventsConfig;
use RoundlyConsulting\Events\Support\EventStates;
use RoundlyConsulting\Events\Support\Posters;
use RoundlyConsulting\Tags\Models\Tag;

/**
 * An event, rendered for one viewer. Managers see the state, the visibility, hidden ticket types
 * and the counts; the join link reaches managers and going attendees only; counts follow
 * `show_counts`. Related people render through the configured reference resources, and tags in a
 * minimal shape (never tags' usage counts, which add up private events too).
 *
 * @property Event $resource
 */
final class EventResource extends JsonResource
{
    private ?Viewer $viewer = null;

    public function for(Viewer $viewer): self
    {
        $this->viewer = $viewer;

        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $event = $this->resource;
        $viewer = $this->viewer ?? Viewer::of($request);
        $manages = $viewer->manages($event);
        $rsvp = $viewer->rsvp($event);
        $going = $viewer->isGoing($event);
        $counts = $manages || $event->show_counts;

        return [
            'id' => $event->uuid,
            'slug' => $event->slug,
            'title' => $event->title,
            'description' => $event->description,
            'phase' => $event->phase->value,
            ...($manages ? ['status' => (string) EventStates::stored($event->status), 'visibility' => $event->visibility->value] : []),
            'schema_status' => EventJsonLd::status($event),
            'attendance_mode' => $event->attendance_mode->value,
            'starts_at' => $this->local($event, $event->starts_at),
            'ends_at' => $this->local($event, $event->ends_at),
            'doors_open_at' => $this->local($event, $event->doors_open_at),
            'previous_starts_at' => $this->local($event, $event->previous_starts_at),
            'all_day' => $event->all_day,
            'timezone' => $event->timezone,
            'starts_on' => $event->starts_on,
            'ends_on' => $event->ends_on,
            'venue' => $event->venue === null ? null : (new VenueResource($event->venue))->resolve($request),
            'online_url' => $manages || $going ? $event->online_url : null,
            'organizer' => References::organizer($event->organizer, $request),
            ...($viewer->show ? ['hosts' => $event->hosts->map(static fn (EventHost $host): ?array => References::host($host->host, $request))->filter()->values()->all()] : []),
            'category' => $this->category($event->category),
            'labels' => $event->labels->map($this->tag(...))->values()->all(),
            'poster' => $this->poster($event),
            'tiers' => $event->tiers
                ->filter(static fn (AdmissionTier $tier): bool => $manages || ! $tier->hidden)
                ->map(static fn (AdmissionTier $tier): array => (new AdmissionTierResource($tier->setRelation('event', $event)))->forManager($manages)->resolve($request))
                ->values()->all(),
            'capacity' => $event->capacity,
            'seats_left' => $counts ? $event->seatsLeft() : null,
            'counts' => $counts ? [
                'going' => $event->going_count,
                'waitlisted' => $event->waitlist_count,
                ...($manages ? ['interested' => $event->interested_count] : []),
            ] : null,
            'viewer' => [
                'rsvp' => $rsvp === null ? null : (new RsvpResource($rsvp->setRelation('event', $event)))->asOwner()->resolve($request),
                ...($viewer->show ? ['can' => ['rsvp' => $viewer->user !== null && $this->allows($viewer, 'rsvp', $event), 'manage' => $manages, 'check_in' => $manages]] : []),
            ],
            'links' => [
                'self' => Route::has('events.show') ? route('events.show', ['event' => $event->uuid]) : null,
                'ics' => Route::has('events.calendar') ? route('events.calendar', ['event' => $event->uuid]) : null,
                'url' => App::make(EventUrls::class)->event($event),
            ],
        ];
    }

    private function local(Event $event, ?CarbonImmutable $moment): ?string
    {
        return $moment?->setTimezone($event->timezone)->toIso8601String();
    }

    /**
     * @return array<string, mixed>|null
     */
    private function category(?Tag $tag): ?array
    {
        if ($tag === null) {
            return null;
        }

        return [
            ...$this->tag($tag),
            'path' => $tag->ancestors()->map(static fn (Tag $ancestor): string => (string) $ancestor->name)->values()->all(),
        ];
    }

    /**
     * @return array{id: mixed, slug: string|null, name: string|null, color: string|null, icon: string|null}
     */
    private function tag(Tag $tag): array
    {
        return ['id' => $tag->getKey(), 'slug' => $tag->slug, 'name' => $tag->name, 'color' => $tag->color, 'icon' => $tag->icon];
    }

    /**
     * @return array{url: string|null, variants: array<string, string|null>}|null
     */
    private function poster(Event $event): ?array
    {
        if (Posters::of($event) === null) {
            return null;
        }

        $variants = [];

        foreach (EventsConfig::posterVariants() as $variant) {
            $variants[$variant->name] = Posters::url($event, $variant->name);
        }

        return ['url' => Posters::url($event), 'variants' => $variants];
    }

    private function allows(Viewer $viewer, string $ability, Event $event): bool
    {
        return $viewer->user !== null && EventAuthorization::allows($viewer->user, $ability, $event);
    }
}
