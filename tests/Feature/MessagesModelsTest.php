<?php

declare(strict_types=1);

use Filagora\Messages\Models\IgnoredUser;
use Filagora\Messages\Models\PrivateConversation;
use Filagora\Messages\Models\PrivateConversationParticipant;
use Filagora\Messages\Models\PrivateMessage;
use Filagora\Messages\Models\PrivateMessageFolder;
use Filagora\Messages\Support\Models;
use Filagora\Messages\Tests\Support\TestUser;
use Illuminate\Database\QueryException;

function mailboxFixture(): array
{
    $alice = TestUser::make('Alice');
    $bob = TestUser::make('Bob');
    $inbox = PrivateMessageFolder::create(['user_id' => $bob->id, 'name' => 'Inbox', 'slug' => 'inbox', 'is_system' => true]);
    $conversation = PrivateConversation::create(['created_by_id' => $alice->id, 'subject' => 'Hello']);
    $participant = PrivateConversationParticipant::create(['conversation_id' => $conversation->id, 'user_id' => $bob->id, 'folder_id' => $inbox->id])->refresh();

    return [$alice, $bob, $inbox, $conversation, $participant];
}

it('lists its models in the configuration by default', function () {
    expect(array_keys(config('filagora-messages.models')))->toBe(['folder', 'conversation', 'participant', 'message', 'ignored_user'])
        ->and(Models::class('message'))->toBe(PrivateMessage::class)
        ->and(Models::class('ignored_user'))->toBe(IgnoredUser::class);

    config(['filagora.models.message' => 'Custom\\Message']);
    expect(Models::class('message'))->toBe('Custom\\Message');
    expect(fn () => Models::class('nope'))->toThrow(InvalidArgumentException::class);
});

it('starts a participant unread, untrashed and not removed', function () {
    [, , $inbox, , $participant] = mailboxFixture();

    expect($participant->is_read)->toBeFalse()
        ->and($participant->last_read_at)->toBeNull()
        ->and($participant->deleted_at)->toBeNull()
        ->and($participant->trashed_at)->toBeNull()
        ->and($participant->trashed_from_folder_id)->toBeNull()
        ->and($participant->folder->is($inbox))->toBeTrue();
});

it('defaults a message to markdown and keeps a conversation\'s messages in order', function () {
    [$alice, , , $conversation] = mailboxFixture();
    $second = PrivateMessage::create(['conversation_id' => $conversation->id, 'sender_id' => $alice->id, 'body' => 'Second']);
    $first = PrivateMessage::create(['conversation_id' => $conversation->id, 'sender_id' => $alice->id, 'body' => 'First']);
    $second->newQuery()->whereKey($second->id)->toBase()->update(['created_at' => now()->addMinute()]);
    $first->newQuery()->whereKey($first->id)->toBase()->update(['created_at' => now()->subMinute()]);

    expect($conversation->messages->pluck('body')->all())->toBe(['First', 'Second'])
        ->and($first->refresh()->content_format)->toBe('markdown')
        ->and($first->sender->is($alice))->toBeTrue()
        ->and($first->conversation->is($conversation))->toBeTrue();
});

it('gives each person one place in a conversation and one folder per slug', function () {
    [, $bob, $inbox, $conversation] = mailboxFixture();

    expect(fn () => PrivateConversationParticipant::create(['conversation_id' => $conversation->id, 'user_id' => $bob->id, 'folder_id' => $inbox->id]))->toThrow(QueryException::class)
        ->and(fn () => PrivateMessageFolder::create(['user_id' => $bob->id, 'name' => 'Another', 'slug' => 'inbox']))->toThrow(QueryException::class);
});

it('remembers where a trashed conversation came from, and loses that when the folder goes', function () {
    [, $bob, $inbox, , $participant] = mailboxFixture();
    $trash = PrivateMessageFolder::create(['user_id' => $bob->id, 'name' => 'Trash', 'slug' => 'trash', 'is_system' => true]);
    $participant->forceFill(['folder_id' => $trash->id, 'trashed_from_folder_id' => $inbox->id, 'trashed_at' => now()])->save();

    expect($participant->refresh()->trashedFromFolder->is($inbox))->toBeTrue();

    $inbox->delete();
    expect($participant->refresh()->trashed_from_folder_id)->toBeNull()->and($participant->folder_id)->toBe($trash->id);
});

it('takes a conversation\'s participants and messages with it, and a person\'s mailbox with them', function () {
    [$alice, $bob, , $conversation] = mailboxFixture();
    PrivateMessage::create(['conversation_id' => $conversation->id, 'sender_id' => $alice->id, 'body' => 'Hi']);

    $bob->delete();
    expect(PrivateConversationParticipant::count())->toBe(0)->and(PrivateMessageFolder::count())->toBe(0)->and(PrivateMessage::count())->toBe(1);

    $conversation->delete();
    expect(PrivateMessage::count())->toBe(0);
});
