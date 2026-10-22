# Filagora Messages

Private conversations between members for Filagora: send, reply, per-person folders, read and
unread, trash, and a purge of what has sat in the trash too long.

> The package is at `0.1`: the API may change between `0.x` minor
> versions. There is no frontend: it is models and services, and you build the mailbox pages.

## Tables

`private_message_folders`, `private_conversations`, `private_conversation_participants`,
`private_messages` and `ignored_users` (prefixed `filagora_` by default). A conversation has one participant row per
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
  messages off, a suspended account) and the ignore lists below. Anything refused throws
  `MessageRefused` with a `reason`, and `blocked` names the recipients who may not be messaged.
- **Ignore lists.** `IgnoredUsers::ignore($me, $them)` and `unignore()`; `isIgnoring()` and
  `isIgnoredBy()` answer for one direction. Either person ignoring the other blocks private messages
  between them both ways (`isBlockedBetween()`), checked on every send and reply, and
  `blockedIdsFor($me)` gives your recipient search the people to leave out. `UserIgnored` and
  `UserUnignored` fire when a list changes. Messages already in your mailbox stay readable.
- **Mail.** A new message is emailed to its recipients. Set `filagora-messages.mail.enabled` to
  false to notify through your own channels from the `MessageReceived` event.
- **Trash.** `filagora:messages:purge-trashed` (schedule it daily) removes copies trashed more than
  `trash.retention_days` ago, then conversations nobody keeps. Core's retention run does the same.
  `ConversationsPurging` fires first so you can remove attachments.
- **Attachments** (with [`filagora/filagora-forums`](https://github.com/filagora/filagora-forums)
  installed). Messages can carry files stored by forums' `Attachments` service. The migration adds
  two columns to forums' attachments table, linking a file to a message and its conversation
  (`php artisan filagora:messages:link-attachments` adds them if forums was installed after messages).
  Upload a file while writing (`Attachments::storeInline()`), put its URL in the message body, and
  sending or replying claims the sender's own waiting files that the body refers to, using forums'
  `attachments.inline_pattern`. Only people still in the conversation may see the files; removing the
  conversation takes the access away. `PrivateMessage::attachments()` lists a message's files.
  If the conversation is deleted, forums cleans up the orphaned files.
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
