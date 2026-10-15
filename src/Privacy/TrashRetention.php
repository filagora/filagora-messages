<?php

declare(strict_types=1);

namespace Filagora\Messages\Privacy;

use Filagora\Core\Contracts\RetentionRule;
use Filagora\Messages\Services\Mailbox;

/**
 * Trash is emptied after `trash.retention_days` (30 by default; zero keeps it). The same purge
 * as `filagora:messages:purge-trashed`, offered to core's retention run.
 */
final class TrashRetention implements RetentionRule
{
    public function __construct(private readonly Mailbox $mailbox) {}

    public function key(): string
    {
        return 'messages.trash';
    }

    public function prune(bool $dryRun): int
    {
        $days = (int) config('filagora-messages.trash.retention_days', 30);

        if ($days <= 0) {
            return 0;
        }

        $result = $this->mailbox->purge($days, $dryRun);

        return $result['copies'] + $result['conversations'];
    }
}
