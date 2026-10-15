<?php

declare(strict_types=1);

namespace Filagora\Messages\Console;

use Filagora\Messages\Services\Mailbox;
use Illuminate\Console\Command;

/**
 * Removes copies that have sat in Trash too long, and conversations nobody keeps.
 */
final class PurgeTrashedCommand extends Command
{
    protected $signature = 'filagora:messages:purge-trashed {--days= : Days a conversation may sit in Trash (default from config)} {--dry-run : Report counts without deleting}';

    protected $description = 'Permanently remove private conversations that sat in Trash past the retention window, and conversations no participant keeps.';

    public function handle(Mailbox $mailbox): int
    {
        $days = $this->option('days') !== null ? (int) $this->option('days') : (int) config('filagora-messages.trash.retention_days', 30);
        $dryRun = (bool) $this->option('dry-run');

        $result = $mailbox->purge($days, $dryRun);

        $this->info(sprintf('%s %d trashed copy(ies) and %d conversation(s).', $dryRun ? 'Would remove' : 'Removed', $result['copies'], $result['conversations']));

        return self::SUCCESS;
    }
}
