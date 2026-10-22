<?php

declare(strict_types=1);

namespace Filagora\Messages\Services;

use Filagora\Messages\Events\UserIgnored;
use Filagora\Messages\Events\UserUnignored;
use Filagora\Messages\Models\IgnoredUser;
use Filagora\Messages\Support\Models;
use Illuminate\Contracts\Auth\Authenticatable;
use InvalidArgumentException;

/**
 * Ignore lists. Ignoring is one person's choice about another, but it works in both directions
 * for private messages: neither can message the other while either has the other on their list.
 * `Messages` asks `isBlockedBetween()` before every send and reply; a host's recipient search
 * can ask `blockedIdsFor()` to leave those people out.
 */
class IgnoredUsers
{
    /**
     * Put `$target` on `$user`'s ignore list. Doing it twice changes nothing.
     *
     * @return bool Whether it was added now (false when already on the list).
     *
     * @throws InvalidArgumentException When a person tries to ignore themselves.
     */
    public function ignore(Authenticatable $user, Authenticatable $target): bool
    {
        if ($this->same($user, $target)) {
            throw new InvalidArgumentException('A person cannot ignore themselves.');
        }

        /** @var IgnoredUser $row */
        $row = Models::class('ignored_user')::query()->firstOrCreate([
            'user_id' => $user->getAuthIdentifier(),
            'ignored_user_id' => $target->getAuthIdentifier(),
        ]);

        if ($row->wasRecentlyCreated) {
            UserIgnored::dispatch($user->getAuthIdentifier(), $target->getAuthIdentifier());
        }

        return $row->wasRecentlyCreated;
    }

    /**
     * Take `$target` off `$user`'s ignore list.
     *
     * @return bool Whether they were on it.
     */
    public function unignore(Authenticatable $user, Authenticatable $target): bool
    {
        $deleted = Models::class('ignored_user')::query()
            ->where('user_id', $user->getAuthIdentifier())
            ->where('ignored_user_id', $target->getAuthIdentifier())
            ->delete();

        if ($deleted > 0) {
            UserUnignored::dispatch($user->getAuthIdentifier(), $target->getAuthIdentifier());
        }

        return $deleted > 0;
    }

    /**
     * Whether `$user` has `$target` on their own list.
     */
    public function isIgnoring(Authenticatable $user, Authenticatable $target): bool
    {
        return Models::class('ignored_user')::query()
            ->where('user_id', $user->getAuthIdentifier())
            ->where('ignored_user_id', $target->getAuthIdentifier())
            ->exists();
    }

    /**
     * Whether `$target` has `$user` on their list.
     */
    public function isIgnoredBy(Authenticatable $user, Authenticatable $target): bool
    {
        return $this->isIgnoring($target, $user);
    }

    /**
     * Whether either person has the other on their list.
     */
    public function isBlockedBetween(Authenticatable $a, Authenticatable $b): bool
    {
        if ($this->same($a, $b)) {
            return false;
        }

        return Models::class('ignored_user')::query()
            ->where(fn ($query) => $query->where('user_id', $a->getAuthIdentifier())->where('ignored_user_id', $b->getAuthIdentifier()))
            ->orWhere(fn ($query) => $query->where('user_id', $b->getAuthIdentifier())->where('ignored_user_id', $a->getAuthIdentifier()))
            ->exists();
    }

    /**
     * The people `$user` has on their list.
     *
     * @return list<int>
     */
    public function ignoredIds(Authenticatable $user): array
    {
        return $this->ids(Models::class('ignored_user')::query()->where('user_id', $user->getAuthIdentifier())->pluck('ignored_user_id'));
    }

    /**
     * Everyone `$user` cannot message or be messaged by: those on their list, and those who have
     * them on theirs.
     *
     * @return list<int>
     */
    public function blockedIdsFor(Authenticatable $user): array
    {
        $id = $user->getAuthIdentifier();

        return $this->ids(
            Models::class('ignored_user')::query()->where('user_id', $id)->pluck('ignored_user_id')
                ->merge(Models::class('ignored_user')::query()->where('ignored_user_id', $id)->pluck('user_id'))
        );
    }

    /**
     * @param  iterable<int, mixed>  $values
     * @return list<int>
     */
    private function ids(iterable $values): array
    {
        $ids = [];

        foreach ($values as $value) {
            $ids[(int) $value] = (int) $value;
        }

        ksort($ids);

        return array_values($ids);
    }

    private function same(Authenticatable $a, Authenticatable $b): bool
    {
        return (string) $a->getAuthIdentifier() === (string) $b->getAuthIdentifier();
    }
}
