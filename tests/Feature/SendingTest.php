<?php

declare(strict_types=1);

use Filagora\Messages\Contracts\MessagingPolicy;
use Filagora\Messages\Enums\MessageRefusal;
use Filagora\Messages\Events\MessageReceived;
use Filagora\Messages\Exceptions\MessageRefused;
use Filagora\Messages\Models\PrivateConversationParticipant;
use Filagora\Messages\Notifications\MessageReceivedNotification;
use Filagora\Messages\Services\Mailbox;
use Filagora\Messages\Services\Messages;
use Filagora\Messages\Tests\Support\TestUser;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Route;

function participation($conversation, $user): PrivateConversationParticipant
{
    return PrivateConversationParticipant::where('conversation_id', $conversation->id)->where('user_id', $user->id)->firstOrFail();
}

function refusal(callable $callback): MessageRefused
{
    try {
        $callback();
    } catch (MessageRefused $refused) {
        return $refused;
    }

    throw new RuntimeException('Expected a refusal.');
}

it('sends a conversation to inboxes: read for the sender, unread for the recipients', function () {
    [$alice, $bob, $cara] = [TestUser::make('Alice'), TestUser::make('Bob'), TestUser::make('Cara')];

    $conversation = app(Messages::class)->send($alice, [$bob, $cara, $bob, $alice], '  Plans ', "  Hello there \n");

    $message = $conversation->messages->sole();
    expect($conversation->subject)->toBe('Plans')
        ->and($conversation->created_by_id)->toBe($alice->id)
        ->and($message->body)->toBe('Hello there')
        ->and($message->content_format)->toBe('markdown')
        ->and($message->sender_id)->toBe($alice->id)
        ->and($conversation->participants)->toHaveCount(3);

    $mine = participation($conversation, $alice);
    expect($mine->is_read)->toBeTrue()->and($mine->last_read_at)->not->toBeNull()
        ->and($mine->folder->slug)->toBe('inbox')->and($mine->folder->user_id)->toBe($alice->id);

    foreach ([$bob, $cara] as $recipient) {
        $theirs = participation($conversation, $recipient);
        expect($theirs->is_read)->toBeFalse()->and($theirs->last_read_at)->toBeNull()
            ->and($theirs->folder->slug)->toBe('inbox')->and($theirs->folder->user_id)->toBe($recipient->id);
    }
});

it('cuts a long subject to the column', function () {
    [$alice, $bob] = [TestUser::make('Alice'), TestUser::make('Bob')];

    expect(app(Messages::class)->send($alice, [$bob], str_repeat('s', 300), 'Hi')->subject)->toHaveLength(255);
});

it('refuses an empty subject, body or recipient list, and writes nothing', function () {
    [$alice, $bob] = [TestUser::make('Alice'), TestUser::make('Bob')];
    $messages = app(Messages::class);

    expect(refusal(fn () => $messages->send($alice, [$bob], '  ', 'Hi'))->reason)->toBe(MessageRefusal::EMPTY_SUBJECT)
        ->and(refusal(fn () => $messages->send($alice, [$bob], 'Hi', " \n "))->reason)->toBe(MessageRefusal::EMPTY_BODY)
        ->and(refusal(fn () => $messages->send($alice, [], 'Hi', 'Hi'))->reason)->toBe(MessageRefusal::NO_RECIPIENTS)
        ->and(refusal(fn () => $messages->send($alice, [$alice], 'Hi', 'Hi'))->reason)->toBe(MessageRefusal::NO_RECIPIENTS)
        ->and(PrivateConversationParticipant::count())->toBe(0);
});

it('lets the site decide who may message whom, refusing the whole send', function () {
    [$alice, $bob, $cara] = [TestUser::make('Alice'), TestUser::make('Bob'), TestUser::make('Cara')];

    app()->instance(MessagingPolicy::class, new class($cara->id) implements MessagingPolicy
    {
        public function __construct(private readonly int $blockedId) {}

        public function canMessage(Authenticatable $sender, Authenticatable $recipient): bool
        {
            return $recipient->getAuthIdentifier() !== $this->blockedId;
        }
    });

    app()->forgetInstance(Messages::class);

    $refused = refusal(fn () => app(Messages::class)->send($alice, [$bob, $cara], 'Hi', 'Hi'));

    expect($refused->reason)->toBe(MessageRefusal::NOT_ALLOWED)->and($refused->blocked)->toBe([$cara->id])
        ->and(PrivateConversationParticipant::count())->toBe(0);
});

it('replies: unread for the others, read for the sender, and out of everyone\'s trash', function () {
    [$alice, $bob] = [TestUser::make('Alice'), TestUser::make('Bob')];
    $messages = app(Messages::class);
    $mailbox = app(Mailbox::class);
    $conversation = $messages->send($alice, [$bob], 'Hi', 'First');
    $messages->open($bob, $conversation);
    $mailbox->trash($bob, [$conversation->id]);
    $mailbox->trash($alice, [$conversation->id]);

    $reply = $messages->reply($bob, $conversation, ' Second ');

    expect($reply->body)->toBe('Second')->and($conversation->messages()->count())->toBe(2);
    $theirs = participation($conversation, $alice);
    $mine = participation($conversation, $bob);
    expect($theirs->is_read)->toBeFalse()->and($theirs->trashed_at)->toBeNull()->and($theirs->folder->slug)->toBe('inbox')
        ->and($mine->is_read)->toBeTrue()->and($mine->trashed_at)->toBeNull()->and($mine->folder->slug)->toBe('inbox');
});

it('does not bring back a copy someone removed, or tell them', function () {
    [$alice, $bob, $cara] = [TestUser::make('Alice'), TestUser::make('Bob'), TestUser::make('Cara')];
    $messages = app(Messages::class);
    $conversation = $messages->send($alice, [$bob, $cara], 'Hi', 'First');
    app(Mailbox::class)->delete($cara, [$conversation->id]);
    Event::fake([MessageReceived::class]);

    $messages->reply($bob, $conversation, 'Second');

    Event::assertDispatched(MessageReceived::class, fn ($event) => collect($event->recipients)->pluck('id')->all() === [$alice->id]);
    expect(participation($conversation, $cara)->is_read)->toBeFalse()->and(participation($conversation, $cara)->deleted_at)->not->toBeNull();
});

it('only lets a participant who still has the conversation reply or open it', function () {
    [$alice, $bob, $cara] = [TestUser::make('Alice'), TestUser::make('Bob'), TestUser::make('Cara')];
    $messages = app(Messages::class);
    $conversation = $messages->send($alice, [$bob], 'Hi', 'First');

    expect(refusal(fn () => $messages->reply($cara, $conversation, 'Hello'))->reason)->toBe(MessageRefusal::NOT_A_PARTICIPANT)
        ->and(refusal(fn () => $messages->open($cara, $conversation))->reason)->toBe(MessageRefusal::NOT_A_PARTICIPANT);

    app(Mailbox::class)->delete($bob, [$conversation->id]);
    expect(refusal(fn () => $messages->reply($bob, $conversation, 'Hello'))->reason)->toBe(MessageRefusal::NOT_A_PARTICIPANT)
        ->and(refusal(fn () => $messages->reply($alice, $conversation, '  '))->reason)->toBe(MessageRefusal::EMPTY_BODY)
        ->and($conversation->messages()->count())->toBe(1);
});

it('refuses a reply when the policy bars a remaining participant', function () {
    [$alice, $bob] = [TestUser::make('Alice'), TestUser::make('Bob')];
    $conversation = app(Messages::class)->send($alice, [$bob], 'Hi', 'First');

    app()->instance(MessagingPolicy::class, new class implements MessagingPolicy
    {
        public function canMessage(Authenticatable $sender, Authenticatable $recipient): bool
        {
            return false;
        }
    });

    app()->forgetInstance(Messages::class);

    expect(refusal(fn () => app(Messages::class)->reply($alice, $conversation, 'More'))->reason)->toBe(MessageRefusal::NOT_ALLOWED)
        ->and($conversation->messages()->count())->toBe(1);
});

it('opens a conversation, marking the reader\'s copy read', function () {
    [$alice, $bob] = [TestUser::make('Alice'), TestUser::make('Bob')];
    $conversation = app(Messages::class)->send($alice, [$bob], 'Hi', 'First');

    $participant = app(Messages::class)->open($bob, $conversation);

    expect($participant->is_read)->toBeTrue()->and($participant->last_read_at)->not->toBeNull()
        ->and(participation($conversation, $bob)->is_read)->toBeTrue()
        ->and(participation($conversation, $alice)->is_read)->toBeTrue();
});

it('emails the recipients by default, linking to the conversation when the host names a route', function () {
    Notification::fake();
    Route::get('/m/{conversation}', fn () => '')->name('inbox.show');
    app('router')->getRoutes()->refreshNameLookups();
    config(['filagora-messages.mail.routes.conversation' => 'inbox.show']);
    [$alice, $bob, $cara] = [TestUser::make('Alice'), TestUser::make('Bob'), TestUser::make('Cara')];

    $conversation = app(Messages::class)->send($alice, [$bob, $cara], 'Plans', 'Hello');

    Notification::assertSentTo([$bob, $cara], MessageReceivedNotification::class);
    Notification::assertNotSentTo($alice, MessageReceivedNotification::class);

    $mail = (new MessageReceivedNotification($conversation, $conversation->messages->first()))->toMail($bob);
    expect($mail->subject)->toBe('New message: Plans')->and($mail->actionUrl)->toBe(route('inbox.show', $conversation));

    config(['filagora-messages.mail.routes.conversation' => null]);
    expect((new MessageReceivedNotification($conversation, $conversation->messages->first()))->toMail($bob)->actionUrl)->toBeNull();
});

it('sends no mail when the host turns it off, but still tells it through the event', function () {
    Notification::fake();
    config(['filagora-messages.mail.enabled' => false]);
    [$alice, $bob] = [TestUser::make('Alice'), TestUser::make('Bob')];
    $heard = [];
    Event::listen(MessageReceived::class, function (MessageReceived $event) use (&$heard) {
        $heard = collect($event->recipients)->pluck('id')->all();
    });

    app(Messages::class)->send($alice, [$bob], 'Hi', 'Hello');

    Notification::assertNothingSent();
    expect($heard)->toBe([$bob->id]);
});
