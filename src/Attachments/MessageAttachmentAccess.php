<?php

declare(strict_types=1);

namespace Filagora\Messages\Attachments;

use Filagora\Forums\Models\PostAttachment;
use Filagora\Messages\Support\Models;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * The rule forums asks about a file that belongs to a private message: only people still in the
 * conversation (who have not removed it) may see it. A file that belongs to no message is not
 * this rule's to judge.
 */
final class MessageAttachmentAccess
{
    public function __invoke(PostAttachment $attachment, ?Authenticatable $viewer): ?bool
    {
        if ($attachment->getAttribute('private_message_id') === null) {
            return null;
        }

        if ($viewer === null) {
            return false;
        }

        return Models::class('participant')::query()
            ->where('conversation_id', $attachment->getAttribute('private_conversation_id'))
            ->where('user_id', $viewer->getAuthIdentifier())
            ->whereNull('deleted_at')
            ->exists();
    }
}
