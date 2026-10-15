# Filagora Messages

Private conversations between members for Filagora: send, reply, per-person folders, read and
unread, trash, and a purge of what has sat in the trash too long.

> The package is at `0.1`: the API may change between `0.x` minor
> versions. There is no frontend: it is models and services, and you build the mailbox pages.

## Tables

`private_message_folders`, `private_conversations`, `private_conversation_participants` and
`private_messages` (prefixed `filagora_` by default). A conversation has one participant row per
person, holding that person's folder, read state and trash state, so deleting or trashing is
per person. A site that already has these tables can adopt them with `php artisan filagora:adopt`.
Models can be swapped in `config/filagora-messages.php`.

## Using it

```php
$messages = app(\Filagora\Messages\Services\Messages::class);
$conversation = $messages->send($sender, [$recipient], 'Subject', 'Body in markdown');
$messages->reply($recipient, $conversation, 'Thanks');
$messages->open($recipient, $conversation);        // marks their copy read

$mailbox = app(\Filagora\Messages\Services\Mailbox::class);
$mailbox->trash($recipient, [$conversation->id]);
$mailbox->restore($recipient, [$conversation->id]);
```

- **Who may message whom** is the `MessagingPolicy` contract; bind your own (a member who switched
  messages off, an ignore list). Anything refused throws `MessageRefused` with a `reason`.
- **Mail.** A new message is emailed to its recipients. Set `filagora-messages.mail.enabled` to
  false to notify through your own channels from the `MessageReceived` event.
- **Trash.** `filagora:messages:purge-trashed` (schedule it daily) removes copies trashed more than
  `trash.retention_days` ago, then conversations nobody keeps. Core's retention run does the same.
  `ConversationsPurging` fires first so you can remove attachments.
- **Privacy.** Registers a contributor (export and erasure) with core.

## Install

```bash
composer require filagora/filagora-messages
php artisan migrate
```

Schedule `filagora:messages:purge-trashed` daily. Tables are created with the `filagora_` prefix; a site
that already has them can run `php artisan filagora:adopt` instead of migrating.

## Testing

```bash
composer test
```

## Licence

MIT.
