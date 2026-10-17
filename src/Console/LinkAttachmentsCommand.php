<?php

declare(strict_types=1);

namespace Filagora\Messages\Console;

use Filagora\Messages\Attachments\AttachmentLinks;
use Illuminate\Console\Command;

/**
 * Adds the message links to the forums attachments table, for a site that installed forums after
 * messages (the migration found no table to change and was recorded as run).
 */
final class LinkAttachmentsCommand extends Command
{
    protected $signature = 'filagora:messages:link-attachments';

    protected $description = 'Add the private message links to the forums attachments table.';

    public function handle(): int
    {
        if (! AttachmentLinks::available()) {
            $this->error('The attachments table does not exist. Install filagora/filagora-forums and migrate first.');

            return self::FAILURE;
        }

        $already = AttachmentLinks::installed();
        AttachmentLinks::install();

        $this->info($already ? 'The attachments table already has the message links.' : 'Added the message links to the attachments table.');

        return self::SUCCESS;
    }
}
