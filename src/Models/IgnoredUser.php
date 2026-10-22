<?php

declare(strict_types=1);

namespace Filagora\Messages\Models;

use Filagora\Core\Support\Tables;
use Filagora\Core\Support\UserModel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use InvalidArgumentException;

/**
 * One person's decision not to be messaged by, and not to message, another. Either person may be
 * the one who ignores; the other is told nothing.
 *
 * @property int $id
 * @property int $user_id Who put the other on their ignore list.
 * @property int $ignored_user_id Who is on that list.
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class IgnoredUser extends Model
{
    protected $guarded = [];

    protected static function booted(): void
    {
        static::saving(function (self $ignored): void {
            if ((string) $ignored->user_id === (string) $ignored->ignored_user_id) {
                throw new InvalidArgumentException('A person cannot ignore themselves.');
            }
        });
    }

    public function getTable(): string
    {
        return Tables::name('ignored_users');
    }

    /**
     * @return BelongsTo<Model, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(UserModel::class(), 'user_id');
    }

    /**
     * @return BelongsTo<Model, $this>
     */
    public function ignoredUser(): BelongsTo
    {
        return $this->belongsTo(UserModel::class(), 'ignored_user_id');
    }
}
