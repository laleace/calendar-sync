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

#[Signature('calsync:doctor')]
#[Description('Check that the installation is ready: configuration, database, scheduler and queue worker')]
class Doctor extends Command
{
    private int $problems = 0;

    public function handle(GoogleClientFactory $google): int
    {
        $production = app()->isProduction();

        $this->components->info('Configurazione');
        $this->check('APP_KEY impostata', filled(config('app.key')));
        $this->check('APP_DEBUG disattivato', ! config('app.debug'), required: $production);
        $this->check('APP_URL in HTTPS', str_starts_with((string) config('app.url'), 'https://'), required: $production);
        $this->check('Cookie di sessione solo HTTPS (SESSION_SECURE_COOKIE)', (bool) config('session.secure'), required: $production);
        $this->check('Coda: retry_after maggiore del timeout dei sync', (int) config('queue.connections.database.retry_after') > (new SyncJobRunner(0))->timeout);
        $this->check('Lock: cache su database o redis', in_array(config('cache.default'), ['database', 'redis'], true), required: $production);

        $googleReady = $google->isConfigured();
        $this->check('Google OAuth configurato', $googleReady, required: false);

        if ($googleReady) {
            $redirect = $google->redirectUri();
            $this->check("Redirect Google sotto APP_URL ({$redirect})", str_starts_with($redirect, rtrim((string) config('app.url'), '/').'/'));
        }

        $this->newLine();
        $this->components->info('Servizi');

        try {
            DB::connection()->getPdo();
            $this->check('Database raggiungibile ('.config('database.default').')', true);
            $this->check('Utente amministratore creato', User::exists());

            $unreadable = Account::all()->filter->hasUnreadableCredentials();
            $this->check(
                $unreadable->isEmpty()
                    ? 'Credenziali degli account decifrabili con questa APP_KEY'
                    : 'Credenziali illeggibili (APP_KEY diversa da quella usata per salvarle): '.$unreadable->pluck('name')->implode(', '),
                $unreadable->isEmpty(),
            );
        } catch (Throwable $e) {
            $this->check('Database raggiungibile: '.$e->getMessage(), false);
        }

        $tick = Cache::get(DispatchDueSyncJobs::HEARTBEAT_KEY);
        $this->check(
            'Scheduler attivo'.($tick ? ' (ultimo giro '.CarbonImmutable::parse($tick)->diffForHumans().')' : ' (mai eseguito)'),
            $tick !== null && CarbonImmutable::parse($tick)->gt(now()->subMinutes(3)),
        );

        $stuck = SyncRun::where('status', SyncRunStatus::Queued)->where('created_at', '<', now()->subMinutes(2))->count();
        $this->check($stuck === 0 ? 'Worker della coda: nessun run in attesa' : "Worker della coda: {$stuck} run in coda da più di 2 minuti", $stuck === 0);

        $this->newLine();

        if ($this->problems > 0) {
            $this->components->error("{$this->problems} controlli non superati.");

            return self::FAILURE;
        }

        $this->components->info('Tutto a posto.');

        return self::SUCCESS;
    }

    private function check(string $label, bool $ok, bool $required = true): void
    {
        if (! $ok && $required) {
            $this->problems++;
        }

        $this->components->twoColumnDetail($label, match (true) {
            $ok => '<fg=green>OK</>',
            $required => '<fg=red>NO</>',
            default => '<fg=yellow>non attivo</>',
        });
    }
}
