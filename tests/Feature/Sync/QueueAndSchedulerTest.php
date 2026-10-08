<?php

use App\CalSync\Sync\SyncService;
use App\Console\Commands\DispatchDueSyncJobs;
use App\Enums\SyncRunStatus;
use App\Enums\SyncTrigger;
use App\Jobs\SyncJobRunner;
use App\Models\Account;
use App\Models\Calendar;
use App\Models\EventMapping;
use App\Models\SyncJob;
use App\Models\SyncRun;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Tests\Support\InMemoryCalendarProvider as Fake;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-08 10:00', 'Europe/Rome'));
    $this->fake = Fake::install();

    $this->home = Calendar::factory()->for(Account::factory())->create(['name' => 'Casa', 'remote_id' => 'icloud/casa']);
    $this->target = Calendar::factory()->for(Account::factory()->google())->create(['name' => 'Agenda', 'remote_id' => 'google/agenda']);
    $this->job = SyncJob::factory()->create(['target_calendar_id' => $this->target->id, 'enabled' => true, 'interval_minutes' => 15]);
    $this->job->syncSources([$this->home->id]);
    $this->fake->seed($this->home, Fake::event('a', 'Cena', '2026-10-14 20:00', '2026-10-14 22:00'));
});

it('queues runs on the sync queue', function () {
    Queue::fake();

    $run = app(SyncService::class)->queue($this->job, SyncTrigger::Manual);

    expect($run->status)->toBe(SyncRunStatus::Queued);
    Queue::assertPushedOn('sync', SyncJobRunner::class, fn (SyncJobRunner $job) => $job->syncRunId === $run->id);
});

it('executes a queued run', function () {
    $run = SyncRun::factory()->for($this->job)->create();

    (new SyncJobRunner($run->id))->handle(app(SyncService::class));

    expect($run->fresh())
        ->status->toBe(SyncRunStatus::Success)
        ->created_count->toBe(1)
        ->started_at->not->toBeNull()
        ->and($this->fake->eventsOn($this->target))->toHaveCount(1)
        ->and(Cache::lock(SyncJobRunner::lockName($this->job->id), 10)->get())->toBeTrue();
});

it('skips a run while another sync of the same job holds the lock', function () {
    $run = SyncRun::factory()->for($this->job)->create();
    Cache::lock(SyncJobRunner::lockName($this->job->id), 900)->get();

    (new SyncJobRunner($run->id))->handle(app(SyncService::class));

    expect($run->fresh())
        ->status->toBe(SyncRunStatus::Skipped)
        ->message->toContain('già in esecuzione')
        ->and($this->fake->writes)->toBeEmpty();
});

it('ignores runs that are no longer queued', function () {
    $run = SyncRun::factory()->for($this->job)->finished()->create();

    (new SyncJobRunner($run->id))->handle(app(SyncService::class));

    expect($this->fake->writes)->toBeEmpty();
});

it('marks a run as failed on unexpected errors, without secrets', function () {
    $this->fake->readFailures['icloud/casa'] = new RuntimeException('boom with password=hunter2-secret');
    $run = SyncRun::factory()->for($this->job)->create();

    (new SyncJobRunner($run->id))->handle(app(SyncService::class));

    expect($run->fresh())
        ->status->toBe(SyncRunStatus::Failed)
        ->message->toContain('Errore inatteso')
        ->message->not->toContain('hunter2-secret');
});

it('marks a run as failed when the queue gives up on it', function () {
    $run = SyncRun::factory()->for($this->job)->create(['status' => SyncRunStatus::Running]);

    (new SyncJobRunner($run->id))->failed(new RuntimeException('Job has timed out'));

    expect($run->fresh()->status)->toBe(SyncRunStatus::Failed);
});

it('queues the enabled jobs that are due', function () {
    Queue::fake();
    $disabled = SyncJob::factory()->create(['target_calendar_id' => $this->target->id, 'enabled' => false]);
    $notDue = SyncJob::factory()->create(['target_calendar_id' => $this->target->id, 'enabled' => true, 'next_run_at' => now()->addMinutes(5)]);

    $this->artisan('calsync:dispatch-due')->assertSuccessful();

    expect($this->job->runs()->sole())
        ->triggered_by->toBe(SyncTrigger::Schedule)
        ->status->toBe(SyncRunStatus::Queued)
        ->and($this->job->fresh()->next_run_at->eq(now()->addMinutes(15)))->toBeTrue()
        ->and($disabled->runs()->count())->toBe(0)
        ->and($notDue->runs()->count())->toBe(0);

    Queue::assertPushedOn('sync', SyncJobRunner::class);
});

it('does not pile up runs while the previous one is pending', function () {
    Queue::fake();
    SyncRun::factory()->for($this->job)->create(['status' => SyncRunStatus::Running, 'started_at' => now()]);

    $this->artisan('calsync:dispatch-due')->assertSuccessful();

    expect($this->job->runs()->count())->toBe(1)
        ->and($this->job->fresh()->next_run_at)->not->toBeNull();
    Queue::assertNothingPushed();
});

it('fails runs left behind by a dead worker', function () {
    Queue::fake();
    $this->job->update(['enabled' => false]);
    $running = SyncRun::factory()->for($this->job)->create(['status' => SyncRunStatus::Running, 'started_at' => now()->subHour()]);
    $queued = SyncRun::factory()->for($this->job)->create(['created_at' => now()->subHours(7)]);
    $recent = SyncRun::factory()->for($this->job)->create();

    $this->artisan('calsync:dispatch-due')->assertSuccessful();

    expect($running->fresh()->status)->toBe(SyncRunStatus::Failed)
        ->and($queued->fresh()->status)->toBe(SyncRunStatus::Failed)
        ->and($queued->fresh()->message)->toContain('worker della coda non è attivo')
        ->and($recent->fresh()->status)->toBe(SyncRunStatus::Queued);
});

it('schedules the dispatcher every minute', function () {
    $event = collect(app(Schedule::class)->events())->first(fn ($event) => str_contains($event->command, 'calsync:dispatch-due'));

    expect($event)->not->toBeNull()
        ->and($event->expression)->toBe('* * * * *')
        ->and($event->withoutOverlapping)->toBeTrue();
});

it('removes every copy of a job and disables it', function () {
    $this->fake->seed($this->home, Fake::event('past', 'Passato', '2026-10-02 09:00', '2026-10-02 10:00'));
    app(SyncService::class)->run($this->job);

    // Two weeks later the first event is outside the window: it must be removed anyway.
    $this->travelTo(CarbonImmutable::parse('2026-10-22 10:00', 'Europe/Rome'));
    $run = app(SyncService::class)->queue($this->job, SyncTrigger::Manual, purge: true);

    expect($run->fresh())
        ->status->toBe(SyncRunStatus::Success)
        ->deleted_count->toBe(2)
        ->message->toBe('Copie rimosse: 2 su 2.')
        ->and($this->fake->eventsOn($this->target))->toBeEmpty()
        ->and($this->fake->eventsOn($this->home))->toHaveCount(2)
        ->and(EventMapping::count())->toBe(0)
        ->and($this->job->fresh()->enabled)->toBeFalse();
});

it('runs a job from the command line', function () {
    $this->artisan('calsync:run', ['job' => $this->job->id, '--dry-run' => true])
        ->expectsOutputToContain('Dry-run del job')
        ->assertSuccessful();

    expect($this->fake->writes)->toBeEmpty();

    $this->artisan('calsync:run', ['job' => $this->job->id])
        ->expectsOutputToContain('Completato')
        ->assertSuccessful();

    expect($this->fake->eventsOn($this->target))->toHaveCount(1);
});

it('refuses to run a job from the command line while it is running', function () {
    Cache::lock(SyncJobRunner::lockName($this->job->id), 900)->get();

    $this->artisan('calsync:run', ['job' => $this->job->id])->assertFailed();
    $this->artisan('calsync:run', ['job' => 999])->assertFailed();

    expect($this->fake->writes)->toBeEmpty();
});

it('records a heartbeat on every scheduler tick', function () {
    Queue::fake();

    $this->artisan('calsync:dispatch-due')->assertSuccessful();

    expect(Cache::get(DispatchDueSyncJobs::HEARTBEAT_KEY))->toBe(now()->toIso8601String());
});
