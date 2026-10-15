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

/**
 * A conversation between members: a subject, the people in it and its messages.
 *
 * @property int $id
 * @property int $created_by_id
 * @property string $subject
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class PrivateConversation extends Model
{
    protected $fillable = [
        'created_by_id',
        'subject',
    ];

    public function getTable(): string
    {
        return Tables::name('private_conversations');
    }

    /**
     * @return BelongsTo<Model, $this>
     */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(UserModel::class(), 'created_by_id');
    }

    /**
     * @return HasMany<PrivateMessage, $this>
     */
    public function messages(): HasMany
    {
        return $this->hasMany(Models::class('message'), 'conversation_id')->orderBy('created_at')->orderBy('id');
    }

    /**
     * @return HasMany<PrivateConversationParticipant, $this>
     */
    public function participants(): HasMany
    {
        return $this->hasMany(Models::class('participant'), 'conversation_id');
    }
}
