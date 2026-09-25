<?php

namespace App\Providers;

use App\Services\NotificationService;
use App\Services\Notifications\ChannelGateway;
use App\Services\Notifications\FcmGateway;
use App\Services\Notifications\LogChannelGateway;
use App\Services\Notifications\TwilioSmsGateway;
use App\Services\Notifications\WhatsAppCloudGateway;
use App\Services\OtpService;
use App\Services\PushService;
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

        // External notification channels are opt-in. Without credentials these
        // log instead of sending, so the flow is testable end to end.
        $this->app->singleton(NotificationService::class, function ($app) {
            $settings = $app->make(SettingsRepository::class);

            return new NotificationService($settings, $this->channelGateways($settings));
        });

        // The passcode flow reuses the same gateways, so switching WhatsApp on
        // switches it on for login codes too.
        $this->app->singleton(OtpService::class, function ($app) {
            return new OtpService($this->channelGateways($app->make(SettingsRepository::class)));
        });

        // Chat and registration talk to phones through this, so they never need
        // to know which push implementation is bound or whether one is.
        $this->app->singleton(PushService::class, function ($app) {
            $gateways = $this->channelGateways($app->make(SettingsRepository::class));

            $push = collect($gateways)->first(
                fn (ChannelGateway $gateway) => $gateway->name() === 'push' && $gateway->isEnabled(),
            );

            return new PushService($push);
        });
    }

    /**
     * Build the outbound channel list.
     *
     * A channel needs two yeses: the admin switched it on in الإعدادات, and
     * the provider is configured in `config/services.php`. Where the second is
     * missing the real gateway reports itself disabled and the log gateway
     * behind it takes over, so an unconfigured install still exercises the
     * whole path without sending anything.
     *
     * @return array<int, ChannelGateway>
     */
    protected function channelGateways(SettingsRepository $settings): array
    {
        $sms = $settings->bool('notifications.enable_sms', false);
        $whatsapp = $settings->bool('notifications.enable_whatsapp', false);
        $push = $settings->bool('notifications.enable_push', false);

        return [
            new WhatsAppCloudGateway(
                $whatsapp,
                config('services.whatsapp.phone_number_id'),
                config('services.whatsapp.token'),
                config('services.whatsapp.template'),
                (string) config('services.whatsapp.template_language', 'ar'),
                (string) config('services.whatsapp.api_version', 'v21.0'),
            ),
            new TwilioSmsGateway(
                $sms,
                config('services.twilio.sid'),
                config('services.twilio.token'),
                config('services.twilio.from'),
            ),
            new FcmGateway(
                config('services.fcm.credentials'),
                config('services.fcm.project_id'),
                $push,
            ),
            // Fallbacks: only reached when the provider above is unconfigured.
            new LogChannelGateway('whatsapp', $whatsapp && blank(config('services.whatsapp.token'))),
            new LogChannelGateway('sms', $sms && blank(config('services.twilio.sid'))),
            new LogChannelGateway('push', $push && blank(config('services.fcm.project_id'))),
        ];
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
