<?php

declare(strict_types=1);

namespace Filagora\Messages\Services;

use Filagora\Messages\Enums\MessageRefusal;
use Filagora\Messages\Events\ConversationsPurging;
use Filagora\Messages\Exceptions\MessageRefused;
use Filagora\Messages\Models\PrivateConversation;
use Filagora\Messages\Models\PrivateConversationParticipant;
use Filagora\Messages\Models\PrivateMessageFolder;
use Filagora\Messages\Support\Models;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * A person's folders and what is in them. Every person has an Inbox, an Archive and a Trash, made
 * the first time they are needed, and may add folders of their own. Trashing remembers the folder
 * a conversation came from so restoring puts it back; deleting removes only the person's own
 * copy, and a conversation goes for good once nobody keeps a copy (`purge`).
 */
class Mailbox
{
    /**
     * The built-in folders, in the order they are made and listed: slug => label translation key.
     *
     * @var array<string, string>
     */
    public const SYSTEM_FOLDERS = [
        'inbox' => 'filagora-messages::messages.folders.inbox',
        'archive' => 'filagora-messages::messages.folders.archive',
        'trash' => 'filagora-messages::messages.folders.trash',
    ];

    /**
     * Every folder the person has, built-in ones first and then their own by name, making the
     * built-in ones if they are missing.
     *
     * @return Collection<int, PrivateMessageFolder>
     */
    public function foldersFor(Authenticatable $user): Collection
    {
        $userId = $this->id($user);
        $this->ensureSystemFolders([$userId]);

        return $this->folderModel()::query()->where('user_id', $userId)->orderByDesc('is_system')->orderBy('name')->get();
    }

    public function systemFolder(Authenticatable $user, string $slug): PrivateMessageFolder
    {
        $folder = $this->foldersFor($user)->firstWhere('slug', $slug);
        assert($folder instanceof PrivateMessageFolder);

        return $folder;
    }

    /**
     * Make the built-in folders for these people if they are missing, and return each person's
     * Inbox folder id.
     *
     * @param  list<int|string>  $userIds
     * @return array<int|string, int>
     */
    public function ensureSystemFolders(array $userIds): array
    {
        $userIds = array_values(array_unique($userIds));

        if ($userIds === []) {
            return [];
        }

        $now = now();
        $rows = [];

        foreach ($userIds as $userId) {
            foreach (self::SYSTEM_FOLDERS as $slug => $label) {
                $rows[] = ['user_id' => $userId, 'slug' => $slug, 'name' => __($label), 'is_system' => true, 'created_at' => $now, 'updated_at' => $now];
            }
        }

        $this->folderModel()::query()->upsert($rows, ['user_id', 'slug'], ['is_system', 'updated_at']);

        return $this->folderModel()::query()->whereIn('user_id', $userIds)->where('slug', 'inbox')->pluck('id', 'user_id')
            ->map(static fn ($id): int => (int) $id)->all();
    }

    /**
     * A folder of the person's own.
     */
    public function createFolder(Authenticatable $user, string $name): PrivateMessageFolder
    {
        $name = Str::limit(trim($name), 80, '');

        if ($name === '') {
            throw new MessageRefused(MessageRefusal::EMPTY_FOLDER_NAME);
        }

        $taken = $this->folderModel()::query()->where('user_id', $this->id($user))->pluck('slug')->all();
        $base = Str::slug($name) ?: 'folder';
        $slug = Str::limit($base, 80, '');

        for ($suffix = 2; in_array($slug, $taken, true); $suffix++) {
            $slug = Str::limit($base, 76, '').'-'.$suffix;
        }

        $this->ensureSystemFolders([$this->id($user)]);

        return $this->folderModel()::query()->create(['user_id' => $this->id($user), 'name' => $name, 'slug' => $slug, 'is_system' => false]);
    }

    /**
     * Remove one of the person's own folders. Its conversations go to the Inbox.
     */
    public function deleteFolder(Authenticatable $user, PrivateMessageFolder $folder): void
    {
        $this->assertOwns($user, $folder);

        if ($folder->is_system) {
            throw new MessageRefused(MessageRefusal::SYSTEM_FOLDER);
        }

        $inbox = $this->systemFolder($user, 'inbox');

        DB::transaction(function () use ($folder, $inbox): void {
            $this->participantModel()::query()->where('folder_id', $folder->id)->update(['folder_id' => $inbox->id, 'trashed_at' => null, 'trashed_from_folder_id' => null]);
            $folder->delete();
        });
    }

    /**
     * Put conversations into one of the person's folders. Moving into Trash is `trash`.
     *
     * @param  list<int|string>  $conversationIds
     */
    public function move(Authenticatable $user, array $conversationIds, PrivateMessageFolder $target): int
    {
        $this->assertOwns($user, $target);

        if ($target->slug === 'trash' && $target->is_system) {
            return $this->trash($user, $conversationIds);
        }

        return $this->own($user, $conversationIds)->update([
            'folder_id' => $target->id,
            'trashed_at' => null,
            'trashed_from_folder_id' => null,
            'updated_at' => now(),
        ]);
    }

    /**
     * Move conversations to Trash, remembering where each came from.
     *
     * @param  list<int|string>  $conversationIds
     */
    public function trash(Authenticatable $user, array $conversationIds): int
    {
        $trash = $this->systemFolder($user, 'trash');

        return DB::transaction(function () use ($user, $conversationIds, $trash): int {
            $rows = $this->own($user, $conversationIds)->where('folder_id', '!=', $trash->id)->get();

            foreach ($rows as $participant) {
                $participant->forceFill(['trashed_from_folder_id' => $participant->folder_id, 'folder_id' => $trash->id, 'trashed_at' => now()])->save();
            }

            return $rows->count();
        });
    }

    /**
     * Take conversations out of Trash, back to the folder they came from (the Inbox if it is gone).
     *
     * @param  list<int|string>  $conversationIds
     */
    public function restore(Authenticatable $user, array $conversationIds): int
    {
        $trash = $this->systemFolder($user, 'trash');
        $inbox = $this->systemFolder($user, 'inbox');

        return DB::transaction(function () use ($user, $conversationIds, $trash, $inbox): int {
            $rows = $this->own($user, $conversationIds)->where('folder_id', $trash->id)->get();

            foreach ($rows as $participant) {
                $participant->forceFill(['folder_id' => $participant->trashed_from_folder_id ?? $inbox->id, 'trashed_at' => null, 'trashed_from_folder_id' => null])->save();
            }

            return $rows->count();
        });
    }

    /**
     * Pull a conversation out of everyone's Trash, so a new message is never left in a folder
     * that gets purged.
     */
    public function untrashForEveryone(int|string $conversationId): void
    {
        $rows = $this->participantModel()::query()->where('conversation_id', $conversationId)->whereNull('deleted_at')->whereNotNull('trashed_at')->get();

        if ($rows->isEmpty()) {
            return;
        }

        $inboxIds = $this->ensureSystemFolders($rows->pluck('user_id')->all());

        foreach ($rows as $participant) {
            $participant->forceFill([
                'folder_id' => $participant->trashed_from_folder_id ?? $inboxIds[$participant->user_id] ?? $participant->folder_id,
                'trashed_at' => null,
                'trashed_from_folder_id' => null,
            ])->save();
        }
    }

    /**
     * Remove the person's own copy of these conversations for good. The conversation itself stays
     * for the others, and goes when the last of them removes theirs (`purge`).
     *
     * @param  list<int|string>  $conversationIds
     */
    public function delete(Authenticatable $user, array $conversationIds): int
    {
        return $this->own($user, $conversationIds)->update(['deleted_at' => now(), 'updated_at' => now()]);
    }

    /**
     * Remove everything in the person's Trash.
     */
    public function emptyTrash(Authenticatable $user): int
    {
        $trash = $this->systemFolder($user, 'trash');

        return $this->delete($user, $this->participantModel()::query()
            ->where('user_id', $this->id($user))->where('folder_id', $trash->id)->whereNull('deleted_at')
            ->pluck('conversation_id')->all());
    }

    /**
     * @param  list<int|string>  $conversationIds
     */
    public function markRead(Authenticatable $user, array $conversationIds): int
    {
        return $this->own($user, $conversationIds)->update(['is_read' => true, 'last_read_at' => now(), 'updated_at' => now()]);
    }

    /**
     * @param  list<int|string>  $conversationIds
     */
    public function markUnread(Authenticatable $user, array $conversationIds): int
    {
        return $this->own($user, $conversationIds)->update(['is_read' => false, 'updated_at' => now()]);
    }

    /**
     * How many conversations the person has not read, outside Trash.
     */
    public function unreadCount(Authenticatable $user): int
    {
        $trash = $this->systemFolder($user, 'trash');

        return $this->participantModel()::query()->where('user_id', $this->id($user))->whereNull('deleted_at')
            ->where('is_read', false)->where('folder_id', '!=', $trash->id)->count();
    }

    /**
     * Remove copies that have sat in Trash for `$days` days, then delete the conversations nobody
     * keeps a copy of any more. Returns how many copies and how many conversations went.
     * `$dryRun` counts without deleting.
     *
     * @return array{copies: int, conversations: int}
     */
    public function purge(int $days, bool $dryRun = false): array
    {
        $cutoff = now()->subDays(max(1, $days));

        $expired = $this->participantModel()::query()->whereNull('deleted_at')->whereNotNull('trashed_at')->where('trashed_at', '<', $cutoff)
            ->whereHas('folder', fn (Builder $query) => $query->where('slug', 'trash'));

        $copies = $expired->count();

        if (! $dryRun) {
            $expired->update(['deleted_at' => now()]);
        }

        $orphans = $this->conversationModel()::query()->whereDoesntHave('participants', fn (Builder $query) => $query->whereNull('deleted_at'));

        if ($dryRun) {
            // Copies about to be removed may leave more conversations without a keeper.
            $keptElsewhere = $this->participantModel()::query()->whereNull('deleted_at')
                ->where(fn (Builder $query) => $query->whereNull('trashed_at')->orWhere('trashed_at', '>=', $cutoff)->orWhereDoesntHave('folder', fn (Builder $folder) => $folder->where('slug', 'trash')))
                ->select('conversation_id');

            return ['copies' => $copies, 'conversations' => $this->conversationModel()::query()->whereNotIn('id', $keptElsewhere)->count()];
        }

        $ids = $orphans->pluck('id')->map(static fn ($id): int => (int) $id)->all();

        if ($ids !== []) {
            ConversationsPurging::dispatch($ids);
            $this->conversationModel()::query()->whereIn('id', $ids)->delete();
        }

        return ['copies' => $copies, 'conversations' => count($ids)];
    }

    /**
     * The person's own live (not removed) copies of these conversations.
     *
     * @param  list<int|string>  $conversationIds
     * @return Builder<PrivateConversationParticipant>
     */
    private function own(Authenticatable $user, array $conversationIds): Builder
    {
        return $this->participantModel()::query()
            ->where('user_id', $this->id($user))
            ->whereIn('conversation_id', $conversationIds === [] ? [0] : $conversationIds)
            ->whereNull('deleted_at');
    }

    private function assertOwns(Authenticatable $user, PrivateMessageFolder $folder): void
    {
        if ((string) $folder->user_id !== (string) $this->id($user)) {
            throw new MessageRefused(MessageRefusal::NOT_YOUR_FOLDER);
        }
    }

    private function id(Authenticatable $user): int|string
    {
        return $user->getAuthIdentifier();
    }

    /**
     * @return class-string<PrivateMessageFolder>
     */
    private function folderModel(): string
    {
        return Models::class('folder');
    }

    /**
     * @return class-string<PrivateConversationParticipant>
     */
    private function participantModel(): string
    {
        return Models::class('participant');
    }

    /**
     * @return class-string<PrivateConversation>
     */
    private function conversationModel(): string
    {
        return Models::class('conversation');
    }
}
