<?php

use App\CalSync\AccountService;
use App\CalSync\Exceptions\TokenExpired;
use App\CalSync\Google\GoogleProvider;
use App\Enums\AccountStatus;
use App\Filament\Resources\Accounts\AccountResource;
use App\Filament\Resources\Accounts\Pages\EditAccount;
use App\Filament\Resources\Accounts\Pages\ListAccounts;
use App\Models\Account;
use App\Models\User;
use Illuminate\Encryption\Encrypter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\Support\FakeCalDav;

/**
 * Simulates credentials saved by another installation (or before key:generate was run
 * again): encrypted with a key that is not this app's APP_KEY.
 */
function encryptWithAnotherKey(Account $account, array $credentials): Account
{
    $foreign = new Encrypter(Encrypter::generateKey('aes-256-cbc'), 'aes-256-cbc');
    DB::table('accounts')->where('id', $account->id)->update(['credentials' => $foreign->encryptString(json_encode($credentials))]);

    return $account->fresh();
}

beforeEach(function () {
    $this->actingAs(User::factory()->create());
});

it('detects credentials encrypted with another key', function () {
    $account = encryptWithAnotherKey(Account::factory()->create(), ['username' => 'mario@icloud.com', 'password' => 'abcd']);

    expect($account->hasUnreadableCredentials())->toBeTrue()
        ->and($account->safeCredentials())->toBeNull()
        ->and($account->credential('username'))->toBeNull()
        ->and(Account::factory()->create()->hasUnreadableCredentials())->toBeFalse();
});

it('keeps the account pages usable and flags the account', function () {
    $account = encryptWithAnotherKey(Account::factory()->create(['name' => 'iCloud']), ['username' => 'mario@icloud.com', 'password' => 'abcd']);

    Livewire::test(ListAccounts::class)
        ->assertCanSeeTableRecords([$account])
        ->assertSee('Credenziali illeggibili');

    $this->get(AccountResource::getUrl('view', ['record' => $account]))->assertOk()->assertSee('Reinserisci la password');
    $this->get(AccountResource::getUrl('edit', ['record' => $account]))->assertOk();
});

it('asks for the password again and then works', function () {
    FakeCalDavIcloud();
    $account = encryptWithAnotherKey(Account::factory()->create(), ['username' => 'mario@icloud.com', 'password' => 'abcd']);

    Livewire::test(EditAccount::class, ['record' => $account->id])
        ->fillForm(['username' => 'mario@icloud.com'])
        ->call('save')
        ->assertHasErrors(['data.password']);

    Livewire::test(EditAccount::class, ['record' => $account->id])
        ->fillForm(['username' => 'mario@icloud.com', 'password' => 'nuova-app-password'])
        ->call('save')
        ->assertHasNoFormErrors()
        ->assertNotified('Connessione verificata');

    expect($account->fresh())
        ->hasUnreadableCredentials()->toBeFalse()
        ->credentials->toBe(['username' => 'mario@icloud.com', 'password' => 'nuova-app-password']);
});

it('explains the problem on connection tests and syncs instead of failing obscurely', function () {
    $caldav = encryptWithAnotherKey(Account::factory()->create(), ['username' => 'mario@icloud.com', 'password' => 'abcd']);

    $result = app(AccountService::class)->testConnection($caldav);

    expect($result->status)->toBe(AccountStatus::AuthFailed)
        ->and($result->message)->toContain('APP_KEY');
    Http::assertNothingSent();

    configureGoogleOAuth();
    $google = encryptWithAnotherKey(Account::factory()->google()->create(), ['refresh_token' => '1//x']);

    expect(fn () => app(GoogleProvider::class)->discoverCalendars($google))->toThrow(TokenExpired::class, 'Riconnetti');
});

it('lets a Google account be reconnected', function () {
    $google = encryptWithAnotherKey(Account::factory()->google()->create(), ['email' => 'mario@gmail.com', 'refresh_token' => '1//x']);

    $google->mergeCredentials(['email' => 'mario@gmail.com', 'refresh_token' => '1//nuovo', 'access_token' => 'ya29', 'expires_at' => time() + 3600]);
    $google->save();

    expect($google->fresh()->hasUnreadableCredentials())->toBeFalse()
        ->and($google->fresh()->credential('refresh_token'))->toBe('1//nuovo');
});

it('is reported by calsync:doctor', function () {
    encryptWithAnotherKey(Account::factory()->create(['name' => 'iCloud personale']), ['username' => 'x', 'password' => 'y']);

    $this->artisan('calsync:doctor')
        ->expectsOutputToContain('Credenziali illeggibili')
        ->assertFailed();
});

function FakeCalDavIcloud(): void
{
    FakeCalDav::icloud()->install();
}
