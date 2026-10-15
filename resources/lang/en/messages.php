<?php

declare(strict_types=1);

return [
    'folders' => [
        'inbox' => 'Inbox',
        'archive' => 'Archive',
        'trash' => 'Trash',
    ],
    'refusals' => [
        'no_recipients' => 'Choose at least one recipient.',
        'not_allowed' => 'One or more recipients cannot be messaged.',
        'not_a_participant' => 'You are not part of this conversation.',
        'empty_body' => 'Write a message.',
        'empty_subject' => 'Give the conversation a subject.',
        'not_your_folder' => 'That folder is not yours.',
        'system_folder' => 'The built-in folders cannot be changed.',
        'empty_folder_name' => 'Give the folder a name.',
    ],
    'received' => [
        'subject' => 'New message: :subject',
        'intro' => 'You have a new private message in ":subject".',
        'action' => 'Read the conversation',
    ],
];
