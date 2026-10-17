<?php

declare(strict_types=1);

namespace Filagora\Messages\Attachments;

use Filagora\Messages\Events\MessageReceived;

/**
 * When a message is sent, the files its body refers to (found with forums' `attachments.inline_pattern`)
 * that the sender uploaded and nobody has claimed become the message's. Does nothing when forums
 * is not installed or the message links are not in its attachments table.
 */
final class ClaimMessageAttachments
{
    public function handle(MessageReceived $event): void
    {
        $model = config('filagora.models.post_attachment') ?? config('filagora-forums.models.post_attachment');

        if (! is_string($model) || ! AttachmentLinks::installed()) {
            return;
        }

        $pattern = (string) config('filagora-forums.attachments.inline_pattern', '#post-attachments/(\d+)#');

        if (@preg_match_all($pattern, (string) $event->message->body, $matches) === false || ($matches[1] ?? []) === []) {
            return;
        }

        $model::query()
            ->whereIn('id', array_values(array_unique(array_map('intval', $matches[1]))))
            ->where('uploader_id', $event->message->sender_id)
            ->whereNull('post_id')
            ->whereNull('thread_id')
            ->whereNull('private_message_id')
            ->update(['private_message_id' => $event->message->getKey(), 'private_conversation_id' => $event->conversation->getKey()]);
    }
}
