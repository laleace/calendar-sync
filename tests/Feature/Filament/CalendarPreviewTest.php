<?php

use App\Filament\Pages\CalendarPreview;
use App\Models\Account;
use App\Models\Calendar;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\Support\FakeCalDav;

beforeEach(function () {
    $this->actingAs(User::factory()->create());

    $this->calendar = Calendar::factory()
        ->for(Account::factory()->state(['name' => 'iCloud personale']))
        ->create(['remote_id' => 'https://p42-caldav.icloud.com/123456789/calendars/home/', 'name' => 'Casa', 'timezone' => 'Europe/Rome']);
});

function fakeHomeEvents(array $resources): void
{
    (new FakeCalDav)->report('https://p42-caldav.icloud.com/123456789/calendars/home/', FakeCalDav::calendarQueryXml($resources))->install();
}

it('renders the page with calendars grouped by account', function () {
    Calendar::factory()->create(['name' => 'Sparito', 'missing_since' => now()]);

    $this->get(CalendarPreview::getUrl())
        ->assertOk()
        ->assertSee('Anteprima eventi');

    Livewire::test(CalendarPreview::class)
        ->assertFormFieldExists('calendar_id', function ($field): bool {
            return $field->getOptions() === ['iCloud personale' => [$this->calendar->id => 'Casa']];
        });
});

it('shows the events of a calendar in the chosen range', function () {
    fakeHomeEvents([
        '/123456789/calendars/home/single.ics' => 'single.ics',
        '/123456789/calendars/home/weekly.ics' => 'recurring-with-exceptions.ics',
    ]);

    Livewire::test(CalendarPreview::class)
        ->fillForm(['calendar_id' => $this->calendar->id, 'from' => '2026-10-01', 'to' => '2026-10-31'])
        ->call('load')
        ->assertHasNoFormErrors()
        ->assertSet('loaded', true)
        ->assertCountTableRecords(4)
        ->assertSee('Cena con Luca')
        ->assertSee('14/10/2026 18:30 → 20:00')
        ->assertSee('Riunione settimanale (spostata)')
        ->assertSee('Sala Blu')
        ->assertSee('ricorrente')
        ->assertSee('Annullato');
});

it('filters the events with the table search', function () {
    fakeHomeEvents(['/123456789/calendars/home/weekly.ics' => 'recurring-with-exceptions.ics', '/123456789/calendars/home/single.ics' => 'single.ics']);

    Livewire::test(CalendarPreview::class)
        ->fillForm(['calendar_id' => $this->calendar->id, 'from' => '2026-10-01', 'to' => '2026-10-31'])
        ->call('load')
        ->searchTable('luca')
        ->assertCountTableRecords(1)
        ->assertSee('Cena con Luca')
        ->assertDontSee('Sala Verde');
});

it('loads straight away when opened from a calendar row', function () {
    fakeHomeEvents([]);

    Livewire::withQueryParams(['calendar' => $this->calendar->id])
        ->test(CalendarPreview::class)
        ->assertSet('loaded', true)
        ->assertFormSet(['calendar_id' => $this->calendar->id]);

    Http::assertSentCount(1);
});

it('limits the range to 90 days', function () {
    Livewire::test(CalendarPreview::class)
        ->fillForm(['calendar_id' => $this->calendar->id, 'from' => '2026-10-01', 'to' => '2027-01-15'])
        ->call('load')
        ->assertHasErrors(['data.to'])
        ->assertSet('loaded', false);

    Http::assertNothingSent();
});

it('shows unreadable events as a warning', function () {
    fakeHomeEvents(['/123456789/calendars/home/broken.ics' => 'invalid-date.ics']);

    Livewire::test(CalendarPreview::class)
        ->fillForm(['calendar_id' => $this->calendar->id, 'from' => '2026-10-01', 'to' => '2026-10-31'])
        ->call('load')
        ->assertSee('Alcuni eventi non sono leggibili')
        ->assertSee('broken-date')
        ->assertSee('Questo invece va bene');
});

it('reports a calendar that cannot be read', function () {
    (new FakeCalDav)->status('REPORT', $this->calendar->remote_id, 401)->install();

    Livewire::test(CalendarPreview::class)
        ->fillForm(['calendar_id' => $this->calendar->id, 'from' => '2026-10-01', 'to' => '2026-10-31'])
        ->call('load')
        ->assertNotified('Lettura non riuscita')
        ->assertSet('loaded', false);
});
