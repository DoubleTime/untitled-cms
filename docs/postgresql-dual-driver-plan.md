# PostgreSQL Dual-Driver Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Let Untitled CMS run on either MongoDB or PostgreSQL, selected by configuration, without duplicating the model layer.

**Architecture:** All 16 models extend a single `App\Models\Base\Model`, which is a runtime `class_alias` to one of two thin abstract bases — one applying laravel-mongodb's `DocumentModel` trait, one applying `HasUlids`. Both extend the same `Illuminate\Database\Eloquent\Model`, because laravel-mongodb v5 differs from standard Eloquent by a trait rather than a separate hierarchy. Driver-specific SQL aggregations move behind two repository interfaces.

**Tech Stack:** Laravel 13, PHP 8.4, `mongodb/laravel-mongodb` ^5.7, PostgreSQL 14+, SQLite (tests), PHPUnit 13, Pint.

**Spec:** `docs/postgresql-dual-driver-design.md`

## Global Constraints

- Mongo must keep working at every commit. A task that leaves the Mongo path broken is a failed task.
- No foreign-key constraints on any reference column. Mongo enforces none; adding them would diverge delete behaviour between drivers.
- Primary keys are ULID strings on SQL, ObjectId strings on Mongo. Never introduce an integer PK.
- No Postgres-only SQL in shared code. Tests run on SQLite, so `FILTER (WHERE …)` and other Postgres-only syntax are banned outside driver-specific classes.
- `types/index.d.ts`'s `id: string` contract must hold on both drivers.
- Run `./vendor/bin/pint` before every commit.

**Prerequisite for Tasks 1–7:** the test suite currently requires a **live MongoDB** on `127.0.0.1:27017`, because every model pins `$connection = 'mongodb'`. Start `mongod` before running tests. Task 8 removes this requirement.

---

### Task 1: Fix pre-existing cast bugs

These are bugs today, masked by Mongo's native typing. They must be fixed before any SQL driver exists, and they are verifiable on Mongo right now.

**Files:**
- Modify: `app/Models/Role.php:20-23`
- Modify: `app/Models/Redirect.php`
- Test: `tests/Feature/PolicyTest.php` (add cases)

**Interfaces:**
- Consumes: nothing.
- Produces: `Role::$casts` includes `'permissions' => 'array'`; `Redirect::$casts` exists with `'active' => 'boolean'`, `'type' => 'integer'`.

- [ ] **Step 1: Write the failing tests**

Append to `tests/Feature/PolicyTest.php`:

```php
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

public function test_redirect_casts_active_and_type(): void
{
    $redirect = Redirect::create([
        'from_path' => '/old',
        'to_path' => '/new',
        'type' => '301',
        'active' => '1',
    ]);

    $fresh = Redirect::find($redirect->getKey());

    $this->assertIsBool($fresh->active);
    $this->assertIsInt($fresh->type);
    $this->assertSame(301, $fresh->type);
}
```

Add `use App\Models\Redirect;` to the file's imports.

- [ ] **Step 2: Run the tests to verify they fail**

Run: `php artisan test --filter="round_trip_as_an_array|casts_active_and_type"`

Expected: `test_redirect_casts_active_and_type` FAILS with `Failed asserting that '1' is of type bool`. Note that `test_role_permissions_round_trip_as_an_array` will **pass** on Mongo even without the cast — Mongo stores arrays natively. That is exactly the latent bug; the test exists to lock the behaviour in before SQL arrives.

- [ ] **Step 3: Add the casts**

In `app/Models/Role.php`, extend the existing `$casts`:

```php
protected $casts = [
    'permissions' => 'array',
    'is_active' => 'boolean',
    'backend_access' => 'boolean',
];
```

In `app/Models/Redirect.php`, add after `$fillable`:

```php
protected $casts = [
    'active' => 'boolean',
    'type' => 'integer',
];
```

- [ ] **Step 4: Run the tests to verify they pass**

Run: `php artisan test --filter="round_trip_as_an_array|casts_active_and_type"`
Expected: PASS, 2 tests.

- [ ] **Step 5: Commit**

```bash
./vendor/bin/pint
git add app/Models/Role.php app/Models/Redirect.php tests/Feature/PolicyTest.php
git commit -m "fix: add missing casts to Role.permissions and Redirect

Role::permissions is assigned and read as an array but had no cast,
working only because MongoDB stores arrays natively. Redirect had no
casts at all. Both would fail on any SQL driver."
```

---

### Task 2: Base model classes and driver alias

**Files:**
- Create: `app/Models/Base/MongoModel.php`
- Create: `app/Models/Base/SqlModel.php`
- Create: `app/Models/Base/MongoAuthUser.php`
- Create: `app/Models/Base/SqlAuthUser.php`
- Create: `app/Providers/DatastoreServiceProvider.php`
- Modify: `bootstrap/providers.php`
- Modify: `.env.example`
- Test: `tests/Unit/DatastoreAliasTest.php`

**Interfaces:**
- Consumes: nothing.
- Produces: class `App\Models\Base\Model` (runtime alias) and `App\Models\Base\AuthUser` (runtime alias). Task 3 makes all 16 models extend these.

- [ ] **Step 1: Write the failing test**

Create `tests/Unit/DatastoreAliasTest.php`:

```php
<?php

namespace Tests\Unit;

use Illuminate\Database\Eloquent\Model as EloquentModel;
use Tests\TestCase;

class DatastoreAliasTest extends TestCase
{
    public function test_base_model_alias_is_registered(): void
    {
        $this->assertTrue(class_exists(\App\Models\Base\Model::class));
        $this->assertTrue(is_subclass_of(\App\Models\Base\Model::class, EloquentModel::class));
    }

    public function test_base_model_alias_matches_active_driver(): void
    {
        $expected = config('database.default') === 'mongodb'
            ? \App\Models\Base\MongoModel::class
            : \App\Models\Base\SqlModel::class;

        $reflection = new \ReflectionClass(\App\Models\Base\Model::class);

        $this->assertSame($expected, $reflection->getName());
    }

    public function test_keys_are_strings_on_either_driver(): void
    {
        $model = new class extends \App\Models\Base\Model {};

        $this->assertSame('string', $model->getKeyType());
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `php artisan test tests/Unit/DatastoreAliasTest.php`
Expected: FAIL with `Class "App\Models\Base\Model" not found`.

- [ ] **Step 3: Create the base classes**

`app/Models/Base/MongoModel.php`:

```php
<?php

namespace App\Models\Base;

use Illuminate\Database\Eloquent\Model as EloquentModel;
use MongoDB\Laravel\Eloquent\DocumentModel;

/**
 * Base model for the MongoDB driver.
 *
 * laravel-mongodb v5 is not a separate hierarchy: its Model extends
 * Laravel's own Model and applies the DocumentModel trait. The two
 * drivers therefore differ by one trait, not by base class.
 */
abstract class MongoModel extends EloquentModel
{
    use DocumentModel;

    protected $primaryKey = '_id';

    protected $keyType = 'string';
}
```

`app/Models/Base/SqlModel.php`:

```php
<?php

namespace App\Models\Base;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model as EloquentModel;

/**
 * Base model for SQL drivers (PostgreSQL in production, SQLite in tests).
 *
 * ULIDs are used rather than UUIDs because they sort lexically like
 * MongoDB ObjectIds, keeping ordering semantics aligned across drivers,
 * and because they are strings — preserving the `id: string` contract
 * the frontend relies on.
 */
abstract class SqlModel extends EloquentModel
{
    use HasUlids;
}
```

`app/Models/Base/MongoAuthUser.php`:

```php
<?php

namespace App\Models\Base;

use MongoDB\Laravel\Auth\User as MongoAuthenticatable;

abstract class MongoAuthUser extends MongoAuthenticatable
{
    protected $primaryKey = '_id';

    protected $keyType = 'string';
}
```

`app/Models/Base/SqlAuthUser.php`:

```php
<?php

namespace App\Models\Base;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Foundation\Auth\User as Authenticatable;

abstract class SqlAuthUser extends Authenticatable
{
    use HasUlids;
}
```

- [ ] **Step 4: Create the provider**

`app/Providers/DatastoreServiceProvider.php`:

```php
<?php

namespace App\Providers;

use App\Models\Base\MongoAuthUser;
use App\Models\Base\MongoModel;
use App\Models\Base\SqlAuthUser;
use App\Models\Base\SqlModel;
use Illuminate\Support\ServiceProvider;

/**
 * Aliases the model base classes to match the active datastore driver.
 *
 * This MUST be the first provider in bootstrap/providers.php. Laravel's
 * bootstrap order is LoadEnvironmentVariables -> LoadConfiguration ->
 * RegisterProviders -> BootProviders, so config() is available here, and
 * no model class has been autoloaded yet (`Setting::class` elsewhere is
 * constant resolution, which does not trigger the autoloader).
 *
 * Composer's autoload.files is NOT usable for this: env() returns null at
 * autoload time because Dotenv has not run yet.
 */
class DatastoreServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $isMongo = config('database.default') === 'mongodb';

        if (! class_exists(\App\Models\Base\Model::class, false)) {
            class_alias(
                $isMongo ? MongoModel::class : SqlModel::class,
                \App\Models\Base\Model::class
            );
        }

        if (! class_exists(\App\Models\Base\AuthUser::class, false)) {
            class_alias(
                $isMongo ? MongoAuthUser::class : SqlAuthUser::class,
                \App\Models\Base\AuthUser::class
            );
        }
    }
}
```

The `class_exists(..., false)` guard (second argument disables autoloading) makes the provider idempotent, which matters because tests boot the application repeatedly in one process.

- [ ] **Step 5: Register the provider first**

`bootstrap/providers.php`:

```php
<?php

use App\Providers\AppServiceProvider;
use App\Providers\DatastoreServiceProvider;
use MongoDB\Laravel\MongoDBServiceProvider;

return [
    DatastoreServiceProvider::class,
    AppServiceProvider::class,
    MongoDBServiceProvider::class,
];
```

- [ ] **Step 6: Document the driver choice**

Add to `.env.example`, below the existing DB block:

```
# Datastore driver: mongodb (default) or pgsql.
# Changing this requires a matching migration run — the two drivers use
# separate migration paths (database/migrations/mongodb vs /sql).
DB_CONNECTION=mongodb

# PostgreSQL example:
# DB_CONNECTION=pgsql
# DB_HOST=127.0.0.1
# DB_PORT=5432
# DB_DATABASE=untitled_cms
# DB_USERNAME=postgres
# DB_PASSWORD=
```

- [ ] **Step 7: Run the test to verify it passes**

Run: `php artisan test tests/Unit/DatastoreAliasTest.php`
Expected: PASS, 3 tests.

Then confirm nothing else regressed: `php artisan test`
Expected: same results as before this task (Mongo still active).

- [ ] **Step 8: Commit**

```bash
./vendor/bin/pint
git add app/Models/Base app/Providers/DatastoreServiceProvider.php bootstrap/providers.php .env.example tests/Unit/DatastoreAliasTest.php
git commit -m "feat: add driver-aliased model base classes

Introduces App\\Models\\Base\\Model, aliased at provider registration to
either a DocumentModel-backed base (MongoDB) or a HasUlids base (SQL).
No models use it yet."
```

---

### Task 3: Sweep the 16 models onto the aliased base

**Files:**
- Modify (15 models): `app/Models/{ActivityLog,AiHub,Banner,ChatSession,EmailLog,Menu,Page,Redirect,Role,Setting,SuppressedEmail,VaultAuditLog,VaultFile,VaultFolder,VaultFolderPermission}.php`
- Modify: `app/Models/User.php`
- Test: existing suite

**Interfaces:**
- Consumes: `App\Models\Base\Model`, `App\Models\Base\AuthUser` from Task 2.
- Produces: no model declares `$connection` or `$collection`.

Each model gets three edits. Using `Page` as the worked example:

```php
// BEFORE
use MongoDB\Laravel\Eloquent\Model;

class Page extends Model
{
    use HasFactory, SoftDeletes;

    protected $connection = 'mongodb';

    protected $collection = 'pages';

// AFTER
use App\Models\Base\Model;

class Page extends Model
{
    use HasFactory, SoftDeletes;

```

`User` differs only in the base:

```php
// BEFORE
use MongoDB\Laravel\Auth\User as Authenticatable;

class User extends Authenticatable implements MustVerifyEmail
{
    use HasFactory, HasRoles, Notifiable, SoftDeletes;

    protected $connection = 'mongodb';

    protected $collection = 'users';

// AFTER
use App\Models\Base\AuthUser as Authenticatable;

class User extends Authenticatable implements MustVerifyEmail
{
    use HasFactory, HasRoles, Notifiable, SoftDeletes;

```

- [ ] **Step 1: Confirm `$collection` is genuinely inert before deleting it**

Run: `grep -rn "function getTable" vendor/mongodb/laravel-mongodb/src/`
Expected: no match inside `src/Eloquent/`. This confirms v5 ignores `$collection` and resolves table names by Laravel convention. Every declared collection name already matches that convention (`ActivityLog` → `activity_logs`), so deletion is safe.

- [ ] **Step 2: Apply the three edits to all 16 models**

For each file: replace the base-class import, change nothing about the class body except deleting the `protected $connection` and `protected $collection` lines. Do not touch `$fillable`, `$casts`, relationships, or `SoftDeletes`.

- [ ] **Step 3: Verify no stragglers remain**

Run:
```bash
grep -rn "connection = 'mongodb'\|protected \$collection\|MongoDB\\\\Laravel\\\\Eloquent\\\\Model\|MongoDB\\\\Laravel\\\\Auth" app/Models/
```
Expected: no output.

- [ ] **Step 4: Run the full suite on Mongo**

Run: `php artisan test`
Expected: identical results to Task 2. The alias resolves to `MongoModel`, so behaviour is unchanged.

- [ ] **Step 5: Commit**

```bash
./vendor/bin/pint
git add app/Models
git commit -m "refactor: point all models at the driver-aliased base class

Removes hardcoded \$connection and the inert \$collection property
(laravel-mongodb v5 has no \$collection support; table names already
resolved by Laravel convention)."
```

---

### Task 4: Split migrations by driver

**Files:**
- Create: `database/migrations/sql/.gitkeep`
- Create: `database/migrations/mongodb/.gitkeep`
- Move (5 files) into `database/migrations/mongodb/`: `2026_04_05_000000_create_suppressed_emails_index.php`, `2026_04_05_000001_create_email_logs_indexes.php`, `2026_04_06_000000_rename_resend_id_to_provider_message_id_in_email_logs.php`, `2026_04_13_000000_add_search_indexes_to_vault_files.php`, `2026_09_13_093743_add_unique_index_to_vault_folders.php`
- Delete (12 vestigial stubs): the remaining files in `database/migrations/`
- Modify: `app/Providers/DatastoreServiceProvider.php`
- Test: `tests/Unit/DatastoreAliasTest.php`

**Interfaces:**
- Consumes: `DatastoreServiceProvider` from Task 2.
- Produces: `DatastoreServiceProvider::boot()` loading exactly one driver migration path.

The 12 deleted stubs (`create_roles_table` etc.) define no real schema — `create_roles_table` creates only `id` and `timestamps()` — and Task 5 replaces them with authored schema. They are deleted rather than moved because Mongo needs no table creation at all.

- [ ] **Step 1: Write the failing test**

Append to `tests/Unit/DatastoreAliasTest.php`:

```php
public function test_only_the_active_drivers_migration_path_is_registered(): void
{
    $paths = app('migrator')->paths();

    if (config('database.default') === 'mongodb') {
        $this->assertContains(database_path('migrations/mongodb'), $paths);
        $this->assertNotContains(database_path('migrations/sql'), $paths);
    } else {
        $this->assertContains(database_path('migrations/sql'), $paths);
        $this->assertNotContains(database_path('migrations/mongodb'), $paths);
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `php artisan test --filter=test_only_the_active_drivers_migration_path_is_registered`
Expected: FAIL — neither path is registered.

- [ ] **Step 3: Create directories and move the Mongo index migrations**

```bash
mkdir -p database/migrations/sql database/migrations/mongodb
touch database/migrations/sql/.gitkeep database/migrations/mongodb/.gitkeep
git mv database/migrations/2026_04_05_000000_create_suppressed_emails_index.php database/migrations/mongodb/
git mv database/migrations/2026_04_05_000001_create_email_logs_indexes.php database/migrations/mongodb/
git mv database/migrations/2026_04_06_000000_rename_resend_id_to_provider_message_id_in_email_logs.php database/migrations/mongodb/
git mv database/migrations/2026_04_13_000000_add_search_indexes_to_vault_files.php database/migrations/mongodb/
git mv database/migrations/2026_09_13_093743_add_unique_index_to_vault_folders.php database/migrations/mongodb/
git rm database/migrations/*.php
```

- [ ] **Step 4: Register the active path**

Add a `boot()` method to `app/Providers/DatastoreServiceProvider.php`:

```php
public function boot(): void
{
    $this->loadMigrationsFrom(
        config('database.default') === 'mongodb'
            ? database_path('migrations/mongodb')
            : database_path('migrations/sql')
    );
}
```

Add the matching import if your editor does not: none required — `database_path()` is a global helper.

- [ ] **Step 5: Run the test to verify it passes**

Run: `php artisan test --filter=test_only_the_active_drivers_migration_path_is_registered`
Expected: PASS.

Then: `php artisan migrate:status`
Expected: lists only the 5 Mongo index migrations.

- [ ] **Step 6: Commit**

```bash
./vendor/bin/pint
git add -A database/migrations app/Providers/DatastoreServiceProvider.php tests/Unit/DatastoreAliasTest.php
git commit -m "refactor: split migrations by datastore driver

Mongo index migrations move to database/migrations/mongodb. The 12
vestigial stubs are deleted — they created only id + timestamps and
define no usable schema. Task 5 authors the real SQL schema."
```

---

### Task 5: Author the SQL schema

**Files:**
- Create: `database/migrations/sql/2026_09_20_000001_create_core_tables.php`
- Create: `database/migrations/sql/2026_09_20_000002_create_content_tables.php`
- Create: `database/migrations/sql/2026_09_20_000003_create_vault_tables.php`
- Create: `database/migrations/sql/2026_09_20_000004_create_log_tables.php`
- Create: `database/migrations/sql/2026_09_20_000005_create_framework_tables.php`
- Test: `tests/Unit/SqlSchemaTest.php`

**Interfaces:**
- Consumes: the `sql` migration path from Task 4.
- Produces: tables `users`, `roles`, `role_user`, `settings`, `pages`, `banners`, `menus`, `redirects`, `chat_sessions`, `ai_hubs`, `vault_files`, `vault_folders`, `vault_folder_permissions`, `activity_logs`, `vault_audit_logs`, `email_logs`, `suppressed_emails`, plus framework tables.

Grouped into five files by responsibility rather than one-per-table: these tables are created together, always, and 17 single-table files would be noise. Columns are derived from each model's `$fillable` and `$casts`.

- [ ] **Step 1: Write the failing test**

Create `tests/Unit/SqlSchemaTest.php`:

```php
<?php

namespace Tests\Unit;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class SqlSchemaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        if (config('database.default') === 'mongodb') {
            $this->markTestSkipped('SQL schema test applies to SQL drivers only.');
        }
    }

    public static function tableProvider(): array
    {
        return array_map(fn ($t) => [$t], [
            'users', 'roles', 'role_user', 'settings', 'pages', 'banners',
            'menus', 'redirects', 'chat_sessions', 'ai_hubs', 'vault_files',
            'vault_folders', 'vault_folder_permissions', 'activity_logs',
            'vault_audit_logs', 'email_logs', 'suppressed_emails',
        ]);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('tableProvider')]
    public function test_table_exists(string $table): void
    {
        $this->assertTrue(Schema::hasTable($table), "Missing table: {$table}");
    }

    public function test_primary_keys_are_ulid_strings_not_integers(): void
    {
        $page = \App\Models\Page::factory()->create();

        $this->assertIsString($page->getKey());
        $this->assertSame(26, strlen($page->getKey()), 'Expected a 26-character ULID');
        $this->assertFalse($page->incrementing);
    }

    public function test_json_columns_exist_for_array_casts(): void
    {
        $this->assertTrue(Schema::hasColumn('pages', 'tags'));
        $this->assertTrue(Schema::hasColumn('banners', 'slides'));
        $this->assertTrue(Schema::hasColumn('menus', 'items'));
        $this->assertTrue(Schema::hasColumn('roles', 'permissions'));
        $this->assertTrue(Schema::hasColumn('chat_sessions', 'messages'));
    }

    public function test_soft_delete_columns_exist(): void
    {
        foreach (['users', 'pages', 'banners', 'menus', 'chat_sessions', 'vault_files', 'vault_folders'] as $table) {
            $this->assertTrue(Schema::hasColumn($table, 'deleted_at'), "Missing deleted_at on {$table}");
        }
    }
}
```

This test is written to run under SQLite, which Task 8 switches on. Until then it self-skips.

- [ ] **Step 2: Run the test to verify it skips (not fails)**

Run: `php artisan test tests/Unit/SqlSchemaTest.php`
Expected: SKIPPED while `database.default` is `mongodb`. That is the correct state for this step.

- [ ] **Step 3: Write the core tables migration**

`database/migrations/sql/2026_09_20_000001_create_core_tables.php`:

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

        // Mongo stores belongsToMany as ID arrays on both documents; SQL
        // needs a real pivot. The relationship code is identical on both.
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

- [ ] **Step 4: Write the content tables migration**

`database/migrations/sql/2026_09_20_000002_create_content_tables.php`:

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
            $table->string('from_path')->unique();   // CheckRedirects queries this on every request
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

- [ ] **Step 5: Write the vault tables migration**

`database/migrations/sql/2026_09_20_000003_create_vault_tables.php`:

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
            $table->unique(['parent_id', 'path_slug']);   // mirrors add_unique_index_to_vault_folders
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
            $table->index(['original_name', 'mime_type']);   // mirrors add_search_indexes_to_vault_files
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

- [ ] **Step 6: Write the log tables migration**

`database/migrations/sql/2026_09_20_000004_create_log_tables.php`:

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
            $table->string('email')->unique();   // mirrors create_suppressed_emails_index
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

- [ ] **Step 7: Write the framework tables migration**

The deleted stubs included Laravel's `cache`, `jobs`, and session tables. The SQL path needs them back.

`database/migrations/sql/2026_09_20_000005_create_framework_tables.php`:

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

`jobs` and `failed_jobs` keep auto-increment IDs deliberately — they are framework-owned tables, never exposed through the `id: string` frontend contract.

- [ ] **Step 8: Verify the schema builds on SQLite**

Run: `DB_CONNECTION=sqlite DB_DATABASE=:memory: php artisan migrate --pretend`
Expected: prints CREATE TABLE statements for all 17 application tables plus framework tables, no errors.

Then on a real Postgres database, if one is available:
```bash
DB_CONNECTION=pgsql php artisan migrate
```
Expected: all migrations run clean.

- [ ] **Step 9: Commit**

```bash
./vendor/bin/pint
git add database/migrations/sql tests/Unit/SqlSchemaTest.php
git commit -m "feat: author relational schema for the SQL driver

17 application tables derived from model \$fillable and \$casts, plus a
role_user pivot (Mongo stores belongsToMany as ID arrays; SQL needs a
pivot table) and the framework tables. No foreign-key constraints, to
keep delete behaviour identical across drivers."
```

---

### Task 6: Normalize `_id` call sites

**Files:**
- Modify: `app/Http/Controllers/UserController.php:116,134`
- Modify: `app/Http/Controllers/VaultFolderController.php:39,56,110`
- Modify: `app/Models/Role.php:35`
- Modify: `resources/js/types/index.d.ts:8`
- Test: `tests/Feature/UserControllerTest.php` (add case)

**Interfaces:**
- Consumes: models from Task 3.
- Produces: no `_id` literal anywhere in `app/`.

- [ ] **Step 1: Write the failing test**

Append to `tests/Feature/UserControllerTest.php`:

```php
public function test_bulk_deactivate_works_by_primary_key(): void
{
    $targets = User::factory()->count(2)->create(['is_active' => true]);

    $response = $this->actingAs($this->admin)->post('/admin/users/bulk-deactivate', [
        'user_ids' => $targets->pluck('id')->map(fn ($id) => (string) $id)->toArray(),
    ]);

    $response->assertSuccessful();

    foreach ($targets as $target) {
        $this->assertFalse(User::find($target->getKey())->is_active);
    }
}
```

Check the actual bulk-deactivate route path in `routes/web.php` before running, and correct the URL if it differs.

- [ ] **Step 2: Run the test to verify it passes on Mongo**

Run: `php artisan test --filter=test_bulk_deactivate_works_by_primary_key`
Expected: PASS. This is a characterization test — it locks in current behaviour so the refactor below cannot change it.

- [ ] **Step 3: Replace the `_id` usages**

`app/Http/Controllers/UserController.php` — two sites:

```php
// line ~116
$count = User::whereKey($ids)->update(['is_active' => false]);

// line ~134
$count = User::whereKey($ids)->delete();
```

Check line 98 too, which uses the same pattern for activation:

```php
$count = User::whereKey($request->user_ids)->update(['is_active' => true]);
```

`app/Http/Controllers/VaultFolderController.php`:

```php
// line ~39
$folderIds = $folders->pluck('id')->map(fn ($id) => (string) $id)->toArray();

// line ~56
$stat = $filesStats->get((string) $folder->getKey());

// line ~110
->whereKeyNot($folder->getKey())
```

`app/Models/Role.php` line ~35:

```php
foreach ($role->users()->pluck($role->users()->getRelated()->getKeyName()) as $userId) {
```

- [ ] **Step 4: Update the frontend type comment**

`resources/js/types/index.d.ts:8`:

```ts
id: string; // ULID on SQL drivers, MongoDB ObjectId hex on MongoDB
```

- [ ] **Step 5: Verify no `_id` literals remain**

Run: `grep -rn "'_id'\|\"_id\"\|->_id" app/`
Expected: no output. (`$_id` inside `database/migrations/mongodb/` is fine and out of scope.)

- [ ] **Step 6: Run the full suite**

Run: `php artisan test`
Expected: same results as Task 5, including the new characterization test.

- [ ] **Step 7: Commit**

```bash
./vendor/bin/pint
git add app resources/js/types/index.d.ts tests/Feature/UserControllerTest.php
git commit -m "refactor: address records by primary key instead of _id

whereKey/whereKeyNot/getKeyName work on both drivers; the _id literal
only works on MongoDB."
```

---

### Task 7: Stats repository abstraction

**Files:**
- Create: `app/Repositories/Contracts/EmailStatsRepository.php`
- Create: `app/Repositories/Contracts/VaultStatsRepository.php`
- Create: `app/Repositories/Mongo/MongoEmailStatsRepository.php`
- Create: `app/Repositories/Mongo/MongoVaultStatsRepository.php`
- Create: `app/Repositories/Sql/SqlEmailStatsRepository.php`
- Create: `app/Repositories/Sql/SqlVaultStatsRepository.php`
- Modify: `app/Providers/DatastoreServiceProvider.php`
- Modify: `app/Http/Controllers/DashboardController.php:15-45`
- Modify: `app/Http/Controllers/EmailLogController.php:32-60`
- Modify: `app/Http/Controllers/VaultFolderController.php:39-58`
- Test: `tests/Unit/StatsRepositoryTest.php`

**Interfaces:**
- Consumes: models from Task 3, schema from Task 5.
- Produces:
  - `EmailStatsRepository::dailyCounts(CarbonInterface $since): Collection` — keyed `'Y-m-d'` => `['sent' => int, 'delivered' => int]`
  - `EmailStatsRepository::statusTotals(): array` — `['total' => int, 'delivered' => int, 'opened' => int, 'bounced' => int]`
  - `VaultStatsRepository::folderTotals(array $folderIds): Collection` — keyed folder id => `['files_count' => int, 'files_size' => int]`

- [ ] **Step 1: Write the failing test**

Create `tests/Unit/StatsRepositoryTest.php`:

```php
<?php

namespace Tests\Unit;

use App\Models\EmailLog;
use App\Models\VaultFile;
use App\Models\VaultFolder;
use App\Repositories\Contracts\EmailStatsRepository;
use App\Repositories\Contracts\VaultStatsRepository;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StatsRepositoryTest extends TestCase
{
    use RefreshDatabase;

    public function test_daily_counts_buckets_by_day_and_counts_delivered(): void
    {
        EmailLog::factory()->create(['created_at' => Carbon::today(), 'delivered_at' => Carbon::today()]);
        EmailLog::factory()->create(['created_at' => Carbon::today(), 'delivered_at' => null]);

        $result = app(EmailStatsRepository::class)->dailyCounts(Carbon::today()->subDays(6));

        $today = $result->get(Carbon::today()->format('Y-m-d'));

        $this->assertSame(2, (int) $today['sent']);
        $this->assertSame(1, (int) $today['delivered']);
    }

    public function test_status_totals_counts_each_status(): void
    {
        EmailLog::factory()->create(['status' => 'delivered', 'opened_at' => Carbon::now()]);
        EmailLog::factory()->create(['status' => 'bounced', 'opened_at' => null]);

        $totals = app(EmailStatsRepository::class)->statusTotals();

        $this->assertSame(2, $totals['total']);
        $this->assertSame(1, $totals['delivered']);
        $this->assertSame(1, $totals['opened']);
        $this->assertSame(1, $totals['bounced']);
    }

    public function test_folder_totals_sums_size_and_counts_files(): void
    {
        $folder = VaultFolder::factory()->create();

        VaultFile::factory()->create(['folder_id' => $folder->getKey(), 'size_bytes' => 100]);
        VaultFile::factory()->create(['folder_id' => $folder->getKey(), 'size_bytes' => 250]);

        $totals = app(VaultStatsRepository::class)->folderTotals([(string) $folder->getKey()]);

        $stat = $totals->get((string) $folder->getKey());

        $this->assertSame(2, (int) $stat['files_count']);
        $this->assertSame(350, (int) $stat['files_size']);
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `php artisan test tests/Unit/StatsRepositoryTest.php`
Expected: FAIL with `Target class [App\Repositories\Contracts\EmailStatsRepository] does not exist`.

- [ ] **Step 3: Define the contracts**

`app/Repositories/Contracts/EmailStatsRepository.php`:

```php
<?php

namespace App\Repositories\Contracts;

use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

interface EmailStatsRepository
{
    /**
     * @return Collection<string, array{sent: int, delivered: int}> keyed by 'Y-m-d'
     */
    public function dailyCounts(CarbonInterface $since): Collection;

    /**
     * @return array{total: int, delivered: int, opened: int, bounced: int}
     */
    public function statusTotals(): array;
}
```

`app/Repositories/Contracts/VaultStatsRepository.php`:

```php
<?php

namespace App\Repositories\Contracts;

use Illuminate\Support\Collection;

interface VaultStatsRepository
{
    /**
     * @param  array<int, string>  $folderIds
     * @return Collection<string, array{files_count: int, files_size: int}> keyed by folder id
     */
    public function folderTotals(array $folderIds): Collection;
}
```

- [ ] **Step 4: Write the Mongo implementations**

These lift the existing pipelines verbatim out of the controllers.

`app/Repositories/Mongo/MongoEmailStatsRepository.php`:

```php
<?php

namespace App\Repositories\Mongo;

use App\Models\EmailLog;
use App\Repositories\Contracts\EmailStatsRepository;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use MongoDB\BSON\UTCDateTime;

class MongoEmailStatsRepository implements EmailStatsRepository
{
    public function dailyCounts(CarbonInterface $since): Collection
    {
        /** @var \Traversable $cursor */
        $cursor = EmailLog::raw(function ($collection) use ($since) {
            return $collection->aggregate([
                ['$match' => ['created_at' => ['$gte' => new UTCDateTime($since->getTimestampMs())]]],
                ['$group' => [
                    '_id' => ['$dateToString' => ['format' => '%Y-%m-%d', 'date' => '$created_at']],
                    'sent' => ['$sum' => 1],
                    'delivered' => ['$sum' => ['$cond' => [['$ne' => ['$delivered_at', null]], 1, 0]]],
                ]],
                ['$sort' => ['_id' => 1]],
            ]);
        });

        return collect(iterator_to_array($cursor))
            ->keyBy('_id')
            ->map(fn ($row) => [
                'sent' => (int) $row['sent'],
                'delivered' => (int) $row['delivered'],
            ]);
    }

    public function statusTotals(): array
    {
        /** @var \Traversable $cursor */
        $cursor = EmailLog::raw(function ($collection) {
            return $collection->aggregate([
                ['$facet' => [
                    'total' => [['$count' => 'count']],
                    'delivered' => [['$match' => ['status' => 'delivered']], ['$count' => 'count']],
                    'opened' => [['$match' => ['opened_at' => ['$ne' => null]]], ['$count' => 'count']],
                    'bounced' => [['$match' => ['status' => 'bounced']], ['$count' => 'count']],
                ]],
            ]);
        });

        $row = iterator_to_array($cursor)[0] ?? [];

        return [
            'total' => (int) ($row['total'][0]['count'] ?? 0),
            'delivered' => (int) ($row['delivered'][0]['count'] ?? 0),
            'opened' => (int) ($row['opened'][0]['count'] ?? 0),
            'bounced' => (int) ($row['bounced'][0]['count'] ?? 0),
        ];
    }
}
```

`app/Repositories/Mongo/MongoVaultStatsRepository.php`:

```php
<?php

namespace App\Repositories\Mongo;

use App\Models\VaultFile;
use App\Repositories\Contracts\VaultStatsRepository;
use Illuminate\Support\Collection;

class MongoVaultStatsRepository implements VaultStatsRepository
{
    public function folderTotals(array $folderIds): Collection
    {
        $raw = VaultFile::raw(function ($collection) use ($folderIds) {
            return $collection->aggregate([
                ['$match' => ['folder_id' => ['$in' => $folderIds], 'deleted_at' => null]],
                ['$group' => [
                    '_id' => '$folder_id',
                    'files_count' => ['$sum' => 1],
                    'files_size' => ['$sum' => '$size_bytes'],
                ]],
            ]);
        });

        return collect($raw)
            ->keyBy('_id')
            ->map(fn ($row) => [
                'files_count' => (int) $row['files_count'],
                'files_size' => (int) $row['files_size'],
            ]);
    }
}
```

- [ ] **Step 5: Write the SQL implementations**

`app/Repositories/Sql/SqlEmailStatsRepository.php`:

```php
<?php

namespace App\Repositories\Sql;

use App\Models\EmailLog;
use App\Repositories\Contracts\EmailStatsRepository;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class SqlEmailStatsRepository implements EmailStatsRepository
{
    public function dailyCounts(CarbonInterface $since): Collection
    {
        $bucket = $this->dateBucketExpression();

        return EmailLog::query()
            ->where('created_at', '>=', $since)
            ->groupBy(DB::raw($bucket))
            ->orderBy(DB::raw($bucket))
            ->get([
                DB::raw("{$bucket} as day"),
                DB::raw('count(*) as sent'),
                // count() ignores NULLs, reproducing Mongo's $cond on $ne: null
                DB::raw('count(delivered_at) as delivered'),
            ])
            ->keyBy('day')
            ->map(fn ($row) => [
                'sent' => (int) $row->sent,
                'delivered' => (int) $row->delivered,
            ]);
    }

    public function statusTotals(): array
    {
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
    }

    /**
     * Date bucketing is the one place the two SQL dialects genuinely differ.
     */
    private function dateBucketExpression(): string
    {
        return DB::connection()->getDriverName() === 'sqlite'
            ? "strftime('%Y-%m-%d', created_at)"
            : "to_char(created_at, 'YYYY-MM-DD')";
    }
}
```

`app/Repositories/Sql/SqlVaultStatsRepository.php`:

```php
<?php

namespace App\Repositories\Sql;

use App\Models\VaultFile;
use App\Repositories\Contracts\VaultStatsRepository;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class SqlVaultStatsRepository implements VaultStatsRepository
{
    public function folderTotals(array $folderIds): Collection
    {
        return VaultFile::query()
            ->whereIn('folder_id', $folderIds)
            ->groupBy('folder_id')
            ->get([
                'folder_id',
                DB::raw('count(*) as files_count'),
                DB::raw('coalesce(sum(size_bytes), 0) as files_size'),
            ])
            ->keyBy('folder_id')
            ->map(fn ($row) => [
                'files_count' => (int) $row->files_count,
                'files_size' => (int) $row->files_size,
            ]);
    }
}
```

`VaultFile` uses `SoftDeletes`, so the global scope already applies `deleted_at is null` — matching the Mongo pipeline's explicit `'deleted_at' => null` match. Do not add it manually.

- [ ] **Step 6: Bind the implementations**

Add to `DatastoreServiceProvider::register()`, after the aliases:

```php
$this->app->bind(
    \App\Repositories\Contracts\EmailStatsRepository::class,
    $isMongo
        ? \App\Repositories\Mongo\MongoEmailStatsRepository::class
        : \App\Repositories\Sql\SqlEmailStatsRepository::class
);

$this->app->bind(
    \App\Repositories\Contracts\VaultStatsRepository::class,
    $isMongo
        ? \App\Repositories\Mongo\MongoVaultStatsRepository::class
        : \App\Repositories\Sql\SqlVaultStatsRepository::class
);
```

- [ ] **Step 7: Rewire the controllers**

`DashboardController::index()` — replace the `EmailLog::raw(...)` block and the `$statsMap` line:

```php
public function index(EmailStatsRepository $emailStats)
{
    $startDate = Carbon::today()->subDays(6);

    $statsMap = $emailStats->dailyCounts($startDate);

    // ... the existing collect(range(6, 0))->map(...) block is unchanged
```

Remove the now-unused `use MongoDB\BSON\UTCDateTime;` import and add `use App\Repositories\Contracts\EmailStatsRepository;`.

`EmailLogController` — replace the cached block body:

```php
$stats = Cache::remember('email_log_stats', 300, function () use ($emailStats) {
    return $emailStats->statusTotals();
});
```

Inject `EmailStatsRepository $emailStats` into the method signature and keep the surrounding cache call exactly as it is.

`VaultFolderController` — replace the `VaultFile::raw(...)` block:

```php
$filesStats = $vaultStats->folderTotals($folderIds);
```

Inject `VaultStatsRepository $vaultStats` into the method signature. The `$folders->transform(...)` block below is unchanged apart from Task 6's `getKey()` edit.

- [ ] **Step 8: Run the tests**

Run: `php artisan test tests/Unit/StatsRepositoryTest.php`
Expected: PASS, 3 tests (exercising the Mongo implementations while Mongo is still the default).

Run: `php artisan test`
Expected: full suite green.

- [ ] **Step 9: Verify no raw pipelines remain in controllers**

Run: `grep -rn "::raw(function" app/Http/Controllers/`
Expected: no output.

- [ ] **Step 10: Commit**

```bash
./vendor/bin/pint
git add app/Repositories app/Providers/DatastoreServiceProvider.php app/Http/Controllers tests/Unit/StatsRepositoryTest.php
git commit -m "refactor: move aggregation pipelines behind stats repositories

The three raw MongoDB pipelines were the only code that could not run on
a SQL driver, and the only code untestable without a live MongoDB. Each
now has a Mongo and a SQL implementation bound by driver."
```

---

### Task 8: Switch the test suite to SQLite

This is the task that proves the whole design: the existing feature tests become the SQL regression suite.

**Files:**
- Modify: `phpunit.xml`
- Modify: `tests/Feature/*.php` (remove redundant `truncate()` calls)
- Modify: `CLAUDE.md`

**Interfaces:**
- Consumes: everything from Tasks 1–7.
- Produces: `php artisan test` runs with no MongoDB running.

- [ ] **Step 1: Confirm the suite currently needs MongoDB**

Stop `mongod`, then run: `php artisan test --filter=test_admin_can_create_page`
Expected: FAIL with a connection error. This is the baseline being fixed.

- [ ] **Step 2: Point the test suite at SQLite**

`phpunit.xml` already sets `DB_CONNECTION=sqlite` and `DB_DATABASE=:memory:`. With Task 3 removing the hardcoded `$connection`, those values now actually take effect — no edit needed. Verify they are present and unchanged.

- [ ] **Step 3: Run the full suite with MongoDB stopped**

Run: `php artisan test`
Expected: all tests pass against SQLite in-memory, including `SqlSchemaTest` (which no longer skips).

Fix failures as they surface. Anticipated causes, in likely order:
1. **Redundant `truncate()` calls** in `setUp()` (e.g. `PageControllerTest` lines 20-22). `RefreshDatabase` already gives each test a clean database; on SQL these run before migrations in some orderings and can error. Delete them.
2. **Factories emitting Mongo-shaped data.** Check `database/factories/*.php` for hardcoded 24-char hex IDs and replace with `(string) Str::ulid()` or relationship factories.
3. **Missing columns** — a field written dynamically outside `$fillable` that the schema audit missed. Add the column to the relevant Task 5 migration.

- [ ] **Step 4: Correct the CLAUDE.md testing claim**

Replace the testing note in `CLAUDE.md` with:

```markdown
**Testing notes:** PHPUnit runs against SQLite in-memory. This works because
models no longer pin a connection — they extend a driver-aliased base class
(`App\Models\Base\Model`). Before that change the suite silently required a
live MongoDB despite the SQLite setting. Tests in `tests/Feature/` cover Auth,
Maintenance Mode, Profile, Vault upload/folder operations. The `public/hot`
file is created in setUp and removed in tearDown to bypass
`ViteManifestNotFoundException`.
```

- [ ] **Step 5: Verify the Mongo path still works**

Start `mongod`, then run: `DB_CONNECTION=mongodb php artisan test`
Expected: green. If this fails, the dual-driver promise is already broken — fix before committing.

- [ ] **Step 6: Commit**

```bash
./vendor/bin/pint
git add phpunit.xml tests CLAUDE.md
git commit -m "test: run the suite on SQLite

Tests no longer require a live MongoDB. The existing feature tests now
serve as the regression suite for the SQL driver."
```

---

### Task 9: CI job for the MongoDB path

Without this, the default test run only exercises SQL and the Mongo path rots into a broken branch within a release.

**Files:**
- Create or modify: `.github/workflows/tests.yml`

**Interfaces:**
- Consumes: the working dual-driver suite from Task 8.
- Produces: CI running the suite twice, once per driver.

- [ ] **Step 1: Check for an existing workflow**

Run: `ls -la .github/workflows/ 2>/dev/null || echo "none"`

If a workflow exists, add a matrix to it rather than creating a new file.

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

    strategy:
      fail-fast: false
      matrix:
        driver: [sqlite, mongodb]

    services:
      mongodb:
        image: mongo:7
        ports:
          - 27017:27017

    steps:
      - uses: actions/checkout@v4

      - uses: shivammathur/setup-php@v2
        with:
          php-version: '8.4'
          extensions: mongodb, pdo_sqlite, pdo_pgsql
          coverage: none

      - run: composer install --prefer-dist --no-interaction --no-progress

      - run: cp .env.example .env && php artisan key:generate

      - name: Run tests
        env:
          DB_CONNECTION: ${{ matrix.driver }}
          DB_DATABASE: ${{ matrix.driver == 'sqlite' && ':memory:' || 'untitled_cms' }}
          DB_HOST: 127.0.0.1
          DB_PORT: ${{ matrix.driver == 'mongodb' && '27017' || '' }}
        run: php artisan test
```

The `mongodb` service starts for both matrix legs. That is wasteful but harmless, and far simpler than conditional service blocks, which GitHub Actions does not support cleanly.

- [ ] **Step 3: Verify the workflow parses**

Run: `php -r "var_dump(yaml_parse_file('.github/workflows/tests.yml') !== false);"` if `ext-yaml` is available; otherwise push the branch and confirm the run starts.

- [ ] **Step 4: Commit**

```bash
git add .github/workflows/tests.yml
git commit -m "ci: run the test suite against both drivers

A SQL-only CI run would let the MongoDB path break unnoticed."
```

---

### Task 10: Documentation

`CLAUDE.md` requires the wiki to be updated whenever architecture changes, so this task is mandatory, not optional polish.

**Files:**
- Create: `wiki/architecture/datastore-abstraction.md`
- Modify: `wiki/index.md`
- Modify: `wiki/log.md`
- Modify: `CLAUDE.md`

**Interfaces:**
- Consumes: the completed implementation.
- Produces: nothing code depends on.

- [ ] **Step 1: Read the wiki conventions**

Run: `cat wiki/SCHEMA.md`

Follow its formatting rules and `[[folder/page]]` cross-reference syntax exactly. Read `wiki/index.md` to see where an architecture page belongs in the catalog.

- [ ] **Step 2: Write the architecture page**

`wiki/architecture/datastore-abstraction.md` must cover:
- The aliased base class and why a service provider rather than `autoload.files` (env is not loaded at autoload time)
- The requirement that `DatastoreServiceProvider` is registered first
- ULID vs ObjectId primary keys and the preserved `id: string` frontend contract
- The split migration paths, and that `role_user` exists only on SQL
- The stats repositories and which controllers consume them
- The rule that no new code may reference `_id` or use Postgres-only SQL outside `app/Repositories/Sql/`

Cross-reference existing pages with `[[…]]` links per `wiki/SCHEMA.md`.

- [ ] **Step 3: Update the index and log**

Add the new page to `wiki/index.md` under the architecture section. Append a dated entry to `wiki/log.md` describing the dual-driver work.

- [ ] **Step 4: Update CLAUDE.md**

Amend two sections:
- **Stack:** note that MongoDB *or* PostgreSQL is supported, selected by `DB_CONNECTION`.
- **Database:** replace the "MongoDB is required for production" claim, and remove the statement that all models set `$connection` and `$collection` — they no longer do. Point at `wiki/architecture/datastore-abstraction.md`.

- [ ] **Step 5: Commit**

```bash
git add wiki CLAUDE.md
git commit -m "docs: document the datastore abstraction

Adds wiki/architecture/datastore-abstraction.md and corrects CLAUDE.md,
which described MongoDB as required and models as pinning \$connection."
```

---

## Self-Review Notes

**Spec coverage.** Every section of `docs/postgresql-dual-driver-design.md` maps to a task: aliased base class → Task 2; model sweep → Task 3; ULID keys → Tasks 2 and 5; migrations, pivot table, column mapping, indexes → Tasks 4 and 5; stats abstraction → Task 7; portable IDs → Task 6; testing → Task 8; the Mongo CI requirement → Task 9; documentation impact → Task 10. The spec's two pre-existing cast bugs → Task 1.

**Deliberately out of scope**, per the spec: Mongo→Postgres data migration, and PostgreSQL full-text search.

**Known risk carried into execution.** Task 5's schema is derived from `$fillable` and `$casts`. Any attribute written dynamically outside `$fillable` will be missing a column and will surface as a Task 8 test failure. Task 8 Step 3 names this as an anticipated cause and tells the executor to add the column to the relevant Task 5 migration.
