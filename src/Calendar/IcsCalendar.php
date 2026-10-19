<?php

declare(strict_types=1);

namespace RoundlyConsulting\Events\Calendar;

use Illuminate\Contracts\Support\Responsable;
use Illuminate\Http\Response;
use Stringable;

/**
 * An iCalendar file. Return it from a route — `text/calendar; charset=utf-8` with a filename,
 * an attachment for a single event and inline for a subscribable feed — or cast it to a string
 * for a mail attachment.
 */
final readonly class IcsCalendar implements Responsable, Stringable
{
    public function __construct(
        public string $content,
        public string $filename,
        public bool $inline = false,
    ) {}

    public function toResponse($request): Response
    {
        $filename = (string) preg_replace('/[^A-Za-z0-9._-]+/', '-', $this->filename);

        return new Response($this->content, 200, [
            'Content-Type' => 'text/calendar; charset=utf-8',
            'Content-Disposition' => sprintf('%s; filename="%s"', $this->inline ? 'inline' : 'attachment', $filename),
        ]);
    }

    public function __toString(): string
    {
        return $this->content;
    }
}
