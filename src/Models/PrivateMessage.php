<?php

declare(strict_types=1);

namespace Filagora\Messages\Models;

use Filagora\Core\Support\Tables;
use Filagora\Core\Support\UserModel;
use Filagora\Messages\Support\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * One message in a conversation.
 *
 * @property int $id
 * @property int $conversation_id
 * @property int $sender_id
 * @property string $body
 * @property string $content_format
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class PrivateMessage extends Model
{
    protected $fillable = [
        'conversation_id',
        'sender_id',
        'body',
        'content_format',
    ];

    public function getTable(): string
    {
        return Tables::name('private_messages');
    }

    /**
     * @return BelongsTo<PrivateConversation, $this>
     */
    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Models::class('conversation'), 'conversation_id');
    }

    /**
     * @return BelongsTo<Model, $this>
     */
    public function sender(): BelongsTo
    {
        return $this->belongsTo(UserModel::class(), 'sender_id');
    }

    /**
     * The files this message carries (needs `filagora/filagora-forums` and the link columns; see
     * `filagora:messages:link-attachments`).
     *
     * @return HasMany<Model, $this>
     */
    public function attachments(): HasMany
    {
        $model = config('filagora.models.post_attachment') ?? config('filagora-forums.models.post_attachment');

        if (! is_string($model)) {
            throw new LogicException('Message attachments need filagora/filagora-forums.');
        }

        /** @var class-string<Model> $model */
        return $this->hasMany($model, 'private_message_id');
    }
}
