<?php

namespace App\Providers;

use App\Services\NotificationService;
use App\Services\Notifications\LogChannelGateway;
use App\Services\SettingsRepository;
use App\Support\BranchContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // One branch context per request: resolving it repeatedly would re-read
        // the session and defeat the memoisation that keeps scoping cheap.
        $this->app->singleton(BranchContext::class);
        $this->app->singleton(SettingsRepository::class);

        // External notification channels are opt-in. Until a provider is wired
        // up these log instead of sending, so the flow is testable end to end.
        $this->app->singleton(NotificationService::class, function ($app) {
            $settings = $app->make(SettingsRepository::class);

            return new NotificationService($settings, [
                new LogChannelGateway('sms', $settings->bool('notifications.enable_sms', false)),
                new LogChannelGateway('whatsapp', $settings->bool('notifications.enable_whatsapp', false)),
                new LogChannelGateway('push', $settings->bool('notifications.enable_push', false)),
            ]);
        });
    }

    public function boot(): void
    {
        // Fail loudly outside production on a missing relation or a
        // mass-assignment slip, rather than silently returning wrong data.
        Model::preventSilentlyDiscardingAttributes(! app()->isProduction());

        if (app()->isProduction()) {
            URL::forceScheme('https');
        }

        Password::defaults(fn () => app()->isProduction()
            ? Password::min(10)->letters()->numbers()->symbols()->uncompromised()
            : Password::min(8));

        $this->registerBladeDirectives();
    }

    /**
     * Permission-aware Blade directives.
     *
     * These control what is *shown*; they are never the only check — every
     * route and action behind them is guarded by middleware and a policy too.
     */
    protected function registerBladeDirectives(): void
    {
        Blade::if('canDo', fn (string ...$permissions) => auth()->check()
            && auth()->user()->hasAnyPermission(...$permissions));

        Blade::if('branchAll', fn () => auth()->check() && auth()->user()->canAccessAllBranches());
    }
}
