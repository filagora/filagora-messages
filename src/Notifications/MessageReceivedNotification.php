<?php

declare(strict_types=1);

namespace Filagora\Messages\Notifications;

use Filagora\Messages\Models\PrivateConversation;
use Filagora\Messages\Models\PrivateMessage;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

/**
 * Tells a member they have a new private message.
 */
final class MessageReceivedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(private readonly PrivateConversation $conversation, private readonly PrivateMessage $message) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)
            ->subject(__('filagora-messages::messages.received.subject', ['subject' => $this->conversation->subject]))
            ->line(__('filagora-messages::messages.received.intro', ['subject' => $this->conversation->subject]))
            ->line(Str::limit(trim(strip_tags($this->message->body)), 300));

        $name = config('filagora-messages.mail.routes.conversation');

        return is_string($name) && $name !== '' && Route::has($name)
            ? $mail->action(__('filagora-messages::messages.received.action'), route($name, ['conversation' => $this->conversation]))
            : $mail;
    }
}
