<?php

namespace App\Providers;

use App\Contracts\Ai\AiProviderInterface;
use App\Contracts\Ai\ConversationCopilotProviderInterface;
use App\Contracts\Delivery\DeliveryGeocodingProviderInterface;
use App\Contracts\Delivery\DeliveryRouteProviderInterface;
use App\Contracts\WhatsApp\WhatsAppProviderInterface;
use App\Models\Permission;
use App\Models\User;
use App\Services\Ai\Providers\FakeAiProvider;
use App\Services\Ai\Providers\FakeConversationCopilotProvider;
use App\Services\Ai\Providers\N8nAiProvider;
use App\Services\Ai\Providers\OpenAiConversationCopilotProvider;
use App\Services\Delivery\Providers\FakeDeliveryGeocodingProvider;
use App\Services\Delivery\Providers\FakeDeliveryRouteProvider;
use App\Services\Delivery\Providers\GoogleDeliveryGeocodingProvider;
use App\Services\Delivery\Providers\GoogleDeliveryRouteProvider;
use App\Services\WhatsApp\Providers\FakeWhatsAppProvider;
use App\Services\WhatsApp\Providers\MetaCloudWhatsAppProvider;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(DeliveryGeocodingProviderInterface::class, function ($app): DeliveryGeocodingProviderInterface {
            return config('chatbotcrm.delivery.maps.provider') === 'google'
                ? $app->make(GoogleDeliveryGeocodingProvider::class)
                : $app->make(FakeDeliveryGeocodingProvider::class);
        });
        $this->app->bind(DeliveryRouteProviderInterface::class, function ($app): DeliveryRouteProviderInterface {
            return config('chatbotcrm.delivery.maps.provider') === 'google'
                ? $app->make(GoogleDeliveryRouteProvider::class)
                : $app->make(FakeDeliveryRouteProvider::class);
        });
        $this->app->bind(ConversationCopilotProviderInterface::class, function ($app): ConversationCopilotProviderInterface {
            return config('chatbotcrm.ai.copilot.provider') === 'openai'
                ? $app->make(OpenAiConversationCopilotProvider::class)
                : $app->make(FakeConversationCopilotProvider::class);
        });
        $this->app->bind(AiProviderInterface::class, function ($app): AiProviderInterface {
            $provider = (string) config('chatbotcrm.ai.provider', config('chatbotcrm.providers.ai', 'fake'));

            return match ($provider) {
                'n8n' => $app->make(N8nAiProvider::class),
                default => $app->make(FakeAiProvider::class),
            };
        });

        $this->app->bind(WhatsAppProviderInterface::class, function ($app): WhatsAppProviderInterface {
            $provider = (string) config('chatbotcrm.whatsapp.provider', config('chatbotcrm.providers.whatsapp', 'fake'));

            return match ($provider) {
                'meta', 'meta_cloud' => $app->make(MetaCloudWhatsAppProvider::class),
                default => $app->make(FakeWhatsAppProvider::class),
            };
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();
        $this->configureAccessControl();
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );
    }

    protected function configureAccessControl(): void
    {
        foreach (array_keys(Permission::defaults()) as $permission) {
            Gate::define($permission, fn (User $user): bool => $user->hasPermissionTo($permission));
        }
    }
}
