<?php

declare(strict_types=1);

use Filagora\Core\Schema\SchemaRegistry;
use Filagora\Core\Schema\TableShape;
use Filagora\Forums\Models\Forum;
use Filagora\Forums\Models\ForumGroup;
use Filagora\Forums\Models\Post;
use Filagora\Forums\Models\PostAttachment;
use Filagora\Forums\Models\Thread;
use Filagora\Forums\Services\Attachments;
use Filagora\Messages\Attachments\AttachmentLinks;
use Filagora\Messages\Models\PrivateMessage;
use Filagora\Messages\Services\Mailbox;
use Filagora\Messages\Services\Messages;
use Filagora\Messages\Tests\Support\TestUser;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('local');
    app()->forgetInstance(Attachments::class);
});

function filesService(): Attachments
{
    return app(Attachments::class);
}

function waitingUpload(TestUser $uploader, string $name = 'a.png'): PostAttachment
{
    return filesService()->storeInline($uploader, UploadedFile::fake()->image($name, 100, 80));
}

/**
 * @return array{0: TestUser, 1: TestUser, 2: PrivateMessage}
 */
function messageWithFile(?PostAttachment &$file = null): array
{
    $alice = TestUser::make('Alice');
    $bob = TestUser::make('Bob');
    $file = waitingUpload($alice);
    $conversation = app(Messages::class)->send($alice, [$bob], 'Pictures', "Look: ![x](/post-attachments/{$file->id})");

    return [$alice, $bob, $conversation->messages()->sole()];
}

// ---------------------------------------------------------------- the columns

it('adds the message links to the forums attachments table, after the thread link', function () {
    $columns = array_keys(TableShape::inspect('post_attachments')->columns);

    expect(array_slice($columns, 0, 6))->toBe(['id', 'post_id', 'thread_id', 'private_message_id', 'private_conversation_id', 'uploader_id'])
        ->and(AttachmentLinks::installed())->toBeTrue();
});

it('declares the columns as an extension of the forums snapshot that the table equals', function () {
    $registry = app(SchemaRegistry::class);
    $extensions = $registry->extensionsFor('forums');

    expect($extensions)->toHaveCount(1)->and($extensions[0]->name)->toBe('messages')
        ->and($extensions[0]->migrationNames())->toBe(['2026_10_16_300000_add_message_links_to_attachments_table']);

    $extended = $registry->get('forums')->extendedBy($extensions);

    expect(TableShape::inspect('post_attachments')->differencesFrom($extended->tables['post_attachments']))->toBe([]);
});

it('clears the links, not the file row, when the message or conversation goes', function () {
    [, , $message] = messageWithFile($file);

    expect($file->refresh()->private_message_id)->toBe($message->id)->and($file->private_conversation_id)->toBe($message->conversation_id);

    $message->conversation->delete();

    expect($file->refresh()->private_message_id)->toBeNull()->and($file->private_conversation_id)->toBeNull()->and(PostAttachment::count())->toBe(1);
});

it('is safe to run again, and says so when forums has no attachments table', function () {
    expect(AttachmentLinks::install())->toBeTrue()->and(AttachmentLinks::installed())->toBeTrue();

    config(['filagora.table_prefix' => 'nowhere_']);
    expect(AttachmentLinks::available())->toBeFalse()->and(AttachmentLinks::installed())->toBeFalse()->and(AttachmentLinks::install())->toBeFalse();
    AttachmentLinks::uninstall();
    config(['filagora.table_prefix' => 'filagora_']);
    expect(AttachmentLinks::installed())->toBeTrue();
});

it('reports on the command whether the links were added', function () {
    $this->artisan('filagora:messages:link-attachments')->expectsOutput('The attachments table already has the message links.')->assertExitCode(0);

    config(['filagora.table_prefix' => 'nowhere_']);
    $this->artisan('filagora:messages:link-attachments')->expectsOutputToContain('The attachments table does not exist')->assertExitCode(1);
});

// ---------------------------------------------------------------- claiming

it('gives a message the files its body refers to, and records the conversation', function () {
    [, , $message] = messageWithFile($file);

    expect($file->refresh()->private_message_id)->toBe($message->id)
        ->and($file->private_conversation_id)->toBe($message->conversation_id)
        ->and($file->isUnclaimed())->toBeFalse()
        ->and($message->attachments()->pluck('id')->all())->toBe([$file->id]);
});

it('claims files in a reply too', function () {
    [$alice, $bob, $first] = messageWithFile();
    $file = waitingUpload($bob, 'reply.png');

    $reply = app(Messages::class)->reply($bob, $first->conversation, "Here: /post-attachments/{$file->id}");

    expect($file->refresh()->private_message_id)->toBe($reply->id)->and($alice->exists)->toBeTrue();
});

it('claims only the sender\'s own waiting files, once', function () {
    $alice = TestUser::make('Alice');
    $bob = TestUser::make('Bob');
    $mine = waitingUpload($alice);
    $theirs = waitingUpload($bob, 'b.png');
    $other = waitingUpload($alice, 'c.png');

    $first = app(Messages::class)->send($alice, [$bob], 'One', "/post-attachments/{$mine->id} and /post-attachments/{$theirs->id}");
    $second = app(Messages::class)->send($alice, [$bob], 'Two', "/post-attachments/{$mine->id}");

    expect($mine->refresh()->private_message_id)->toBe($first->messages()->sole()->id)
        ->and($theirs->refresh()->private_message_id)->toBeNull()
        ->and($other->refresh()->private_message_id)->toBeNull()
        ->and($second->messages()->sole()->attachments()->count())->toBe(0);
});

it('leaves alone a file that is already on a post', function () {
    $alice = TestUser::make('Alice');
    $bob = TestUser::make('Bob');
    $group = ForumGroup::create(['slug' => 'main', 'name' => 'Main']);
    $forum = Forum::create(['forum_group_id' => $group->id, 'name' => 'F', 'slug' => 'f']);
    $thread = Thread::create(['forum_id' => $forum->id, 'author_id' => $alice->id, 'title' => 'T', 'slug' => 't']);
    $post = Post::create(['thread_id' => $thread->id, 'author_id' => $alice->id, 'body' => 'Hello']);
    $file = filesService()->store($post, $alice, [UploadedFile::fake()->image('on-post.png')])[0];

    app(Messages::class)->send($alice, [$bob], 'Hi', "/post-attachments/{$file->id}");

    expect($file->refresh()->private_message_id)->toBeNull()->and($file->post_id)->toBe($post->id);
});

it('claims nothing when the body names no file or the pattern is not a pattern', function () {
    $alice = TestUser::make('Alice');
    $bob = TestUser::make('Bob');
    $file = waitingUpload($alice);

    app(Messages::class)->send($alice, [$bob], 'Hi', 'No files here');
    config(['filagora-forums.attachments.inline_pattern' => '#unterminated(']);
    app(Messages::class)->send($alice, [$bob], 'Hi again', "/post-attachments/{$file->id}");

    expect($file->refresh()->private_message_id)->toBeNull();
});

it('follows the configured pattern', function () {
    config(['filagora-forums.attachments.inline_pattern' => '#/files/(\d+)/raw#']);
    $alice = TestUser::make('Alice');
    $bob = TestUser::make('Bob');
    $file = waitingUpload($alice);

    app(Messages::class)->send($alice, [$bob], 'Hi', "/post-attachments/{$file->id} /files/{$file->id}/raw");

    expect($file->refresh()->private_message_id)->not->toBeNull();
});

// ------------------------------------------------------------------- access

it('shows a message\'s files to the people in the conversation only', function () {
    [$alice, $bob, $message] = messageWithFile($file);
    $outsider = TestUser::make('Outsider');

    expect(filesService()->canView($file->refresh(), $alice))->toBeTrue()
        ->and(filesService()->canView($file, $bob))->toBeTrue()
        ->and(filesService()->canView($file, $outsider))->toBeFalse()
        ->and(filesService()->canView($file, null))->toBeFalse()
        ->and($message->exists)->toBeTrue();
});

it('stops showing the files to someone who removed the conversation', function () {
    [, $bob, $message] = messageWithFile($file);

    app(Mailbox::class)->delete($bob, [$message->conversation_id]);

    expect(filesService()->canView($file->refresh(), $bob))->toBeFalse();
});

it('keeps the files of a conversation someone trashed visible to them', function () {
    [, $bob, $message] = messageWithFile($file);

    app(Mailbox::class)->trash($bob, [$message->conversation_id]);

    expect(filesService()->canView($file->refresh(), $bob))->toBeTrue();
});

it('does not judge files that belong to no message', function () {
    $alice = TestUser::make('Alice');
    $waiting = waitingUpload($alice);

    expect(filesService()->canView($waiting, $alice))->toBeTrue()->and(filesService()->canView($waiting, TestUser::make('Bob')))->toBeFalse();
});

it('serves a message\'s file to a participant', function () {
    [$alice] = messageWithFile($file);

    $response = filesService()->stream($file->refresh());

    expect($response->headers->get('Content-Disposition'))->toStartWith('attachment')->and(filesService()->canView($file, $alice))->toBeTrue();
});

it('lets forums clean up files whose conversation was deleted', function () {
    [, , $message] = messageWithFile($file);
    $message->conversation->delete();
    PostAttachment::whereKey($file->id)->toBase()->update(['created_at' => now()->subDays(2)]);

    expect(filesService()->pruneOrphaned(24))->toBe(1)->and(PostAttachment::count())->toBe(0);
    Storage::disk('local')->assertMissing($file->storage_path);
});

it('needs forums to list a message\'s files', function () {
    $message = PrivateMessage::make();
    config(['filagora.models.post_attachment' => null, 'filagora-forums.models.post_attachment' => null]);

    expect(fn () => $message->attachments())->toThrow(LogicException::class);
});
