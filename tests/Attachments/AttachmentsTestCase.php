<?php

declare(strict_types=1);

namespace Filagora\Messages\Tests\Attachments;

use Filagora\Forums\ForumsServiceProvider;
use Filagora\Messages\Tests\TestCase;

/**
 * The messages test application with forums installed too, for the attachment links.
 */
abstract class AttachmentsTestCase extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [...parent::getPackageProviders($app), ForumsServiceProvider::class];
    }
}
