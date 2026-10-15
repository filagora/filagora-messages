# Filagora Messages

Private conversations between members for Filagora: send, reply, per-person folders, read and
unread, trash, and a purge of what has sat in the trash too long.

> In progress, unreleased. The package is at `0.1`: the API may change between `0.x` minor
> versions. There is no frontend: it is models and services, and you build the mailbox pages.

## Tables

`private_message_folders`, `private_conversations`, `private_conversation_participants` and
`private_messages` (prefixed `filagora_` by default). A conversation has one participant row per
person, holding that person's folder, read state and trash state, so deleting or trashing is
per person. A site that already has these tables can adopt them with `php artisan filagora:adopt`.
Models can be swapped in `config/filagora-messages.php`.

## Install

Not yet released.

## Testing

```bash
composer test
```

## Licence

MIT.
