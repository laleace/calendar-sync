<?php

use App\Enums\AccountStatus;
use App\Enums\AccountType;
use App\Filament\Pages\CalendarPreview;
use App\Filament\Resources\Accounts\AccountResource;
use App\Filament\Resources\Accounts\Pages\CreateAccount;
use App\Filament\Resources\Accounts\Pages\EditAccount;
use App\Filament\Resources\Accounts\Pages\ListAccounts;
use App\Filament\Resources\Accounts\Pages\ViewAccount;
use App\Filament\Resources\Accounts\RelationManagers\CalendarsRelationManager;
use App\Models\Account;
use App\Models\Calendar;
use App\Models\SyncJob;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\Support\FakeCalDav;

beforeEach(function () {
    $this->actingAs(User::factory()->create());
});

it('requires authentication', function () {
    auth()->logout();

    $this->get(AccountResource::getUrl('index'))->assertRedirect('/admin/login');
});

it('lists accounts with their status', function () {
    $accounts = Account::factory()->count(2)->create();
    Account::factory()->create(['name' => 'Rotto', 'status' => AccountStatus::AuthFailed]);

    Livewire::test(ListAccounts::class)
        ->assertCanSeeTableRecords($accounts)
        ->assertSee('Credenziali non valide');
});

it('creates an iCloud account and discovers its calendars right away', function () {
    FakeCalDav::icloud()->install();

    Livewire::test(CreateAccount::class)
        ->fillForm([
            'type' => AccountType::IcloudCaldav->value,
            'name' => 'iCloud personale',
            'username' => 'mario@icloud.com',
            'password' => 'abcd-efgh-ijkl-mnop',
        ])
        ->call('create')
        ->assertHasNoFormErrors()
        ->assertNotified('Calendari aggiornati')
        ->assertRedirect(AccountResource::getUrl('view', ['record' => Account::sole()]));

    $account = Account::sole();

    expect($account->type)->toBe(AccountType::IcloudCaldav)
        ->and($account->base_url)->toBe('https://caldav.icloud.com/')
        ->and($account->credentials)->toBe(['username' => 'mario@icloud.com', 'password' => 'abcd-efgh-ijkl-mnop'])
        ->and($account->status)->toBe(AccountStatus::Ok)
        ->and($account->calendars()->count())->toBe(4)
        ->and(DB::table('accounts')->value('credentials'))->not->toContain('abcd-efgh-ijkl-mnop');
});

it('keeps the account and reports rejected credentials on creation', function () {
    (new FakeCalDav)->status('PROPFIND', 'https://caldav.icloud.com/', 401)->install();

    Livewire::test(CreateAccount::class)
        ->fillForm(['type' => 'icloud_caldav', 'name' => 'iCloud', 'username' => 'mario@icloud.com', 'password' => 'sbagliata'])
        ->call('create')
        ->assertNotified('Scoperta calendari non riuscita');

    expect(Account::sole()->status)->toBe(AccountStatus::AuthFailed);
});

it('validates the account form', function (array $data, array $errors) {
    Livewire::test(CreateAccount::class)
        ->fillForm($data)
        ->call('create')
        ->assertHasFormErrors($errors);

    expect(Account::count())->toBe(0);
    Http::assertNothingSent();
})->with([
    'missing password' => [['type' => 'icloud_caldav', 'name' => 'iCloud', 'username' => 'me@icloud.com'], ['password' => 'required']],
    'generic without url' => [['type' => 'caldav_generic', 'name' => 'Nextcloud', 'username' => 'me', 'password' => 'secret-pass'], ['base_url' => 'required']],
    'generic with bad url' => [['type' => 'caldav_generic', 'name' => 'Nextcloud', 'base_url' => 'not a url', 'username' => 'me', 'password' => 'secret-pass'], ['base_url']],
    'google without OAuth configuration' => [['type' => 'google', 'name' => 'Google'], ['type']],
]);

it('never sends the stored password to the browser', function () {
    $account = Account::factory()->create(['credentials' => ['username' => 'mario@icloud.com', 'password' => 'abcd-efgh-ijkl-mnop']]);
    $ciphertext = DB::table('accounts')->value('credentials');

    foreach (['view', 'edit'] as $page) {
        $this->get(AccountResource::getUrl($page, ['record' => $account]))
            ->assertOk()
            ->assertSee('mario@icloud.com')
            ->assertDontSee('abcd-efgh-ijkl-mnop')
            ->assertDontSee($ciphertext);
    }

    Livewire::test(EditAccount::class, ['record' => $account->id])
        ->assertFormSet(['username' => 'mario@icloud.com', 'password' => null]);
});

it('keeps the stored password when the field is left empty', function () {
    $account = Account::factory()->create(['credentials' => ['username' => 'mario@icloud.com', 'password' => 'abcd-efgh-ijkl-mnop']]);

    Livewire::test(EditAccount::class, ['record' => $account->id])
        ->fillForm(['name' => 'Nuovo nome'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($account->fresh())
        ->name->toBe('Nuovo nome')
        ->credentials->toBe(['username' => 'mario@icloud.com', 'password' => 'abcd-efgh-ijkl-mnop']);

    // Only the name changed: no connection test.
    Http::assertNothingSent();
});

it('updates credentials, tests them and clears the password from the form', function () {
    FakeCalDav::icloud()->install();
    $account = Account::factory()->create(['credentials' => ['username' => 'mario@icloud.com', 'password' => 'vecchia-password'], 'status' => AccountStatus::AuthFailed]);

    Livewire::test(EditAccount::class, ['record' => $account->id])
        ->fillForm(['password' => 'nuova-app-password'])
        ->call('save')
        ->assertHasNoFormErrors()
        ->assertNotified('Connessione verificata')
        ->assertFormSet(['password' => null]);

    expect($account->fresh())
        ->credentials->toBe(['username' => 'mario@icloud.com', 'password' => 'nuova-app-password'])
        ->status->toBe(AccountStatus::Ok);
});

it('does not allow changing the account type', function () {
    $account = Account::factory()->create();

    Livewire::test(EditAccount::class, ['record' => $account->id])
        ->assertFormFieldDisabled('type');
});

it('tests the connection and discovers calendars from the account page', function () {
    FakeCalDav::icloud()->install();
    $account = Account::factory()->create();

    Livewire::test(ViewAccount::class, ['record' => $account->id])
        ->callAction('testConnection')
        ->assertNotified('Connessione riuscita')
        ->callAction('discoverCalendars')
        ->assertNotified('Calendari aggiornati')
        ->assertDispatched('calendars-discovered');

    expect($account->fresh()->status)->toBe(AccountStatus::Ok)
        ->and($account->calendars()->count())->toBe(4);
});

it('reports a failed connection test', function () {
    (new FakeCalDav)->status('PROPFIND', 'https://caldav.icloud.com/', 401)->install();
    $account = Account::factory()->create();

    Livewire::test(ListAccounts::class)
        ->callAction(TestAction::make('testConnection')->table($account))
        ->assertNotified('Connessione non riuscita');

    expect($account->fresh()->status)->toBe(AccountStatus::AuthFailed);
});

it('refuses to delete an account used by a sync job', function () {
    $account = Account::factory()->create();
    SyncJob::factory()->create()->syncSources([Calendar::factory()->for($account)->create()->id]);

    Livewire::test(ViewAccount::class, ['record' => $account->id])
        ->assertActionDisabled('delete');

    expect(Account::find($account->id))->not->toBeNull();
});

it('deletes an unused account with its calendars', function () {
    $account = Account::factory()->has(Calendar::factory()->count(2))->create();

    Livewire::test(ViewAccount::class, ['record' => $account->id])
        ->callAction('delete');

    expect(Account::count())->toBe(0)
        ->and(Calendar::count())->toBe(0);
});

it('lists the account calendars with their state', function () {
    $account = Account::factory()->create();
    $active = Calendar::factory()->for($account)->create(['name' => 'Casa']);
    $missing = Calendar::factory()->for($account)->create(['name' => 'Vecchio', 'missing_since' => now()]);

    Livewire::test(CalendarsRelationManager::class, ['ownerRecord' => $account, 'pageClass' => ViewAccount::class])
        ->assertCanSeeTableRecords([$active, $missing])
        ->assertSee('Non più trovato')
        ->assertActionHasUrl(TestAction::make('preview')->table($active), CalendarPreview::getUrl(['calendar' => $active->id]));
});

it('creates a Google account and sends the user to the consent screen', function () {
    configureGoogleOAuth();

    Livewire::test(CreateAccount::class)
        ->fillForm(['type' => AccountType::Google->value, 'name' => 'Google personale'])
        ->assertFormFieldHidden('username')
        ->assertFormFieldHidden('password')
        ->call('create')
        ->assertHasNoFormErrors()
        ->assertRedirect(route('oauth.google.redirect', Account::sole()));

    expect(Account::sole())
        ->type->toBe(AccountType::Google)
        ->credentials->toBe([])
        ->base_url->toBeNull();

    Http::assertNothingSent();
});

it('offers to connect or reconnect Google accounts only', function () {
    $google = Account::factory()->google()->create();
    $notConnected = Account::factory()->google()->create(['credentials' => []]);

    Livewire::test(ViewAccount::class, ['record' => $google->id])
        ->assertActionVisible('connectGoogle')
        ->assertActionHasLabel('connectGoogle', 'Riconnetti Google')
        ->assertActionHasUrl('connectGoogle', route('oauth.google.redirect', $google));

    Livewire::test(ViewAccount::class, ['record' => $notConnected->id])
        ->assertActionHasLabel('connectGoogle', 'Connetti con Google');

    Livewire::test(ViewAccount::class, ['record' => Account::factory()->create()->id])
        ->assertActionHidden('connectGoogle');
});

it('edits only the name of a Google account', function () {
    $account = Account::factory()->google()->create();
    $credentials = $account->credentials;

    Livewire::test(EditAccount::class, ['record' => $account->id])
        ->assertFormFieldHidden('password')
        ->fillForm(['name' => 'Google lavoro'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($account->fresh())
        ->name->toBe('Google lavoro')
        ->credentials->toBe($credentials);
});
