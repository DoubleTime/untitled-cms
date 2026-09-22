<?php

namespace App\Providers;

use App\Listeners\InjectUnsubscribeHeaders;
use App\Listeners\LogSentEmail;
use App\Listeners\StopSuppressedEmail;
use App\Models\AiModel;
use App\Models\Customer;
use App\Models\EmailLog;
use App\Models\MachineBrand;
use App\Models\MachineModel;
use App\Models\Script;
use App\Models\Setting;
use App\Models\UnysisBox;
use App\Models\User;
use App\Policies\AiModelPolicy;
use App\Policies\CustomerPolicy;
use App\Policies\EmailLogPolicy;
use App\Policies\MachineBrandPolicy;
use App\Policies\MachineModelPolicy;
use App\Policies\ScriptPolicy;
use App\Policies\SettingPolicy;
use App\Policies\UnysisBoxPolicy;
use App\Services\EmailWebhooks\Contracts\WebhookProvider;
use App\Services\EmailWebhooks\MailgunWebhookProvider;
use App\Services\EmailWebhooks\ResendWebhookProvider;
use App\Services\EmailWebhooks\SendGridWebhookProvider;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\Request;
use Illuminate\Mail\Events\MessageSending;
use Illuminate\Mail\Events\MessageSent;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Vite;
use Illuminate\Support\ServiceProvider;
use Resend\Client;
use Resend\Contracts\Client as ResendClient;
use Resend\Laravel\Transport\ResendTransportFactory;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(WebhookProvider::class, function () {
            return match (config('services.email_webhook.provider')) {
                'mailgun' => new MailgunWebhookProvider,
                'sendgrid' => new SendGridWebhookProvider,
                default => new ResendWebhookProvider,
            };
        });

        // Bind the Resend client (normally done by ResendServiceProvider, which we exclude
        // from auto-discovery to suppress its built-in /resend/webhook route).
        $this->app->singleton(ResendClient::class, function () {
            $apiKey = config('resend.api_key') ?? config('services.resend.key');

            return \Resend::client($apiKey);
        });
        $this->app->alias(ResendClient::class, 'resend');
        $this->app->alias(ResendClient::class, Client::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Marketplace polymorphic Revision/Download rows store these short aliases in
        // revisable_type instead of class names; keys match config/marketplace.php.
        // `user` is here because enforceMorphMap() makes the map exhaustive: Sanctum's
        // personal access tokens are a morphMany on User, so User needs an alias too.
        Relation::enforceMorphMap([
            'ai_model' => AiModel::class,
            'script' => Script::class,
            'user' => User::class,
        ]);

        // Re-register the Resend mail transport manually because resend/resend-laravel
        // is excluded from auto-discovery (see composer.json) to prevent its built-in
        // webhook route from being registered — the app uses its own /webhooks/email endpoint.
        Mail::extend('resend', function (array $config = []) {
            return new ResendTransportFactory($this->app->make(ResendClient::class), $config['options'] ?? []);
        });

        $this->configureRpaToolRateLimiters();

        Vite::prefetch(concurrency: 3);
        Gate::policy(Setting::class, SettingPolicy::class);
        Gate::policy(EmailLog::class, EmailLogPolicy::class);
        Gate::policy(Customer::class, CustomerPolicy::class);
        Gate::policy(MachineBrand::class, MachineBrandPolicy::class);
        Gate::policy(MachineModel::class, MachineModelPolicy::class);
        Gate::policy(Script::class, ScriptPolicy::class);
        Gate::policy(AiModel::class, AiModelPolicy::class);
        Gate::policy(UnysisBox::class, UnysisBoxPolicy::class);

        // Email Logging & Suppression
        // ORDER MATTERS: StopSuppressedEmail must be registered first.
        // Returning false from it halts event propagation, preventing
        // InjectUnsubscribeHeaders from running on blocked emails.
        Event::listen(MessageSending::class, StopSuppressedEmail::class);
        Event::listen(MessageSending::class, InjectUnsubscribeHeaders::class);
        Event::listen(MessageSent::class, LogSentEmail::class);
    }

    /**
     * Rate limiters for the RPA-TOOL API (routes/api.php).
     *
     * Laravel's plain `throttle:60,1` buckets authenticated callers by user id, but
     * one Customer User may run several UNYSIS Boxes and each box is a separate client.
     * The token is the box (its name is the motherboard UUID), so the token id is
     * the bucket; unauthenticated callers fall back to the IP.
     */
    private function configureRpaToolRateLimiters(): void
    {
        $perToken = function (Request $request) {
            $token = $request->user()?->currentAccessToken();

            return $token instanceof Model
                ? 'token:'.$token->getKey()
                : 'ip:'.$request->ip();
        };

        RateLimiter::for('rpa', fn (Request $request) => Limit::perMinute(60)->by($perToken($request)));
        RateLimiter::for('rpa-download', fn (Request $request) => Limit::perMinute(20)->by($perToken($request)));
        RateLimiter::for('rpa-login', fn (Request $request) => Limit::perMinute(5)->by($request->ip()));
    }
}
