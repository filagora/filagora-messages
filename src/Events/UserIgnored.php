<?php

declare(strict_types=1);

namespace Filagora\Messages\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * A person put another on their ignore list.
 */
final class UserIgnored
{
    use Dispatchable;

    public function __construct(
        public readonly int|string $userId,
        public readonly int|string $ignoredUserId,
    ) {}
}
