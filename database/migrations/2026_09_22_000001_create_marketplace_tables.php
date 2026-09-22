<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Unysis Marketplace foundation schema.
 *
 * Follows the convention established by the 2026_09_21_* files: ULID string primary
 * keys, an index on every reference column, and no foreign key constraints (see
 * wiki/architecture/datastore.md). Status columns are plain strings with a default
 * rather than native enums so the same DDL runs on SQLite and PostgreSQL.
 */
return new class extends Migration
{
    public function up(): void
    {
        // A company that owns AI Boxes and has its own Customer Users. The Customer
        // label on a catalogue entry is a filter, never an access wall (docs/adr/0001).
        Schema::create('customers', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('name');
            $table->string('company')->nullable();
            $table->string('contact_name')->nullable();
            $table->string('contact_email')->nullable();
            $table->string('contact_phone')->nullable();
            $table->text('notes')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->index('name');
        });

        // Customer Users are ordinary users carrying a customer_id; Team Members leave it null.
        Schema::table('users', function (Blueprint $table) {
            $table->ulid('customer_id')->nullable()->after('id');
            $table->index('customer_id');
        });

        Schema::create('machine_brands', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('name')->unique();
            $table->string('slug');
            $table->timestamps();
            $table->index('slug');
        });

        Schema::create('machine_models', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('machine_brand_id');
            $table->string('name');
            $table->string('slug');
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->index('machine_brand_id');
            $table->index('slug');
            $table->unique(['machine_brand_id', 'name']);
        });

        Schema::create('flowchart_scripts', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('machine_model_id');
            $table->ulid('customer_id')->nullable();
            $table->string('name');
            $table->string('slug');
            $table->text('description')->nullable();
            $table->ulid('created_by')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->index('machine_model_id');
            $table->index('customer_id');
            $table->index('created_by');
            $table->index('slug');
        });

        // Preview Images live in the Vault and are served on the public /media route;
        // only the link and the ordering belong here (docs/adr/0003).
        Schema::create('flowchart_script_images', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('flowchart_script_id');
            $table->ulid('vault_file_id');
            $table->integer('sort_order')->default(0);
            $table->timestamps();
            $table->index('flowchart_script_id');
            $table->index('vault_file_id');
        });

        Schema::create('ai_models', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('machine_model_id');
            $table->ulid('customer_id')->nullable();
            $table->string('name');
            $table->string('slug');
            $table->text('description')->nullable();
            $table->string('framework')->nullable();
            $table->string('input_size')->nullable();
            $table->text('labels')->nullable();
            $table->text('notes')->nullable();
            $table->ulid('created_by')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->index('machine_model_id');
            $table->index('customer_id');
            $table->index('created_by');
            $table->index('slug');
        });

        // Polymorphic: AI Models and FlowChart Scripts share one Revision implementation
        // while staying separate entities in the UI and the API.
        Schema::create('revisions', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('revisable_type');
            $table->ulid('revisable_id');
            $table->integer('number');
            $table->string('status')->default('draft');   // draft, released, deprecated
            $table->text('change_note')->nullable();
            $table->string('original_filename')->nullable();
            $table->string('disk_path')->nullable();
            $table->bigInteger('size_bytes')->default(0);
            $table->string('sha256')->nullable();
            $table->string('mime')->nullable();
            $table->ulid('uploaded_by')->nullable();
            $table->ulid('released_by')->nullable();
            $table->timestamp('released_at')->nullable();
            $table->timestamp('deprecated_at')->nullable();
            $table->timestamps();
            $table->index(['revisable_type', 'revisable_id']);
            $table->index('uploaded_by');
            $table->index('released_by');
            $table->index('status');
            $table->unique(['revisable_type', 'revisable_id', 'number']);
        });

        // Auto-registered on first login from RPA-TOOL, keyed by the motherboard UUID
        // the box reports (docs/adr/0002).
        Schema::create('ai_boxes', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('customer_id');
            $table->string('motherboard_uuid')->unique();
            $table->string('name')->nullable();
            $table->string('location')->nullable();
            $table->ulid('machine_model_id')->nullable();
            $table->string('status')->default('pending');   // pending, active, blocked
            $table->timestamp('last_seen_at')->nullable();
            $table->string('last_ip')->nullable();
            $table->ulid('first_user_id')->nullable();
            $table->timestamps();
            $table->index('customer_id');
            $table->index('machine_model_id');
            $table->index('first_user_id');
            $table->index('status');
        });

        // One recorded fetch of a Revision file. revisable_* is denormalised off the
        // revision so the log can be filtered by catalogue entry without a join.
        Schema::create('downloads', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('revision_id');
            $table->string('revisable_type');
            $table->ulid('revisable_id');
            $table->ulid('user_id')->nullable();
            $table->ulid('ai_box_id')->nullable();
            $table->string('source')->default('api');   // api, web
            $table->string('ip')->nullable();
            $table->text('user_agent')->nullable();
            $table->timestamps();
            $table->index('revision_id');
            $table->index(['revisable_type', 'revisable_id']);
            $table->index('user_id');
            $table->index('ai_box_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('downloads');
        Schema::dropIfExists('ai_boxes');
        Schema::dropIfExists('revisions');
        Schema::dropIfExists('ai_models');
        Schema::dropIfExists('flowchart_script_images');
        Schema::dropIfExists('flowchart_scripts');
        Schema::dropIfExists('machine_models');
        Schema::dropIfExists('machine_brands');

        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['customer_id']);
            $table->dropColumn('customer_id');
        });

        Schema::dropIfExists('customers');
    }
};
