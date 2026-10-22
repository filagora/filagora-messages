<?php

declare(strict_types=1);

namespace Filagora\Messages\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * A person took another off their ignore list.
 */
final class UserUnignored
{
    use Dispatchable;

    public function __construct(
        public readonly int|string $userId,
        public readonly int|string $ignoredUserId,
    ) {}
}
