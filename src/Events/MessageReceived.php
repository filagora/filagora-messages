<?php

declare(strict_types=1);

namespace Filagora\Messages\Events;

use Filagora\Messages\Models\PrivateConversation;
use Filagora\Messages\Models\PrivateMessage;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A new message, and the people it is for (everyone in the conversation but the sender, and only
 * those who have not removed it). By default each is emailed; a host with more channels turns that
 * off and listens instead.
 */
final class MessageReceived
{
    use Dispatchable;

    /**
     * @param  list<Authenticatable>  $recipients
     */
    public function __construct(
        public readonly PrivateConversation $conversation,
        public readonly PrivateMessage $message,
        public readonly array $recipients,
    ) {}
}
