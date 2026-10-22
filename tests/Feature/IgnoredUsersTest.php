<?php

declare(strict_types=1);

use Filagora\Messages\Events\UserIgnored;
use Filagora\Messages\Events\UserUnignored;
use Filagora\Messages\Models\IgnoredUser;
use Filagora\Messages\Services\IgnoredUsers;
use Filagora\Messages\Tests\Support\TestUser;
use Illuminate\Support\Facades\Event;

function ignores(): IgnoredUsers
{
    return app(IgnoredUsers::class);
}

it('puts a person on the list once, and says whether it was new', function () {
    [$alice, $bob] = [TestUser::make('Alice'), TestUser::make('Bob')];

    expect(ignores()->ignore($alice, $bob))->toBeTrue()
        ->and(ignores()->ignore($alice, $bob))->toBeFalse()
        ->and(IgnoredUser::count())->toBe(1)
        ->and(IgnoredUser::sole()->only(['user_id', 'ignored_user_id']))->toBe(['user_id' => $alice->id, 'ignored_user_id' => $bob->id]);
});

it('refuses to let a person ignore themselves', function () {
    $alice = TestUser::make('Alice');

    expect(fn () => ignores()->ignore($alice, $alice))->toThrow(InvalidArgumentException::class, 'cannot ignore themselves')
        ->and(IgnoredUser::count())->toBe(0);
});

it('refuses a self-ignore written straight to the model too', function () {
    $alice = TestUser::make('Alice');

    expect(fn () => IgnoredUser::create(['user_id' => $alice->id, 'ignored_user_id' => $alice->id]))->toThrow(InvalidArgumentException::class);
});

it('takes a person off the list, and says whether they were on it', function () {
    [$alice, $bob, $cara] = [TestUser::make('Alice'), TestUser::make('Bob'), TestUser::make('Cara')];
    ignores()->ignore($alice, $bob);
    ignores()->ignore($alice, $cara);

    expect(ignores()->unignore($alice, $bob))->toBeTrue()
        ->and(ignores()->unignore($alice, $bob))->toBeFalse()
        ->and(ignores()->isIgnoring($alice, $cara))->toBeTrue()
        ->and(ignores()->isIgnoring($alice, $bob))->toBeFalse();
});

it('is a choice about one direction only when asked directly', function () {
    [$alice, $bob] = [TestUser::make('Alice'), TestUser::make('Bob')];
    ignores()->ignore($alice, $bob);

    expect(ignores()->isIgnoring($alice, $bob))->toBeTrue()
        ->and(ignores()->isIgnoring($bob, $alice))->toBeFalse()
        ->and(ignores()->isIgnoredBy($bob, $alice))->toBeTrue()
        ->and(ignores()->isIgnoredBy($alice, $bob))->toBeFalse();
});

it('blocks messaging both ways when either has the other on their list', function () {
    [$alice, $bob, $cara] = [TestUser::make('Alice'), TestUser::make('Bob'), TestUser::make('Cara')];
    ignores()->ignore($alice, $bob);

    expect(ignores()->isBlockedBetween($alice, $bob))->toBeTrue()
        ->and(ignores()->isBlockedBetween($bob, $alice))->toBeTrue()
        ->and(ignores()->isBlockedBetween($alice, $cara))->toBeFalse()
        ->and(ignores()->isBlockedBetween($alice, $alice))->toBeFalse();
});

it('lists who a person ignores, and everyone they cannot message', function () {
    [$alice, $bob, $cara, $dan, $eve] = [TestUser::make('Alice'), TestUser::make('Bob'), TestUser::make('Cara'), TestUser::make('Dan'), TestUser::make('Eve')];
    ignores()->ignore($alice, $dan);
    ignores()->ignore($alice, $bob);
    ignores()->ignore($cara, $alice);
    ignores()->ignore($eve, $bob);

    expect(ignores()->ignoredIds($alice))->toBe([$bob->id, $dan->id])
        ->and(ignores()->blockedIdsFor($alice))->toBe([$bob->id, $cara->id, $dan->id])
        ->and(ignores()->blockedIdsFor($eve))->toBe([$bob->id])
        ->and(ignores()->blockedIdsFor(TestUser::make('Nobody')))->toBe([]);
});

it('lists a person once when they are on the list in both directions', function () {
    [$alice, $bob] = [TestUser::make('Alice'), TestUser::make('Bob')];
    ignores()->ignore($alice, $bob);
    ignores()->ignore($bob, $alice);

    expect(ignores()->blockedIdsFor($alice))->toBe([$bob->id]);
});

it('fires an event when someone is added or removed, and not when nothing changed', function () {
    Event::fake([UserIgnored::class, UserUnignored::class]);
    [$alice, $bob] = [TestUser::make('Alice'), TestUser::make('Bob')];

    ignores()->ignore($alice, $bob);
    ignores()->ignore($alice, $bob);
    ignores()->unignore($alice, $bob);
    ignores()->unignore($alice, $bob);

    Event::assertDispatchedTimes(UserIgnored::class, 1);
    Event::assertDispatched(UserIgnored::class, fn (UserIgnored $event) => $event->userId === $alice->id && $event->ignoredUserId === $bob->id);
    Event::assertDispatchedTimes(UserUnignored::class, 1);
    Event::assertDispatched(UserUnignored::class, fn (UserUnignored $event) => $event->userId === $alice->id && $event->ignoredUserId === $bob->id);
});

it('removes entries with the person on either side', function () {
    [$alice, $bob, $cara] = [TestUser::make('Alice'), TestUser::make('Bob'), TestUser::make('Cara')];
    ignores()->ignore($alice, $bob);
    ignores()->ignore($cara, $alice);

    $alice->delete();

    expect(IgnoredUser::count())->toBe(0);
});
