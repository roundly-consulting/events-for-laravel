<?php

declare(strict_types=1);

namespace RoundlyConsulting\Events\Http\Controllers;

use Illuminate\Http\Request;
use RoundlyConsulting\Events\Calendar\IcsCalendar;
use RoundlyConsulting\Events\Http\Requests\FeedRequest;

/**
 * Calendar files: one event (the join link only for a viewer who may see it) and the public feed.
 *
 * @internal
 */
final class IcsController extends Controller
{
    public function show(Request $request, string $event): IcsCalendar
    {
        $model = $this->event($request, $event);

        return $this->manager()->for($model)->ics($this->user($request));
    }

    public function feed(FeedRequest $request): IcsCalendar
    {
        return $this->manager()->feeds()->public($request->toQuery());
    }
}
