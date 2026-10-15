<?php

declare(strict_types=1);

namespace Filagora\Messages\Models;

use Filagora\Core\Support\Tables;
use Filagora\Core\Support\UserModel;
use Filagora\Messages\Support\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

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
     * @return BelongsTo<Model, $this>
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
}
