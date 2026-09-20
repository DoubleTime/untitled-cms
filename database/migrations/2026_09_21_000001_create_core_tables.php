<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('name');
            $table->string('email')->unique();
            $table->timestamp('email_verified_at')->nullable();
            $table->string('password')->nullable();
            $table->boolean('is_active')->default(true);
            $table->boolean('bounce_hard')->default(false);
            $table->integer('session_version')->default(0);
            $table->json('social_accounts')->nullable();
            $table->rememberToken();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('roles', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('description')->nullable();
            $table->json('permissions')->nullable();
            $table->boolean('is_active')->default(true);
            $table->boolean('backend_access')->default(false);
            $table->timestamps();
        });

        // MongoDB stored belongsToMany as ID arrays on both documents.
        // SQL needs a real pivot; the relationship code is unchanged.
        Schema::create('role_user', function (Blueprint $table) {
            $table->ulid('user_id');
            $table->ulid('role_id');
            $table->unique(['user_id', 'role_id']);
            $table->index('role_id');
        });

        Schema::create('settings', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('key')->unique();
            $table->text('value')->nullable();
            $table->string('group')->nullable();
            $table->string('type')->nullable();
            $table->string('label')->nullable();
            // Help text shown alongside the setting in the admin UI. Absent from
            // Setting::$fillable, so seeders reach it only via Model::unguarded().
            $table->text('description')->nullable();
            $table->boolean('is_public')->default(false);
            $table->timestamps();
            $table->index('group');
        });

        Schema::create('ai_hubs', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('name');
            $table->string('default_model')->nullable();
            $table->string('image_model')->nullable();
            $table->text('api_key')->nullable();   // encrypted cast: ciphertext exceeds plaintext
            $table->boolean('is_active')->default(false);
            $table->integer('monthly_quota')->nullable();
            $table->integer('monthly_usage')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_hubs');
        Schema::dropIfExists('settings');
        Schema::dropIfExists('role_user');
        Schema::dropIfExists('roles');
        Schema::dropIfExists('users');
    }
};
