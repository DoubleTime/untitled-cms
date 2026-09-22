<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sanctum's own migration is not auto-loaded (it is publish-only in Sanctum 4) and
 * its `morphs('tokenable')` would create a bigint key, which cannot hold this app's
 * ULID user ids. This is the same table with a string tokenable_id instead.
 *
 * Customer Users authenticate from RPA-TOOL with these tokens (docs/adr/0002);
 * the Marketplace admin can revoke them per Customer User.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('personal_access_tokens', function (Blueprint $table) {
            $table->id();
            $table->string('tokenable_type');
            $table->string('tokenable_id');
            $table->text('name');
            $table->string('token', 64)->unique();
            $table->text('abilities')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('expires_at')->nullable()->index();
            $table->timestamps();

            $table->index(['tokenable_type', 'tokenable_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('personal_access_tokens');
    }
};
