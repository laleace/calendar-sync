<?php

use App\CalSync\Sync\FilterRule;
use App\Enums\SyncRunStatus;
use App\Models\SyncJob;
use App\Models\User;
use App\Support\DateFormat;
use Illuminate\Support\Facades\File;

/**
 * Literal keys passed to __() and trans_choice() in the app and in the views.
 *
 * @return list<string>
 */
function translationKeysInSource(): array
{
    $files = collect([...File::allFiles(app_path()), ...File::allFiles(resource_path('views'))])
        ->filter(fn (SplFileInfo $file) => str_ends_with($file->getFilename(), '.php'));

    return $files
        ->flatMap(function (SplFileInfo $file): array {
            preg_match_all("/(?:__|trans_choice)\\(\\s*'((?:[^'\\\\]|\\\\.)*)'/", File::get($file->getPathname()), $matches);

            return array_map(fn (string $key) => str_replace(["\\'", '\\\\'], ["'", '\\'], $key), $matches[1]);
        })
        ->unique()
        ->sort()
        ->values()
        ->all();
}

/**
 * @return array<string, string>
 */
function italianTranslations(): array
{
    return json_decode(File::get(lang_path('it.json')), true, flags: JSON_THROW_ON_ERROR);
}

/**
 * @return list<string>
 */
function placeholders(string $line): array
{
    preg_match_all('/(?<![\w:]):([a-z_]+)/', $line, $matches);

    return collect($matches[1])->unique()->sort()->values()->all();
}

it('translates every key used in the code into Italian', function () {
    $missing = array_values(array_diff(translationKeysInSource(), array_keys(italianTranslations())));

    expect($missing)->toBe([]);
});

it('has no unused Italian translations', function () {
    $unused = array_values(array_diff(array_keys(italianTranslations()), translationKeysInSource()));

    expect($unused)->toBe([]);
});

it('keeps placeholders and plural forms in the Italian translations', function () {
    foreach (italianTranslations() as $key => $value) {
        expect(placeholders($value))->toBe(placeholders($key), "Placeholders differ for: {$key}")
            ->and(substr_count($value, '|'))->toBe(substr_count($key, '|'), "Plural forms differ for: {$key}");
    }
});

it('shows the panel in English', function () {
    app()->setLocale('en');

    $this->actingAs(User::factory()->create())
        ->get('/admin/accounts')
        ->assertOk()
        ->assertSee('No accounts')
        ->assertSee('Add an iCloud, CalDAV or Google account to get started.')
        ->assertDontSee('Nessun account');
});

it('shows the panel in Italian', function () {
    app()->setLocale('it');

    $this->actingAs(User::factory()->create())
        ->get('/admin/accounts')
        ->assertOk()
        ->assertSee('Nessun account')
        ->assertDontSee('No accounts');
});

it('serves the privacy page in English', function () {
    app()->setLocale('en');

    $this->get('/privacy')
        ->assertOk()
        ->assertSee('<html lang="en">', false)
        ->assertSee('Privacy policy');
});

it('translates labels, rule descriptions, weekdays and dates', function (string $locale, string $status, string $rule, string $days, string $date) {
    app()->setLocale($locale);

    $weekday = FilterRule::fromArray(['field' => 'weekday', 'weekdays' => [1, 6]]);
    $title = FilterRule::fromArray(['field' => 'title', 'operator' => 'starts_with', 'value' => '[Work]']);

    expect(SyncRunStatus::Partial->getLabel())->toBe($status)
        ->and($title->describe())->toBe($rule)
        ->and($weekday->describe())->toBe($days)
        ->and(DateFormat::date())->toBe($date);
})->with([
    'english' => ['en', 'Completed with errors', 'Title starts with "[Work]"', 'Day: Mon, Sat', 'Y-m-d'],
    'italian' => ['it', 'Completato con errori', 'Titolo inizia con «[Work]»', 'Giorno: lun, sab', 'd/m/Y'],
]);

it('uses ISO dates for locales without a date format', function () {
    app()->setLocale('de');

    expect(DateFormat::dateTime())->toBe('Y-m-d H:i');
});

it('defaults the busy title to the app language', function () {
    app()->setLocale('en');
    expect((new SyncJob)->busy_title)->toBe('Busy');

    app()->setLocale('it');
    expect((new SyncJob)->busy_title)->toBe('Occupato');
});

it('keeps the busy title stored on existing jobs', function () {
    $job = SyncJob::factory()->create(['busy_title' => 'Impegnato']);

    app()->setLocale('en');

    expect($job->fresh()->busy_title)->toBe('Impegnato');
});

it('picks plural forms in both languages', function (string $locale, string $one, string $many) {
    app()->setLocale($locale);

    expect(trans_choice('Found :count calendar.|Found :count calendars.', 1))->toBe($one)
        ->and(trans_choice('Found :count calendar.|Found :count calendars.', 3))->toBe($many);
})->with([
    'english' => ['en', 'Found 1 calendar.', 'Found 3 calendars.'],
    'italian' => ['it', 'Trovato 1 calendario.', 'Trovati 3 calendari.'],
]);
