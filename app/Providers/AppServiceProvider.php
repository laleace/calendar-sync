<?php

namespace App\Providers;

use App\CalSync\ProviderRegistry;
use App\CalSync\Support\HttpExchangeFormatter;
use App\Jobs\SyncJobRunner;
use Filament\Support\Facades\FilamentTimezone;
use Illuminate\Foundation\DevCommands;
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

        // `php artisan dev`: web server, queue worker (sync queue included), scheduler, logs.
        DevCommands::artisan('queue:listen --queue='.SyncJobRunner::QUEUE.',default --tries=1 --timeout=0', 'queue');
        DevCommands::artisan('schedule:work', 'scheduler');

        if (config('calsync.http_log')) {
            Event::listen(ResponseReceived::class, function (ResponseReceived $event) {
                Log::debug('[calsync:http] '.HttpExchangeFormatter::summary($event->request, $event->response), [
                    'headers' => HttpExchangeFormatter::requestHeaders($event->request),
                ]);
            });
        }
    }
}
