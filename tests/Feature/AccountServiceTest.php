<?php

use App\CalSync\AccountService;
use App\CalSync\CalDav\CalDavProvider;
use App\CalSync\Contracts\CalendarProvider;
use App\CalSync\Dto\ConnectionResult;
use App\CalSync\Dto\DiscoveryResult;
use App\CalSync\Dto\EventList;
use App\CalSync\Exceptions\AuthenticationFailed;
use App\CalSync\Exceptions\ProviderException;
use App\Enums\AccountStatus;
use App\Models\Account;
use App\Models\Calendar;
use Carbon\CarbonImmutable;
use Tests\Support\FakeCalDav;

it('stores discovered calendars and the discovery endpoints', function () {
    FakeCalDav::icloud()->install();
    $account = Account::factory()->create();

    $summary = app(AccountService::class)->discoverCalendars($account);

    expect($summary->created)->toBe(4)
        ->and($summary->updated)->toBe(0)
        ->and($summary->missing)->toBeEmpty()
        ->and($account->calendars()->pluck('name')->sort()->values()->all())
        ->toBe(['Casa', 'Compleanni amici', 'Famiglia (condiviso)', 'Lavoro & Ufficio']);

    $account->refresh();
    expect($account->status)->toBe(AccountStatus::Ok)
        ->and($account->status_message)->toBe('Trovati 4 calendari.')
        ->and($account->last_checked_at)->not->toBeNull()
        ->and($account->principal_url)->toBe('https://caldav.icloud.com/123456789/principal/')
        ->and($account->calendar_home_url)->toBe('https://p42-caldav.icloud.com/123456789/calendars/');
});

it('updates, flags missing and revives calendars on later discoveries', function () {
    $account = Account::factory()->create();
    $old = Calendar::factory()->for($account)->create(['remote_id' => 'https://p42-caldav.icloud.com/123456789/calendars/old/', 'name' => 'Vecchio']);
    $home = Calendar::factory()->for($account)->create([
        'remote_id' => 'https://p42-caldav.icloud.com/123456789/calendars/home/',
        'name' => 'Casa (vecchio nome)',
        'missing_since' => now()->subDay(),
    ]);

    FakeCalDav::icloud()->install();
    $summary = app(AccountService::class)->discoverCalendars($account);

    expect($summary->created)->toBe(3)
        ->and($summary->updated)->toBe(1)
        ->and($summary->missing->pluck('id')->all())->toBe([$old->id])
        ->and($home->fresh()->name)->toBe('Casa')
        ->and($home->fresh()->missing_since)->toBeNull()
        ->and($old->fresh()->missing_since)->not->toBeNull();

    // A second run changes nothing.
    $again = app(AccountService::class)->discoverCalendars($account);
    expect($again->created + $again->updated)->toBe(0)
        ->and($again->missing)->toBeEmpty();
});

it('keeps calendar ids when iCloud moves the account to another shard', function () {
    $account = Account::factory()->create();
    $home = Calendar::factory()->for($account)->create(['remote_id' => 'https://p07-caldav.icloud.com/123456789/calendars/home/']);

    FakeCalDav::icloud()->install();
    app(AccountService::class)->discoverCalendars($account);

    expect($home->fresh()->remote_id)->toBe('https://p42-caldav.icloud.com/123456789/calendars/home/')
        ->and($home->fresh()->missing_since)->toBeNull()
        ->and($account->calendars()->count())->toBe(4);
});

it('records authentication failures on the account', function () {
    (new FakeCalDav)->status('PROPFIND', 'https://caldav.icloud.com/', 401)->install();
    $account = Account::factory()->create();

    expect(fn () => app(AccountService::class)->discoverCalendars($account))->toThrow(AuthenticationFailed::class);

    expect($account->fresh()->status)->toBe(AccountStatus::AuthFailed)
        ->and($account->fresh()->status_message)->toContain('HTTP 401');
});

it('records the connection test result', function () {
    FakeCalDav::icloud()->install();
    $account = Account::factory()->create(['status' => AccountStatus::AuthFailed]);

    $result = app(AccountService::class)->testConnection($account);

    expect($result->successful())->toBeTrue()
        ->and($account->fresh()->status)->toBe(AccountStatus::Ok);
});

it('reports a Google account when OAuth is not configured', function () {
    $account = Account::factory()->google()->create();

    $result = app(AccountService::class)->testConnection($account);

    expect($result->status)->toBe(AccountStatus::Error)
        ->and($account->fresh()->status_message)->toContain('GOOGLE_CLIENT_ID');
});

it('never stores secrets in the status message', function () {
    $account = Account::factory()->create(['credentials' => ['username' => 'me', 'password' => 'abcd-efgh-ijkl-mnop']]);

    app()->bind(CalDavProvider::class, fn () => new class implements CalendarProvider
    {
        public function testConnection(Account $account): ConnectionResult
        {
            return ConnectionResult::failed(AccountStatus::Error, 'server echoed abcd-efgh-ijkl-mnop and Bearer ya29.leaked');
        }

        public function discoverCalendars(Account $account): DiscoveryResult
        {
            throw new ProviderException('server echoed abcd-efgh-ijkl-mnop');
        }

        public function listEvents(Calendar $calendar, CarbonImmutable $from, CarbonImmutable $to): EventList
        {
            return new EventList;
        }
    });

    app(AccountService::class)->testConnection($account);
    expect($account->fresh()->status_message)->toBe('server echoed *** and Bearer ***');

    expect(fn () => app(AccountService::class)->discoverCalendars($account))->toThrow(ProviderException::class);
    expect($account->fresh()->status_message)->toBe('server echoed ***');
});
