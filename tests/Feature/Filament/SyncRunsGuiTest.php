<?php

use App\CalSync\Sync\SyncService;
use App\Enums\SyncRunStatus;
use App\Enums\SyncTrigger;
use App\Filament\Resources\SyncJobs\Pages\DryRunSyncJob;
use App\Filament\Resources\SyncJobs\Pages\EditSyncJob;
use App\Filament\Resources\SyncJobs\Pages\ListSyncJobs;
use App\Filament\Resources\SyncJobs\RelationManagers\RunsRelationManager;
use App\Filament\Resources\SyncRuns\Pages\ListSyncRuns;
use App\Filament\Resources\SyncRuns\SyncRunResource;
use App\Filament\Widgets\LatestRuns;
use App\Filament\Widgets\SyncOverview;
use App\Jobs\SyncJobRunner;
use App\Models\Account;
use App\Models\Calendar;
use App\Models\EventMapping;
use App\Models\SyncJob;
use App\Models\SyncRun;
use App\Models\User;
use Carbon\CarbonImmutable;
use Filament\Actions\Testing\TestAction;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\Support\InMemoryCalendarProvider as Fake;

beforeEach(function () {
    $this->actingAs(User::factory()->create());
    $this->travelTo(CarbonImmutable::parse('2026-10-08 10:00', 'Europe/Rome'));
    $this->fake = Fake::install();

    $this->home = Calendar::factory()->for(Account::factory())->create(['name' => 'Casa', 'remote_id' => 'icloud/casa']);
    $this->target = Calendar::factory()->for(Account::factory()->google())->create(['name' => 'Agenda', 'remote_id' => 'google/agenda']);
    $this->job = SyncJob::factory()->create(['name' => 'Casa → Agenda', 'target_calendar_id' => $this->target->id]);
    $this->job->syncSources([$this->home->id]);
});

it('queues a sync from the job list', function () {
    Queue::fake();

    Livewire::test(ListSyncJobs::class)
        ->callAction(TestAction::make('runNow')->table($this->job))
        ->assertNotified('Sync accodato');

    expect($this->job->runs()->sole())
        ->status->toBe(SyncRunStatus::Queued)
        ->triggered_by->toBe(SyncTrigger::Manual)
        ->dry_run->toBeFalse();
    Queue::assertPushedOn('sync', SyncJobRunner::class);
});

it('does not queue a second sync while one is pending', function () {
    SyncRun::factory()->for($this->job)->create();

    Livewire::test(EditSyncJob::class, ['record' => $this->job->id])
        ->assertActionDisabled('runNow');
});

it('runs the sync for real from the dry-run page', function () {
    $this->fake->seed($this->home, Fake::event('a', 'Cena', '2026-10-14 20:00', '2026-10-14 22:00'));

    Livewire::test(DryRunSyncJob::class, ['record' => $this->job->id])
        ->callAction('runNow')
        ->assertNotified('Sync accodato');

    // The test queue is synchronous: the run already happened.
    expect($this->fake->eventsOn($this->target))->toHaveCount(1)
        ->and($this->job->runs()->where('dry_run', false)->sole()->status)->toBe(SyncRunStatus::Success);
});

it('removes the copies of a job after confirmation', function () {
    $this->fake->seed($this->home, Fake::event('a', 'Cena', '2026-10-14 20:00', '2026-10-14 22:00'));
    app(SyncService::class)->run($this->job);
    $this->job->update(['enabled' => true]);

    Livewire::test(EditSyncJob::class, ['record' => $this->job->id])
        ->assertActionVisible('purgeCopies')
        ->callAction('purgeCopies')
        ->assertNotified('Rimozione delle copie accodata');

    expect($this->fake->eventsOn($this->target))->toBeEmpty()
        ->and(EventMapping::count())->toBe(0)
        ->and($this->job->fresh()->enabled)->toBeFalse();
});

it('hides the copy removal when the job has no copies', function () {
    Livewire::test(EditSyncJob::class, ['record' => $this->job->id])
        ->assertActionHidden('purgeCopies');
});

it('lists the runs and filters them by kind', function () {
    $sync = SyncRun::factory()->for($this->job)->finished()->create(['created_count' => 3]);
    $dryRun = SyncRun::factory()->for($this->job)->finished()->create(['dry_run' => true]);
    $purge = SyncRun::factory()->for($this->job)->finished()->create(['purge' => true]);

    Livewire::test(ListSyncRuns::class)
        ->assertCanSeeTableRecords([$sync, $dryRun, $purge])
        ->assertSee('+3')
        ->filterTable('kind', 'dry_run')
        ->assertCanSeeTableRecords([$dryRun])
        ->assertCanNotSeeTableRecords([$sync, $purge]);
});

it('shows the details of a run with its errors and plan', function () {
    $run = SyncRun::factory()->for($this->job)->finished(SyncRunStatus::Partial)->create([
        'dry_run' => true,
        'error_count' => 1,
        'errors' => [['op' => 'read', 'source' => 'Casa', 'uid' => null, 'title' => null, 'message' => 'Evento rotto non leggibile']],
        'plan' => [['op' => 'create', 'title' => 'Cena con Luca', 'start' => '2026-10-14T18:30:00+02:00', 'end' => '2026-10-14T20:00:00+02:00', 'all_day' => false, 'source' => 'Casa', 'reason' => null]],
        'message' => '0 copie già allineate.',
    ]);

    $this->get(SyncRunResource::getUrl('view', ['record' => $run]))
        ->assertOk()
        ->assertSee('Casa → Agenda')
        ->assertSee('Completato con errori')
        ->assertSee('Evento rotto non leggibile')
        ->assertSee('Cena con Luca')
        ->assertSee('14/10/2026 18:30 → 20:00')
        ->assertSee('Dry-run: operazioni previste, nessuna modifica eseguita.');
});

it('shows the run history of a job', function () {
    $runs = SyncRun::factory()->for($this->job)->count(2)->finished()->create();
    $other = SyncRun::factory()->finished()->create();

    Livewire::test(RunsRelationManager::class, ['ownerRecord' => $this->job, 'pageClass' => EditSyncJob::class])
        ->assertCanSeeTableRecords($runs)
        ->assertCanNotSeeTableRecords([$other]);
});

it('summarises the sync activity on the dashboard', function () {
    $failed = SyncRun::factory()->for($this->job)->finished(SyncRunStatus::Failed)->create();

    $this->get('/admin')->assertOk();

    // Dashboard widgets load lazily: test them directly.
    Livewire::test(SyncOverview::class)
        ->assertSee('Job attivi')
        ->assertSee('Sync nelle ultime 24 ore')
        ->assertSee('1 con errori');

    Livewire::test(LatestRuns::class)
        ->assertSee('Ultimi run')
        ->assertCanSeeTableRecords([$failed]);
});

it('warns when queued runs are not being processed', function () {
    SyncRun::factory()->for($this->job)->create(['created_at' => now()->subMinutes(5)]);

    $this->get('/admin')->assertSee('1 sync in coda da più di 2 minuti');
});

it('does not warn about the queue for fresh runs', function () {
    SyncRun::factory()->for($this->job)->create();

    $this->get('/admin')->assertDontSee('sync in coda da più di');
});
