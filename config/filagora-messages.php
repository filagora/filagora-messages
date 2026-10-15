<?php

declare(strict_types=1);

use Filagora\Messages\Models\PrivateConversation;
use Filagora\Messages\Models\PrivateConversationParticipant;
use Filagora\Messages\Models\PrivateMessage;
use Filagora\Messages\Models\PrivateMessageFolder;

return [

    /*
    |--------------------------------------------------------------------------
    | Swappable models
    |--------------------------------------------------------------------------
    |
    | The classes this package uses for its tables. Point a key at your own subclass to
    | replace one (here, or under `filagora.models.<key>`, which wins). Subclass the package
    | model; the package always asks for the class through its Models helper.
    |
    */
    'models' => [
        'folder' => PrivateMessageFolder::class,
        'conversation' => PrivateConversation::class,
        'participant' => PrivateConversationParticipant::class,
        'message' => PrivateMessage::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | Mail
    |--------------------------------------------------------------------------
    |
    | Whether a new message is emailed to its recipients by default, and the name of the route
    | the email links to (it receives a `conversation` parameter). Turn mail off to notify
    | through your own channels from the `MessageReceived` event.
    |
    */
    'mail' => [
        'enabled' => true,
        'routes' => ['conversation' => null],
    ],

    /*
    |--------------------------------------------------------------------------
    | Trash
    |--------------------------------------------------------------------------
    |
    | Days a conversation may sit in a person's Trash before their copy is permanently removed
    | by `filagora:messages:purge-trashed` (and by the retention rules core runs).
    |
    */
    'trash' => [
        'retention_days' => 30,
    ],

];
