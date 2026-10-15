<?php

declare(strict_types=1);

use Filagora\Core\Support\Tables;
use Filagora\Core\Support\UserKey;
use Filagora\Core\Support\UserModel;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Private conversations, in the shape of the forum the package was extracted from: each person's
 * folders, the conversations, who takes part in them (with their own folder, read state and trash),
 * and the messages. Names that would pass MariaDB's 64 character limit once the table prefix is
 * added are written out (the names that forum has), unprefixed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create(Tables::name('private_message_folders'), function (Blueprint $table): void {
            $table->id();
            UserKey::column($table, 'user_id');
            $table->string('name', 80);
            $table->string('slug', 80);
            $table->boolean('is_system')->default(false)->index();
            $table->timestamps();

            $table->unique(['user_id', 'slug']);
            $table->index(['user_id', 'name']);

            $table->foreign('user_id')->references('id')->on(UserModel::table())->cascadeOnDelete();
        });

        Schema::create(Tables::name('private_conversations'), function (Blueprint $table): void {
            $table->id();
            UserKey::column($table, 'created_by_id');
            $table->string('subject');
            $table->timestamps();

            $table->foreign('created_by_id')->references('id')->on(UserModel::table())->cascadeOnDelete();
        });

        Schema::create(Tables::name('private_conversation_participants'), function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('conversation_id');
            UserKey::column($table, 'user_id');
            $table->unsignedBigInteger('folder_id');
            $table->timestamp('last_read_at')->nullable();
            $table->boolean('is_read')->default(false)->index();
            $table->timestamp('deleted_at')->nullable()->index();
            $table->timestamp('trashed_at')->nullable()->index();
            $table->unsignedBigInteger('trashed_from_folder_id')->nullable();
            $table->timestamps();

            $table->unique(['conversation_id', 'user_id'], 'private_conversation_participants_conversation_id_user_id_unique');
            $table->index(['user_id', 'folder_id'], 'private_conversation_participants_user_id_folder_id_index');
            $table->index(['conversation_id', 'user_id', 'deleted_at'], 'pcp_conversation_user_deleted_idx');

            $table->foreign('conversation_id', 'private_conversation_participants_conversation_id_foreign')->references('id')->on(Tables::name('private_conversations'))->cascadeOnDelete();
            $table->foreign('user_id')->references('id')->on(UserModel::table())->cascadeOnDelete();
            $table->foreign('folder_id')->references('id')->on(Tables::name('private_message_folders'))->cascadeOnDelete();
            $table->foreign('trashed_from_folder_id', 'private_conversation_participants_trashed_from_folder_id_foreign')->references('id')->on(Tables::name('private_message_folders'))->nullOnDelete();
        });

        Schema::create(Tables::name('private_messages'), function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('conversation_id');
            UserKey::column($table, 'sender_id');
            $table->text('body');
            $table->string('content_format', 20)->default('markdown')->index();
            $table->timestamps();

            $table->index(['conversation_id', 'created_at']);

            $table->foreign('conversation_id')->references('id')->on(Tables::name('private_conversations'))->cascadeOnDelete();
            $table->foreign('sender_id')->references('id')->on(UserModel::table())->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(Tables::name('private_messages'));
        Schema::dropIfExists(Tables::name('private_conversation_participants'));
        Schema::dropIfExists(Tables::name('private_conversations'));
        Schema::dropIfExists(Tables::name('private_message_folders'));
    }
};
