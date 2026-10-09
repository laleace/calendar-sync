<?php

use App\Filament\Resources\Accounts\Pages\CreateAccount;
use App\Filament\Resources\Accounts\Pages\EditAccount;
use App\Filament\Resources\Accounts\Pages\ListAccounts;
use App\Filament\Resources\Accounts\Pages\ViewAccount;
use App\Filament\Resources\Groups\Pages\ManageGroups;
use App\Filament\Resources\SyncJobs\Pages\CreateSyncJob;
use App\Filament\Resources\SyncJobs\Pages\EditSyncJob;
use App\Filament\Resources\SyncJobs\Pages\ListSyncJobs;
use App\Filament\Resources\SyncRuns\Pages\ListSyncRuns;
use App\Filament\Support\GroupFields;
use App\Models\Account;
use App\Models\Calendar;
use App\Models\Group;
use App\Models\SyncJob;
use App\Models\SyncRun;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Livewire\Livewire;

beforeEach(function () {
    $this->actingAs(User::factory()->create());

    $this->personal = Group::factory()->create(['name' => 'Personale']);
    $this->client = Group::factory()->create(['name' => 'Utente X']);

    $this->myIcloud = Account::factory()->create(['name' => 'iCloud', 'group_id' => $this->personal->id]);
    $this->myGoogle = Account::factory()->google()->create(['name' => 'Google', 'group_id' => $this->personal->id]);
    $this->clientGoogle = Account::factory()->google()->create(['name' => 'Google X', 'group_id' => $this->client->id]);
    $this->ungrouped = Account::factory()->create(['name' => 'Vecchio']);

    $this->home = Calendar::factory()->for($this->myIcloud)->create(['name' => 'Casa']);
    $this->agenda = Calendar::factory()->for($this->myGoogle)->create(['name' => 'Agenda']);
    $this->clientAgenda = Calendar::factory()->for($this->clientGoogle)->create(['name' => 'Agenda X']);
});

it('creates, renames and lists the groups with what they contain', function () {
    SyncJob::factory()->create(['target_calendar_id' => $this->agenda->id, 'group_id' => $this->personal->id]);

    Livewire::test(ManageGroups::class)
        ->assertCanSeeTableRecords([$this->personal, $this->client])
        ->assertTableColumnStateSet('accounts_count', 2, $this->personal)
        ->assertTableColumnStateSet('sync_jobs_count', 1, $this->personal)
        ->callAction('create', data: ['name' => 'Utente Y'])
        ->assertHasNoFormErrors()
        ->callAction(TestAction::make('edit')->table($this->client), data: ['name' => 'Rossi'])
        ->assertHasNoFormErrors();

    Livewire::test(ManageGroups::class)
        ->callAction('create', data: ['name' => 'personale'])
        ->assertHasFormErrors(['name']);

    // Renaming a group to itself is fine.
    Livewire::test(ManageGroups::class)
        ->callAction(TestAction::make('edit')->table($this->personal), data: ['name' => 'PERSONALE'])
        ->assertHasNoFormErrors();

    expect(Group::orderBy('name')->pluck('name')->all())->toBe(['PERSONALE', 'Rossi', 'Utente Y']);
});

it('leaves accounts and jobs without a group when the group is deleted', function () {
    $job = SyncJob::factory()->create(['target_calendar_id' => $this->clientAgenda->id, 'group_id' => $this->client->id]);

    Livewire::test(ManageGroups::class)
        ->callAction(TestAction::make('delete')->table($this->client));

    expect(Group::find($this->client->id))->toBeNull()
        ->and($this->clientGoogle->fresh()->group_id)->toBeNull()
        ->and($job->fresh())->not->toBeNull()
        ->group_id->toBeNull();
});

it('links the group counts to the filtered lists', function () {
    Livewire::withQueryParams(['filters' => ['group' => ['value' => $this->personal->id]]])
        ->test(ListAccounts::class)
        ->assertCanSeeTableRecords([$this->myIcloud, $this->myGoogle])
        ->assertCanNotSeeTableRecords([$this->clientGoogle, $this->ungrouped]);
});

it('filters the accounts by group, or without one', function () {
    Livewire::test(ListAccounts::class)
        ->assertSee('Personale')
        ->filterTable('group', $this->client->id)
        ->assertCanSeeTableRecords([$this->clientGoogle])
        ->assertCanNotSeeTableRecords([$this->myIcloud, $this->myGoogle, $this->ungrouped])
        ->filterTable('group', GroupFields::NONE)
        ->assertCanSeeTableRecords([$this->ungrouped])
        ->assertCanNotSeeTableRecords([$this->myIcloud, $this->clientGoogle]);
});

it('assigns a group to several accounts at once', function () {
    $credentials = $this->ungrouped->credentials;

    Livewire::test(ListAccounts::class)
        ->callTableBulkAction('assignGroup', [$this->ungrouped, $this->clientGoogle], data: ['group_id' => $this->personal->id])
        ->assertNotified('Gruppo assegnato');

    expect($this->ungrouped->fresh())
        ->group_id->toBe($this->personal->id)
        ->credentials->toBe($credentials)
        ->and($this->clientGoogle->fresh()->group_id)->toBe($this->personal->id);

    Livewire::test(ListAccounts::class)
        ->callTableBulkAction('assignGroup', [$this->ungrouped], data: ['group_id' => null]);

    expect($this->ungrouped->fresh()->group_id)->toBeNull();
});

it('sets the group of an account in its form', function () {
    configureGoogleOAuth();

    Livewire::test(CreateAccount::class)
        ->fillForm(['type' => 'google', 'name' => 'Google Y', 'group_id' => $this->client->id])
        ->call('create')
        ->assertHasNoFormErrors();

    Livewire::test(EditAccount::class, ['record' => $this->ungrouped->id])
        ->fillForm(['group_id' => $this->personal->id])
        ->call('save')
        ->assertHasNoFormErrors();

    expect(Account::firstWhere('name', 'Google Y')->group_id)->toBe($this->client->id)
        ->and($this->ungrouped->fresh()->group_id)->toBe($this->personal->id);

    Livewire::test(ViewAccount::class, ['record' => $this->ungrouped->id])
        ->assertSee('Personale');
});

it('creates a group from the account form', function () {
    Livewire::test(EditAccount::class, ['record' => $this->ungrouped->id])
        ->callAction(TestAction::make('createOption')->schemaComponent('group_id'), data: ['name' => 'Utente Z'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($this->ungrouped->fresh()->group->name)->toBe('Utente Z');
});

it('filters the jobs by group and assigns groups in bulk', function () {
    $mine = SyncJob::factory()->create(['name' => 'Mio', 'target_calendar_id' => $this->agenda->id, 'group_id' => $this->personal->id]);
    $theirs = SyncJob::factory()->create(['name' => 'Suo', 'target_calendar_id' => $this->clientAgenda->id]);

    Livewire::test(ListSyncJobs::class)
        ->filterTable('group', $this->personal->id)
        ->assertCanSeeTableRecords([$mine])
        ->assertCanNotSeeTableRecords([$theirs]);

    // The filter is kept in the session.
    Livewire::test(ListSyncJobs::class)
        ->assertCanNotSeeTableRecords([$theirs])
        ->removeTableFilters()
        ->callTableBulkAction('assignGroup', [$mine, $theirs], data: ['group_id' => $this->client->id]);

    expect($mine->fresh()->group_id)->toBe($this->client->id)
        ->and($theirs->fresh()->group_id)->toBe($this->client->id);
});

it('suggests the group shared by every calendar of a job', function () {
    Livewire::test(CreateSyncJob::class)
        ->fillForm(['source_ids' => [$this->home->id]])
        ->assertFormSet(['group_id' => null])
        ->fillForm(['target_calendar_id' => $this->agenda->id])
        ->assertFormSet(['group_id' => $this->personal->id]);

    // Calendars of different groups: no guess.
    Livewire::test(CreateSyncJob::class)
        ->fillForm(['source_ids' => [$this->home->id], 'target_calendar_id' => $this->clientAgenda->id])
        ->assertFormSet(['group_id' => null]);

    // A group chosen by hand is kept.
    Livewire::test(CreateSyncJob::class)
        ->fillForm(['group_id' => $this->client->id, 'source_ids' => [$this->home->id], 'target_calendar_id' => $this->agenda->id])
        ->assertFormSet(['group_id' => $this->client->id]);
});

it('saves the group of a job', function () {
    Livewire::test(CreateSyncJob::class)
        ->fillForm([
            'name' => 'Per X',
            'group_id' => $this->client->id,
            'source_ids' => [$this->home->id],
            'target_calendar_id' => $this->clientAgenda->id,
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $job = SyncJob::sole();

    Livewire::test(EditSyncJob::class, ['record' => $job->id])
        ->assertFormSet(['group_id' => $this->client->id])
        ->fillForm(['group_id' => null])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($job->fresh()->group_id)->toBeNull();
});

it('prefixes the account group in the calendar choices', function () {
    $options = Calendar::groupedOptions(Calendar::with('account.group')->get());

    expect(array_keys($options))->toBe(['Personale · Google', 'Personale · iCloud', 'Utente X · Google X'])
        ->and($options['Utente X · Google X'])->toBe([$this->clientAgenda->id => 'Agenda X']);
});

it('filters the run history by the group of the job', function () {
    $mine = SyncJob::factory()->create(['target_calendar_id' => $this->agenda->id, 'group_id' => $this->personal->id]);
    $theirs = SyncJob::factory()->create(['target_calendar_id' => $this->clientAgenda->id, 'group_id' => $this->client->id]);
    $myRun = SyncRun::factory()->for($mine)->finished()->create();
    $theirRun = SyncRun::factory()->for($theirs)->finished()->create();

    Livewire::test(ListSyncRuns::class)
        ->filterTable('group', $this->client->id)
        ->assertCanSeeTableRecords([$theirRun])
        ->assertCanNotSeeTableRecords([$myRun]);
});
