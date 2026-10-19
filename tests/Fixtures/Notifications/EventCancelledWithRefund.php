<?php

declare(strict_types=1);

namespace RoundlyConsulting\Events\Tests\Fixtures\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use RoundlyConsulting\Events\Notifications\EventCancelledNotification;

/**
 * The README's override: the package's notice plus one line.
 */
final class EventCancelledWithRefund extends EventCancelledNotification
{
    public function toMail(object $notifiable): MailMessage
    {
        return parent::toMail($notifiable)->line('Your ticket is refunded within 5 days.');
    }
}
