<?php

declare(strict_types=1);

use Filagora\Core\Privacy\PrivacyContributorRegistry;
use Filagora\Core\Privacy\RetentionRuleRegistry;
use Filagora\Messages\Events\ConversationsPurging;
use Filagora\Messages\Models\IgnoredUser;
use Filagora\Messages\Models\PrivateConversation;
use Filagora\Messages\Models\PrivateConversationParticipant;
use Filagora\Messages\Models\PrivateMessage;
use Filagora\Messages\Models\PrivateMessageFolder;
use Filagora\Messages\Privacy\MessagesDataContributor;
use Filagora\Messages\Services\IgnoredUsers;
use Filagora\Messages\Services\Mailbox;
use Filagora\Messages\Services\Messages;
use Filagora\Messages\Tests\Support\TestUser;
use Illuminate\Support\Facades\Event;

function contributor(): MessagesDataContributor
{
    return collect(app(PrivacyContributorRegistry::class)->all())->first(fn ($c) => $c->key() === 'messages');
}

it('registers its privacy contributor and retention rule with core', function () {
    expect(contributor())->toBeInstanceOf(MessagesDataContributor::class)
        ->and(collect(app(RetentionRuleRegistry::class)->all())->map->key()->all())->toContain('messages.trash');
});

it('exports the folders and every message of the conversations a person is in', function () {
    [$alice, $bob, $cara] = [TestUser::make('Alice'), TestUser::make('Bob'), TestUser::make('Cara')];
    $messages = app(Messages::class);
    $conversation = $messages->send($alice, [$bob], 'Plans', 'Hello');
    $messages->reply($bob, $conversation, 'Reply');
    $messages->send($cara, [$alice], 'Other', 'Not for Bob');
    app(Mailbox::class)->createFolder($bob, 'Keep');

    $export = contributor()->export($bob);

    expect(collect($export['folders'])->pluck('slug')->sort()->values()->all())->toBe(['archive', 'inbox', 'keep', 'trash'])
        ->and($export['conversations'])->toHaveCount(1)
        ->and($export['conversations'][0]['subject'])->toBe('Plans')
        ->and(collect($export['conversations'][0]['messages'])->pluck('body')->all())->toBe(['Hello', 'Reply']);
});

it('leaves out conversations the person removed', function () {
    [$alice, $bob] = [TestUser::make('Alice'), TestUser::make('Bob')];
    $conversation = app(Messages::class)->send($alice, [$bob], 'Plans', 'Hello');
    app(Mailbox::class)->delete($bob, [$conversation->id]);

    expect(contributor()->export($bob)['conversations'])->toBe([]);
});

it('erases a person\'s copies and folders, keeping what others still have', function () {
    [$alice, $bob, $cara] = [TestUser::make('Alice'), TestUser::make('Bob'), TestUser::make('Cara')];
    $shared = app(Messages::class)->send($alice, [$bob], 'Plans', 'Hello');
    $solo = app(Messages::class)->send($bob, [$cara], 'Mine', 'Hello');
    app(Mailbox::class)->delete($cara, [$solo->id]);
    Event::fake([ConversationsPurging::class]);

    contributor()->erase($bob);

    expect(PrivateConversationParticipant::where('user_id', $bob->id)->count())->toBe(0)
        ->and(PrivateMessageFolder::where('user_id', $bob->id)->count())->toBe(0)
        ->and(PrivateConversation::find($shared->id))->not->toBeNull()
        ->and(PrivateMessage::where('conversation_id', $shared->id)->count())->toBe(1)
        ->and(PrivateConversationParticipant::where('user_id', $alice->id)->count())->toBe(1);
});

it('deletes conversations that nobody is left in, telling the host first', function () {
    [$alice, $bob] = [TestUser::make('Alice'), TestUser::make('Bob')];
    $conversation = app(Messages::class)->send($alice, [$bob], 'Plans', 'Hello');
    PrivateConversationParticipant::where('user_id', $alice->id)->delete();
    Event::fake([ConversationsPurging::class]);

    contributor()->erase($bob);

    expect(PrivateConversation::count())->toBe(0)->and(PrivateMessage::count())->toBe(0);
    Event::assertDispatched(ConversationsPurging::class, fn ($event) => $event->conversationIds === [$conversation->id]);
});

it('purges Trash through core\'s retention run, and honours zero as keep', function () {
    [, $bob] = [TestUser::make('Alice'), TestUser::make('Bob'), null, null];
    $alice = TestUser::make('Dora');
    $conversation = app(Messages::class)->send($alice, [$bob], 'Plans', 'Hello');
    app(Mailbox::class)->trash($bob, [$conversation->id]);
    PrivateConversationParticipant::where('user_id', $bob->id)->update(['trashed_at' => now()->subDays(40)->setTime(12, 0)]);
    $rule = collect(app(RetentionRuleRegistry::class)->all())->first(fn ($r) => $r->key() === 'messages.trash');

    config(['filagora-messages.trash.retention_days' => 0]);
    expect($rule->prune(false))->toBe(0);

    config(['filagora-messages.trash.retention_days' => 30]);
    expect($rule->prune(true))->toBe(1)->and(PrivateConversationParticipant::whereNotNull('deleted_at')->count())->toBe(0)
        ->and($rule->prune(false))->toBe(1)->and(PrivateConversationParticipant::whereNotNull('deleted_at')->count())->toBe(1);
});

it('exports the people a person ignores, and not the people who ignore them', function () {
    [$alice, $bob, $cara] = [TestUser::make('Alice'), TestUser::make('Bob'), TestUser::make('Cara')];
    $this->travelTo(now()->setTime(12, 0));
    app(IgnoredUsers::class)->ignore($alice, $bob);
    app(IgnoredUsers::class)->ignore($cara, $alice);

    expect(contributor()->export($alice)['ignoring'])->toBe([['user_id' => $bob->id, 'since' => now()->toIso8601String()]])
        ->and(contributor()->export($cara)['ignoring'])->toBe([['user_id' => $alice->id, 'since' => now()->toIso8601String()]])
        ->and(contributor()->export($bob)['ignoring'])->toBe([]);
});

it('erases a person\'s ignore list and the entries that name them, and nobody else\'s', function () {
    [$alice, $bob, $cara, $dan] = [TestUser::make('Alice'), TestUser::make('Bob'), TestUser::make('Cara'), TestUser::make('Dan')];
    app(IgnoredUsers::class)->ignore($alice, $bob);
    app(IgnoredUsers::class)->ignore($cara, $alice);
    app(IgnoredUsers::class)->ignore($bob, $dan);

    contributor()->erase($alice);

    expect(IgnoredUser::count())->toBe(1)
        ->and(IgnoredUser::sole()->only(['user_id', 'ignored_user_id']))->toBe(['user_id' => $bob->id, 'ignored_user_id' => $dan->id]);
});
