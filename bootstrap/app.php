<?php

use App\Http\Middleware\CheckMaintenanceMode;
use App\Http\Middleware\CheckPermission;
use App\Http\Middleware\CheckRedirects;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\RequireAdminAccess;
use App\Http\Middleware\ResolveAiBox;
use App\Http\Middleware\VerifyEmailWebhook;
use App\Http\Middleware\VerifySessionVersion;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Exceptions\PostTooLargeException;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        // RPA-TOOL API — see routes/api.php and docs/api/rpa-tool-v1.md.
        api: __DIR__.'/../routes/api.php',
        apiPrefix: 'api',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withEvents(discover: false)
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->validateCsrfTokens(except: [
            '/webhooks/email',
        ]);

        $middleware->web(append: [
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
            CheckRedirects::class,
            CheckMaintenanceMode::class,
            VerifySessionVersion::class,
        ]);

        $middleware->alias([
            'can' => CheckPermission::class,
            'admin' => RequireAdminAccess::class,
            'webhook.email' => VerifyEmailWebhook::class,
            'ai-box' => ResolveAiBox::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        // A request body larger than php.ini's post_max_size is discarded by PHP
        // before validation runs, so the form request's `max:` rule never fires and
        // the user would otherwise see a bare 413. Turn it into a flash message.
        $exceptions->render(function (PostTooLargeException $e, Request $request) {
            $maxMb = round(((int) config('marketplace.max_upload_kb')) / 1024);

            $message = "The upload was rejected before it finished: the request body exceeded the web server's limit. "
                ."Files up to {$maxMb} MB are allowed — if this one was smaller, raise upload_max_filesize and post_max_size on the server.";

            if ($request->expectsJson() || $request->is('api/*')) {
                return response()->json(['message' => $message], 413);
            }

            return back()->with('error', $message);
        });

        $exceptions->render(function (Throwable $e, Request $request) {
            $response = null;

            if ($e instanceof HttpExceptionInterface) {
                $status = $e->getStatusCode();
                if (in_array($status, [403, 404, 500, 503])) {
                    // Always try to render an Inertia response if it's a web request looking for HTML
                    if ($request->header('X-Inertia') || (! $request->wantsJson() && ! $request->is('api/*'))) {
                        return Inertia::render('Error', ['status' => $status])
                            ->toResponse($request)
                            ->setStatusCode($status);
                    }
                }
            }

            return null;
        });
    })->create();
