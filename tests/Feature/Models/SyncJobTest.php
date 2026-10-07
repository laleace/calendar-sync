<?php

use App\Enums\PrivacyMode;
use App\Enums\SyncDirection;
use App\Models\Calendar;
use App\Models\EventMapping;
use App\Models\SyncJob;
use App\Models\SyncRun;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;

it('has sensible defaults', function () {
    $job = SyncJob::create(['name' => 'Test', 'target_calendar_id' => Calendar::factory()->create()->id])->fresh();

    expect($job->enabled)->toBeFalse()
        ->and($job->direction)->toBe(SyncDirection::OneWay)
        ->and($job->privacy_mode)->toBe(PrivacyMode::Full)
        ->and($job->busy_title)->toBe('Occupato')
        ->and($job->allow_duplicates)->toBeFalse()
        ->and($job->skip_existing_in_target)->toBeFalse();
});

it('keeps sources ordered by priority', function () {
    $job = SyncJob::factory()->create();
    [$a, $b, $c] = Calendar::factory()->count(3)->create();

    $job->syncSources([$c->id, $a->id, $b->id]);

    expect($job->sources()->pluck('calendars.id')->all())->toBe([$c->id, $a->id, $b->id]);

    $job->syncSources([$b->id, $c->id]);

    expect($job->sources()->pluck('calendars.id')->all())->toBe([$b->id, $c->id]);
});

it('computes the sync window in the configured timezone', function () {
    config(['calsync.timezone' => 'Europe/Rome']);
    $job = SyncJob::factory()->make(['range_past_days' => 7, 'range_future_days' => 30]);

    // 23:30 UTC on Oct 7 is already Oct 8 in Rome.
    [$from, $to] = $job->syncWindow(CarbonImmutable::parse('2026-10-07 23:30:00', 'UTC'));

    expect($from->toIso8601String())->toBe('2026-10-01T00:00:00+02:00')
        ->and($to->format('Y-m-d H:i:s P'))->toBe('2026-11-07 23:59:59 +01:00');
});

it('deletes mappings and runs together with the job', function () {
    $job = SyncJob::factory()->create();
    EventMapping::factory()->for($job)->create();
    SyncRun::factory()->for($job)->create();

    $job->delete();

    expect(EventMapping::count())->toBe(0)
        ->and(SyncRun::count())->toBe(0);
});

it('refuses to delete a calendar used by a job', function () {
    $job = SyncJob::factory()->create();
    $source = Calendar::factory()->create();
    $job->syncSources([$source->id]);

    expect(fn () => $source->delete())->toThrow(QueryException::class)
        ->and(fn () => $job->target->delete())->toThrow(QueryException::class);
});

it('scopes writable calendars', function () {
    Calendar::factory()->create(['name' => 'rw']);
    Calendar::factory()->readOnly()->create(['name' => 'ro']);
    Calendar::factory()->create(['name' => 'gone', 'missing_since' => now()]);

    expect(Calendar::writable()->pluck('name')->all())->toBe(['rw']);
});
