<?php

use App\CalSync\CalDav\CalDavProvider;
use App\CalSync\CalDav\ICalendarMapper;
use App\CalSync\Dto\EventDto;
use App\CalSync\Exceptions\AuthenticationFailed;
use App\CalSync\Exceptions\ProviderException;
use App\CalSync\Markers;
use App\Models\Account;
use App\Models\Calendar;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Support\FakeCalDav;

const ICLOUD_TARGET = 'https://p42-caldav.icloud.com/123456789/calendars/home/';

beforeEach(function () {
    $this->calendar = Calendar::factory()
        ->for(Account::factory()->state(['credentials' => ['username' => 'mario@icloud.com', 'password' => 'abcd-efgh-ijkl-mnop']]))
        ->create(['remote_id' => ICLOUD_TARGET]);
    $this->key = str_repeat('ab', 32);
    $this->resource = ICLOUD_TARGET.'calsync-'.Markers::copyHash('7', $this->key).'.ics';
});

function caldavCopy(array $overrides = []): EventDto
{
    $key = str_repeat('ab', 32);

    return new EventDto(...array_merge([
        'uid' => Markers::caldavUid('7', $key),
        'recurrenceId' => null,
        'title' => '[Google] Riunione di progetto',
        'start' => CarbonImmutable::parse('2026-10-14 10:00', 'Europe/Rome'),
        'end' => CarbonImmutable::parse('2026-10-14 11:00', 'Europe/Rome'),
        'allDay' => false,
        'timezone' => 'Europe/Rome',
        'location' => 'Sala 2',
        'markers' => ['job' => '7', 'source' => '7kq3m9v1abcd0123@google.com', 'key' => $key],
    ], $overrides));
}

it('creates a copy with PUT If-None-Match and a deterministic resource name', function () {
    (new FakeCalDav)->on('PUT', $this->resource, fn () => Http::response('', 201, ['ETag' => '"abc123"']))->install();

    $written = app(CalDavProvider::class)->createEvent($this->calendar, caldavCopy());

    expect($written->remoteId)->toBe($this->resource)
        ->and($written->etag)->toBe('"abc123"');

    Http::assertSent(function (Request $r) {
        $body = str_replace("\r\n ", '', $r->body());

        return $r->method() === 'PUT'
            && $r->header('If-None-Match') === ['*']
            && str_starts_with($r->header('Content-Type')[0], 'text/calendar')
            && $r->header('Authorization') === ['Basic '.base64_encode('mario@icloud.com:abcd-efgh-ijkl-mnop')]
            && str_contains($body, 'UID:'.Markers::caldavUid('7', $this->key))
            && str_contains($body, 'DTSTART:20261014T080000Z')
            && str_contains($body, 'SUMMARY:[Google] Riunione di progetto')
            && str_contains($body, 'X-CALSYNC-JOB:7')
            && str_contains($body, 'X-CALSYNC-SOURCE:7kq3m9v1abcd0123@google.com')
            && ! str_contains($body, 'ATTENDEE')
            && ! str_contains($body, 'ORGANIZER')
            && ! str_contains($body, 'VALARM');
    });
});

it('writes all-day copies as dates', function () {
    (new FakeCalDav)->on('PUT', $this->resource, fn () => Http::response('', 201))->install();

    app(CalDavProvider::class)->createEvent($this->calendar, caldavCopy([
        'allDay' => true,
        'start' => CarbonImmutable::parse('2026-10-24', 'Europe/Rome'),
        'end' => CarbonImmutable::parse('2026-10-27', 'Europe/Rome'),
    ]));

    Http::assertSent(fn (Request $r) => str_contains($r->body(), 'DTSTART;VALUE=DATE:20261024') && str_contains($r->body(), 'DTEND;VALUE=DATE:20261027'));
});

it('takes over its own copy when the resource already exists', function () {
    $mine = (new ICalendarMapper)->toIcs(caldavCopy(['title' => 'vecchio']));
    $puts = 0;

    (new FakeCalDav)
        ->on('PUT', $this->resource, function () use (&$puts) {
            return ++$puts === 1 ? Http::response('', 412) : Http::response('', 204, ['ETag' => '"v2"']);
        })
        ->on('GET', $this->resource, fn () => Http::response($mine, 200, ['Content-Type' => 'text/calendar']))
        ->install();

    $written = app(CalDavProvider::class)->createEvent($this->calendar, caldavCopy());

    expect($written->etag)->toBe('"v2"');
    Http::assertSent(fn (Request $r) => $r->method() === 'PUT' && $r->header('If-None-Match') === []);
});

it('never overwrites a resource it did not create', function () {
    (new FakeCalDav)
        ->on('PUT', $this->resource, fn () => Http::response('', 412))
        ->on('GET', $this->resource, fn () => Http::response(FakeCalDav::fixtureIcs('single.ics'), 200))
        ->install();

    expect(fn () => app(CalDavProvider::class)->createEvent($this->calendar, caldavCopy()))
        ->toThrow(ProviderException::class, 'non creata da questo job');

    Http::assertSentCount(2);
});

it('reports read-only calendars and rejected credentials', function (int $status, string $exception, string $message) {
    (new FakeCalDav)->on('PUT', $this->resource, fn () => Http::response('', $status))->install();

    expect(fn () => app(CalDavProvider::class)->createEvent($this->calendar, caldavCopy()))->toThrow($exception, $message);
})->with([
    'read-only' => [403, ProviderException::class, 'sola lettura'],
    'credentials' => [401, AuthenticationFailed::class, 'HTTP 401'],
    'server error' => [500, ProviderException::class, 'HTTP 500'],
]);

it('updates a copy with an unconditional PUT', function () {
    (new FakeCalDav)->on('PUT', $this->resource, fn () => Http::response('', 204, ['ETag' => '"v3"']))->install();

    $written = app(CalDavProvider::class)->updateEvent($this->calendar, $this->resource, caldavCopy(['title' => 'Nuovo titolo']));

    expect($written->etag)->toBe('"v3"');
    Http::assertSent(fn (Request $r) => $r->header('If-None-Match') === [] && str_contains($r->body(), 'SUMMARY:Nuovo titolo'));
});

it('deletes copies and ignores the ones already gone', function () {
    (new FakeCalDav)
        ->on('DELETE', $this->resource, fn () => Http::response('', 204))
        ->on('DELETE', ICLOUD_TARGET.'gone.ics', fn () => Http::response('', 404))
        ->on('DELETE', ICLOUD_TARGET.'broken.ics', fn () => Http::response('', 500))
        ->install();

    app(CalDavProvider::class)->deleteEvent($this->calendar, $this->resource);
    app(CalDavProvider::class)->deleteEvent($this->calendar, ICLOUD_TARGET.'gone.ics');

    expect(fn () => app(CalDavProvider::class)->deleteEvent($this->calendar, ICLOUD_TARGET.'broken.ics'))->toThrow(ProviderException::class);
});

it('never sends credentials to a resource outside the account hosts', function () {
    (new FakeCalDav)->install();

    expect(fn () => app(CalDavProvider::class)->deleteEvent($this->calendar, 'https://evil.test/steal.ics'))
        ->toThrow(ProviderException::class, 'non consentito');

    Http::assertNothingSent();
});

it('reads its own copies back as marked', function () {
    $tz = new DateTimeZone('Europe/Rome');
    $event = (new ICalendarMapper)->parse(
        (new ICalendarMapper)->toIcs(caldavCopy()),
        new DateTimeImmutable('2026-10-01', $tz),
        new DateTimeImmutable('2026-11-01', $tz),
        $tz,
    )->events[0];

    expect($event->isMarked())->toBeTrue()
        ->and($event->markers)->toMatchArray(['job' => '7', 'source' => '7kq3m9v1abcd0123@google.com', 'key' => str_repeat('ab', 32)])
        ->and($event->markers['uid'])->toBe(Markers::caldavUid('7', str_repeat('ab', 32)))
        ->and($event->start->setTimezone('Europe/Rome')->format('Y-m-d H:i'))->toBe('2026-10-14 10:00')
        ->and($event->location)->toBe('Sala 2');
});
