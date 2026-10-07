<?php

use App\CalSync\CalDav\CalDavProvider;
use App\CalSync\Dto\EventDto;
use App\CalSync\Exceptions\AuthenticationFailed;
use App\Models\Account;
use App\Models\Calendar;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Support\FakeCalDav;

const HOME_CALENDAR = 'https://p42-caldav.icloud.com/123456789/calendars/home/';

function homeCalendar(array $attributes = []): Calendar
{
    return Calendar::factory()
        ->for(Account::factory()->state(['credentials' => ['username' => 'mario@icloud.com', 'password' => 'abcd-efgh-ijkl-mnop']]))
        ->create(['remote_id' => HOME_CALENDAR, 'timezone' => 'Europe/Rome', ...$attributes]);
}

function octoberWindow(): array
{
    return [
        CarbonImmutable::parse('2026-10-01 00:00', 'Europe/Rome'),
        CarbonImmutable::parse('2026-11-08 00:00', 'Europe/Rome'),
    ];
}

it('reads and expands the events of a calendar with a calendar-query REPORT', function () {
    (new FakeCalDav)->report(HOME_CALENDAR, FakeCalDav::calendarQueryXml([
        '/123456789/calendars/home/single.ics' => 'single.ics',
        '/123456789/calendars/home/weekly.ics' => 'recurring-with-exceptions.ics',
        '/123456789/calendars/home/all-day.ics' => 'all-day.ics',
    ]))->install();

    $list = app(CalDavProvider::class)->listEvents(homeCalendar(), ...octoberWindow());

    expect($list->isComplete())->toBeTrue()
        ->and(array_map(fn (EventDto $e) => [$e->start->format('m-d H:i'), $e->title], $list->events))->toBe([
            ['10-05 09:00', 'Riunione settimanale'],
            ['10-10 00:00', 'Gita al lago'],
            ['10-14 18:30', 'Cena con Luca'],
            ['10-20 14:00', 'Riunione settimanale (spostata)'],
            ['10-24 00:00', 'Weekend a Roma'],
            ['10-26 09:00', 'Riunione settimanale'],
            ['10-30 00:00', 'Scadenza bollo auto'],
            ['11-02 09:00', 'Riunione settimanale'],
        ]);

    $single = collect($list->events)->firstWhere('title', 'Cena con Luca');
    expect($single->remoteId)->toBe(HOME_CALENDAR.'single.ics')
        ->and($single->etag)->toBe('"C=1@U=abc"');
});

it('sends the time range in UTC with Depth 1', function () {
    (new FakeCalDav)->report(HOME_CALENDAR, FakeCalDav::calendarQueryXml([]))->install();

    app(CalDavProvider::class)->listEvents(homeCalendar(), ...octoberWindow());

    Http::assertSent(fn (Request $r) => $r->method() === 'REPORT'
        && $r->url() === HOME_CALENDAR
        && $r->header('Depth') === ['1']
        && $r->header('Authorization') === ['Basic '.base64_encode('mario@icloud.com:abcd-efgh-ijkl-mnop')]
        // 1 Oct 00:00 CEST and 8 Nov 00:00 CET
        && str_contains($r->body(), '<c:time-range start="20260930T220000Z" end="20261107T230000Z"/>')
        && str_contains($r->body(), '<c:calendar-data/>'));
});

it('keeps readable events when some resources are broken', function () {
    (new FakeCalDav)->report(HOME_CALENDAR, FakeCalDav::calendarQueryXml([
        '/123456789/calendars/home/single.ics' => 'single.ics',
        '/123456789/calendars/home/broken.ics' => 'invalid-date.ics',
        '/123456789/calendars/home/empty.ics' => null,
    ]))->install();

    $list = app(CalDavProvider::class)->listEvents(homeCalendar(), ...octoberWindow());

    expect(collect($list->events)->pluck('title')->all())->toBe(['Questo invece va bene', 'Cena con Luca'])
        ->and($list->isComplete())->toBeFalse()
        ->and($list->problems)->toHaveCount(2)
        ->and($list->problems[1])->toContain('empty.ics');
});

it('interprets floating times in the calendar time zone', function () {
    (new FakeCalDav)->report(HOME_CALENDAR, FakeCalDav::calendarQueryXml([
        '/123456789/calendars/home/tz.ics' => 'timezones.ics',
    ]))->install();

    $list = app(CalDavProvider::class)->listEvents(homeCalendar(['timezone' => 'America/New_York']), ...octoberWindow());
    $floating = collect($list->events)->firstWhere('uid', 'tz-floating');

    expect($floating->timezone)->toBe('America/New_York')
        ->and($floating->start->format('H:i P'))->toBe('10:00 -04:00');
});

it('falls back to the app time zone when the calendar one is unknown', function () {
    (new FakeCalDav)->report(HOME_CALENDAR, FakeCalDav::calendarQueryXml([
        '/123456789/calendars/home/tz.ics' => 'timezones.ics',
    ]))->install();

    $list = app(CalDavProvider::class)->listEvents(homeCalendar(['timezone' => 'Mars/Olympus']), ...octoberWindow());

    expect(collect($list->events)->firstWhere('uid', 'tz-floating')->timezone)->toBe('Europe/Rome');
});

it('fails when the credentials are rejected', function () {
    (new FakeCalDav)->status('REPORT', HOME_CALENDAR, 401)->install();

    app(CalDavProvider::class)->listEvents(homeCalendar(), ...octoberWindow());
})->throws(AuthenticationFailed::class);
