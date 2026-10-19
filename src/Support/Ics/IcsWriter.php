<?php

declare(strict_types=1);

namespace RoundlyConsulting\Events\Support\Ics;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Events\Contracts\EventUrls;
use RoundlyConsulting\Events\Contracts\Organizer;
use RoundlyConsulting\Events\Enums\EventPhase;
use RoundlyConsulting\Events\Models\Event;
use RoundlyConsulting\Events\Models\Venue;
use RoundlyConsulting\Events\Support\Clock;
use RoundlyConsulting\Events\Support\OnlineUrls;

/**
 * RFC 5545 calendar text for events. The TEXT escaping, parameter sanitising and 75-octet
 * folding are copied from appointments-for-laravel's generator (`Support\Ics\IcsGenerator`).
 *
 * Timed events use UTC instants (`…Z`, DST-safe for single events); all-day events use
 * `VALUE=DATE` with the exclusive end day. Drafts are never written. The join link goes in only
 * for a viewer who may see it, and there are never ATTENDEE lines.
 *
 * @internal reach it through `Events::for($event)->ics()`, `Events::ics()` or `Events::feeds()`
 */
final readonly class IcsWriter
{
    private const string CRLF = "\r\n";

    private const string PRODID = '-//Roundly Consulting//Events for Laravel//EN';

    public function __construct(
        private EventUrls $urls,
    ) {}

    /**
     * @param  iterable<Event>  $events
     * @param  list<string>  $headers  extra calendar properties (`X-WR-CALNAME:…`), already escaped
     */
    public function write(iterable $events, ?Model $viewer = null, array $headers = []): string
    {
        $lines = ['BEGIN:VCALENDAR', 'VERSION:2.0', 'PRODID:'.self::PRODID, 'CALSCALE:GREGORIAN', ...$headers];

        foreach ($events as $event) {
            if ($event->phase !== EventPhase::Draft) {
                $lines = [...$lines, ...$this->event($event, $viewer)];
            }
        }

        $lines[] = 'END:VCALENDAR';

        return implode(self::CRLF, array_map($this->fold(...), $lines)).self::CRLF;
    }

    /**
     * Escape a TEXT value for a header line a caller builds (`X-WR-CALNAME:`).
     */
    public function text(string $value): string
    {
        return $this->escape($value);
    }

    /**
     * @return list<string>
     */
    private function event(Event $event, ?Model $viewer): array
    {
        $onlineUrl = OnlineUrls::for($event, $viewer);
        $pageUrl = $this->urls->event($event);
        $lines = [
            'BEGIN:VEVENT',
            'UID:'.$this->uid($event),
            'DTSTAMP:'.$this->stamp(Clock::now()),
            'SEQUENCE:'.$event->sequence,
            ...$this->dates($event),
            'SUMMARY:'.$this->escape((string) $event->title),
            'STATUS:'.$this->status($event),
        ];

        if ($event->updated_at !== null) {
            $lines[] = 'LAST-MODIFIED:'.$this->stamp($event->updated_at);
        }

        $description = trim(strip_tags((string) $event->description));

        if ($onlineUrl !== null && $event->venue_id !== null) {
            $description = trim($description."\n\n".__('events::feeds.join_online', ['url' => $onlineUrl]));
        }

        if ($description !== '') {
            $lines[] = 'DESCRIPTION:'.$this->escape($description);
        }

        $venue = $event->venue;
        $location = $venue instanceof Venue ? $this->location($venue) : $onlineUrl;

        if ($location !== null && $location !== '') {
            $lines[] = 'LOCATION:'.$this->escape($location);
        }

        if ($venue instanceof Venue && $venue->coordinates !== null) {
            $lines[] = sprintf('GEO:%s;%s', $this->coordinate($venue->coordinates->latitude), $this->coordinate($venue->coordinates->longitude));
        }

        $url = $pageUrl ?? ($venue === null ? $onlineUrl : null);

        if ($url !== null) {
            $lines[] = 'URL:'.$this->withoutControls($url);
        }

        $categories = $this->categories($event);

        if ($categories !== []) {
            $lines[] = 'CATEGORIES:'.implode(',', array_map($this->escape(...), $categories));
        }

        $organizer = $this->organizer($event);

        if ($organizer !== null) {
            $lines[] = $organizer;
        }

        $lines[] = 'END:VEVENT';

        return $lines;
    }

    /**
     * @return list<string>
     */
    private function dates(Event $event): array
    {
        if ($event->all_day) {
            $start = CarbonImmutable::parse((string) $event->starts_on);
            $end = CarbonImmutable::parse((string) $event->ends_on)->addDay();

            return ['DTSTART;VALUE=DATE:'.$start->format('Ymd'), 'DTEND;VALUE=DATE:'.$end->format('Ymd')];
        }

        return ['DTSTART:'.$this->stamp($event->starts_at), 'DTEND:'.$this->stamp($event->ends_at)];
    }

    /**
     * RFC 5545 §3.8.4.7: a UID unique across every calendar it can meet — the event's random
     * uuid, qualified by the app's host.
     */
    private function uid(Event $event): string
    {
        $url = config('app.url');
        $host = is_string($url) ? parse_url($url, PHP_URL_HOST) : null;

        return sprintf('%s@%s', $event->uuid, is_string($host) && $host !== '' ? $host : 'events-for-laravel');
    }

    private function stamp(CarbonImmutable $moment): string
    {
        return $moment->utc()->format('Ymd\THis\Z');
    }

    private function status(Event $event): string
    {
        return match ($event->phase) {
            EventPhase::Postponed => 'TENTATIVE',
            EventPhase::Cancelled => 'CANCELLED',
            default => 'CONFIRMED',
        };
    }

    private function location(Venue $venue): string
    {
        return implode(', ', array_values(array_filter([(string) $venue->name, $venue->formattedAddress()], static fn (string $part): bool => $part !== '')));
    }

    /**
     * The category and the labels, by name.
     *
     * @return list<string>
     */
    private function categories(Event $event): array
    {
        $names = [];

        foreach ([$event->category, ...$event->labels] as $tag) {
            $name = $tag?->getTranslation('name', app()->getLocale());

            if (is_string($name) && $name !== '') {
                $names[] = $name;
            }
        }

        return array_values(array_unique($names));
    }

    /**
     * `ORGANIZER;CN=…:mailto:…` when the organizer offers an email through the optional
     * `eventOrganizerEmail()` method.
     */
    private function organizer(Event $event): ?string
    {
        $organizer = $event->organizer;

        if (! $organizer instanceof Organizer || ! method_exists($organizer, 'eventOrganizerEmail')) {
            return null;
        }

        $email = $organizer->eventOrganizerEmail();

        if (! is_string($email) || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            return null;
        }

        $name = $organizer->eventOrganizerName();
        $params = $name !== '' ? ';CN='.$this->param($name) : '';

        return 'ORGANIZER'.$params.':mailto:'.$this->param($email, quote: false);
    }

    /**
     * Sanitise an iCalendar parameter value, quoting it when it carries separators.
     */
    private function param(string $value, bool $quote = true): string
    {
        // A parameter value can hold neither a DQUOTE nor any control character (§3.1).
        $clean = str_replace('"', '', $this->withoutControls($value));

        if ($quote && (str_contains($clean, ':') || str_contains($clean, ';') || str_contains($clean, ','))) {
            return '"'.$clean.'"';
        }

        return $clean;
    }

    private function coordinate(float $value): string
    {
        return rtrim(rtrim(number_format($value, 6, '.', ''), '0'), '.');
    }

    /**
     * Escape a TEXT value (RFC 5545 §3.3.11). Every line break — CRLF, a lone CR or LF — becomes
     * one escaped `\n`, so no raw CR/LF can end the content line early; any other control
     * character TEXT cannot carry is dropped (a tab is allowed).
     */
    private function escape(string $value): string
    {
        $value = preg_replace('/\r\n?/', "\n", $value) ?? $value;
        $value = preg_replace('/[\x00-\x08\x0B-\x1F\x7F]/', '', $value) ?? $value;

        return str_replace(['\\', "\n", ',', ';'], ['\\\\', '\\n', '\\,', '\;'], $value);
    }

    /** Strip every control character except a tab. */
    private function withoutControls(string $value): string
    {
        return preg_replace('/[\x00-\x08\x0A-\x1F\x7F]/', '', $value) ?? $value;
    }

    /**
     * Fold lines longer than 75 octets (continuation lines start with one space, which counts),
     * never splitting a UTF-8 character.
     */
    private function fold(string $line): string
    {
        if (strlen($line) <= 75) {
            return $line;
        }

        $chunks = [];
        $current = '';
        $limit = 75;

        foreach (mb_str_split($line) as $char) {
            if (strlen($current) + strlen($char) > $limit) {
                $chunks[] = $current;
                $current = '';
                $limit = 74;
            }

            $current .= $char;
        }

        $chunks[] = $current;

        return implode(self::CRLF.' ', $chunks);
    }
}
