<?php

use App\Enums\AccountStatus;
use App\Enums\AccountType;
use App\Models\Account;
use App\Models\Calendar;
use App\Models\SyncJob;
use Illuminate\Support\Facades\DB;

it('stores credentials encrypted at rest', function () {
    $account = Account::factory()->create([
        'credentials' => ['username' => 'me@icloud.com', 'password' => 'abcd-efgh-ijkl-mnop'],
    ]);

    $raw = DB::table('accounts')->where('id', $account->id)->value('credentials');

    expect($raw)->not->toContain('abcd-efgh-ijkl-mnop')
        ->not->toContain('me@icloud.com')
        ->and(decrypt($raw, unserialize: false))->toContain('abcd-efgh-ijkl-mnop')
        ->and($account->fresh()->credential('password'))->toBe('abcd-efgh-ijkl-mnop');
});

it('never serializes credentials', function () {
    $account = Account::factory()->google()->create();

    expect($account->toArray())->not->toHaveKey('credentials')
        ->and($account->toJson())->not->toContain('refresh-token')
        ->and($account->toJson())->not->toContain('access-token');
});

it('merges credentials without dropping existing keys', function () {
    $account = Account::factory()->google()->create();

    $account->mergeCredentials(['access_token' => 'ya29.new']);
    $account->save();

    expect($account->fresh()->credentials)
        ->toMatchArray(['access_token' => 'ya29.new', 'refresh_token' => '1//test-refresh-token']);
});

it('casts type and status to enums', function () {
    $account = Account::factory()->create()->fresh();

    expect($account->type)->toBe(AccountType::IcloudCaldav)
        ->and($account->status)->toBe(AccountStatus::Unknown);
});

it('knows when its calendars are used by a sync job', function () {
    $account = Account::factory()->create();
    $calendar = Calendar::factory()->for($account)->create();

    expect($account->isInUse())->toBeFalse();

    SyncJob::factory()->create()->syncSources([$calendar->id]);
    expect($account->isInUse())->toBeTrue();

    $other = Account::factory()->create();
    SyncJob::factory()->create(['target_calendar_id' => Calendar::factory()->for($other)]);
    expect($other->isInUse())->toBeTrue();
});
