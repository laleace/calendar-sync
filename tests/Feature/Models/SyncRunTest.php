<?php

use App\Enums\SyncRunStatus;
use App\Enums\SyncTrigger;
use App\Models\SyncRun;

it('casts counters, enums and json payloads', function () {
    $run = SyncRun::factory()->finished(SyncRunStatus::Partial)->create([
        'triggered_by' => SyncTrigger::Api,
        'error_count' => 1,
        'errors' => [['op' => 'create', 'uid' => 'abc', 'message' => 'boom']],
    ])->fresh();

    expect($run->status)->toBe(SyncRunStatus::Partial)
        ->and($run->triggered_by)->toBe(SyncTrigger::Api)
        ->and($run->error_count)->toBe(1)
        ->and($run->errors[0]['message'])->toBe('boom');
});

it('prunes runs older than the retention period', function () {
    config(['calsync.run_retention_days' => 30]);

    $old = SyncRun::factory()->create(['created_at' => now()->subDays(31)]);
    $recent = SyncRun::factory()->create(['created_at' => now()->subDays(29)]);

    $this->artisan('model:prune', ['--model' => [SyncRun::class]])->assertSuccessful();

    expect(SyncRun::find($old->id))->toBeNull()
        ->and(SyncRun::find($recent->id))->not->toBeNull();
});
