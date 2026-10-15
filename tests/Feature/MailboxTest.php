<?php

declare(strict_types=1);

use Filagora\Messages\Enums\MessageRefusal;
use Filagora\Messages\Events\ConversationsPurging;
use Filagora\Messages\Exceptions\MessageRefused;
use Filagora\Messages\Models\PrivateConversation;
use Filagora\Messages\Models\PrivateConversationParticipant;
use Filagora\Messages\Models\PrivateMessageFolder;
use Filagora\Messages\Services\Mailbox;
use Filagora\Messages\Services\Messages;
use Filagora\Messages\Tests\Support\TestUser;
use Illuminate\Support\Facades\Event;

function exchange(): array
{
    $alice = TestUser::make('Alice');
    $bob = TestUser::make('Bob');
    $conversation = app(Messages::class)->send($alice, [$bob], 'Hi', 'Hello');

    return [$alice, $bob, $conversation, app(Mailbox::class)];
}

function copy_of(PrivateConversation $conversation, TestUser $user): PrivateConversationParticipant
{
    return PrivateConversationParticipant::where('conversation_id', $conversation->id)->where('user_id', $user->id)->firstOrFail();
}

it('makes the built-in folders once, built-ins first and own folders by name', function () {
    $alice = TestUser::make('Alice');
    $mailbox = app(Mailbox::class);

    $mailbox->createFolder($alice, 'Zebra');
    $mailbox->createFolder($alice, 'Admin');

    expect($mailbox->foldersFor($alice)->pluck('slug')->all())->toBe(['archive', 'inbox', 'trash', 'admin', 'zebra'])
        ->and($mailbox->foldersFor($alice)->where('is_system', true)->pluck('name')->sort()->values()->all())->toBe(['Archive', 'Inbox', 'Trash'])
        ->and(PrivateMessageFolder::where('user_id', $alice->id)->count())->toBe(5);
});

it('gives a folder a slug that is unique for the person, and refuses a blank name', function () {
    $alice = TestUser::make('Alice');
    $mailbox = app(Mailbox::class);

    expect($mailbox->createFolder($alice, 'Work Stuff')->slug)->toBe('work-stuff')
        ->and($mailbox->createFolder($alice, 'Work stuff')->slug)->toBe('work-stuff-2')
        ->and($mailbox->createFolder($alice, 'Inbox')->slug)->toBe('inbox-2')
        ->and($mailbox->createFolder($alice, str_repeat('n', 100))->name)->toHaveLength(80)
        ->and(fn () => $mailbox->createFolder($alice, '  '))->toThrow(MessageRefused::class);
});

it('moves conversations between the person\'s own folders only', function () {
    [$alice, $bob, $conversation, $mailbox] = exchange();
    $archive = $mailbox->systemFolder($bob, 'archive');

    expect($mailbox->move($bob, [$conversation->id], $archive))->toBe(1)
        ->and(copy_of($conversation, $bob)->folder_id)->toBe($archive->id)
        ->and(copy_of($conversation, $alice)->folder->slug)->toBe('inbox');

    expect(fn () => $mailbox->move($bob, [$conversation->id], $mailbox->systemFolder($alice, 'archive')))->toThrow(MessageRefused::class)
        ->and($mailbox->move($alice, [$conversation->id + 999], $mailbox->systemFolder($alice, 'archive')))->toBe(0);
});

it('trashes remembering the folder, and restores to it, or to the inbox when it is gone', function () {
    [, $bob, $conversation, $mailbox] = exchange();
    $custom = $mailbox->createFolder($bob, 'Keep');
    $mailbox->move($bob, [$conversation->id], $custom);

    expect($mailbox->trash($bob, [$conversation->id]))->toBe(1);
    $row = copy_of($conversation, $bob);
    expect($row->folder->slug)->toBe('trash')->and($row->trashed_at)->not->toBeNull()->and($row->trashed_from_folder_id)->toBe($custom->id)
        ->and($mailbox->trash($bob, [$conversation->id]))->toBe(0);

    expect($mailbox->restore($bob, [$conversation->id]))->toBe(1);
    $row = copy_of($conversation, $bob);
    expect($row->folder_id)->toBe($custom->id)->and($row->trashed_at)->toBeNull()->and($row->trashed_from_folder_id)->toBeNull();

    $mailbox->trash($bob, [$conversation->id]);
    $custom->delete();
    $mailbox->restore($bob, [$conversation->id]);
    expect(copy_of($conversation, $bob)->folder->slug)->toBe('inbox');
});

it('moves into Trash by the same path as trashing', function () {
    [, $bob, $conversation, $mailbox] = exchange();

    $mailbox->move($bob, [$conversation->id], $mailbox->systemFolder($bob, 'trash'));

    $row = copy_of($conversation, $bob);
    expect($row->trashed_at)->not->toBeNull()->and($row->trashed_from_folder_id)->toBe($mailbox->systemFolder($bob, 'inbox')->id);
});

it('restores only what is in Trash', function () {
    [, $bob, $conversation, $mailbox] = exchange();

    expect($mailbox->restore($bob, [$conversation->id]))->toBe(0)
        ->and(copy_of($conversation, $bob)->folder->slug)->toBe('inbox');
});

it('deletes a folder by sending its conversations to the inbox, and keeps the built-in ones', function () {
    [$alice, $bob, $conversation, $mailbox] = exchange();
    $custom = $mailbox->createFolder($bob, 'Keep');
    $mailbox->move($bob, [$conversation->id], $custom);

    $mailbox->deleteFolder($bob, $custom);

    expect(PrivateMessageFolder::find($custom->id))->toBeNull()
        ->and(copy_of($conversation, $bob)->folder->slug)->toBe('inbox');

    $reason = fn (callable $callback) => rescue(fn () => $callback(), fn (MessageRefused $e) => $e->reason, false);
    expect($reason(fn () => $mailbox->deleteFolder($bob, $mailbox->systemFolder($bob, 'trash'))))->toBe(MessageRefusal::SYSTEM_FOLDER)
        ->and($reason(fn () => $mailbox->deleteFolder($bob, $mailbox->createFolder($alice, 'Mine'))))->toBe(MessageRefusal::NOT_YOUR_FOLDER);
});

it('marks read and unread, and counts unread outside Trash', function () {
    [$alice, $bob, $conversation, $mailbox] = exchange();
    $second = app(Messages::class)->send($alice, [$bob], 'Again', 'Hello');

    expect($mailbox->unreadCount($bob))->toBe(2);

    $mailbox->markRead($bob, [$conversation->id]);
    expect($mailbox->unreadCount($bob))->toBe(1)->and(copy_of($conversation, $bob)->last_read_at)->not->toBeNull();

    $mailbox->trash($bob, [$second->id]);
    expect($mailbox->unreadCount($bob))->toBe(0);

    $mailbox->markUnread($bob, [$conversation->id]);
    expect($mailbox->unreadCount($bob))->toBe(1)->and(copy_of($conversation, $alice)->is_read)->toBeTrue();
});

it('deletes only the person\'s own copy, and empties Trash', function () {
    [$alice, $bob, $conversation, $mailbox] = exchange();
    $other = app(Messages::class)->send($alice, [$bob], 'Again', 'Hello');
    $mailbox->trash($bob, [$other->id]);

    expect($mailbox->emptyTrash($bob))->toBe(1)
        ->and(copy_of($other, $bob)->deleted_at)->not->toBeNull()
        ->and(copy_of($other, $alice)->deleted_at)->toBeNull()
        ->and(copy_of($conversation, $bob)->deleted_at)->toBeNull();

    expect($mailbox->delete($bob, [$conversation->id]))->toBe(1)->and($mailbox->delete($bob, [$conversation->id]))->toBe(0);
});

it('purges copies trashed past the window, then conversations nobody keeps', function () {
    [$alice, $bob, $conversation, $mailbox] = exchange();
    Event::fake([ConversationsPurging::class]);
    $mailbox->trash($bob, [$conversation->id]);
    $mailbox->trash($alice, [$conversation->id]);
    copy_of($conversation, $bob)->forceFill(['trashed_at' => now()->subDays(31)->setTime(12, 0)])->save();

    expect($mailbox->purge(30))->toBe(['copies' => 1, 'conversations' => 0])
        ->and(copy_of($conversation, $bob)->deleted_at)->not->toBeNull()
        ->and(copy_of($conversation, $alice)->deleted_at)->toBeNull();

    copy_of($conversation, $alice)->forceFill(['trashed_at' => now()->subDays(40)->setTime(12, 0)])->save();

    expect($mailbox->purge(30))->toBe(['copies' => 1, 'conversations' => 1])
        ->and(PrivateConversation::count())->toBe(0)->and(PrivateConversationParticipant::count())->toBe(0);
    Event::assertDispatched(ConversationsPurging::class, fn ($event) => $event->conversationIds === [$conversation->id]);
});

it('keeps what is inside the window, or not in Trash, or still kept by someone', function () {
    [$alice, $bob, $conversation, $mailbox] = exchange();
    $mailbox->trash($bob, [$conversation->id]);
    copy_of($conversation, $bob)->forceFill(['trashed_at' => now()->subDays(29)->setTime(12, 0)])->save();

    expect($mailbox->purge(30))->toBe(['copies' => 0, 'conversations' => 0]);

    copy_of($conversation, $bob)->forceFill(['trashed_at' => now()->subDays(60)->setTime(12, 0)])->save();
    copy_of($conversation, $alice)->forceFill(['trashed_at' => now()->subDays(60)->setTime(12, 0)])->save();

    expect($mailbox->purge(30))->toBe(['copies' => 1, 'conversations' => 0])
        ->and(PrivateConversation::count())->toBe(1);
});

it('counts a dry run without deleting, including conversations that would be left empty', function () {
    [$alice, $bob, $conversation, $mailbox] = exchange();
    foreach ([$alice, $bob] as $user) {
        $mailbox->trash($user, [$conversation->id]);
        copy_of($conversation, $user)->forceFill(['trashed_at' => now()->subDays(45)->setTime(12, 0)])->save();
    }

    expect($mailbox->purge(30, dryRun: true))->toBe(['copies' => 2, 'conversations' => 1])
        ->and(PrivateConversation::count())->toBe(1)
        ->and(PrivateConversationParticipant::whereNotNull('deleted_at')->count())->toBe(0);
});

it('purges from the command, using the configured window or --days, and reports a dry run', function () {
    [, $bob, $conversation, $mailbox] = exchange();
    $mailbox->trash($bob, [$conversation->id]);
    copy_of($conversation, $bob)->forceFill(['trashed_at' => now()->subDays(10)->setTime(12, 0)])->save();

    $this->artisan('filagora:messages:purge-trashed')->expectsOutput('Removed 0 trashed copy(ies) and 0 conversation(s).')->assertExitCode(0);
    $this->artisan('filagora:messages:purge-trashed', ['--days' => 5, '--dry-run' => true])->expectsOutput('Would remove 1 trashed copy(ies) and 0 conversation(s).')->assertExitCode(0);
    expect(copy_of($conversation, $bob)->deleted_at)->toBeNull();

    config(['filagora-messages.trash.retention_days' => 5]);
    $this->artisan('filagora:messages:purge-trashed')->expectsOutput('Removed 1 trashed copy(ies) and 0 conversation(s).')->assertExitCode(0);
    expect(copy_of($conversation, $bob)->deleted_at)->not->toBeNull();
});
