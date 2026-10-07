<?php

use App\Models\Account;
use App\Providers\AppServiceProvider;
use Illuminate\Support\Facades\Log;
use Tests\Support\FakeCalDav;

it('logs CalDAV requests with the Authorization header masked when enabled', function () {
    config(['calsync.http_log' => true]);
    (new AppServiceProvider(app()))->boot();

    $lines = [];
    Log::listen(function ($message) use (&$lines) {
        $lines[] = $message->message.' '.json_encode($message->context);
    });

    FakeCalDav::icloud()->install();
    $account = Account::factory()->create(['credentials' => ['username' => 'me@icloud.com', 'password' => 'abcd-efgh-ijkl-mnop']]);

    $this->artisan('calsync:test-account', ['id' => $account->id])->assertSuccessful();

    $log = implode("\n", $lines);

    expect($lines)->toHaveCount(3)
        ->and($log)->toContain('[calsync:http] PROPFIND https://caldav.icloud.com/ (Depth: 0) → 207')
        ->and($log)->toContain('Basic ***')
        ->and($log)->not->toContain(base64_encode('me@icloud.com:abcd-efgh-ijkl-mnop'));
});
