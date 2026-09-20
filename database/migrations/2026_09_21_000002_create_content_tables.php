<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pages', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('title');
            $table->string('slug')->unique();
            $table->longText('content')->nullable();
            $table->string('status')->default('draft');
            $table->string('seo_title')->nullable();
            $table->text('seo_description')->nullable();
            $table->string('featured_image')->nullable();
            $table->json('featured_images')->nullable();
            $table->ulid('author_id')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->json('tags')->nullable();
            $table->boolean('is_system_page')->default(false);
            $table->timestamps();
            $table->softDeletes();
            $table->index('author_id');
            $table->index(['status', 'published_at']);
        });

        Schema::create('banners', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('title');
            $table->string('slug')->nullable()->unique();
            $table->json('slides')->nullable();
            $table->json('image_url')->nullable();
            $table->string('alt_text')->nullable();
            $table->string('link_url')->nullable();
            $table->text('description')->nullable();
            $table->integer('order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamp('start_at')->nullable();
            $table->timestamp('end_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['is_active', 'order']);
        });

        Schema::create('menus', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('name');
            $table->string('slug')->unique();
            $table->json('items')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('redirects', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('from_path')->unique();   // CheckRedirects hits this every request
            $table->string('to_path');
            $table->integer('type')->default(301);
            $table->boolean('active')->default(true);
            $table->timestamps();
        });

        Schema::create('chat_sessions', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('user_id')->nullable();
            $table->string('title')->nullable();
            $table->json('messages')->nullable();
            $table->timestamp('last_active_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['user_id', 'last_active_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chat_sessions');
        Schema::dropIfExists('redirects');
        Schema::dropIfExists('menus');
        Schema::dropIfExists('banners');
        Schema::dropIfExists('pages');
    }
};
