<?php

declare(strict_types=1);

namespace Filagora\Messages\Contracts;

use Illuminate\Contracts\Auth\Authenticatable;

/**
 * Whether one person may message another: a member who switched messages off, an ignore list, a
 * suspended account. The default allows everyone; bind your own to apply the site's rules.
 */
interface MessagingPolicy
{
    public function canMessage(Authenticatable $sender, Authenticatable $recipient): bool;
}
