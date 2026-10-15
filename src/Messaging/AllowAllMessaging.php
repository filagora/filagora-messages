<?php

declare(strict_types=1);

namespace Filagora\Messages\Messaging;

use Filagora\Messages\Contracts\MessagingPolicy;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * The default policy: anyone may message anyone.
 */
final class AllowAllMessaging implements MessagingPolicy
{
    public function canMessage(Authenticatable $sender, Authenticatable $recipient): bool
    {
        return true;
    }
}
