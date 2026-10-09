<?php

use App\CalSync\Dto\EventDto;
use App\CalSync\Sync\EventFilters;
use App\CalSync\Sync\FilterRule;
use App\Enums\EventStatus;
use App\Enums\FilterField;
use App\Enums\TextOperator;
use Carbon\CarbonImmutable;
use Tests\Support\InMemoryCalendarProvider as Fake;

function filtersOf(array $data, string $timezone = 'Europe/Rome'): EventFilters
{
    return EventFilters::fromArray($data, $timezone);
}

function eventTitled(string $title, array $overrides = []): EventDto
{
    return Fake::event('uid', $title, '2026-10-14 10:00', '2026-10-14 11:00', $overrides);
}

function eventAt(string $start, array $overrides = []): EventDto
{
    $start = CarbonImmutable::parse($start);

    return Fake::event('uid', 'Evento', '2026-01-01 00:00', '2026-01-01 01:00', ['start' => $start, 'end' => $start->addHour(), ...$overrides]);
}

function passes(EventFilters $filters, EventDto $event): bool
{
    return $filters->rejectReason($event) === null;
}

it('lets every event through without rules', function () {
    $filters = filtersOf([]);

    expect($filters->count())->toBe(0)
        ->and(passes($filters, eventTitled('Qualsiasi')))->toBeTrue()
        ->and(passes(EventFilters::fromArray(null, 'UTC'), eventTitled('Qualsiasi')))->toBeTrue();
});

it('compares text ignoring case', function (string $operator, string $value, string $title, bool $expected) {
    $filters = filtersOf(['include' => [['field' => 'title', 'operator' => $operator, 'value' => $value]]]);

    expect(passes($filters, eventTitled($title)))->toBe($expected);
})->with([
    'contains' => ['contains', 'luca', 'Cena con Luca', true],
    'does not contain' => ['contains', 'marco', 'Cena con Luca', false],
    'starts with' => ['starts_with', '[lavoro]', '[Lavoro] Riunione', true],
    'starts with, after leading spaces' => ['starts_with', '[Lavoro]', '  [Lavoro] Riunione', true],
    'starts with, elsewhere' => ['starts_with', 'Riunione', '[Lavoro] Riunione', false],
    'ends with' => ['ends_with', '(remoto)', 'Standup (Remoto)', true],
    'ends with, longer value' => ['ends_with', 'Standup lungo', 'Standup', false],
    'equals' => ['equals', 'palestra', ' Palestra ', true],
    'equals, partial' => ['equals', 'palestra', 'Palestra con Anna', false],
    'equals, accented' => ['equals', 'ÈVENTO', 'èvento', true],
    'regex' => ['regex', '^\[(Lavoro|Work)\]', '[work] Demo', true],
    'regex, no match' => ['regex', '^\[(Lavoro|Work)\]', 'Demo [Work]', false],
    'regex with a slash' => ['regex', 'turno a/b', 'Turno A/B', true],
]);

it('looks at location and notes', function () {
    $location = filtersOf(['include' => [['field' => 'location', 'operator' => 'contains', 'value' => 'ufficio']]]);
    $notes = filtersOf(['include' => [['field' => 'description', 'operator' => 'contains', 'value' => '#sync']]]);

    expect(passes($location, eventTitled('A', ['location' => 'Ufficio Milano'])))->toBeTrue()
        ->and(passes($location, eventTitled('A')))->toBeFalse()
        ->and(passes($notes, eventTitled('A', ['description' => "Promemoria\n#sync"])))->toBeTrue()
        ->and(passes($notes, eventTitled('A', ['description' => 'altro'])))->toBeFalse();
});

it('lets exclusion win over inclusion', function () {
    $filters = filtersOf([
        'include' => [['field' => 'title', 'operator' => 'starts_with', 'value' => '[Lavoro]']],
        'exclude' => [['field' => 'title', 'operator' => 'contains', 'value' => 'annullata']],
    ]);

    expect($filters->rejectReason(eventTitled('[Lavoro] Riunione')))->toBeNull()
        ->and($filters->rejectReason(eventTitled('[Lavoro] Riunione annullata')))->toBe('escluso dal filtro: Titolo contiene «annullata»')
        ->and($filters->rejectReason(eventTitled('Cena')))->toBe('non soddisfa il filtro: Titolo inizia con «[Lavoro]»');
});

it('requires one include rule or all of them', function () {
    $rules = [
        ['field' => 'title', 'operator' => 'starts_with', 'value' => '[Lavoro]'],
        ['field' => 'location', 'operator' => 'contains', 'value' => 'Ufficio'],
    ];
    $any = filtersOf(['include' => $rules]);
    $all = filtersOf(['include' => $rules, 'include_mode' => 'all']);
    $onlyTitle = eventTitled('[Lavoro] Riunione', ['location' => 'Casa']);

    expect($any->rejectReason($onlyTitle))->toBeNull()
        ->and($any->rejectReason(eventTitled('Cena')))->toBe('non soddisfa nessuno dei filtri di inclusione')
        ->and($all->rejectReason($onlyTitle))->toBe('non soddisfa il filtro: Luogo contiene «Ufficio»')
        ->and($all->rejectReason(eventTitled('[Lavoro] Riunione', ['location' => 'Ufficio 2'])))->toBeNull();
});

it('evaluates weekdays in the app timezone', function () {
    $weekdays = filtersOf(['include' => [['field' => 'weekday', 'weekdays' => ['1', '2', '3', '4', '5']]]]);

    // Sunday 23:30 UTC is already Monday in Rome.
    expect(passes($weekdays, eventAt('2026-10-11 23:30 UTC')))->toBeTrue()
        ->and(passes($weekdays, eventAt('2026-10-11 20:00 UTC')))->toBeFalse()
        ->and(passes($weekdays, eventAt('2026-10-14 10:00 Europe/Rome')))->toBeTrue();
});

it('keeps the date of all-day events when checking the weekday', function () {
    $weekdays = filtersOf(['include' => [['field' => 'weekday', 'weekdays' => [1, 2, 3, 4, 5]]]], 'America/New_York');
    // Saturday, all day: converted to New York it would become Friday evening.
    $saturday = Fake::event('uid', 'Gita', '2026-10-17', '2026-10-18', ['allDay' => true]);

    expect(passes($weekdays, $saturday))->toBeFalse();
});

it('checks the start time', function (string $from, string $to, string $start, bool $expected) {
    $filters = filtersOf(['include' => [['field' => 'start_time', 'from' => $from, 'to' => $to]]]);

    expect(passes($filters, eventAt($start)))->toBe($expected);
})->with([
    'before' => ['09:00', '18:00', '2026-10-14 08:59 Europe/Rome', false],
    'at the start' => ['09:00', '18:00', '2026-10-14 09:00 Europe/Rome', true],
    'inside' => ['09:00', '18:00', '2026-10-14 17:59 Europe/Rome', true],
    'at the end, excluded' => ['09:00', '18:00', '2026-10-14 18:00 Europe/Rome', false],
    'in another timezone' => ['09:00', '18:00', '2026-10-14 07:30 UTC', true],
    'overnight, late' => ['22:00', '06:00', '2026-10-14 23:00 Europe/Rome', true],
    'overnight, early' => ['22:00', '06:00', '2026-10-14 05:00 Europe/Rome', true],
    'overnight, midday' => ['22:00', '06:00', '2026-10-14 12:00 Europe/Rome', false],
    'with seconds' => ['09:00:00', '18:00:00', '2026-10-14 10:00 Europe/Rome', true],
]);

it('never matches all-day events on the start time', function () {
    $filters = filtersOf(['include' => [['field' => 'start_time', 'from' => '00:00', 'to' => '23:59']]]);

    expect(passes($filters, Fake::event('uid', 'Ferie', '2026-10-14', '2026-10-15', ['allDay' => true])))->toBeFalse();
});

it('checks the event flags', function (string $field, array $matching) {
    $filters = filtersOf(['exclude' => [['field' => $field]]]);

    expect(passes($filters, eventTitled('Normale')))->toBeTrue()
        ->and($filters->rejectReason(eventTitled('Speciale', $matching)))->toBe('escluso dal filtro: '.FilterField::from($field)->getLabel());
})->with([
    'all day' => ['all_day', ['allDay' => true]],
    'free' => ['free', ['transparent' => true]],
    'tentative' => ['tentative', ['status' => EventStatus::Tentative]],
    'recurring' => ['recurring', ['recurrenceId' => '20261014T080000Z']],
]);

it('ignores incomplete or invalid rules', function () {
    $filters = filtersOf([
        'include' => [
            ['field' => 'nope'],
            ['field' => 'title', 'operator' => 'contains', 'value' => '   '],
            ['field' => 'title', 'operator' => 'regex', 'value' => '(unclosed'],
            ['field' => 'title', 'value' => 'senza operatore'],
            ['field' => 'weekday', 'weekdays' => [0, 8]],
            ['field' => 'start_time', 'from' => '09:00', 'to' => '09:00'],
            ['field' => 'start_time', 'from' => '25:00', 'to' => '09:00'],
            'not a rule',
        ],
        'exclude' => 'not a list',
    ]);

    expect($filters->count())->toBe(0)
        ->and(passes($filters, eventTitled('Qualsiasi')))->toBeTrue();
});

it('accepts enum values from the form state', function () {
    $rule = FilterRule::fromArray(['field' => FilterField::Title, 'operator' => TextOperator::EndsWith, 'value' => '!']);

    expect($rule?->describe())->toBe('Titolo finisce con «!»');
});

it('describes every kind of rule', function (array $rule, string $description) {
    expect(FilterRule::fromArray($rule)?->describe())->toBe($description);
})->with([
    [['field' => 'location', 'operator' => 'equals', 'value' => 'Casa'], 'Luogo è uguale a «Casa»'],
    [['field' => 'title', 'operator' => 'regex', 'value' => '^x'], 'Titolo corrisponde a /^x/'],
    [['field' => 'weekday', 'weekdays' => [6, 1, 7, 1]], 'Giorno: lun, sab, dom'],
    [['field' => 'start_time', 'from' => '22:00', 'to' => '06:30'], 'Inizia tra 22:00 e 06:30'],
    [['field' => 'recurring'], 'Ricorrente'],
]);

it('validates regular expressions written without delimiters', function () {
    expect(FilterRule::isValidPattern('^\[(Lavoro|Work)\]'))->toBeTrue()
        ->and(FilterRule::isValidPattern('a/b'))->toBeTrue()
        ->and(FilterRule::isValidPattern('(unclosed'))->toBeFalse()
        ->and(FilterRule::isValidPattern('trailing\\'))->toBeFalse();
});

it('strips the matched prefix from the copy title', function (string $title, string $expected) {
    $filters = filtersOf([
        'include' => [
            ['field' => 'title', 'operator' => 'starts_with', 'value' => '[Lavoro]'],
            ['field' => 'title', 'operator' => 'starts_with', 'value' => '[Lavoro] Team'],
            ['field' => 'title', 'operator' => 'starts_with', 'value' => 'Lavoro'],
        ],
        'exclude' => [['field' => 'title', 'operator' => 'starts_with', 'value' => 'Cena']],
        'strip_prefix' => true,
    ]);

    expect($filters->copyTitle($title))->toBe($expected);
})->with([
    'bracketed' => ['[Lavoro] Riunione', 'Riunione'],
    'other case' => ['[LAVORO]: Riunione', 'Riunione'],
    'with a dash' => ['Lavoro – Riunione', 'Riunione'],
    'leading spaces' => ['  [Lavoro] Riunione', 'Riunione'],
    'longest prefix wins' => ['[Lavoro] Team Standup', 'Standup'],
    'only the prefix' => ['[Lavoro]', '[Lavoro]'],
    'no matching prefix' => ['Riunione [Lavoro]', 'Riunione [Lavoro]'],
    'exclude rules are not prefixes' => ['Cena con Luca', 'Cena con Luca'],
]);

it('keeps the title when stripping is off', function () {
    $filters = filtersOf(['include' => [['field' => 'title', 'operator' => 'starts_with', 'value' => '[Lavoro]']]]);

    expect($filters->copyTitle('[Lavoro] Riunione'))->toBe('[Lavoro] Riunione');
});
