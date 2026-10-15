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
 * One person's place in a conversation: which folder it is in for them, whether they have read
 * it, and whether they trashed or permanently removed their copy.
 *
 * @property int $id
 * @property int $conversation_id
 * @property int $user_id
 * @property int $folder_id
 * @property Carbon|null $last_read_at
 * @property bool $is_read
 * @property Carbon|null $deleted_at
 * @property Carbon|null $trashed_at
 * @property int|null $trashed_from_folder_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class PrivateConversationParticipant extends Model
{
    protected $fillable = [
        'conversation_id',
        'user_id',
        'folder_id',
        'last_read_at',
        'is_read',
        'deleted_at',
        'trashed_at',
        'trashed_from_folder_id',
    ];

    public function getTable(): string
    {
        return Tables::name('private_conversation_participants');
    }

    protected function casts(): array
    {
        return [
            'is_read' => 'boolean',
            'last_read_at' => 'datetime',
            'deleted_at' => 'datetime',
            'trashed_at' => 'datetime',
        ];
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
    public function user(): BelongsTo
    {
        return $this->belongsTo(UserModel::class(), 'user_id');
    }

    /**
     * @return BelongsTo<PrivateMessageFolder, $this>
     */
    public function folder(): BelongsTo
    {
        return $this->belongsTo(Models::class('folder'), 'folder_id');
    }

    /**
     * @return BelongsTo<PrivateMessageFolder, $this>
     */
    public function trashedFromFolder(): BelongsTo
    {
        return $this->belongsTo(Models::class('folder'), 'trashed_from_folder_id');
    }
}
