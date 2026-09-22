<?php

namespace App\Providers;

use App\Listeners\InjectUnsubscribeHeaders;
use App\Listeners\LogSentEmail;
use App\Listeners\StopSuppressedEmail;
use App\Models\AiModel;
use App\Models\Customer;
use App\Models\EmailLog;
use App\Models\FlowchartScript;
use App\Models\MachineBrand;
use App\Models\MachineModel;
use App\Models\Setting;
use App\Models\User;
use App\Policies\AiModelPolicy;
use App\Policies\CustomerPolicy;
use App\Policies\EmailLogPolicy;
use App\Policies\FlowchartScriptPolicy;
use App\Policies\MachineBrandPolicy;
use App\Policies\MachineModelPolicy;
use App\Policies\SettingPolicy;
use App\Services\EmailWebhooks\Contracts\WebhookProvider;
use App\Services\EmailWebhooks\MailgunWebhookProvider;
use App\Services\EmailWebhooks\ResendWebhookProvider;
use App\Services\EmailWebhooks\SendGridWebhookProvider;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Mail\Events\MessageSending;
use Illuminate\Mail\Events\MessageSent;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Mail;
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
            'flowchart_script' => FlowchartScript::class,
            'user' => User::class,
        ]);

        // Re-register the Resend mail transport manually because resend/resend-laravel
        // is excluded from auto-discovery (see composer.json) to prevent its built-in
        // webhook route from being registered — the app uses its own /webhooks/email endpoint.
        Mail::extend('resend', function (array $config = []) {
            return new ResendTransportFactory($this->app->make(ResendClient::class), $config['options'] ?? []);
        });

        Vite::prefetch(concurrency: 3);
        Gate::policy(Setting::class, SettingPolicy::class);
        Gate::policy(EmailLog::class, EmailLogPolicy::class);
        Gate::policy(Customer::class, CustomerPolicy::class);
        Gate::policy(MachineBrand::class, MachineBrandPolicy::class);
        Gate::policy(MachineModel::class, MachineModelPolicy::class);
        Gate::policy(FlowchartScript::class, FlowchartScriptPolicy::class);
        Gate::policy(AiModel::class, AiModelPolicy::class);

        // Email Logging & Suppression
        // ORDER MATTERS: StopSuppressedEmail must be registered first.
        // Returning false from it halts event propagation, preventing
        // InjectUnsubscribeHeaders from running on blocked emails.
        Event::listen(MessageSending::class, StopSuppressedEmail::class);
        Event::listen(MessageSending::class, InjectUnsubscribeHeaders::class);
        Event::listen(MessageSent::class, LogSentEmail::class);
    }
}
