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
 * One of a person's mailbox folders. The system folders (inbox, archive, trash) are made for
 * everyone; the rest are theirs.
 *
 * @property int $id
 * @property int $user_id
 * @property string $name
 * @property string $slug
 * @property bool $is_system
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class PrivateMessageFolder extends Model
{
    protected $fillable = [
        'user_id',
        'name',
        'slug',
        'is_system',
    ];

    public function getTable(): string
    {
        return Tables::name('private_message_folders');
    }

    protected function casts(): array
    {
        return [
            'is_system' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<Model, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(UserModel::class(), 'user_id');
    }

    /**
     * @return HasMany<Model, $this>
     */
    public function participants(): HasMany
    {
        return $this->hasMany(Models::class('participant'), 'folder_id');
    }
}
