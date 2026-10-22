<?php

declare(strict_types=1);

use Filagora\Core\Support\Tables;
use Filagora\Core\Support\UserKey;
use Filagora\Core\Support\UserModel;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Who has put whom on their ignore list, in the shape of the forum the package was extracted from
 * (the key and foreign key names are written out, as that forum has them, so its table can be
 * adopted as it is). One row per pair; a person cannot ignore themselves, which the model checks.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create(Tables::name('ignored_users'), function (Blueprint $table): void {
            $table->id();
            UserKey::column($table, 'user_id');
            UserKey::column($table, 'ignored_user_id');
            $table->timestamps();

            $table->unique(['user_id', 'ignored_user_id'], 'ignored_users_user_id_ignored_user_id_unique');

            $table->foreign('user_id', 'ignored_users_user_id_foreign')->references('id')->on(UserModel::table())->cascadeOnDelete();
            $table->foreign('ignored_user_id', 'ignored_users_ignored_user_id_foreign')->references('id')->on(UserModel::table())->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(Tables::name('ignored_users'));
    }
};
