<?php

use App\Enums\AccountStatus;
use App\Enums\AccountType;
use App\Enums\SyncRunStatus;

it('distinguishes CalDAV accounts from Google', function () {
    expect(AccountType::IcloudCaldav->isCalDav())->toBeTrue()
        ->and(AccountType::CaldavGeneric->isCalDav())->toBeTrue()
        ->and(AccountType::Google->isCalDav())->toBeFalse();
});

it('flags statuses that need a reconnect', function () {
    expect(AccountStatus::TokenExpired->needsAttention())->toBeTrue()
        ->and(AccountStatus::AuthFailed->needsAttention())->toBeTrue()
        ->and(AccountStatus::Ok->needsAttention())->toBeFalse()
        ->and(AccountStatus::Error->needsAttention())->toBeFalse();
});

it('knows which run statuses are final', function () {
    expect(SyncRunStatus::Queued->isFinished())->toBeFalse()
        ->and(SyncRunStatus::Running->isFinished())->toBeFalse()
        ->and(SyncRunStatus::Success->isFinished())->toBeTrue()
        ->and(SyncRunStatus::Skipped->isFinished())->toBeTrue();
});
