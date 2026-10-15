<?php

declare(strict_types=1);

namespace Filagora\Messages\Enums;

/**
 * Why a message or mailbox change was refused.
 */
enum MessageRefusal: string
{
    case NO_RECIPIENTS = 'no_recipients';
    case NOT_ALLOWED = 'not_allowed';
    case NOT_A_PARTICIPANT = 'not_a_participant';
    case EMPTY_BODY = 'empty_body';
    case EMPTY_SUBJECT = 'empty_subject';
    case NOT_YOUR_FOLDER = 'not_your_folder';
    case SYSTEM_FOLDER = 'system_folder';
    case EMPTY_FOLDER_NAME = 'empty_folder_name';

    public function message(): string
    {
        return __("filagora-messages::messages.refusals.{$this->value}");
    }
}
