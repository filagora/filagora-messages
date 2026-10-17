<?php

declare(strict_types=1);

namespace Filagora\Messages\Attachments;

use Filagora\Core\Support\Tables;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The two columns by which private messages claim files in the attachments table of
 * `filagora-forums`. The table belongs to forums; this adds a message link and a conversation link,
 * each cleared (not cascaded) when the message or conversation goes, so the files can be cleaned
 * up. Safe to run again, and does nothing when forums is not installed: if forums is installed
 * later, run `php artisan filagora:messages:link-attachments`.
 */
final class AttachmentLinks
{
    public static function table(): string
    {
        return Tables::name('post_attachments');
    }

    public static function available(): bool
    {
        return Schema::hasTable(self::table());
    }

    public static function installed(): bool
    {
        return self::available() && Schema::hasColumns(self::table(), ['private_message_id', 'private_conversation_id']);
    }

    /**
     * @return bool Whether the columns are there now (false when the attachments table does not exist).
     */
    public static function install(): bool
    {
        if (! self::available()) {
            return false;
        }

        self::link('private_message_id', 'private_messages', 'thread_id');
        self::link('private_conversation_id', 'private_conversations', 'private_message_id');

        return true;
    }

    public static function uninstall(): void
    {
        if (! self::available()) {
            return;
        }

        foreach (['private_conversation_id', 'private_message_id'] as $column) {
            if (self::hasForeignKey($column)) {
                Schema::table(self::table(), function (Blueprint $table) use ($column): void {
                    $table->dropForeign([$column]);
                });
            }

            if (Schema::hasColumn(self::table(), $column)) {
                Schema::table(self::table(), function (Blueprint $table) use ($column): void {
                    $table->dropColumn($column);
                });
            }
        }
    }

    /**
     * Add the column (after another) and its foreign key, whichever of the two is missing.
     */
    private static function link(string $column, string $referenced, string $after): void
    {
        if (! Schema::hasColumn(self::table(), $column)) {
            Schema::table(self::table(), function (Blueprint $table) use ($column, $after): void {
                $table->unsignedBigInteger($column)->nullable()->after($after);
            });
        }

        if (! self::hasForeignKey($column)) {
            Schema::table(self::table(), function (Blueprint $table) use ($column, $referenced): void {
                $table->foreign($column)->references('id')->on(Tables::name($referenced))->nullOnDelete();
            });
        }
    }

    private static function hasForeignKey(string $column): bool
    {
        foreach (Schema::getForeignKeys(self::table()) as $key) {
            if ($key['columns'] === [$column]) {
                return true;
            }
        }

        return false;
    }
}
