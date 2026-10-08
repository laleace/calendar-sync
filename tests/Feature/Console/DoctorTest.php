<?php

use App\Console\Commands\DispatchDueSyncJobs;
use App\Jobs\SyncJobRunner;
use App\Models\SyncJob;
use App\Models\SyncRun;
use App\Models\User;
use Illuminate\Support\Facades\Cache;

it('keeps the queue retry_after above the sync timeout', function () {
    // Otherwise a sync still running would be handed to a second worker.
    expect(config('queue.connections.database.retry_after'))->toBeGreaterThan((new SyncJobRunner(1))->timeout);
});

it('reports a healthy installation', function () {
    User::factory()->create();
    Cache::forever(DispatchDueSyncJobs::HEARTBEAT_KEY, now()->toIso8601String());

    $this->artisan('calsync:doctor')
        ->expectsOutputToContain('Tutto a posto.')
        ->assertSuccessful();
});

it('reports a missing scheduler, admin and stuck queue', function () {
    SyncRun::factory()->for(SyncJob::factory())->create(['created_at' => now()->subMinutes(10)]);

    $this->artisan('calsync:doctor')
        ->expectsOutputToContain('Scheduler attivo (mai eseguito)')
        ->expectsOutputToContain('1 run in coda da più di 2 minuti')
        ->expectsOutputToContain('controlli non superati')
        ->assertFailed();
});

it('requires a production-grade configuration in production', function () {
    User::factory()->create();
    Cache::forever(DispatchDueSyncJobs::HEARTBEAT_KEY, now()->toIso8601String());
    app()->detectEnvironment(fn () => 'production');
    config(['app.debug' => true, 'app.url' => 'http://calsync.lan', 'session.secure' => false]);

    $this->artisan('calsync:doctor')
        ->expectsOutputToContain('APP_DEBUG disattivato')
        ->assertFailed();
});
