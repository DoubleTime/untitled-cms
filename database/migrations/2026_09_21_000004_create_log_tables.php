<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('activity_logs', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('user_id')->nullable();
            $table->string('action');
            $table->text('description')->nullable();
            $table->string('subject_type')->nullable();
            $table->ulid('subject_id')->nullable();
            $table->string('ip_address')->nullable();
            $table->text('user_agent')->nullable();
            $table->json('before_state')->nullable();
            $table->boolean('is_ai_action')->default(false);
            $table->timestamps();
            $table->index(['subject_type', 'subject_id']);
            $table->index('user_id');
            $table->index('created_at');
        });

        Schema::create('vault_audit_logs', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('user_id')->nullable();
            $table->string('event');
            $table->string('resource_type')->nullable();
            $table->ulid('resource_id')->nullable();
            $table->json('old_values')->nullable();
            $table->json('new_values')->nullable();
            $table->string('ip_address')->nullable();
            $table->text('user_agent')->nullable();
            $table->timestamps();
            $table->index(['resource_type', 'resource_id']);
            $table->index('user_id');
        });

        Schema::create('email_logs', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('provider_message_id')->nullable();
            $table->string('recipient');
            $table->string('subject')->nullable();
            $table->string('mailable')->nullable();
            $table->string('context_type')->nullable();
            $table->ulid('context_id')->nullable();
            $table->string('status')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('opened_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('bounced_at')->nullable();
            $table->timestamp('clicked_at')->nullable();
            $table->timestamp('complained_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->index('provider_message_id');     // webhook lookups
            $table->index('recipient');
            $table->index(['status', 'created_at']);  // dashboard aggregation
            $table->index(['context_type', 'context_id']);
        });

        Schema::create('suppressed_emails', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('email')->unique();   // was create_suppressed_emails_index
            $table->string('reason');
            $table->json('metadata')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('suppressed_emails');
        Schema::dropIfExists('email_logs');
        Schema::dropIfExists('vault_audit_logs');
        Schema::dropIfExists('activity_logs');
    }
};
