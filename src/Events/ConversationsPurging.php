<?php

declare(strict_types=1);

namespace Filagora\Messages\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * Conversations that every participant has removed, fired just before they and their messages are
 * deleted, so a host can remove what it attached to them (uploaded files, search entries).
 */
final class ConversationsPurging
{
    use Dispatchable;

    /**
     * @param  list<int>  $conversationIds
     */
    public function __construct(public readonly array $conversationIds) {}
}
