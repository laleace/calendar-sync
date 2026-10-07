<?php

use App\CalSync\CalDav\CalDavProvider;
use App\CalSync\Dto\CalendarDto;
use App\CalSync\Exceptions\AuthenticationFailed;
use App\CalSync\Exceptions\ProviderException;
use App\Enums\AccountStatus;
use App\Models\Account;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Support\FakeCalDav;

function icloudAccount(): Account
{
    return Account::factory()->create([
        'credentials' => ['username' => 'mario@icloud.com', 'password' => 'abcd-efgh-ijkl-mnop'],
    ]);
}

function nextcloudAccount(): Account
{
    return Account::factory()->caldavGeneric('https://dav.example.test/')->create([
        'credentials' => ['username' => 'mario', 'password' => 'nc-app-password'],
    ]);
}

it('discovers iCloud calendars on the shard host', function () {
    FakeCalDav::icloud()->install();

    $result = app(CalDavProvider::class)->discoverCalendars(icloudAccount());

    expect($result->principalUrl)->toBe('https://caldav.icloud.com/123456789/principal/')
        ->and($result->calendarHomeUrl)->toBe('https://p42-caldav.icloud.com/123456789/calendars/')
        ->and(array_map(fn (CalendarDto $c) => $c->name, $result->calendars))
        ->toBe(['Casa', 'Lavoro & Ufficio', 'Famiglia (condiviso)', 'Compleanni amici']);

    [$home, $work, $shared, $birthdays] = $result->calendars;

    expect($home)->toEqual(new CalendarDto(
        remoteId: 'https://p42-caldav.icloud.com/123456789/calendars/home/',
        name: 'Casa',
        canWrite: true,
        color: '#FF2968',
        timezone: 'Europe/Rome',
    ))
        ->and($work->canWrite)->toBeTrue()
        ->and($work->color)->toBe('#1BADF8')
        ->and($work->timezone)->toBeNull()
        ->and($shared->canWrite)->toBeFalse()
        ->and($shared->color)->toBeNull()
        ->and($birthdays->remoteId)->toBe('https://p42-caldav.icloud.com/123456789/calendars/Compleanni%20amici/')
        ->and($birthdays->canWrite)->toBeTrue();
});

it('sends PROPFIND requests with Basic auth, Depth and an XML body', function () {
    FakeCalDav::icloud()->install();

    app(CalDavProvider::class)->discoverCalendars(icloudAccount());

    $basic = 'Basic '.base64_encode('mario@icloud.com:abcd-efgh-ijkl-mnop');

    Http::assertSentCount(3);
    Http::assertSent(fn (Request $r) => $r->method() === 'PROPFIND'
        && $r->url() === 'https://caldav.icloud.com/'
        && $r->header('Depth') === ['0']
        && $r->header('Authorization') === [$basic]
        && str_contains($r->body(), '<d:current-user-principal/>'));
    Http::assertSent(fn (Request $r) => $r->url() === 'https://p42-caldav.icloud.com/123456789/calendars/'
        && $r->header('Depth') === ['1']
        && str_contains($r->header('Content-Type')[0], 'application/xml')
        && str_contains($r->body(), '<c:supported-calendar-component-set/>'));
});

it('discovers a generic server through .well-known, keeping PROPFIND across the redirect', function () {
    FakeCalDav::nextcloud()->install();

    $result = app(CalDavProvider::class)->discoverCalendars(nextcloudAccount());

    expect($result->principalUrl)->toBe('https://dav.example.test/remote.php/dav/principals/users/mario/')
        ->and($result->calendarHomeUrl)->toBe('https://dav.example.test/remote.php/dav/calendars/mario/')
        ->and(array_map(fn (CalendarDto $c) => [$c->name, $c->canWrite, $c->color], $result->calendars))->toBe([
            ['Personale', true, '#0082C9'],
            ['Compleanni dei contatti', false, '#FFFFCA'],
        ]);

    Http::assertSent(fn (Request $r) => $r->url() === 'https://dav.example.test/remote.php/dav/'
        && $r->method() === 'PROPFIND'
        && $r->header('Depth') === ['0']);
    Http::assertNotSent(fn (Request $r) => $r->method() !== 'PROPFIND');
});

it('reports rejected credentials', function () {
    (new FakeCalDav)->status('PROPFIND', 'https://caldav.icloud.com/', 401)->install();

    $result = app(CalDavProvider::class)->testConnection(icloudAccount());

    expect($result->successful())->toBeFalse()
        ->and($result->status)->toBe(AccountStatus::AuthFailed)
        ->and($result->message)->toContain('HTTP 401');

    // Authentication failures are not retried on .well-known.
    Http::assertSentCount(1);
});

it('confirms a working connection with the principal', function () {
    FakeCalDav::icloud()->install();

    $result = app(CalDavProvider::class)->testConnection(icloudAccount());

    expect($result->successful())->toBeTrue()
        ->and($result->message)->toContain('https://caldav.icloud.com/123456789/principal/');
});

it('never follows a redirect to another host', function () {
    (new FakeCalDav)
        ->redirect('PROPFIND', 'https://dav.example.test/', 'https://evil.test/steal', 302)
        ->redirect('PROPFIND', 'https://dav.example.test/.well-known/caldav', 'https://evil.test/steal', 302)
        ->install();

    expect(fn () => app(CalDavProvider::class)->discoverCalendars(nextcloudAccount()))
        ->toThrow(ProviderException::class, 'evil.test');

    Http::assertNotSent(fn (Request $r) => str_contains($r->url(), 'evil.test'));
});

it('never sends iCloud credentials to a calendar home outside icloud.com', function () {
    (new FakeCalDav)
        ->propfind('https://caldav.icloud.com/', 0, 'icloud/principal.xml')
        ->on('PROPFIND', 'https://caldav.icloud.com/123456789/principal/', fn () => Http::response(
            str_replace('p42-caldav.icloud.com:443', 'icloud.com.evil.test', FakeCalDav::fixture('icloud/calendar-home-set.xml')),
            207,
        ))
        ->install();

    expect(fn () => app(CalDavProvider::class)->discoverCalendars(icloudAccount()))
        ->toThrow(ProviderException::class, 'non consentito');

    Http::assertNotSent(fn (Request $r) => str_contains($r->url(), 'evil.test'));
});

it('refuses to downgrade HTTPS to HTTP', function () {
    (new FakeCalDav)
        ->redirect('PROPFIND', 'https://dav.example.test/', 'http://dav.example.test/dav/')
        ->redirect('PROPFIND', 'https://dav.example.test/.well-known/caldav', 'http://dav.example.test/dav/')
        ->install();

    expect(fn () => app(CalDavProvider::class)->discoverCalendars(nextcloudAccount()))
        ->toThrow(ProviderException::class);

    Http::assertNotSent(fn (Request $r) => str_starts_with($r->url(), 'http://'));
});

it('gives up after too many redirects', function () {
    (new FakeCalDav)
        ->redirect('PROPFIND', 'https://dav.example.test/', '/a')
        ->redirect('PROPFIND', 'https://dav.example.test/a', '/')
        ->redirect('PROPFIND', 'https://dav.example.test/.well-known/caldav', '/a')
        ->install();

    expect(fn () => app(CalDavProvider::class)->discoverCalendars(nextcloudAccount()))
        ->toThrow(ProviderException::class, 'Troppi redirect');
});

it('fails without credentials and without sending anything', function () {
    $account = Account::factory()->create(['credentials' => ['username' => 'mario@icloud.com']]);

    expect(fn () => app(CalDavProvider::class)->discoverCalendars($account))
        ->toThrow(AuthenticationFailed::class);

    Http::assertNothingSent();
});

it('reports connection errors without leaking the password', function () {
    Http::fake(['*' => Http::failedConnection('cURL error 6: Could not resolve host: caldav.icloud.com')]);

    try {
        app(CalDavProvider::class)->discoverCalendars(icloudAccount());
        $this->fail('Expected a ProviderException');
    } catch (ProviderException $e) {
        expect($e->getMessage())->toContain('Connessione a caldav.icloud.com non riuscita')
            ->not->toContain('abcd-efgh-ijkl-mnop');
    }

    // Network failures are not retried on .well-known.
    Http::assertSentCount(1);
});

it('reports a server that does not expose a principal', function () {
    (new FakeCalDav)
        ->status('PROPFIND', 'https://dav.example.test/', 405)
        ->status('PROPFIND', 'https://dav.example.test/.well-known/caldav', 404)
        ->install();

    expect(fn () => app(CalDavProvider::class)->discoverCalendars(nextcloudAccount()))
        ->toThrow(ProviderException::class, 'current-user-principal');
});
