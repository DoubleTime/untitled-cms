<?php

use App\Http\Controllers\ActivityLogController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\EmailLogController;
use App\Http\Controllers\EmailWebhookController;
use App\Http\Controllers\Marketplace\AiModelController;
use App\Http\Controllers\Marketplace\CustomerController;
use App\Http\Controllers\Marketplace\CustomerUserController;
use App\Http\Controllers\Marketplace\DownloadController;
use App\Http\Controllers\Marketplace\MachineBrandController;
use App\Http\Controllers\Marketplace\MachineModelController;
use App\Http\Controllers\Marketplace\ReportController;
use App\Http\Controllers\Marketplace\ScriptController;
use App\Http\Controllers\Marketplace\UnysisBoxController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\RoleController;
use App\Http\Controllers\SettingController;
use App\Http\Controllers\UnsubscribeController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\VaultController;
use App\Http\Controllers\VaultFolderController;
use Illuminate\Support\Facades\Route;

/**
 * Public Media Serving Endpoint
 * Serves optimized/original vault files with RFC 7232 caching headers.
 * Throttled to 1000 requests per minute to accommodate high-density image pages while preventing scraping/DoS.
 * Monitoring recommended if deployed behind a gateway or CDN.
 */
// Primary route — UUID with extension e.g. /media/bb440a63-....jpg
// The extension is cosmetic (browsers and CDNs use it for type hints and cache keying);
// the controller resolves the file by UUID only.
Route::get('/media/{uuid}.{extension}', [VaultController::class, 'servePublic'])
    ->middleware('throttle:1000,1')
    ->name('vault.public');

// Fallback for any legacy URLs already stored without an extension.
Route::get('/media/{uuid}', [VaultController::class, 'servePublic'])
    ->middleware('throttle:1000,1');

// Non-admin profile — auth only, no admin middleware
Route::middleware('auth')->group(function () {
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
});

// Admin routes — require auth, email verification, and at least one permission (admin middleware)
Route::prefix('admin')->name('admin.')->middleware(['auth', 'verified', 'admin'])->group(function () {

    // Admin profile
    Route::get('/profile', [ProfileController::class, 'adminEdit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'adminUpdate'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'adminDestroy'])->name('profile.destroy');

    // Dashboard
    Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');

    // Users
    Route::post('/users/invite', [UserController::class, 'invite'])->name('users.invite');
    Route::middleware('throttle:10,1')->group(function () {
        Route::post('/users/batch-activate', [UserController::class, 'batchActivate'])->name('users.batch-activate');
        Route::post('/users/batch-deactivate', [UserController::class, 'batchDeactivate'])->name('users.batch-deactivate');
        Route::post('/users/batch-delete', [UserController::class, 'batchDelete'])->name('users.batch-delete');
    });
    Route::post('/users/{id}/restore', [UserController::class, 'restore'])->name('users.restore');
    Route::delete('/users/{id}/force-delete', [UserController::class, 'forceDelete'])->name('users.force-delete');
    Route::post('/users/{id}/logout-all-devices', [UserController::class, 'logoutAllDevices'])->name('users.logout-all-devices');
    Route::get('/users/trashed', [UserController::class, 'trashed'])->name('users.trashed');
    Route::resource('users', UserController::class)->except(['show']);

    // Roles
    Route::resource('roles', RoleController::class);

    // Marketplace — Customers, Customer Users and Machines (Phase 2)
    Route::prefix('marketplace')->name('marketplace.')->group(function () {
        Route::resource('machine-brands', MachineBrandController::class)->except(['show']);
        Route::resource('machine-models', MachineModelController::class)->except(['show']);
        Route::resource('customers', CustomerController::class);

        // Customer Users live under the Customer that owns them.
        Route::post('/customers/{customer}/users', [CustomerUserController::class, 'store'])
            ->name('customers.users.store');
        Route::post('/customers/{customer}/users/{user}/toggle-active', [CustomerUserController::class, 'toggleActive'])
            ->name('customers.users.toggle-active');
        Route::post('/customers/{customer}/users/{user}/revoke-tokens', [CustomerUserController::class, 'revokeTokens'])
            ->name('customers.users.revoke-tokens');
        Route::post('/customers/{customer}/users/{user}/send-password-reset', [CustomerUserController::class, 'sendPasswordReset'])
            ->name('customers.users.send-password-reset');

        // Catalogue — Scripts (Phase 3)
        Route::resource('scripts', ScriptController::class);
        Route::put('/scripts/{script}/images', [ScriptController::class, 'syncImages'])
            ->name('scripts.images.sync');
        Route::post('/scripts/{script}/restore', [ScriptController::class, 'restore'])
            ->withTrashed()->name('scripts.restore');
        Route::delete('/scripts/{script}/force', [ScriptController::class, 'forceDestroy'])
            ->withTrashed()->name('scripts.force-destroy');

        Route::post('/scripts/{script}/revisions', [ScriptController::class, 'storeRevision'])
            ->middleware('throttle:30,1')->name('scripts.revisions.store');
        Route::post('/scripts/{script}/revisions/{revision}/release', [ScriptController::class, 'releaseRevision'])
            ->name('scripts.revisions.release');
        Route::post('/scripts/{script}/revisions/{revision}/deprecate', [ScriptController::class, 'deprecateRevision'])
            ->name('scripts.revisions.deprecate');
        Route::get('/scripts/{script}/revisions/{revision}/download', [ScriptController::class, 'downloadRevision'])
            ->name('scripts.revisions.download');
        Route::delete('/scripts/{script}/revisions/{revision}', [ScriptController::class, 'destroyRevision'])
            ->name('scripts.revisions.destroy');

        // Catalogue — AI Models (Phase 3)
        Route::resource('ai-models', AiModelController::class);
        Route::post('/ai-models/{ai_model}/restore', [AiModelController::class, 'restore'])
            ->withTrashed()->name('ai-models.restore');
        Route::delete('/ai-models/{ai_model}/force', [AiModelController::class, 'forceDestroy'])
            ->withTrashed()->name('ai-models.force-destroy');

        Route::post('/ai-models/{ai_model}/revisions', [AiModelController::class, 'storeRevision'])
            ->middleware('throttle:30,1')->name('ai-models.revisions.store');
        Route::post('/ai-models/{ai_model}/revisions/{revision}/release', [AiModelController::class, 'releaseRevision'])
            ->name('ai-models.revisions.release');
        Route::post('/ai-models/{ai_model}/revisions/{revision}/deprecate', [AiModelController::class, 'deprecateRevision'])
            ->name('ai-models.revisions.deprecate');
        Route::get('/ai-models/{ai_model}/revisions/{revision}/download', [AiModelController::class, 'downloadRevision'])
            ->name('ai-models.revisions.download');
        Route::delete('/ai-models/{ai_model}/revisions/{revision}', [AiModelController::class, 'destroyRevision'])
            ->name('ai-models.revisions.destroy');

        // UNYSIS Boxes (Phase 5) — auto-registered by RPA-TOOL, so no create/store.
        Route::resource('unysis-boxes', UnysisBoxController::class)->only(['index', 'show', 'edit', 'update', 'destroy']);
        Route::post('/unysis-boxes/{unysis_box}/block', [UnysisBoxController::class, 'block'])->name('unysis-boxes.block');
        Route::post('/unysis-boxes/{unysis_box}/unblock', [UnysisBoxController::class, 'unblock'])->name('unysis-boxes.unblock');
        Route::post('/unysis-boxes/{unysis_box}/activate', [UnysisBoxController::class, 'activate'])->name('unysis-boxes.activate');

        // Download log (Phase 5) and reporting (Phase 6)
        Route::middleware('can:downloads.view')->group(function () {
            // The export sits above the index so /downloads/export is not swallowed
            // by a future /downloads/{download} route.
            Route::get('/downloads/export', [DownloadController::class, 'export'])->name('downloads.export');
            Route::get('/downloads', [DownloadController::class, 'index'])->name('downloads.index');

            Route::get('/reports/usage/export', [ReportController::class, 'usageExport'])->name('reports.usage.export');
            Route::get('/reports/usage', [ReportController::class, 'usage'])->name('reports.usage');
        });
    });

    // Activity Log
    Route::get('/activity-log', [ActivityLogController::class, 'index'])->name('activity-log.index');

    // Settings
    Route::get('/settings', [SettingController::class, 'index'])->name('settings.index');
    Route::put('/settings/{key}', [SettingController::class, 'update'])->name('settings.update');

    // Email Logs
    Route::get('/email-logs', [EmailLogController::class, 'index'])->name('email-logs.index')->middleware('can:email_logs.view');

    // Vault Manager Routes
    Route::prefix('vault')->name('vault.')->group(function () {
        Route::get('/', [VaultController::class, 'adminPage'])->name('index');
        Route::get('/files', [VaultController::class, 'list'])->name('files.list');
        Route::get('/trash', [VaultController::class, 'trash'])->name('trash.list');
        Route::delete('/trash', [VaultController::class, 'emptyTrash'])->middleware('throttle:5,1')->name('trash.empty');
        Route::post('/upload', [VaultController::class, 'upload'])->name('upload');
        Route::get('/check-duplicate', [VaultController::class, 'checkDuplicate'])->middleware('throttle:120,1')->name('check-duplicate');
        Route::get('/file/{uuid}', [VaultController::class, 'serve'])->name('file.serve');
        Route::post('/files/batch-move', [VaultController::class, 'batchMove'])->name('files.batch_move');
        Route::post('/files/batch-delete', [VaultController::class, 'batchDelete'])->name('files.batch_delete');
        Route::post('/files/batch-restore', [VaultController::class, 'batchRestore'])->middleware('throttle:30,1')->name('files.batch_restore');
        Route::delete('/file/{uuid}', [VaultController::class, 'destroy'])->name('file.destroy');
        Route::post('/file/{uuid}/restore', [VaultController::class, 'restore'])->name('file.restore');
        Route::delete('/file/{uuid}/force', [VaultController::class, 'forceDestroy'])->name('file.force_destroy');
        Route::patch('/file/{uuid}/rename', [VaultController::class, 'rename'])->name('file.rename');
        Route::patch('/file/{uuid}/move', [VaultController::class, 'move'])->name('file.move');
        Route::patch('/file/{uuid}/alt-text', [VaultController::class, 'updateAltText'])->name('file.alt_text');
        Route::patch('/file/{uuid}/toggle-optimization', [VaultController::class, 'toggleOptimization'])->name('file.toggle_optimization');

        Route::get('/folders', [VaultFolderController::class, 'list'])->name('folders.list');
        Route::post('/folders', [VaultFolderController::class, 'store'])->name('folders.store');
        Route::patch('/folders/{id}/rename', [VaultFolderController::class, 'rename'])->name('folders.rename');
        Route::patch('/folders/{id}/move', [VaultFolderController::class, 'move'])->name('folders.move');
        Route::post('/folders/{id}/restore', [VaultFolderController::class, 'restore'])->name('folders.restore');
        Route::delete('/folders/{id}/force', [VaultFolderController::class, 'forceDestroy'])->name('folders.force_destroy');
        Route::delete('/folders/{id}', [VaultFolderController::class, 'destroy'])->name('folders.destroy');
    });
});

require __DIR__.'/auth.php';

// Email Webhooks
Route::post('/webhooks/email', EmailWebhookController::class)
    ->name('webhooks.email')
    ->middleware('webhook.email');

// Unsubscribe
Route::get('/unsubscribe/{token}', UnsubscribeController::class)->name('unsubscribe');

// Root — the Marketplace has no public surface yet, so `/` just points signed-in
// Team Members at the dashboard and everyone else at the login screen.
// A public landing page may replace this later.
Route::get('/', function () {
    $user = request()->user();

    return $user && $user->canAccessBackend()
        ? redirect()->route('admin.dashboard')
        : redirect()->route('login');
})->name('home');
