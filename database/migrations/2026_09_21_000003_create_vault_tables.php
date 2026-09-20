<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vault_folders', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->uuid('uuid')->unique();
            $table->ulid('parent_id')->nullable();
            $table->string('name');
            $table->string('path_slug')->nullable();
            $table->ulid('owner_id')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->index('owner_id');
        });

        // Partial unique index instead of a plain unique constraint on (parent_id, path_slug):
        //   - excludes soft-deleted rows, so a trashed folder never blocks reuse of its name
        //   - uses COALESCE(parent_id, '') so root-level folders (parent_id IS NULL) are also
        //     enforced uniquely — PostgreSQL treats NULLs as distinct in a plain unique index,
        //     which would otherwise let unlimited root folders share a name.
        // Works identically on PostgreSQL and SQLite (both support expression + partial indexes).
        DB::statement(
            'CREATE UNIQUE INDEX vault_folders_parent_path_unique ON vault_folders (COALESCE(parent_id, \'\'), path_slug) WHERE deleted_at IS NULL'
        );

        Schema::create('vault_files', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->uuid('uuid')->unique();
            $table->ulid('folder_id')->nullable();
            $table->string('storage_path');
            $table->string('original_name');
            $table->string('mime_type')->nullable();
            $table->string('extension')->nullable();
            $table->bigInteger('size_bytes')->default(0);
            $table->string('hash_sha256')->nullable();
            $table->ulid('uploaded_by')->nullable();
            $table->boolean('is_public')->default(false);
            $table->string('validation_status')->nullable();
            $table->integer('width')->nullable();
            $table->integer('height')->nullable();
            $table->string('alt_text')->nullable();
            $table->string('optimized_path')->nullable();
            $table->bigInteger('optimized_size')->nullable();
            $table->boolean('is_optimized')->default(false);
            $table->boolean('use_original')->default(false);
            $table->text('moderation_reason')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->index('folder_id');
            $table->index('uploaded_by');
            $table->index('hash_sha256');
            $table->index(['original_name', 'mime_type']);   // was add_search_indexes_to_vault_files
        });

        Schema::create('vault_folder_permissions', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('folder_id');
            $table->ulid('user_id')->nullable();
            $table->ulid('role_id')->nullable();
            $table->string('permission');   // read, write, delete
            $table->timestamps();
            $table->index(['folder_id', 'user_id']);
            $table->index(['folder_id', 'role_id']);
        });
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS vault_folders_parent_path_unique');
        Schema::dropIfExists('vault_folder_permissions');
        Schema::dropIfExists('vault_files');
        Schema::dropIfExists('vault_folders');
    }
};
