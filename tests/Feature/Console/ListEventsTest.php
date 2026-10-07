<?php

use App\Models\Account;
use App\Models\Calendar;
use Tests\Support\FakeCalDav;

function calendarWithEvents(array $resources): Calendar
{
    $calendar = Calendar::factory()->for(Account::factory(), 'account')->create([
        'remote_id' => 'https://p42-caldav.icloud.com/123456789/calendars/home/',
        'name' => 'Casa',
        'timezone' => 'Europe/Rome',
    ]);

    (new FakeCalDav)->report($calendar->remote_id, FakeCalDav::calendarQueryXml($resources))->install();

    return $calendar;
}

it('lists the events of a calendar', function () {
    $calendar = calendarWithEvents([
        '/123456789/calendars/home/single.ics' => 'single.ics',
        '/123456789/calendars/home/all-day.ics' => 'all-day.ics',
    ]);

    $this->artisan('calsync:list-events', ['calendar' => $calendar->id, '--from' => '2026-10-01', '--to' => '2026-10-31'])
        ->expectsOutputToContain('dal 01/10/2026 al 31/10/2026')
        ->expectsTable(['Inizio', 'Fine', 'Titolo', 'Stato', 'Note', 'UID'], [
            ['10/10/2026', '10/10/2026', 'Gita al lago', 'Confermato', 'tutto il giorno, libero', 'allday-one-day'],
            ['14/10/2026 18:30', '14/10/2026 20:00', 'Cena con Luca', 'Provvisorio', '', '4B7F2C1A-0D3E-4F5A-9B8C-...'],
            ['24/10/2026', '26/10/2026', 'Weekend a Roma', 'Confermato', 'tutto il giorno', 'allday-three-days-dst'],
            ['30/10/2026', '30/10/2026', 'Scadenza bollo auto', 'Confermato', 'tutto il giorno', 'allday-no-dtend'],
        ])
        ->expectsOutputToContain('Eventi: 4 · Occorrenze di ricorrenze: 0 · Creati da calendar-sync: 0')
        ->assertSuccessful();
});

it('flags recurring, marked and foreign-timezone events and reports problems', function () {
    $calendar = calendarWithEvents([
        '/123456789/calendars/home/weekly.ics' => 'recurring-with-exceptions.ics',
        '/123456789/calendars/home/copy.ics' => 'marked.ics',
        '/123456789/calendars/home/broken.ics' => 'invalid-date.ics',
    ]);

    $this->artisan('calsync:list-events', ['calendar' => $calendar->id, '--from' => '2026-10-01', '--to' => '2026-10-31'])
        ->expectsOutputToContain('Eventi: 5 · Occorrenze di ricorrenze: 3 · Creati da calendar-sync: 1')
        ->expectsOutputToContain('broken-date')
        ->assertSuccessful();
});

it('validates the arguments', function (array $arguments) {
    calendarWithEvents([]);

    $this->artisan('calsync:list-events', $arguments)->assertFailed();
})->with([
    'unknown calendar' => [['calendar' => 999]],
    'bad date' => [['calendar' => 1, '--from' => 'ieri sera']],
    'reversed range' => [['calendar' => 1, '--from' => '2026-10-31', '--to' => '2026-10-01']],
    'range too long' => [['calendar' => 1, '--from' => '2026-01-01', '--to' => '2027-06-01']],
]);
