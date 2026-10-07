<?php

namespace App\Providers;

use App\CalSync\ProviderRegistry;
use App\CalSync\Support\HttpExchangeFormatter;
use Filament\Support\Facades\FilamentTimezone;
use Illuminate\Http\Client\Events\ResponseReceived;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(ProviderRegistry::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Password::defaults(fn () => Password::min(12));

        // Datetimes are stored in UTC and displayed in the configured timezone.
        FilamentTimezone::set(config('calsync.timezone'));

        if (config('calsync.http_log')) {
            Event::listen(ResponseReceived::class, function (ResponseReceived $event) {
                Log::debug('[calsync:http] '.HttpExchangeFormatter::summary($event->request, $event->response), [
                    'headers' => HttpExchangeFormatter::requestHeaders($event->request),
                ]);
            });
        }
    }
}
