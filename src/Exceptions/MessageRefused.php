<?php

declare(strict_types=1);

namespace Filagora\Messages\Exceptions;

use Filagora\Messages\Enums\MessageRefusal;
use RuntimeException;

/**
 * A message or mailbox change that cannot be made. The message is safe to show the user.
 * `$blocked` lists the recipients who may not be messaged when the reason is `NOT_ALLOWED`.
 */
final class MessageRefused extends RuntimeException
{
    /**
     * @param  list<int|string>  $blocked
     */
    public function __construct(public readonly MessageRefusal $reason, public readonly array $blocked = [])
    {
        parent::__construct($reason->message());
    }
}
