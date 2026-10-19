<?php

declare(strict_types=1);

namespace RoundlyConsulting\Events\Http\Requests;

use Illuminate\Http\UploadedFile;
use RoundlyConsulting\Events\Support\EventModels;
use RoundlyConsulting\Events\Support\EventsConfig;
use RoundlyConsulting\MediaLibrary\Facades\MediaLibrary;

/**
 * `PUT /events/{event}/poster` — an upload (`poster`, validated by media-library's rules for the
 * poster bucket) or a draft token from an upload-first form (`draft`).
 *
 * @internal
 */
final class PosterRequest extends EventsRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'poster' => ['required_without:draft', 'prohibits:draft', ...MediaLibrary::rulesFor(EventModels::event(), EventsConfig::posterBucket())],
            'draft' => ['required_without:poster', 'string', 'max:255'],
        ];
    }

    public function poster(): ?UploadedFile
    {
        $file = $this->validated('poster');

        return $file instanceof UploadedFile ? $file : null;
    }

    public function draft(): ?string
    {
        $draft = $this->validated('draft');

        return is_string($draft) ? $draft : null;
    }
}
