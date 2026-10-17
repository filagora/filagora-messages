# Changelog

All notable changes to this package are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/).

## [Unreleased]

### Added

- **Attachments** with `filagora/filagora-forums` `^0.6` (suggested, not required). A migration, run from `database/migrations/forums` and dated after forums' attachments table, adds `private_message_id` and `private_conversation_id` (each a foreign key that is cleared, not cascaded) to that table; it does nothing without forums, and `filagora:messages:link-attachments` adds the columns later. `AttachmentLinks` installs them safely from any half-done state. Sending or replying claims the sender's own waiting files that the body refers to (`ClaimMessageAttachments`); `MessageAttachmentAccess` lets only people still in the conversation see them; `PrivateMessage::attachments()`. The columns are declared as a schema extension of the forums snapshot, so `filagora:adopt` accepts a table that has them.

## [0.1.0] - 2026-10-15

First release.

### Added
- `Messages` service: `send`, `reply` and `open`. A conversation goes to its recipients' Inboxes unread; a reply pulls it out of everyone's Trash and makes it unread for the others.
- `Mailbox` service: folders (built-in Inbox, Archive and Trash plus a person's own), move, trash, restore, delete, empty Trash, read and unread, unread count, and `purge`.
- `MessagingPolicy` contract (allow everyone by default) so a site decides who may message whom; a send or reply is refused whole if any recipient is off limits.
- `MessageRefused` with a `MessageRefusal` reason for every refusal.
- `MessageReceived` event with a default email (switch off with `mail.enabled`; link with `mail.routes.conversation`), and `ConversationsPurging` so a host can remove what it attached.
- `filagora:messages:purge-trashed` command and a `messages.trash` retention rule for core (`trash.retention_days`, 30 by default).
- A privacy contributor: export a person's folders and conversations; erase their copies and folders.
- Tables for folders, conversations, participants and messages, with models, a swappable model map and a schema snapshot, so a site that already has these tables can adopt them with `filagora:adopt`.
