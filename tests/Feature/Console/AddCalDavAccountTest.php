<?php

use App\Enums\AccountType;
use App\Models\Account;
use Illuminate\Support\Facades\DB;

it('creates an iCloud account with encrypted credentials', function () {
    $this->artisan('calsync:add-caldav-account', ['--type' => 'icloud', '--name' => 'iCloud personale', '--username' => 'mario@icloud.com'])
        ->expectsQuestion('Password per app', 'abcd-efgh-ijkl-mnop')
        ->expectsOutputToContain('calsync:test-account 1')
        ->doesntExpectOutputToContain('abcd-efgh-ijkl-mnop')
        ->assertSuccessful();

    $account = Account::sole();

    expect($account->type)->toBe(AccountType::IcloudCaldav)
        ->and($account->base_url)->toBe('https://caldav.icloud.com/')
        ->and($account->credentials)->toBe(['username' => 'mario@icloud.com', 'password' => 'abcd-efgh-ijkl-mnop'])
        ->and(DB::table('accounts')->value('credentials'))->not->toContain('abcd-efgh-ijkl-mnop');
});

it('creates a generic CalDAV account', function () {
    $this->artisan('calsync:add-caldav-account', ['--type' => 'generic', '--url' => 'https://dav.example.test/', '--name' => 'Nextcloud', '--username' => 'mario'])
        ->expectsQuestion('Password', 'nc-app-password')
        ->assertSuccessful();

    expect(Account::sole())
        ->type->toBe(AccountType::CaldavGeneric)
        ->base_url->toBe('https://dav.example.test/');
});

it('warns about plain HTTP servers', function () {
    $this->artisan('calsync:add-caldav-account', ['--type' => 'generic', '--url' => 'http://radicale.lan:5232/', '--name' => 'Radicale', '--username' => 'mario'])
        ->expectsQuestion('Password', 'secret-password')
        ->expectsOutputToContain('http')
        ->assertSuccessful();
});

it('rejects invalid input', function (array $options) {
    $this->artisan('calsync:add-caldav-account', $options)->assertFailed();

    expect(Account::count())->toBe(0);
})->with([
    'unknown type' => [['--type' => 'exchange']],
    'invalid url' => [['--type' => 'generic', '--url' => 'ftp://dav.example.test/']],
]);
