<?php

declare(strict_types=1);

use Filagora\Messages\Tests\Attachments\AttachmentsTestCase;
use Filagora\Messages\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;

pest()->extend(TestCase::class)->use(RefreshDatabase::class)->in('Feature');
pest()->extend(AttachmentsTestCase::class)->use(RefreshDatabase::class)->in('Attachments');
