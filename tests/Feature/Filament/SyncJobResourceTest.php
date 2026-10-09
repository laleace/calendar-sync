<?php

use App\Enums\FilterField;
use App\Enums\PrivacyMode;
use App\Enums\SyncRunStatus;
use App\Enums\TextOperator;
use App\Filament\Resources\SyncJobs\Pages\CreateSyncJob;
use App\Filament\Resources\SyncJobs\Pages\DryRunSyncJob;
use App\Filament\Resources\SyncJobs\Pages\EditSyncJob;
use App\Filament\Resources\SyncJobs\Pages\ListSyncJobs;
use App\Filament\Resources\SyncJobs\SyncJobResource;
use App\Models\Account;
use App\Models\Calendar;
use App\Models\SyncJob;
use App\Models\SyncRun;
use App\Models\User;
use Carbon\CarbonImmutable;
use Filament\Actions\Testing\TestAction;
use Filament\Forms\Components\Repeater;
use Livewire\Livewire;
use Tests\Support\InMemoryCalendarProvider as Fake;

beforeEach(function () {
    $this->actingAs(User::factory()->create());
    $this->travelTo(CarbonImmutable::parse('2026-10-08 10:00', 'Europe/Rome'));

    $icloud = Account::factory()->create(['name' => 'iCloud']);
    $google = Account::factory()->google()->create(['name' => 'Google']);
    $this->home = Calendar::factory()->for($icloud)->create(['name' => 'Casa', 'remote_id' => 'icloud/casa']);
    $this->work = Calendar::factory()->for($icloud)->create(['name' => 'Lavoro', 'remote_id' => 'icloud/lavoro']);
    $this->target = Calendar::factory()->for($google)->create(['name' => 'Agenda', 'remote_id' => 'google/agenda']);
    $this->holidays = Calendar::factory()->for($google)->readOnly()->create(['name' => 'Festività']);
});

it('lists the jobs with sources and target', function () {
    $job = SyncJob::factory()->create(['name' => 'iCloud → Google', 'target_calendar_id' => $this->target->id]);
    $job->syncSources([$this->home->id, $this->work->id]);

    Livewire::test(ListSyncJobs::class)
        ->assertCanSeeTableRecords([$job])
        ->assertSee('Casa')
        ->assertSee('Lavoro')
        ->assertSee('Agenda')
        ->assertActionHasUrl(TestAction::make('dryRun')->table($job), SyncJobResource::getUrl('dry-run', ['record' => $job]));
});

it('creates a job keeping the source order as priority', function () {
    Livewire::test(CreateSyncJob::class)
        ->fillForm([
            'name' => 'iCloud → Google',
            'source_ids' => [$this->work->id, $this->home->id],
            'target_calendar_id' => $this->target->id,
            'privacy_mode' => PrivacyMode::BusyOnly->value,
            'busy_title' => 'Impegnato',
            'title_prefix' => '[Ale]',
            'range_past_days' => 3,
            'range_future_days' => 90,
            'interval_minutes' => 30,
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $job = SyncJob::sole();

    expect($job->sources()->pluck('calendars.id')->all())->toBe([$this->work->id, $this->home->id])
        ->and($job->target_calendar_id)->toBe($this->target->id)
        ->and($job->privacy_mode)->toBe(PrivacyMode::BusyOnly)
        ->and($job->busy_title)->toBe('Impegnato')
        ->and($job->title_prefix)->toBe('[Ale]')
        ->and($job->range_past_days)->toBe(3)
        ->and($job->range_future_days)->toBe(90)
        ->and($job->interval_minutes)->toBe(30)
        ->and($job->enabled)->toBeFalse();
});

it('validates the job form', function (Closure $data, array $errors) {
    Livewire::test(CreateSyncJob::class)
        ->fillForm(['name' => 'Job', ...$data($this)])
        ->call('create')
        ->assertHasFormErrors($errors);

    expect(SyncJob::count())->toBe(0);
})->with([
    'no source' => [fn ($t) => ['source_ids' => [], 'target_calendar_id' => $t->target->id], ['source_ids' => 'required']],
    'target among sources' => [fn ($t) => ['source_ids' => [$t->home->id], 'target_calendar_id' => $t->home->id], ['target_calendar_id']],
    'read-only target' => [fn ($t) => ['source_ids' => [$t->home->id], 'target_calendar_id' => $t->holidays->id], ['target_calendar_id']],
    'range too long' => [fn ($t) => ['source_ids' => [$t->home->id], 'target_calendar_id' => $t->target->id, 'range_future_days' => 1000], ['range_future_days']],
]);

it('saves the job filters', function () {
    $undoRepeaterFake = Repeater::fake();

    Livewire::test(CreateSyncJob::class)
        ->fillForm([
            'name' => 'Solo lavoro',
            'source_ids' => [$this->home->id],
            'target_calendar_id' => $this->target->id,
            'filters' => [
                'include' => [
                    ['field' => 'title', 'operator' => 'starts_with', 'value' => '[Lavoro]'],
                    ['field' => 'weekday', 'weekdays' => [1, 2, 3, 4, 5]],
                ],
                'include_mode' => 'all',
                'strip_prefix' => true,
                'exclude' => [
                    ['field' => 'start_time', 'from' => '22:00', 'to' => '06:00'],
                    ['field' => 'free'],
                ],
            ],
        ])
        ->assertFormFieldVisible('filters.include_mode')
        ->assertFormFieldVisible('filters.strip_prefix')
        ->call('create')
        ->assertHasNoFormErrors();

    $undoRepeaterFake();
    $job = SyncJob::sole();
    $filters = $job->eventFilters();

    // Only the inputs shown for each rule are stored.
    expect($job->filters['include'][0])->toBe(['field' => 'title', 'operator' => 'starts_with', 'value' => '[Lavoro]'])
        ->and($filters->matchAll)->toBeTrue()
        ->and($filters->stripPrefix)->toBeTrue()
        ->and(array_map(fn ($rule) => $rule->describe(), $filters->include))->toBe(['Titolo inizia con «[Lavoro]»', 'Giorno: lun, mar, mer, gio, ven'])
        ->and(array_map(fn ($rule) => $rule->describe(), $filters->exclude))->toBe(['Inizia tra 22:00 e 06:00', 'Segnato come libero']);
});

it('validates the filter rules', function (array $rule, string $field) {
    $undoRepeaterFake = Repeater::fake();

    Livewire::test(CreateSyncJob::class)
        ->fillForm([
            'name' => 'Job',
            'source_ids' => [$this->home->id],
            'target_calendar_id' => $this->target->id,
            'filters' => ['exclude' => [$rule]],
        ])
        ->call('create')
        ->assertHasFormErrors(["filters.exclude.0.{$field}"]);

    $undoRepeaterFake();

    expect(SyncJob::count())->toBe(0);
})->with([
    'no field' => [['field' => null], 'field'],
    'empty text' => [['field' => 'location', 'operator' => 'contains', 'value' => ''], 'value'],
    'invalid regex' => [['field' => 'title', 'operator' => 'regex', 'value' => '(unclosed'], 'value'],
    'no weekday' => [['field' => 'weekday', 'weekdays' => []], 'weekdays'],
    'empty time range' => [['field' => 'start_time', 'from' => '09:00', 'to' => '09:00'], 'to'],
]);

it('offers to strip the prefix only with a "starts with" title rule', function () {
    $undoRepeaterFake = Repeater::fake();

    Livewire::test(CreateSyncJob::class)
        ->assertFormFieldHidden('filters.strip_prefix')
        ->assertFormFieldHidden('filters.include_mode')
        ->fillForm(['filters' => ['include' => [['field' => 'title', 'operator' => 'contains', 'value' => 'x']]]])
        ->assertFormFieldHidden('filters.strip_prefix')
        ->fillForm(['filters' => ['include' => [['field' => 'title', 'operator' => 'starts_with', 'value' => 'x']]]])
        ->assertFormFieldVisible('filters.strip_prefix');

    $undoRepeaterFake();
});

it('edits the filters and counts them in the list', function () {
    $job = SyncJob::factory()->create(['target_calendar_id' => $this->target->id, 'filters' => [
        'include' => [['field' => 'title', 'operator' => 'contains', 'value' => 'Lavoro']],
        'exclude' => [['field' => 'all_day']],
    ]]);
    $job->syncSources([$this->home->id]);

    Livewire::test(ListSyncJobs::class)->assertSee('2 regole');

    $undoRepeaterFake = Repeater::fake();

    Livewire::test(EditSyncJob::class, ['record' => $job->id])
        ->assertFormSet([
            'filters.include.0.field' => FilterField::Title,
            'filters.include.0.operator' => TextOperator::Contains,
            'filters.include.0.value' => 'Lavoro',
            'filters.exclude.0.field' => FilterField::AllDay,
        ])
        ->fillForm(['filters' => ['include' => [['field' => 'title', 'operator' => 'contains', 'value' => 'Lavoro']], 'exclude' => []]])
        ->call('save')
        ->assertHasNoFormErrors();

    $undoRepeaterFake();

    expect($job->fresh()->eventFilters()->count())->toBe(1);
});

it('only asks for the busy title in busy-only mode', function () {
    Livewire::test(CreateSyncJob::class)
        ->fillForm(['privacy_mode' => PrivacyMode::Full->value])
        ->assertFormFieldHidden('busy_title')
        ->fillForm(['privacy_mode' => PrivacyMode::BusyOnly->value])
        ->assertFormFieldVisible('busy_title');
});

it('tells apart calendars with the same name', function () {
    $google = $this->target->account;
    $a = Calendar::factory()->for($google)->create(['name' => 'DMA Clima', 'timezone' => 'UTC']);
    $b = Calendar::factory()->for($google)->create(['name' => 'DMA Clima', 'timezone' => 'Europe/Rome']);

    Livewire::test(CreateSyncJob::class)
        ->assertFormFieldExists('source_ids', function ($field) use ($a, $b): bool {
            $google = $field->getOptions()['Google'];

            return $google[$a->id] === "DMA Clima (UTC, #{$a->id})"
                && $google[$b->id] === "DMA Clima (Europe/Rome, #{$b->id})"
                && $google[$this->target->id] === 'Agenda';
        });
});

it('edits the sources of a job', function () {
    $job = SyncJob::factory()->create(['target_calendar_id' => $this->target->id]);
    $job->syncSources([$this->home->id, $this->work->id]);

    Livewire::test(EditSyncJob::class, ['record' => $job->id])
        ->assertFormSet(['source_ids' => [$this->home->id, $this->work->id]])
        ->fillForm(['source_ids' => [$this->work->id]])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($job->sources()->pluck('calendars.id')->all())->toBe([$this->work->id]);
});

it('shows what a sync would do without writing anything', function () {
    $fake = Fake::install();
    $fake->seed($this->home, Fake::event('a', 'Cena con Luca', '2026-10-14 18:30', '2026-10-14 20:00'));
    $fake->seed($this->home, Fake::event('b', 'Rifiutato', '2026-10-15 09:00', '2026-10-15 10:00', ['declined' => true]));

    $job = SyncJob::factory()->create(['name' => 'iCloud → Google', 'target_calendar_id' => $this->target->id]);
    $job->syncSources([$this->home->id]);

    $this->get(SyncJobResource::getUrl('dry-run', ['record' => $job]))
        ->assertOk()
        ->assertSee('Dry-run: iCloud → Google')
        ->assertSee('Da creare: 1')
        ->assertSee('Saltati: 1')
        ->assertSee('Cena con Luca')
        ->assertSee('14/10/2026 18:30 → 20:00')
        ->assertSee('invito rifiutato')
        ->assertSee('Nessuna modifica è stata fatta ai calendari.');

    expect($fake->writes)->toBeEmpty()
        ->and(SyncRun::sole())
        ->dry_run->toBeTrue()
        ->status->toBe(SyncRunStatus::Success);
});

it('filters the planned operations and repeats the dry-run', function () {
    $fake = Fake::install();
    $fake->seed($this->home, Fake::event('a', 'Cena con Luca', '2026-10-14 18:30', '2026-10-14 20:00'));
    $fake->seed($this->home, Fake::event('b', 'Rifiutato', '2026-10-15 09:00', '2026-10-15 10:00', ['declined' => true]));
    $job = SyncJob::factory()->create(['target_calendar_id' => $this->target->id]);
    $job->syncSources([$this->home->id]);

    Livewire::test(DryRunSyncJob::class, ['record' => $job->id])
        ->assertCountTableRecords(2)
        ->filterTable('op', 'skip')
        ->assertCountTableRecords(1)
        ->callAction('repeat');

    expect(SyncRun::count())->toBe(2);
});

it('explains a dry-run that cannot run', function () {
    Fake::install();
    $this->target->update(['can_write' => false]);
    $job = SyncJob::factory()->create(['target_calendar_id' => $this->target->id]);
    $job->syncSources([$this->home->id]);

    $this->get(SyncJobResource::getUrl('dry-run', ['record' => $job]))
        ->assertOk()
        ->assertSee('Dry-run non riuscito')
        ->assertSee('sola lettura');
});
