<?php

declare(strict_types=1);

use Filagora\Messages\Attachments\AttachmentLinks;
use Illuminate\Database\Migrations\Migration;

/*
 * Lets private messages claim files in the attachments table of `filagora-forums`. Dated after
 * that table's migration. Does nothing when forums is not installed.
 */
return new class extends Migration
{
    public function up(): void
    {
        AttachmentLinks::install();
    }

    public function down(): void
    {
        AttachmentLinks::uninstall();
    }
};
