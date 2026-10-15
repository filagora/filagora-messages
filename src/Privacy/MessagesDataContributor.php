<?php

declare(strict_types=1);

namespace Filagora\Messages\Privacy;

use Filagora\Core\Contracts\PrivacyContributor;
use Filagora\Messages\Events\ConversationsPurging;
use Filagora\Messages\Models\PrivateConversation;
use Filagora\Messages\Support\Models;
use Illuminate\Database\Eloquent\Model;

/**
 * What the private messages hold about a person, for the compliance package's export: the
 * conversations they are in with every message in them. Erasure removes their copy of each
 * conversation and their folders; messages they sent stay for the other people in the
 * conversation, under the account the host's anonymiser has by then made anonymous, and a
 * conversation nobody is left in is deleted.
 */
final class MessagesDataContributor implements PrivacyContributor
{
    public function key(): string
    {
        return 'messages';
    }

    public function export(Model $user): array
    {
        $conversations = [];

        $participations = Models::class('participant')::query()->where('user_id', $user->getKey())->whereNull('deleted_at')->orderBy('id')->get();

        foreach ($participations as $participation) {
            $conversation = $participation->conversation;

            if (! $conversation instanceof PrivateConversation) {
                continue;
            }

            $conversations[] = [
                'id' => $conversation->id,
                'subject' => $conversation->subject,
                'messages' => $conversation->messages()->orderBy('id')->get(['id', 'sender_id', 'body', 'created_at'])->toArray(),
            ];
        }

        return [
            'folders' => Models::class('folder')::query()->where('user_id', $user->getKey())->orderBy('id')->get(['name', 'slug', 'is_system'])->toArray(),
            'conversations' => $conversations,
        ];
    }

    public function erase(Model $user): void
    {
        $conversationIds = Models::class('participant')::query()->where('user_id', $user->getKey())->pluck('conversation_id')->all();

        Models::class('participant')::query()->where('user_id', $user->getKey())->delete();
        Models::class('folder')::query()->where('user_id', $user->getKey())->delete();

        // Conversations no one is left in. Hosts hear of them through ConversationsPurging.
        $orphans = Models::class('conversation')::query()->whereIn('id', $conversationIds)->whereDoesntHave('participants')->pluck('id')->map(static fn ($id): int => (int) $id)->all();

        if ($orphans !== []) {
            ConversationsPurging::dispatch($orphans);
            Models::class('conversation')::query()->whereIn('id', $orphans)->delete();
        }
    }
}
