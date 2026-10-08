<?php

use App\CalSync\AccountService;
use App\CalSync\Dto\CalendarDto;
use App\CalSync\Dto\EventDto;
use App\CalSync\Exceptions\EventNotFound;
use App\CalSync\Exceptions\ProviderException;
use App\CalSync\Exceptions\TokenExpired;
use App\CalSync\Google\GoogleProvider;
use App\CalSync\Markers;
use App\Enums\AccountStatus;
use App\Enums\EventStatus;
use App\Models\Account;
use App\Models\Calendar;
use Carbon\CarbonImmutable;
use GuzzleHttp\Psr7\Response;
use Psr\Http\Message\RequestInterface;
use Tests\Support\FakeGoogle;

const WORK_CALENDAR = 'c_8f2a1b3c4d5e6f70@group.calendar.google.com';

beforeEach(function () {
    configureGoogleOAuth();
    $this->google = new FakeGoogle;
    $this->account = Account::factory()->google()->create(['credentials' => [
        'email' => 'mario.rossi@gmail.com',
        'access_token' => 'ya29.current-access-token',
        'refresh_token' => '1//current-refresh-token',
        'expires_at' => now()->addHour()->getTimestamp(),
    ]]);
    $this->calendar = Calendar::factory()->for($this->account)->create(['remote_id' => WORK_CALENDAR, 'timezone' => 'Europe/Rome']);
});

function provider(): GoogleProvider
{
    return app(GoogleProvider::class);
}

function copyOf(array $overrides = []): EventDto
{
    return new EventDto(...array_merge([
        'uid' => 'calsync-copy',
        'recurrenceId' => null,
        'title' => '[iCloud] Cena con Luca',
        'start' => CarbonImmutable::parse('2026-10-14 18:30', 'Europe/Rome'),
        'end' => CarbonImmutable::parse('2026-10-14 20:00', 'Europe/Rome'),
        'allDay' => false,
        'timezone' => 'Europe/Rome',
        'location' => 'Trattoria da Mario',
        'description' => 'Prenotato per 4.',
        'markers' => ['job' => '3', 'source' => '4B7F2C1A-0D3E-4F5A-9B8C-1A2B3C4D5E6F', 'key' => str_repeat('ab', 32)],
    ], $overrides));
}

it('discovers the calendars of the account', function () {
    $this->google->on('GET', FakeGoogle::API.'/users/me/calendarList', FakeGoogle::fixture('calendar-list.json'))->install();

    $result = provider()->discoverCalendars($this->account);

    expect($result->calendars)->toEqual([
        new CalendarDto('mario.rossi@gmail.com', 'mario.rossi@gmail.com', true, '#9FE1E7', 'Europe/Rome'),
        new CalendarDto(WORK_CALENDAR, 'Lavoro (ufficio)', true, '#16A765', 'Europe/Rome'),
        new CalendarDto('it.italian#holiday@group.v.calendar.google.com', 'Festività in Italia', false, '#92E1C0', 'Europe/Rome'),
    ]);

    $request = $this->google->sent('GET', FakeGoogle::API.'/users/me/calendarList')[0];
    expect(FakeGoogle::query($request))->toMatchArray(['showHidden' => 'true'])
        ->and($request->getHeaderLine('Authorization'))->toBe('Bearer ya29.current-access-token');
});

it('follows calendar list pagination', function () {
    $this->google->on('GET', FakeGoogle::API.'/users/me/calendarList', function (RequestInterface $request) {
        return isset(FakeGoogle::query($request)['pageToken'])
            ? FakeGoogle::json(['items' => [['id' => 'second@group.calendar.google.com', 'summary' => 'Seconda pagina', 'accessRole' => 'owner']]])
            : FakeGoogle::json(['items' => [['id' => 'first@group.calendar.google.com', 'summary' => 'Prima pagina', 'accessRole' => 'owner']], 'nextPageToken' => 'page-2']);
    })->install();

    expect(array_map(fn (CalendarDto $c) => $c->name, provider()->discoverCalendars($this->account)->calendars))
        ->toBe(['Prima pagina', 'Seconda pagina']);
});

it('lists events as single instances with the expected query', function () {
    $this->google->on('GET', FakeGoogle::API.'/calendars/'.WORK_CALENDAR.'/events', FakeGoogle::fixture('events.json'))->install();

    $list = provider()->listEvents(
        $this->calendar,
        CarbonImmutable::parse('2026-10-01 00:00', 'Europe/Rome'),
        CarbonImmutable::parse('2026-11-01 00:00', 'Europe/Rome'),
    );

    expect(FakeGoogle::query($this->google->requests[0]))->toMatchArray([
        'singleEvents' => 'true',
        'timeMin' => '2026-10-01T00:00:00+02:00',
        'timeMax' => '2026-11-01T00:00:00+01:00',
        'maxAttendees' => '1',
    ]);

    // Working-location entries and cancelled instances are skipped.
    expect($list->isComplete())->toBeTrue()
        ->and(array_map(fn (EventDto $e) => $e->title, $list->events))->toBe([
            'Riunione di progetto', '[iCloud] Cena con Luca', 'Call con New York', 'Call commerciale', 'Ferie', 'Standup',
        ]);

    $events = collect($list->events)->keyBy('title');

    expect($events['Riunione di progetto'])
        ->uid->toBe('7kq3m9v1abcd0123@google.com')
        ->recurrenceId->toBeNull()
        ->timezone->toBe('Europe/Rome')
        ->description->toBe('<b>Ordine del giorno</b><br>Avanzamento lavori')
        ->location->toBe('Sala Riunioni 2')
        ->remoteId->toBe('7kq3m9v1abcd0123')
        ->etag->toBe('"3517445650000000"')
        ->and($events['Riunione di progetto']->start->format('Y-m-d H:i P'))->toBe('2026-10-14 10:00 +02:00');

    expect($events['Ferie'])
        ->allDay->toBeTrue()
        ->transparent->toBeTrue()
        ->and($events['Ferie']->start->format('Y-m-d H:i'))->toBe('2026-10-20 00:00')
        ->and($events['Ferie']->end->format('Y-m-d H:i'))->toBe('2026-10-23 00:00');

    // Same normalization as the CalDAV side: original start in UTC.
    expect($events['Standup'])
        ->uid->toBe('standup789@google.com')
        ->recurrenceId->toBe('20261019T070000Z');

    expect($events['Call con New York'])
        ->timezone->toBe('America/New_York')
        ->status->toBe(EventStatus::Tentative)
        ->and($events['Call con New York']->start->format('H:i P'))->toBe('09:00 -04:00');

    expect($events['Call commerciale']->declined)->toBeTrue()
        ->and($events['Riunione di progetto']->declined)->toBeFalse();

    expect($events['[iCloud] Cena con Luca']->isMarked())->toBeTrue()
        ->and($events['[iCloud] Cena con Luca']->markers)->toBe([
            'job' => '3',
            'source' => '4B7F2C1A-0D3E-4F5A-9B8C-1A2B3C4D5E6F',
            'key' => '0f1e2d3c4b5a69788796a5b4c3d2e1f00f1e2d3c4b5a69788796a5b4c3d2e1f0',
        ]);
});

it('renews an expired access token and stores it', function () {
    $this->account->mergeCredentials(['expires_at' => now()->subMinute()->getTimestamp()]);
    $this->account->save();

    $this->google
        ->token(['access_token' => 'ya29.renewed', 'refresh_token' => null])
        ->on('GET', FakeGoogle::API.'/users/me/calendarList/primary', ['id' => 'mario.rossi@gmail.com'])
        ->install();

    expect(provider()->testConnection($this->account)->message)->toBe('Connessione riuscita come mario.rossi@gmail.com.');

    $tokenRequest = $this->google->sent('POST', FakeGoogle::TOKEN_URL)[0];
    expect(FakeGoogle::body($tokenRequest))->toMatchArray(['grant_type' => 'refresh_token', 'refresh_token' => '1//current-refresh-token'])
        ->and($this->google->sent('GET', FakeGoogle::API.'/users/me/calendarList/primary')[0]->getHeaderLine('Authorization'))->toBe('Bearer ya29.renewed')
        ->and($this->account->fresh()->credentials)->toMatchArray(['access_token' => 'ya29.renewed', 'refresh_token' => '1//current-refresh-token'])
        ->and($this->account->fresh()->credential('expires_at'))->toBeGreaterThan(now()->addMinutes(50)->getTimestamp());
});

it('does not renew a valid access token', function () {
    $this->google->on('GET', FakeGoogle::API.'/users/me/calendarList/primary', ['id' => 'mario.rossi@gmail.com'])->install();

    provider()->testConnection($this->account);

    expect($this->google->sent('POST', FakeGoogle::TOKEN_URL))->toBeEmpty();
});

it('flags the account when Google refuses the refresh token', function () {
    $this->account->mergeCredentials(['expires_at' => 0]);
    $this->account->save();
    $this->google->on('POST', FakeGoogle::TOKEN_URL, ['error' => 'invalid_grant', 'error_description' => 'Token has been expired or revoked.'], 400)->install();

    $result = app(AccountService::class)->testConnection($this->account);

    expect($result->status)->toBe(AccountStatus::TokenExpired)
        ->and($this->account->fresh()->status)->toBe(AccountStatus::TokenExpired)
        ->and($this->account->fresh()->status_message)->toContain('riconnetti');
});

it('treats an account without refresh token as not connected', function () {
    $account = Account::factory()->google()->create(['credentials' => []]);
    $this->google->install();

    expect(fn () => provider()->discoverCalendars($account))->toThrow(TokenExpired::class, 'Connetti con Google');
    expect($this->google->requests)->toBeEmpty();
});

it('maps API errors', function (int $status, string $reason, string $exception, string $message) {
    $this->google->on('GET', FakeGoogle::API.'/users/me/calendarList', FakeGoogle::error($status, $reason, 'Request had problems.'))->install();

    expect(fn () => provider()->discoverCalendars($this->account))->toThrow($exception, $message);
})->with([
    'revoked access' => [401, 'authError', TokenExpired::class, 'HTTP 401'],
    'rate limit' => [403, 'rateLimitExceeded', ProviderException::class, 'Limite di richieste'],
    'forbidden' => [403, 'forbidden', ProviderException::class, 'Permessi insufficienti'],
    'server error' => [503, 'backendError', ProviderException::class, 'HTTP 503'],
]);

it('creates copies with a deterministic id, markers, no reminders and no notifications', function () {
    $expectedId = Markers::googleEventId('3', str_repeat('ab', 32));
    $this->google->on('POST', FakeGoogle::API.'/calendars/'.WORK_CALENDAR.'/events', ['id' => $expectedId, 'etag' => '"1"'])->install();

    $written = provider()->createEvent($this->calendar, copyOf());

    expect($written->remoteId)->toBe($expectedId)
        ->and($written->etag)->toBe('"1"');

    $request = $this->google->requests[0];
    $body = FakeGoogle::body($request);

    expect(FakeGoogle::query($request))->toBe(['sendUpdates' => 'none'])
        ->and($body)->toMatchArray([
            'id' => $expectedId,
            'summary' => '[iCloud] Cena con Luca',
            'location' => 'Trattoria da Mario',
            'description' => 'Prenotato per 4.',
            'start' => ['dateTime' => '2026-10-14T18:30:00+02:00', 'timeZone' => 'Europe/Rome'],
            'end' => ['dateTime' => '2026-10-14T20:00:00+02:00', 'timeZone' => 'Europe/Rome'],
            'transparency' => 'opaque',
            'status' => 'confirmed',
            'reminders' => ['useDefault' => false],
            'extendedProperties' => ['private' => [
                'calsync_job_id' => '3',
                'calsync_source_uid' => '4B7F2C1A-0D3E-4F5A-9B8C-1A2B3C4D5E6F',
                'calsync_source_key' => str_repeat('ab', 32),
            ]],
        ])
        ->and($body)->not->toHaveKey('attendees');
});

it('writes all-day copies as dates', function () {
    $this->google->on('POST', FakeGoogle::API.'/calendars/'.WORK_CALENDAR.'/events', ['id' => 'x'])->install();

    provider()->createEvent($this->calendar, copyOf([
        'allDay' => true,
        'start' => CarbonImmutable::parse('2026-10-24', 'Europe/Rome'),
        'end' => CarbonImmutable::parse('2026-10-27', 'Europe/Rome'),
        'transparent' => true,
    ]));

    expect(FakeGoogle::body($this->google->requests[0]))->toMatchArray([
        'start' => ['date' => '2026-10-24'],
        'end' => ['date' => '2026-10-27'],
        'transparency' => 'transparent',
    ]);
});

it('takes over its own copy when the id already exists', function () {
    $id = Markers::googleEventId('3', str_repeat('ab', 32));
    $eventUrl = FakeGoogle::API.'/calendars/'.WORK_CALENDAR.'/events/'.$id;

    $this->google
        ->on('POST', FakeGoogle::API.'/calendars/'.WORK_CALENDAR.'/events', FakeGoogle::error(409, 'duplicate', 'The requested identifier already exists.'))
        ->on('GET', $eventUrl, ['id' => $id, 'status' => 'cancelled', 'extendedProperties' => ['private' => ['calsync_job_id' => '3']]])
        ->on('PUT', $eventUrl, ['id' => $id, 'etag' => '"2"'])
        ->install();

    $written = provider()->createEvent($this->calendar, copyOf());

    expect($written->etag)->toBe('"2"')
        ->and(FakeGoogle::body($this->google->sent('PUT', $eventUrl)[0])['status'])->toBe('confirmed');
});

it('never takes over an event created by someone else', function () {
    $id = Markers::googleEventId('3', str_repeat('ab', 32));
    $eventUrl = FakeGoogle::API.'/calendars/'.WORK_CALENDAR.'/events/'.$id;

    $this->google
        ->on('POST', FakeGoogle::API.'/calendars/'.WORK_CALENDAR.'/events', FakeGoogle::error(409, 'duplicate', 'The requested identifier already exists.'))
        ->on('GET', $eventUrl, ['id' => $id, 'summary' => 'Evento di qualcun altro'])
        ->install();

    expect(fn () => provider()->createEvent($this->calendar, copyOf()))->toThrow(ProviderException::class, 'non creato da questo job');
    expect($this->google->sent('PUT', $eventUrl))->toBeEmpty();
});

it('updates a copy and reports copies deleted from the target', function () {
    $eventUrl = FakeGoogle::API.'/calendars/'.WORK_CALENDAR.'/events/abc123';
    $this->google->on('PUT', $eventUrl, ['id' => 'abc123', 'etag' => '"3"'])->install();

    expect(provider()->updateEvent($this->calendar, 'abc123', copyOf(['title' => 'Nuovo titolo']))->etag)->toBe('"3"')
        ->and(FakeGoogle::body($this->google->requests[0])['summary'])->toBe('Nuovo titolo')
        ->and(FakeGoogle::query($this->google->requests[0]))->toBe(['sendUpdates' => 'none']);

    expect(fn () => provider()->updateEvent($this->calendar, 'missing', copyOf()))->toThrow(EventNotFound::class);
});

it('deletes copies and ignores the ones already gone', function () {
    $this->google
        ->on('DELETE', FakeGoogle::API.'/calendars/'.WORK_CALENDAR.'/events/abc123', new Response(204))
        ->on('DELETE', FakeGoogle::API.'/calendars/'.WORK_CALENDAR.'/events/gone', FakeGoogle::error(410, 'deleted', 'Resource has been deleted'))
        ->install();

    provider()->deleteEvent($this->calendar, 'abc123');
    provider()->deleteEvent($this->calendar, 'gone');

    expect($this->google->requests)->toHaveCount(2)
        ->and(FakeGoogle::query($this->google->requests[0]))->toBe(['sendUpdates' => 'none']);
});
