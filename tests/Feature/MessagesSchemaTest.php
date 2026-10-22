<?php

declare(strict_types=1);

use Filagora\Core\Schema\Adopter;
use Filagora\Core\Schema\AdoptionStatus;
use Filagora\Core\Schema\SchemaRegistry;
use Filagora\Core\Schema\TableShape;
use Filagora\Core\Support\Tables;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * The tables must match the site they were extracted from, so an existing site can adopt them as
 * they are. The shipped snapshot is that shape (checked against the real table definitions, which
 * differ only by two import-id columns a profile package declares).
 */

const MESSAGE_TABLES = ['private_message_folders', 'private_conversations', 'private_conversation_participants', 'private_messages', 'ignored_users'];
const MESSAGE_MIGRATION = '2026_10_15_000000_create_filagora_message_tables';
const IGNORED_MIGRATION = '2026_10_22_000000_create_filagora_ignored_users_table';

function messageMigration(): object
{
    return require __DIR__.'/../../database/migrations/'.MESSAGE_MIGRATION.'.php';
}

function ignoredMigration(): object
{
    return require __DIR__.'/../../database/migrations/'.IGNORED_MIGRATION.'.php';
}

/**
 * The tables as an existing site has them: no prefix, migration not recorded. Two foreign key
 * names do not carry the prefix, so the prefixed set has to go first; afterEach() puts it back.
 */
function existingMessageTables(): void
{
    messageMigration()->down();
    ignoredMigration()->down();
    config(['filagora.table_prefix' => '']);
    messageMigration()->down();
    ignoredMigration()->down();
    messageMigration()->up();
    ignoredMigration()->up();
    DB::table('migrations')->whereIn('migration', [MESSAGE_MIGRATION, IGNORED_MIGRATION])->delete();
}

afterEach(function () {
    foreach (array_reverse(MESSAGE_TABLES) as $table) {
        Schema::dropIfExists($table);
    }

    config(['filagora.table_prefix' => 'filagora_']);

    if (! Schema::hasTable(Tables::name('private_messages'))) {
        messageMigration()->up();
    }

    if (! Schema::hasTable(Tables::name('ignored_users'))) {
        ignoredMigration()->up();
    }
});

it('ships a snapshot of the five tables that matches the migration, column order included', function () {
    $snapshot = app(SchemaRegistry::class)->get('messages');

    expect(array_keys($snapshot->tables))->toBe(MESSAGE_TABLES)
        ->and($snapshot->migrationNames())->toBe([MESSAGE_MIGRATION, IGNORED_MIGRATION]);

    foreach ($snapshot->tables as $base => $expected) {
        expect(TableShape::inspect($base)->differencesFrom($expected, compareColumnOrder: true))->toBe([], $base);
    }
});

it('keeps the names the live site has, even where the prefix would make them too long', function () {
    $participants = TableShape::inspect('private_conversation_participants');

    expect($participants->indexes['private_conversation_participants_conversation_id_user_id_unique']['unique'])->toBeTrue()
        ->and($participants->indexes['private_conversation_participants_user_id_folder_id_index']['columns'])->toBe(['user_id', 'folder_id'])
        ->and($participants->indexes['pcp_conversation_user_deleted_idx']['columns'])->toBe(['conversation_id', 'user_id', 'deleted_at'])
        ->and(array_keys($participants->foreignKeys))->toContain('private_conversation_participants_conversation_id_foreign', 'private_conversation_participants_trashed_from_folder_id_foreign');
});

it('keeps the uniqueness and defaults the site relies on', function () {
    $folders = TableShape::inspect('private_message_folders');
    $participants = TableShape::inspect('private_conversation_participants');
    $messages = TableShape::inspect('private_messages');

    expect($folders->indexes['private_message_folders_user_id_slug_unique']['unique'])->toBeTrue()
        ->and($folders->columns['is_system']['default'])->toBe('0')
        ->and($participants->columns['is_read']['default'])->toBe('0')
        ->and($participants->columns['trashed_from_folder_id']['nullable'])->toBeTrue()
        ->and($messages->columns['content_format']['default'])->toBe('markdown')
        ->and($messages->indexes['private_messages_conversation_id_created_at_index']['columns'])->toBe(['conversation_id', 'created_at']);
});

it('adopts the message tables of an existing site and records the migration', function () {
    existingMessageTables();

    $plan = app(Adopter::class)->planFor(app(SchemaRegistry::class)->get('messages'));

    expect($plan->status)->toBe(AdoptionStatus::Adoptable, implode('; ', $plan->differences))
        ->and($plan->pendingMigrations)->toBe([MESSAGE_MIGRATION, IGNORED_MIGRATION]);

    $this->artisan('filagora:adopt', ['--package' => 'messages'])->expectsOutputToContain('Recorded 2 migration(s) as run.')->assertExitCode(0);

    expect(DB::table('migrations')->where('migration', MESSAGE_MIGRATION)->exists())->toBeTrue()
        ->and(DB::table('migrations')->where('migration', IGNORED_MIGRATION)->exists())->toBeTrue();
});

it('refuses a site that lost an index or has only some of the tables', function () {
    existingMessageTables();
    Schema::table('private_conversation_participants', fn ($table) => $table->dropIndex('pcp_conversation_user_deleted_idx'));

    $plan = app(Adopter::class)->planFor(app(SchemaRegistry::class)->get('messages'));
    expect($plan->status)->toBe(AdoptionStatus::Mismatch)
        ->and($plan->differences)->toContain('private_conversation_participants: index pcp_conversation_user_deleted_idx is missing');

    Schema::drop('private_messages');
    expect(app(Adopter::class)->planFor(app(SchemaRegistry::class)->get('messages'))->status)->toBe(AdoptionStatus::Partial);
});

it('is already adopted on a site that ran the package migration', function () {
    expect(app(Adopter::class)->planFor(app(SchemaRegistry::class)->get('messages'))->status)->toBe(AdoptionStatus::AlreadyAdopted)
        ->and(Tables::name('private_messages'))->toBe('filagora_private_messages');
});

it('keeps the ignore list one row per pair, with the key names the live site has', function () {
    $shape = TableShape::inspect('ignored_users');

    expect(array_keys($shape->columns))->toBe(['id', 'user_id', 'ignored_user_id', 'created_at', 'updated_at'])
        ->and($shape->indexes['ignored_users_user_id_ignored_user_id_unique'])->toMatchArray(['columns' => ['user_id', 'ignored_user_id'], 'unique' => true])
        ->and($shape->foreignKeys['ignored_users_user_id_foreign']['on_delete'])->toBe('cascade')
        ->and($shape->foreignKeys['ignored_users_ignored_user_id_foreign']['on_delete'])->toBe('cascade');
});
