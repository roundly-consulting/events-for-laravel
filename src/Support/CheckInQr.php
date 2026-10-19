<?php

declare(strict_types=1);

namespace RoundlyConsulting\Events\Support;

use RoundlyConsulting\Events\Models\Rsvp;
use RoundlyConsulting\Qr\DataTransferObjects\QrOptions;
use RoundlyConsulting\Qr\Enums\Sensitivity;
use RoundlyConsulting\Qr\QrManager;
use RoundlyConsulting\Qr\ValueObjects\Svg;

/**
 * The check-in code as a QR SVG — always Secret sensitivity in qr-for-laravel: never memoised,
 * never put in the SVG cache, served `no-store`. The payload is the bare code, or
 * `events.check_in.url` with `{code}` filled in. qr's `<x-qr-code>` component renders Public
 * (cacheable) QR codes, so it must never draw a check-in code.
 *
 * @internal
 */
final readonly class CheckInQr
{
    public function __construct(
        private QrManager $qr,
    ) {}

    public function render(Rsvp $rsvp, ?QrOptions $options = null): ?Svg
    {
        $code = $rsvp->checkInCode();

        if ($code === null) {
            return null;
        }

        $template = EventsConfig::checkInUrl();
        $pending = $this->qr->text($template === null ? $code : str_replace('{code}', $code, $template))
            ->sensitivity(Sensitivity::Secret);

        if ($options !== null) {
            $pending = $pending->withOptions($options)->sensitivity(Sensitivity::Secret);
        }

        return $pending->svg();
    }
}
