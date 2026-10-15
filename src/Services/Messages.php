<?php

declare(strict_types=1);

namespace Filagora\Messages\Services;

use Filagora\Messages\Contracts\MessagingPolicy;
use Filagora\Messages\Enums\MessageRefusal;
use Filagora\Messages\Events\MessageReceived;
use Filagora\Messages\Exceptions\MessageRefused;
use Filagora\Messages\Models\PrivateConversation;
use Filagora\Messages\Models\PrivateConversationParticipant;
use Filagora\Messages\Models\PrivateMessage;
use Filagora\Messages\Support\Models;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Sending, replying and opening. A new conversation goes to its recipients' Inboxes, unread; a
 * reply pulls the conversation out of anyone's Trash and makes it unread for everyone but the
 * sender. The site's `MessagingPolicy` decides who may message whom, and the whole send is refused
 * (`MessageRefused`) if any recipient is off limits. Rendering and attachments are the host's;
 * the body is kept as given, in the `markdown` content format.
 */
class Messages
{
    public function __construct(private readonly Mailbox $mailbox, private readonly MessagingPolicy $policy) {}

    /**
     * Start a conversation. The sender is a participant too, with their copy already read.
     *
     * @param  iterable<Authenticatable>  $recipients
     */
    public function send(Authenticatable $sender, iterable $recipients, string $subject, string $body): PrivateConversation
    {
        $subject = trim($subject);
        $body = trim($body);

        if ($subject === '') {
            throw new MessageRefused(MessageRefusal::EMPTY_SUBJECT);
        }

        if ($body === '') {
            throw new MessageRefused(MessageRefusal::EMPTY_BODY);
        }

        $recipients = $this->distinctOthers($sender, $recipients);

        if ($recipients === []) {
            throw new MessageRefused(MessageRefusal::NO_RECIPIENTS);
        }

        $this->assertAllowed($sender, $recipients);

        $created = DB::transaction(function () use ($sender, $recipients, $subject, $body): array {
            $conversation = Models::class('conversation')::query()->create([
                'created_by_id' => $sender->getAuthIdentifier(),
                'subject' => Str::limit($subject, 255, ''),
            ]);

            $message = $this->addMessage($conversation, $sender, $body);
            $inboxes = $this->mailbox->ensureSystemFolders([$sender->getAuthIdentifier(), ...array_keys($recipients)]);

            Models::class('participant')::query()->create([
                'conversation_id' => $conversation->id,
                'user_id' => $sender->getAuthIdentifier(),
                'folder_id' => $inboxes[$sender->getAuthIdentifier()],
                'is_read' => true,
                'last_read_at' => now(),
            ]);

            foreach (array_keys($recipients) as $recipientId) {
                Models::class('participant')::query()->create([
                    'conversation_id' => $conversation->id,
                    'user_id' => $recipientId,
                    'folder_id' => $inboxes[$recipientId],
                    'is_read' => false,
                ]);
            }

            return [$conversation, $message];
        });

        [$conversation, $message] = $created;
        MessageReceived::dispatch($conversation, $message, array_values($recipients));

        return $conversation;
    }

    /**
     * Add a message to a conversation the sender is still part of.
     */
    public function reply(Authenticatable $sender, PrivateConversation $conversation, string $body): PrivateMessage
    {
        $this->participation($sender, $conversation);

        $body = trim($body);

        if ($body === '') {
            throw new MessageRefused(MessageRefusal::EMPTY_BODY);
        }

        $others = Models::class('participant')::query()
            ->where('conversation_id', $conversation->id)->where('user_id', '!=', $sender->getAuthIdentifier())->whereNull('deleted_at')
            ->with('user')->get();

        $recipients = [];

        foreach ($others as $participant) {
            $user = $participant->user;

            if ($user instanceof Authenticatable) {
                $recipients[$user->getAuthIdentifier()] = $user;
            }
        }

        $this->assertAllowed($sender, $recipients);

        $message = DB::transaction(function () use ($conversation, $sender, $body): PrivateMessage {
            $message = $this->addMessage($conversation, $sender, $body);

            $this->mailbox->untrashForEveryone($conversation->id);

            Models::class('participant')::query()->where('conversation_id', $conversation->id)->whereNull('deleted_at')
                ->where('user_id', '!=', $sender->getAuthIdentifier())->update(['is_read' => false, 'updated_at' => now()]);
            Models::class('participant')::query()->where('conversation_id', $conversation->id)->where('user_id', $sender->getAuthIdentifier())
                ->update(['is_read' => true, 'last_read_at' => now(), 'updated_at' => now()]);

            return $message;
        });

        MessageReceived::dispatch($conversation, $message, array_values($recipients));

        return $message;
    }

    /**
     * Open a conversation for reading: the person's place in it, marked read.
     */
    public function open(Authenticatable $user, PrivateConversation $conversation): PrivateConversationParticipant
    {
        $participant = $this->participation($user, $conversation);
        $participant->forceFill(['is_read' => true, 'last_read_at' => now()])->save();

        return $participant;
    }

    private function participation(Authenticatable $user, PrivateConversation $conversation): PrivateConversationParticipant
    {
        $participant = Models::class('participant')::query()
            ->where('conversation_id', $conversation->id)->where('user_id', $user->getAuthIdentifier())->whereNull('deleted_at')->first();

        return $participant ?? throw new MessageRefused(MessageRefusal::NOT_A_PARTICIPANT);
    }

    private function addMessage(PrivateConversation $conversation, Authenticatable $sender, string $body): PrivateMessage
    {
        return Models::class('message')::query()->create([
            'conversation_id' => $conversation->id,
            'sender_id' => $sender->getAuthIdentifier(),
            'body' => $body,
            'content_format' => 'markdown',
        ]);
    }

    /**
     * @param  iterable<Authenticatable>  $recipients
     * @return array<int|string, Authenticatable>
     */
    private function distinctOthers(Authenticatable $sender, iterable $recipients): array
    {
        $distinct = [];

        foreach ($recipients as $recipient) {
            if ((string) $recipient->getAuthIdentifier() !== (string) $sender->getAuthIdentifier()) {
                $distinct[$recipient->getAuthIdentifier()] = $recipient;
            }
        }

        return $distinct;
    }

    /**
     * @param  array<int|string, Authenticatable>  $recipients
     */
    private function assertAllowed(Authenticatable $sender, array $recipients): void
    {
        $blocked = [];

        foreach ($recipients as $id => $recipient) {
            if (! $this->policy->canMessage($sender, $recipient)) {
                $blocked[] = $id;
            }
        }

        if ($blocked !== []) {
            throw new MessageRefused(MessageRefusal::NOT_ALLOWED, $blocked);
        }
    }
}
