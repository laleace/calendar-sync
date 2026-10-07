<?php

use App\CalSync\CalDav\ICalendarMapper;
use App\CalSync\Dto\EventDto;
use App\CalSync\Dto\EventList;
use App\Enums\EventStatus;

/**
 * Window used by every test: October 2026 plus the first week of November, Rome time.
 * EU daylight saving time ends on Sunday 25 October 2026.
 */
function parseIcs(string $fixture, string $from = '2026-10-01 00:00', string $to = '2026-11-08 00:00'): EventList
{
    $tz = new DateTimeZone('Europe/Rome');

    return (new ICalendarMapper)->parse(
        file_get_contents(__DIR__.'/../../Fixtures/ics/'.$fixture),
        new DateTimeImmutable($from, $tz),
        new DateTimeImmutable($to, $tz),
        $tz,
        'https://p42-caldav.icloud.com/123456789/calendars/home/'.$fixture,
        '"C=101@U=abc"',
    );
}

function eventByUid(EventList $list, string $uid): EventDto
{
    return collect($list->events)->firstOrFail(fn (EventDto $e) => $e->uid === $uid);
}

it('parses a single timed event', function () {
    $list = parseIcs('single.ics');

    expect($list->isComplete())->toBeTrue()
        ->and($list)->toHaveCount(1);

    $event = $list->events[0];

    expect($event->uid)->toBe('4B7F2C1A-0D3E-4F5A-9B8C-1A2B3C4D5E6F')
        ->and($event->recurrenceId)->toBeNull()
        ->and($event->title)->toBe('Cena con Luca')
        ->and($event->start->format('Y-m-d H:i P'))->toBe('2026-10-14 18:30 +02:00')
        ->and($event->end->format('Y-m-d H:i P'))->toBe('2026-10-14 20:00 +02:00')
        ->and($event->allDay)->toBeFalse()
        ->and($event->timezone)->toBe('Europe/Rome')
        ->and($event->location)->toBe('Trattoria da Mario, Via Roma 1, Milano')
        ->and($event->description)->toBe("Prenotato per 4.\nPortare il vino e la torta per il compleanno di Giulia.")
        ->and($event->status)->toBe(EventStatus::Tentative)
        ->and($event->transparent)->toBeFalse()
        ->and($event->isMarked())->toBeFalse()
        ->and($event->remoteId)->toBe('https://p42-caldav.icloud.com/123456789/calendars/home/single.ics')
        ->and($event->etag)->toBe('"C=101@U=abc"');
});

it('parses all-day events with an exclusive end date', function () {
    $list = parseIcs('all-day.ics');

    $oneDay = eventByUid($list, 'allday-one-day');
    expect($oneDay->allDay)->toBeTrue()
        ->and($oneDay->start->format('Y-m-d H:i'))->toBe('2026-10-10 00:00')
        ->and($oneDay->end->format('Y-m-d H:i'))->toBe('2026-10-11 00:00')
        ->and($oneDay->timezone)->toBe('Europe/Rome')
        ->and($oneDay->transparent)->toBeTrue();

    // Spans the DST change of 25 October: still whole days, ending at midnight.
    $threeDays = eventByUid($list, 'allday-three-days-dst');
    expect($threeDays->start->format('Y-m-d H:i P'))->toBe('2026-10-24 00:00 +02:00')
        ->and($threeDays->end->format('Y-m-d H:i P'))->toBe('2026-10-27 00:00 +01:00');

    // No DTEND on a date: one day long.
    $noEnd = eventByUid($list, 'allday-no-dtend');
    expect($noEnd->end->format('Y-m-d H:i'))->toBe('2026-10-31 00:00');
});

it('expands a recurring event with EXDATE, a moved and a cancelled occurrence', function () {
    $events = parseIcs('recurring-with-exceptions.ics')->events;

    expect(array_map(fn (EventDto $e) => [
        $e->recurrenceId,
        $e->start->format('Y-m-d H:i P'),
        $e->title,
        $e->location,
        $e->status,
    ], $events))->toBe([
        ['20261005T070000Z', '2026-10-05 09:00 +02:00', 'Riunione settimanale', 'Sala Verde', EventStatus::Confirmed],
        // 12 October is excluded by EXDATE.
        // 19 October moved to Tuesday 20 at 14:00: the recurrence id keeps the original start.
        ['20261019T070000Z', '2026-10-20 14:00 +02:00', 'Riunione settimanale (spostata)', 'Sala Blu', EventStatus::Confirmed],
        // After the DST change the local time stays 09:00, the UTC time moves to 08:00.
        ['20261026T080000Z', '2026-10-26 09:00 +01:00', 'Riunione settimanale', null, EventStatus::Cancelled],
        ['20261102T080000Z', '2026-11-02 09:00 +01:00', 'Riunione settimanale', 'Sala Verde', EventStatus::Confirmed],
    ]);

    expect(collect($events)->pluck('uid')->unique()->all())->toBe(['9E1D7C55-6A2B-4C3D-8E9F-001122334455']);
});

it('returns only the occurrences inside the window', function () {
    $events = parseIcs('recurring-with-exceptions.ics', from: '2026-10-19 00:00', to: '2026-10-27 00:00')->events;

    expect(array_map(fn (EventDto $e) => $e->recurrenceId, $events))->toBe(['20261019T070000Z', '20261026T080000Z']);
});

it('resolves different time zones', function () {
    $list = parseIcs('timezones.ics');

    expect(eventByUid($list, 'tz-new-york'))
        ->timezone->toBe('America/New_York')
        ->and(eventByUid($list, 'tz-new-york')->start->format('Y-m-d H:i P'))->toBe('2026-10-15 09:00 -04:00')
        ->and(eventByUid($list, 'tz-new-york')->start->setTimezone('Europe/Rome')->format('H:i'))->toBe('15:00');

    // Outlook uses Windows zone names: mapped to an IANA zone with the same rules.
    expect(eventByUid($list, 'tz-outlook-windows-name'))
        ->timezone->toBe('Europe/Berlin')
        ->and(eventByUid($list, 'tz-outlook-windows-name')->start->format('Y-m-d H:i P'))->toBe('2026-10-16 11:00 +02:00');

    expect(eventByUid($list, 'tz-utc'))
        ->timezone->toBe('UTC')
        ->and(eventByUid($list, 'tz-utc')->start->format('Y-m-d H:i P'))->toBe('2026-10-17 12:00 +00:00');

    // Floating time: interpreted in the calendar time zone.
    expect(eventByUid($list, 'tz-floating'))
        ->timezone->toBe('Europe/Rome')
        ->and(eventByUid($list, 'tz-floating')->start->format('Y-m-d H:i P'))->toBe('2026-10-18 10:00 +02:00');
});

it('handles DURATION, yearly all-day recurrences, transparency and window edges', function () {
    $list = parseIcs('various.ics');

    expect(eventByUid($list, 'duration-event')->end->format('H:i'))->toBe('16:30');

    $birthday = eventByUid($list, 'birthday-yearly');
    expect($birthday->recurrenceId)->toBe('20261012')
        ->and($birthday->allDay)->toBeTrue()
        ->and($birthday->start->format('Y-m-d'))->toBe('2026-10-12')
        ->and($birthday->end->format('Y-m-d'))->toBe('2026-10-13');

    expect(eventByUid($list, 'transparent-timed')->transparent)->toBeTrue();

    // Starts before the window and ends inside it: included. Ends exactly at the window start: excluded.
    expect(collect($list->events)->pluck('uid')->all())->toContain('edge-inside')
        ->not->toContain('edge-outside');
});

it('expands long-running daily events created years ago', function () {
    $list = parseIcs('daily-since-2010.ics');

    expect($list->isComplete())->toBeTrue()
        ->and($list)->toHaveCount(38)
        ->and($list->events[0]->recurrenceId)->toBe('20261001T053000Z')
        ->and($list->events[0]->start->format('Y-m-d H:i'))->toBe('2026-10-01 07:30');
});

it('reports runaway recurrences as a problem instead of failing', function () {
    $list = parseIcs('runaway-recurrence.ics');

    expect($list->events)->toBeEmpty()
        ->and($list->isComplete())->toBeFalse()
        ->and($list->problems[0])->toContain('runaway')->toContain('troppe ricorrenze');
});

it('reads calendar-sync markers', function () {
    $event = parseIcs('marked.ics')->events[0];

    expect($event->isMarked())->toBeTrue()
        ->and($event->markers)->toBe([
            'job' => '5',
            'source' => '4B7F2C1A-0D3E-4F5A-9B8C-1A2B3C4D5E6F',
            'uid' => 'calsync-5f4dcc3b5aa765d61d8327deb882cf99@calendar-sync',
        ]);
});

it('keeps the original recurrence id of an occurrence stored without its master', function () {
    $event = parseIcs('orphan-override.ics')->events[0];

    expect($event->recurrenceId)->toBe('20261008T080000Z')
        ->and($event->start->format('Y-m-d H:i'))->toBe('2026-10-08 11:30');
});

it('reports unreadable events and keeps the readable ones', function () {
    $list = parseIcs('invalid-date.ics');

    expect(collect($list->events)->pluck('uid')->all())->toBe(['fine-event'])
        ->and($list->isComplete())->toBeFalse()
        ->and($list->problems[0])->toContain('broken-date')->toContain('invalid-date.ics');
});

it('reports resources that are not iCalendar at all', function () {
    $list = (new ICalendarMapper)->parse('<html>oops</html>', new DateTimeImmutable('2026-10-01'), new DateTimeImmutable('2026-11-01'), new DateTimeZone('UTC'));

    expect($list->events)->toBeEmpty()
        ->and($list->problems)->toHaveCount(1);
});
