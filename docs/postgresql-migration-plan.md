# PostgreSQL Migration Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace MongoDB with PostgreSQL as the sole datastore, using standard Eloquent throughout.

**Architecture:** All 16 models become plain Eloquent models with ULID string primary keys. The relational schema is authored from scratch (none exists — the current migrations are vestigial stubs). The three raw MongoDB aggregation pipelines become query-builder code. `mongodb/laravel-mongodb` is removed.

**Tech Stack:** Laravel 13, PHP 8.4, PostgreSQL 14+, SQLite (tests), PHPUnit 13, Pint.

**Spec:** `docs/postgresql-migration-design.md`

## Global Constraints

- **PHP binary is `/c/tools/php84/php84.exe`.** The `php` on PATH is 8.2.8 and fails `composer`'s platform check (`>= 8.4.1`). Every `artisan`, `composer`, and test command in this plan must use the full path.
- Primary keys are ULID strings. Never introduce an integer PK on an application table.
- No foreign-key constraints on reference columns. The current code does not expect cascade or restrict behaviour.
- No PostgreSQL-only SQL. Tests run on SQLite, so `FILTER (WHERE …)`, `DISTINCT ON`, and similar are banned.
- `types/index.d.ts`'s `id: string` contract must hold.
- Run `./vendor/bin/pint` before every commit.

**Verification reality.** The test suite cannot run until Tasks 1–3 are all complete — models currently pin `mongodb`, and `phpunit.xml`'s `DB_DATABASE=:memory:` is fed to the Mongo driver, which rejects it. All 97 tests error today. Tasks 1–5 therefore carry targeted verification (grep assertions, `migrate --pretend`, boot smoke tests) instead of suite runs. **Task 6 is the first real gate** and is where the suite must go green. Do not treat Tasks 1–5 as validated work until Task 6 passes.

---

### Task 1: Convert the model layer to plain Eloquent

**Files:**
- Create: `app/Models/Concerns/HasUlidKey.php`
- Modify (15): `app/Models/{ActivityLog,AiHub,Banner,ChatSession,EmailLog,Menu,Page,Redirect,Role,Setting,SuppressedEmail,VaultAuditLog,VaultFile,VaultFolder,VaultFolderPermission}.php`
- Modify: `app/Models/User.php`

**Interfaces:**
- Consumes: nothing.
- Produces: all models extend `Illuminate\Database\Eloquent\Model` (User: `Illuminate\Foundation\Auth\User`) and use `HasUlidKey`. `Role::$casts` includes `'permissions' => 'array'`; `Redirect::$casts` exists.

- [ ] **Step 1: Confirm `$collection` is inert before deleting it**

Run: `grep -rn "function getTable" vendor/mongodb/laravel-mongodb/src/Eloquent/`
Expected: no match. This confirms v5 ignores `$collection`; table names already resolve by Laravel convention and every declared name matches it.

- [ ] **Step 2: Create the key trait**

`app/Models/Concerns/HasUlidKey.php`:

```php
<?php

namespace App\Models\Concerns;

use Illuminate\Database\Eloquent\Concerns\HasUlids;

/**
 * ULID string primary keys.
 *
 * Wraps HasUlids in one place so the key strategy can change without
 * touching 16 models. ULIDs are used because they are strings — keeping
 * the frontend's `id: string` contract intact after the move off
 * MongoDB ObjectIds — and because they sort lexically by creation time.
 */
trait HasUlidKey
{
    use HasUlids;
}
```

- [ ] **Step 3: Sweep the 15 non-User models**

For each, three edits. `Page` as the worked example:

```php
// BEFORE
use MongoDB\Laravel\Eloquent\Model;

class Page extends Model
{
    use HasFactory, SoftDeletes;

    protected $connection = 'mongodb';

    protected $collection = 'pages';

// AFTER
use App\Models\Concerns\HasUlidKey;
use Illuminate\Database\Eloquent\Model;

class Page extends Model
{
    use HasFactory, HasUlidKey, SoftDeletes;

```

Do not touch `$fillable`, `$casts`, relationships, or `SoftDeletes` beyond adding `HasUlidKey` to the `use` list. Keep trait lists alphabetical, matching existing style.

- [ ] **Step 4: Convert User**

```php
// BEFORE
use MongoDB\Laravel\Auth\User as Authenticatable;

class User extends Authenticatable implements MustVerifyEmail
{
    use HasFactory, HasRoles, Notifiable, SoftDeletes;

    protected $connection = 'mongodb';

    protected $collection = 'users';

// AFTER
use App\Models\Concerns\HasUlidKey;
use Illuminate\Foundation\Auth\User as Authenticatable;

class User extends Authenticatable implements MustVerifyEmail
{
    use HasFactory, HasRoles, HasUlidKey, Notifiable, SoftDeletes;

```

- [ ] **Step 5: Fix the two cast bugs**

`app/Models/Role.php` — `permissions` is assigned and read as an array (`syncPermissions(array)`, `in_array($this->permissions ?? [])`) but has no cast. This works only because MongoDB stores arrays natively; on PostgreSQL it round-trips as a string and the permission system fails silently:

```php
protected $casts = [
    'permissions' => 'array',
    'is_active' => 'boolean',
    'backend_access' => 'boolean',
];
```

`app/Models/Redirect.php` — add after `$fillable`:

```php
protected $casts = [
    'active' => 'boolean',
    'type' => 'integer',
];
```

- [ ] **Step 6: Verify no Mongo references remain in models**

Run:
```bash
grep -rn "connection = 'mongodb'\|protected \$collection\|MongoDB" app/Models/
```
Expected: no output.

Run:
```bash
grep -c "HasUlidKey" app/Models/*.php | grep -v ":0" | wc -l
```
Expected: `16`.

- [ ] **Step 7: Commit**

```bash
./vendor/bin/pint
git add app/Models
git commit -m "refactor: convert models to plain Eloquent with ULID keys

Drops the MongoDB base classes, the hardcoded \$connection, and the
inert \$collection property (laravel-mongodb v5 has no \$collection
support). Also adds two missing casts: Role::permissions is used as an
array but had none, and Redirect had no casts at all — both worked only
because MongoDB stores native types."
```

---

### Task 2: Author the relational schema

**Files:**
- Delete: all 17 files in `database/migrations/`
- Create: `database/migrations/2026_09_21_000001_create_core_tables.php`
- Create: `database/migrations/2026_09_21_000002_create_content_tables.php`
- Create: `database/migrations/2026_09_21_000003_create_vault_tables.php`
- Create: `database/migrations/2026_09_21_000004_create_log_tables.php`
- Create: `database/migrations/2026_09_21_000005_create_framework_tables.php`

**Interfaces:**
- Consumes: models from Task 1.
- Produces: tables `users`, `roles`, `role_user`, `settings`, `ai_hubs`, `pages`, `banners`, `menus`, `redirects`, `chat_sessions`, `vault_files`, `vault_folders`, `vault_folder_permissions`, `activity_logs`, `vault_audit_logs`, `email_logs`, `suppressed_emails`, plus framework tables.

Columns are derived from each model's `$fillable` and `$casts`, since no schema exists to copy. Grouped into five files by responsibility — these tables are always created together, and 17 single-table files would be noise.

- [ ] **Step 1: Delete the vestigial migrations**

```bash
git rm database/migrations/*.php
```

These define no usable schema — `create_roles_table` creates only `id` and `timestamps()` — and the five Mongo index migrations describe MongoDB indexes that have no meaning on PostgreSQL. Their index intent is preserved in the new schema below.

- [ ] **Step 2: Write the core tables**

`database/migrations/2026_09_21_000001_create_core_tables.php`:

```php
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
```

- [ ] **Step 3: Write the content tables**

`database/migrations/2026_09_21_000002_create_content_tables.php`:

```php
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
            $table->string('slug')->unique();
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
```

- [ ] **Step 4: Write the vault tables**

`database/migrations/2026_09_21_000003_create_vault_tables.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
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
            $table->string('path_slug');
            $table->ulid('owner_id')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['parent_id', 'path_slug']);   // was add_unique_index_to_vault_folders
            $table->index('owner_id');
        });

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
        Schema::dropIfExists('vault_folder_permissions');
        Schema::dropIfExists('vault_files');
        Schema::dropIfExists('vault_folders');
    }
};
```

- [ ] **Step 5: Write the log tables**

`database/migrations/2026_09_21_000004_create_log_tables.php`:

```php
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
```

- [ ] **Step 6: Write the framework tables**

`database/migrations/2026_09_21_000005_create_framework_tables.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('password_reset_tokens', function (Blueprint $table) {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('sessions', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->ulid('user_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });

        Schema::create('cache', function (Blueprint $table) {
            $table->string('key')->primary();
            $table->mediumText('value');
            $table->integer('expiration');
        });

        Schema::create('cache_locks', function (Blueprint $table) {
            $table->string('key')->primary();
            $table->string('owner');
            $table->integer('expiration');
        });

        Schema::create('jobs', function (Blueprint $table) {
            $table->id();
            $table->string('queue')->index();
            $table->longText('payload');
            $table->unsignedTinyInteger('attempts');
            $table->unsignedInteger('reserved_at')->nullable();
            $table->unsignedInteger('available_at');
            $table->unsignedInteger('created_at');
        });

        Schema::create('job_batches', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->string('name');
            $table->integer('total_jobs');
            $table->integer('pending_jobs');
            $table->integer('failed_jobs');
            $table->longText('failed_job_ids');
            $table->mediumText('options')->nullable();
            $table->integer('cancelled_at')->nullable();
            $table->integer('created_at');
            $table->integer('finished_at')->nullable();
        });

        Schema::create('failed_jobs', function (Blueprint $table) {
            $table->id();
            $table->string('uuid')->unique();
            $table->text('connection');
            $table->text('queue');
            $table->longText('payload');
            $table->longText('exception');
            $table->timestamp('failed_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('failed_jobs');
        Schema::dropIfExists('job_batches');
        Schema::dropIfExists('jobs');
        Schema::dropIfExists('cache_locks');
        Schema::dropIfExists('cache');
        Schema::dropIfExists('sessions');
        Schema::dropIfExists('password_reset_tokens');
    }
};
```

`jobs` and `failed_jobs` keep auto-increment IDs deliberately — framework-owned, never exposed through the `id: string` frontend contract.

- [ ] **Step 7: Verify the schema builds**

Run:
```bash
DB_CONNECTION=sqlite DB_DATABASE=:memory: /c/tools/php84/php84.exe artisan migrate --pretend
```
Expected: CREATE TABLE statements for all 17 application tables plus framework tables, no errors.

This will still fail if Task 3 has not run (the Mongo service provider is registered). If it errors on MongoDB, note it and proceed — Task 3 Step 5 re-runs this check.

- [ ] **Step 8: Commit**

```bash
./vendor/bin/pint
git add -A database/migrations
git commit -m "feat: author the relational schema

17 application tables derived from model \$fillable and \$casts, plus the
role_user pivot MongoDB did not need and the framework tables. Replaces
the vestigial stubs, which created only id + timestamps. No foreign-key
constraints — the application never relied on referential integrity."
```

---

### Task 3: Remove the MongoDB dependency

**Files:**
- Modify: `composer.json`
- Modify: `bootstrap/providers.php`
- Modify: `config/database.php`
- Modify: `.env`, `.env.example`

**Interfaces:**
- Consumes: Tasks 1 and 2.
- Produces: no MongoDB anywhere; `database.default` is `pgsql`.

- [ ] **Step 1: Confirm nothing outside models still imports MongoDB**

Run: `grep -rn "MongoDB" app/ config/ bootstrap/ database/ tests/`

Expected: matches only in `app/Http/Controllers/{Dashboard,EmailLog,VaultFolder}Controller.php` (the `UTCDateTime` import and `::raw()` pipelines, handled in Task 5). If anything else appears, report it before proceeding — it is not covered by this plan.

- [ ] **Step 2: Remove the provider**

`bootstrap/providers.php`:

```php
<?php

use App\Providers\AppServiceProvider;

return [
    AppServiceProvider::class,
];
```

- [ ] **Step 3: Remove the connection block**

In `config/database.php`, delete the entire `'mongodb' => [ ... ]` entry from `connections` (lines 35-46), and change the default:

```php
'default' => env('DB_CONNECTION', 'pgsql'),
```

- [ ] **Step 4: Point the environment at PostgreSQL**

In `.env`, replace the DB block:

```
DB_CONNECTION=pgsql
DB_HOST=127.0.0.1
DB_PORT=5432
DB_DATABASE=unysis_marketplace
DB_USERNAME=postgres
DB_PASSWORD=
```

Note the database name uses underscores — `unysis-marketplace` with a hyphen is legal in PostgreSQL only when quoted, and Laravel does not quote it.

Mirror the same block in `.env.example`, with an empty password.

- [ ] **Step 5: Remove the composer dependency**

```bash
/c/tools/php84/php84.exe /path/to/composer.phar remove mongodb/laravel-mongodb
```

If `composer` is available directly, use it with the php84 binary:
```bash
/c/tools/php84/php84.exe $(which composer) remove mongodb/laravel-mongodb
```

Then verify: `grep -n "mongodb" composer.json`
Expected: no output.

- [ ] **Step 6: Verify the application boots**

Run: `/c/tools/php84/php84.exe artisan about`
Expected: boots without error; the Database section reports `pgsql`.

Run: `DB_CONNECTION=sqlite DB_DATABASE=:memory: /c/tools/php84/php84.exe artisan migrate --pretend`
Expected: clean output, no MongoDB errors. This is the Task 2 Step 7 check, now unblocked.

- [ ] **Step 7: Commit**

```bash
./vendor/bin/pint
git add composer.json composer.lock bootstrap/providers.php config/database.php .env.example
git commit -m "chore: remove the MongoDB dependency

Drops mongodb/laravel-mongodb, its service provider, and the mongodb
connection block. Default connection is now pgsql."
```

`.env` is git-ignored and is not committed — change it locally only.

---

### Task 4: Normalize `_id` call sites

**Files:**
- Modify: `app/Http/Controllers/UserController.php:98,116,134`
- Modify: `app/Http/Controllers/VaultFolderController.php:39,56,110`
- Modify: `app/Models/Role.php:35`
- Modify: `resources/js/types/index.d.ts:8`

**Interfaces:**
- Consumes: Tasks 1-3.
- Produces: no `_id` literal anywhere in `app/`.

- [ ] **Step 1: Replace the UserController usages**

```php
// line ~98
$count = User::whereKey($request->user_ids)->update(['is_active' => true]);

// line ~116
$count = User::whereKey($ids)->update(['is_active' => false]);

// line ~134
$count = User::whereKey($ids)->delete();
```

- [ ] **Step 2: Replace the VaultFolderController usages**

```php
// line ~39
$folderIds = $folders->pluck('id')->map(fn ($id) => (string) $id)->toArray();

// line ~56
$stat = $filesStats->get((string) $folder->getKey());

// line ~110
->whereKeyNot($folder->getKey())
```

- [ ] **Step 3: Replace the Role usage**

`app/Models/Role.php` line ~35:

```php
foreach ($role->users()->pluck('users.id') as $userId) {
```

The table prefix is required — this is a `belongsToMany` through `role_user`, and an unqualified `id` is ambiguous in the join.

- [ ] **Step 4: Update the frontend type comment**

`resources/js/types/index.d.ts:8`:

```ts
id: string; // ULID (26-char, lexically sortable)
```

- [ ] **Step 5: Verify**

Run: `grep -rn "'_id'\|\"_id\"\|->_id" app/ resources/js/`
Expected: no output.

- [ ] **Step 6: Commit**

```bash
./vendor/bin/pint
git add app resources/js/types/index.d.ts
git commit -m "refactor: address records by primary key instead of _id"
```

---

### Task 5: Rewrite the aggregation pipelines

**Files:**
- Modify: `app/Http/Controllers/DashboardController.php:15-45`
- Modify: `app/Http/Controllers/EmailLogController.php:32-60`
- Modify: `app/Http/Controllers/VaultFolderController.php:39-58`
- Create: `app/Support/DateBucket.php`

**Interfaces:**
- Consumes: Tasks 1-4.
- Produces: `DateBucket::expression(string $column): string`. No `::raw(function` anywhere.

- [ ] **Step 1: Create the date-bucket helper**

Production runs PostgreSQL and tests run SQLite, and the two spell date truncation differently. One helper keeps that difference in a single place.

`app/Support/DateBucket.php`:

```php
<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

class DateBucket
{
    /**
     * A SQL expression bucketing a timestamp column to 'YYYY-MM-DD'.
     */
    public static function expression(string $column): string
    {
        return DB::connection()->getDriverName() === 'sqlite'
            ? "strftime('%Y-%m-%d', {$column})"
            : "to_char({$column}, 'YYYY-MM-DD')";
    }
}
```

`$column` is never user input — every call site passes a literal column name.

- [ ] **Step 2: Rewrite the dashboard aggregation**

In `DashboardController::index()`, replace the entire `EmailLog::raw(...)` block and the `$stats` / `$statsMap` lines with:

```php
$bucket = DateBucket::expression('created_at');

$statsMap = EmailLog::query()
    ->where('created_at', '>=', $startDate)
    ->groupBy(DB::raw($bucket))
    ->orderBy(DB::raw($bucket))
    ->get([
        DB::raw("{$bucket} as day"),
        DB::raw('count(*) as sent'),
        // count() ignores NULLs, reproducing Mongo's $cond on $ne: null
        DB::raw('count(delivered_at) as delivered'),
    ])
    ->keyBy('day');
```

The `collect(range(6, 0))->map(...)` block below is unchanged — it already reads `$dayData['sent']` and `$dayData['delivered']`.

Remove `use MongoDB\BSON\UTCDateTime;`. Add `use App\Support\DateBucket;` and `use Illuminate\Support\Facades\DB;`.

- [ ] **Step 3: Rewrite the email log stats**

In `EmailLogController`, replace the cached `EmailLog::raw(...)` block body with:

```php
$stats = Cache::remember('email_log_stats', 300, function () {
    // sum(case when ...) rather than Postgres FILTER, so the same SQL
    // runs on SQLite under test.
    $row = EmailLog::query()->get([
        DB::raw('count(*) as total'),
        DB::raw("sum(case when status = 'delivered' then 1 else 0 end) as delivered"),
        DB::raw('sum(case when opened_at is not null then 1 else 0 end) as opened'),
        DB::raw("sum(case when status = 'bounced' then 1 else 0 end) as bounced"),
    ])->first();

    return [
        'total' => (int) ($row->total ?? 0),
        'delivered' => (int) ($row->delivered ?? 0),
        'opened' => (int) ($row->opened ?? 0),
        'bounced' => (int) ($row->bounced ?? 0),
    ];
});
```

Check the lines immediately after the original block: it previously unpacked `$row['total'][0]['count']` into local variables. Keep whatever shape the surrounding code passes to the view, adapting from the array above.

Add `use Illuminate\Support\Facades\DB;`.

- [ ] **Step 4: Rewrite the vault folder stats**

In `VaultFolderController`, replace the `VaultFile::raw(...)` block with:

```php
$filesStats = VaultFile::query()
    ->whereIn('folder_id', $folderIds)
    ->groupBy('folder_id')
    ->get([
        'folder_id',
        DB::raw('count(*) as files_count'),
        DB::raw('coalesce(sum(size_bytes), 0) as files_size'),
    ])
    ->keyBy('folder_id');
```

`VaultFile` uses `SoftDeletes`, so the global scope already applies `deleted_at is null`, matching the old pipeline's explicit match. Do not add it manually.

The `$folders->transform(...)` block below reads `$stat['files_count']` and `$stat['files_size']`; with Eloquent results these are now object properties. Change to `$stat->files_count` and `$stat->files_size`, keeping the `(int)` casts.

Add `use Illuminate\Support\Facades\DB;`.

- [ ] **Step 5: Verify no pipelines remain**

Run: `grep -rn "::raw(function\|UTCDateTime\|aggregate(" app/`
Expected: no output.

- [ ] **Step 6: Commit**

```bash
./vendor/bin/pint
git add app
git commit -m "refactor: replace MongoDB aggregation pipelines with SQL

The three \$group/\$facet pipelines become query-builder expressions.
Date bucketing differs between PostgreSQL and SQLite, so it lives in one
helper rather than being spelled inline three times."
```

---

### Task 6: Get the test suite green

**This is the first real verification gate.** Tasks 1-5 are unvalidated until this passes.

**Files:**
- Modify: `tests/**/*.php` as needed
- Modify: `database/factories/*.php` as needed
- Create: `tests/Unit/SchemaTest.php`

**Interfaces:**
- Consumes: Tasks 1-5.
- Produces: `/c/tools/php84/php84.exe artisan test` fully green on SQLite.

- [ ] **Step 1: Add the schema test**

`tests/Unit/SchemaTest.php`:

```php
<?php

namespace Tests\Unit;

use App\Models\Page;
use App\Models\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class SchemaTest extends TestCase
{
    use RefreshDatabase;

    public static function tableProvider(): array
    {
        return array_map(fn ($t) => [$t], [
            'users', 'roles', 'role_user', 'settings', 'ai_hubs', 'pages',
            'banners', 'menus', 'redirects', 'chat_sessions', 'vault_files',
            'vault_folders', 'vault_folder_permissions', 'activity_logs',
            'vault_audit_logs', 'email_logs', 'suppressed_emails',
        ]);
    }

    #[DataProvider('tableProvider')]
    public function test_table_exists(string $table): void
    {
        $this->assertTrue(Schema::hasTable($table), "Missing table: {$table}");
    }

    public function test_primary_keys_are_ulids(): void
    {
        $page = Page::factory()->create();

        $this->assertIsString($page->getKey());
        $this->assertSame(26, strlen($page->getKey()));
        $this->assertFalse($page->incrementing);
    }

    public function test_role_permissions_round_trip_as_an_array(): void
    {
        $role = Role::factory()->create([
            'permissions' => ['pages.edit', 'media.upload'],
        ]);

        $fresh = Role::find($role->getKey());

        $this->assertIsArray($fresh->permissions);
        $this->assertTrue($fresh->hasPermission('pages.edit'));
        $this->assertFalse($fresh->hasPermission('pages.delete'));
    }
}
```

- [ ] **Step 2: Run the suite and record the baseline**

Run: `/c/tools/php84/php84.exe artisan test 2>&1 | tail -40`

Expected at this point: many failures. Before this task the suite could not run at all (97 errors, `Invalid database name: ':memory:'`), so any test that now executes is progress. Record the failure count.

- [ ] **Step 3: Remove the Mongo-era truncate calls**

Several tests call `User::truncate()`, `Role::truncate()`, `Page::truncate()` and similar in `setUp()` — a workaround for MongoDB's lack of transaction support. `RefreshDatabase` now gives each test a clean database, so these are redundant and can fail on a fresh schema.

Find them: `grep -rn "::truncate()" tests/`

Delete those lines, keeping the rest of each `setUp()` intact.

- [ ] **Step 4: Fix the factories**

Check every file in `database/factories/` for MongoDB-shaped data — hardcoded 24-character hex IDs, `new ObjectId(...)`, or `_id` keys.

Find them: `grep -rn "ObjectId\|_id\|[0-9a-f]\{24\}" database/factories/`

Replace generated IDs with `(string) Str::ulid()`, or better, let relationship factories assign them (`User::factory()` for an `author_id`).

- [ ] **Step 5: Iterate to green**

Run the suite repeatedly, fixing failures. Expected causes in likely order:

1. **Missing columns** — an attribute written outside `$fillable` that the schema audit missed. Add the column to the relevant Task 2 migration file and re-run. This is the known risk the design names explicitly.
2. **Ambiguous column names** in queries that join `role_user` — qualify with the table name.
3. **Type strictness** — SQLite and PostgreSQL reject values MongoDB accepted, typically NULLs into non-nullable columns. Fix the column nullability, not the test, unless the test is genuinely asserting the wrong thing.

Do not weaken assertions to make tests pass. If a test looks genuinely wrong, report it rather than editing it.

- [ ] **Step 6: Verify against real PostgreSQL**

Create a database and run the suite against it:

```bash
createdb unysis_marketplace_test 2>/dev/null || true
DB_CONNECTION=pgsql DB_DATABASE=unysis_marketplace_test /c/tools/php84/php84.exe artisan test
```

Expected: green. SQLite is permissive about types in ways PostgreSQL is not, so this catches what SQLite hides. If `createdb` is unavailable, use any PostgreSQL client to create the database first.

- [ ] **Step 7: Commit**

```bash
./vendor/bin/pint
git add tests database/factories database/migrations
git commit -m "test: bring the suite green on the relational schema

The suite could not run at all before this migration: models pinned the
mongodb connection while phpunit.xml supplied SQLite's :memory: database
name, which MongoDB rejects. Removes the truncate() calls that worked
around MongoDB's lack of transaction support."
```

---

### Task 7: CI

**Files:**
- Create or modify: `.github/workflows/tests.yml`

- [ ] **Step 1: Check for an existing workflow**

Run: `ls -la .github/workflows/ 2>/dev/null || echo "none"`

If one exists, extend it rather than creating a second.

- [ ] **Step 2: Write the workflow**

`.github/workflows/tests.yml`:

```yaml
name: tests

on:
  push:
    branches: [master]
  pull_request:

jobs:
  test:
    runs-on: ubuntu-latest

    services:
      postgres:
        image: postgres:16
        env:
          POSTGRES_PASSWORD: postgres
          POSTGRES_DB: unysis_marketplace_test
        ports:
          - 5432:5432
        options: >-
          --health-cmd pg_isready
          --health-interval 10s
          --health-timeout 5s
          --health-retries 5

    steps:
      - uses: actions/checkout@v4

      - uses: shivammathur/setup-php@v2
        with:
          php-version: '8.4'
          extensions: pdo_pgsql, pdo_sqlite, gd, zip
          coverage: none

      - run: composer install --prefer-dist --no-interaction --no-progress

      - run: cp .env.example .env && php artisan key:generate

      - name: Tests (SQLite)
        env:
          DB_CONNECTION: sqlite
          DB_DATABASE: ':memory:'
        run: php artisan test

      - name: Tests (PostgreSQL)
        env:
          DB_CONNECTION: pgsql
          DB_HOST: 127.0.0.1
          DB_PORT: 5432
          DB_DATABASE: unysis_marketplace_test
          DB_USERNAME: postgres
          DB_PASSWORD: postgres
        run: php artisan test
```

Both legs run because the suite's day-to-day home is SQLite while production is PostgreSQL; only the second leg catches dialect differences.

- [ ] **Step 3: Commit**

```bash
git add .github/workflows/tests.yml
git commit -m "ci: run the suite on SQLite and PostgreSQL"
```

---

### Task 8: Documentation

`CLAUDE.md` requires the wiki to be updated whenever architecture changes, so this is mandatory.

**Files:**
- Create: `wiki/architecture/datastore.md`
- Modify: `wiki/index.md`, `wiki/log.md`, `CLAUDE.md`

- [ ] **Step 1: Read the wiki conventions**

Run: `cat wiki/SCHEMA.md` and `cat wiki/index.md`

Follow its formatting rules and `[[folder/page]]` cross-reference syntax.

- [ ] **Step 2: Write the datastore page**

`wiki/architecture/datastore.md` covering:
- PostgreSQL is the datastore; MongoDB was removed and why
- ULID primary keys and the `id: string` frontend contract
- The schema lives in `database/migrations/`, grouped by responsibility
- No foreign-key constraints, and the reasoning
- `role_user` exists because SQL needs a pivot where MongoDB used ID arrays
- Tests run SQLite, production runs PostgreSQL — keep SQL dialect-neutral, and `DateBucket` is where the one unavoidable difference lives

- [ ] **Step 3: Update the index and log**

Add the page to `wiki/index.md`; append a dated entry to `wiki/log.md`.

- [ ] **Step 4: Update CLAUDE.md**

Three edits:
- **Stack:** PostgreSQL, not MongoDB.
- **Database section:** replace the MongoDB env block and the claim that models set `$connection`/`$collection`; drop the `mongodb/laravel-mongodb` reference and the note about MongoDB relationship internals.
- **Testing notes:** the suite runs on SQLite in-memory. Note that it previously could not run at all, and that a PostgreSQL CI leg guards dialect differences.

- [ ] **Step 5: Commit**

```bash
git add wiki CLAUDE.md
git commit -m "docs: document the move to PostgreSQL"
```

---

## Self-Review Notes

**Spec coverage.** Model conversion → Task 1; ULID keys → Tasks 1 and 2; schema, pivot, column mapping, indexes → Task 2; dependency removal → Task 3; `_id` normalization → Task 4; aggregations → Task 5; testing → Task 6; CI → Task 7; documentation → Task 8. The two pre-existing cast bugs are folded into Task 1, since they are edits to the same two model files.

**Out of scope**, per the spec: MongoDB data migration, PostgreSQL full-text search, foreign-key constraints.

**Known risk carried into execution.** Task 2's schema is derived from `$fillable`/`$casts`. Any attribute written dynamically will be missing a column and surfaces as a Task 6 failure; Task 6 Step 5 names this as the first thing to check.

**Verification gap.** Tasks 1-5 cannot be validated by the test suite, because the suite cannot run until all three of Tasks 1-3 land. Their steps use targeted checks instead. Task 6 is the real gate — a reviewer should treat Tasks 1-5 as provisional until it passes.
