<?php

use App\Http\Controllers\Api\V1\AiModelController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\FlowchartScriptController;
use App\Http\Controllers\Api\V1\LookupController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| RPA-TOOL API (v1)
|--------------------------------------------------------------------------
|
| The read-only catalogue API consumed by RPA-TOOL on UNYSIS AI Boxes. Reference
| documentation for its developers lives in docs/api/rpa-tool-v1.md.
|
| Auth is a Sanctum personal access token issued by POST /api/v1/login, named
| after the motherboard UUID of the AI Box it was issued for (docs/adr/0002).
| `ai-box` (App\Http\Middleware\ResolveAiBox) turns that name back into an AI Box
| on every request and re-checks that the box, the Customer User and the Customer
| are all still allowed in, so blocking a box takes effect at once.
|
| Throttles are named limiters registered in AppServiceProvider so that the bucket
| is the token — one AI Box — rather than the Customer User, who may run several.
|
*/

Route::prefix('v1')->name('api.v1.')->group(function () {
    Route::post('login', [AuthController::class, 'login'])
        ->middleware('throttle:rpa-login')
        ->name('login');

    Route::middleware(['auth:sanctum', 'ai-box'])->group(function () {
        Route::middleware('throttle:rpa-download')->group(function () {
            Route::get('scripts/{entry}/download', [FlowchartScriptController::class, 'download'])
                ->name('scripts.download');
            Route::get('ai-models/{entry}/download', [AiModelController::class, 'download'])
                ->name('ai-models.download');
        });

        Route::middleware('throttle:rpa')->group(function () {
            Route::post('logout', [AuthController::class, 'logout'])->name('logout');
            Route::get('me', [AuthController::class, 'me'])->name('me');

            Route::get('machine-brands', [LookupController::class, 'machineBrands'])->name('machine-brands');
            Route::get('machine-models', [LookupController::class, 'machineModels'])->name('machine-models');
            Route::get('customers', [LookupController::class, 'customers'])->name('customers');

            Route::get('scripts', [FlowchartScriptController::class, 'index'])->name('scripts.index');
            Route::get('scripts/{entry}', [FlowchartScriptController::class, 'show'])->name('scripts.show');
            Route::get('scripts/{entry}/revisions', [FlowchartScriptController::class, 'revisions'])
                ->name('scripts.revisions');
            Route::get('scripts/{entry}/check-update', [FlowchartScriptController::class, 'checkUpdate'])
                ->name('scripts.check-update');

            Route::get('ai-models', [AiModelController::class, 'index'])->name('ai-models.index');
            Route::get('ai-models/{entry}', [AiModelController::class, 'show'])->name('ai-models.show');
            Route::get('ai-models/{entry}/revisions', [AiModelController::class, 'revisions'])
                ->name('ai-models.revisions');
            Route::get('ai-models/{entry}/check-update', [AiModelController::class, 'checkUpdate'])
                ->name('ai-models.check-update');
        });
    });
});
