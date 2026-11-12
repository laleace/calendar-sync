<?php

namespace App\Console\Commands;

use App\CalSync\Google\GoogleClientFactory;
use App\Enums\SyncRunStatus;
use App\Jobs\SyncJobRunner;
use App\Models\Account;
use App\Models\SyncRun;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

#[Signature('calsync:doctor {--services : Only check the database, the scheduler and the queue worker, as a container healthcheck}')]
#[Description('Check that the installation is ready: configuration, database, scheduler and queue worker')]
class Doctor extends Command
{
    private int $problems = 0;

    public function handle(GoogleClientFactory $google): int
    {
        if (! $this->option('services')) {
            $this->checkConfiguration($google);
        }

        $this->components->info(__('Services'));

        try {
            DB::connection()->getPdo();
            $this->check(__('Database reachable (:connection)', ['connection' => config('database.default')]), true);
            if (! $this->option('services')) {
                $this->check(__('Administrator user created'), User::exists());
            }

            $unreadable = Account::all()->filter->hasUnreadableCredentials();
            $this->check(
                $unreadable->isEmpty()
                    ? __('Account credentials readable with this APP_KEY')
                    : __('Unreadable credentials (APP_KEY differs from the one used to save them): :accounts', ['accounts' => $unreadable->pluck('name')->implode(', ')]),
                $unreadable->isEmpty(),
            );
        } catch (Throwable $e) {
            $this->check(__('Database reachable: :error', ['error' => $e->getMessage()]), false);
        }

        $tick = Cache::get(DispatchDueSyncJobs::HEARTBEAT_KEY);
        $this->check(
            __('Scheduler running').' '.($tick ? __('(last tick :ago)', ['ago' => CarbonImmutable::parse($tick)->diffForHumans()]) : __('(never run)')),
            $tick !== null && CarbonImmutable::parse($tick)->gt(now()->subMinutes(3)),
        );

        $stuck = SyncRun::where('status', SyncRunStatus::Queued)->where('created_at', '<', now()->subMinutes(2))->count();
        $this->check($stuck === 0 ? __('Queue worker: no runs waiting') : __('Queue worker: :count runs queued for more than 2 minutes', ['count' => $stuck]), $stuck === 0);

        $this->newLine();

        if ($this->problems > 0) {
            $this->components->error(__(':count checks failed.', ['count' => $this->problems]));

            return self::FAILURE;
        }

        $this->components->info(__('All good.'));

        return self::SUCCESS;
    }

    private function checkConfiguration(GoogleClientFactory $google): void
    {
        $production = app()->isProduction();

        $this->components->info(__('Configuration'));
        $this->check(__('APP_KEY set'), filled(config('app.key')));
        $this->check(__('APP_DEBUG off'), ! config('app.debug'), required: $production);
        $this->check(__('APP_URL on HTTPS'), str_starts_with((string) config('app.url'), 'https://'), required: $production);
        $this->check(__('HTTPS-only session cookie (SESSION_SECURE_COOKIE)'), (bool) config('session.secure'), required: $production);
        $this->check(__('Queue: retry_after longer than the sync timeout'), (int) config('queue.connections.database.retry_after') > (new SyncJobRunner(0))->timeout);
        $this->check(__('Locks: cache on database or redis'), in_array(config('cache.default'), ['database', 'redis'], true), required: $production);

        $googleReady = $google->isConfigured();
        $this->check(__('Google OAuth configured'), $googleReady, required: false);

        if ($googleReady) {
            $redirect = $google->redirectUri();
            $this->check(__('Google redirect under APP_URL (:url)', ['url' => $redirect]), str_starts_with($redirect, rtrim((string) config('app.url'), '/').'/'));
        }

        $this->newLine();
    }

    private function check(string $label, bool $ok, bool $required = true): void
    {
        if (! $ok && $required) {
            $this->problems++;
        }

        $this->components->twoColumnDetail($label, match (true) {
            $ok => '<fg=green>OK</>',
            $required => '<fg=red>NO</>',
            default => '<fg=yellow>'.__('not enabled').'</>',
        });
    }
}
