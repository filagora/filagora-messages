<?php

declare(strict_types=1);

namespace Filagora\Messages\Listeners;

use Filagora\Messages\Events\MessageReceived;
use Filagora\Messages\Notifications\MessageReceivedNotification;
use Illuminate\Support\Facades\Notification;

/**
 * The default email for a new message. Silent when `filagora-messages.mail.enabled` is off, for a
 * host that notifies through its own channels from the same event.
 */
final class SendMessageMail
{
    public function handle(MessageReceived $event): void
    {
        if ((bool) config('filagora-messages.mail.enabled', true) && $event->recipients !== []) {
            Notification::send($event->recipients, new MessageReceivedNotification($event->conversation, $event->message));
        }
    }
}
