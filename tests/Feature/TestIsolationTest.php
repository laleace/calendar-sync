<?php

use App\CalSync\Google\GoogleProvider;
use App\Models\Account;
use Illuminate\Support\Facades\Http;

it('ignores the Google OAuth client configured in .env', function () {
    expect(config('services.google.client_id'))->toBeEmpty()
        ->and(config('services.google.client_secret'))->toBeEmpty();
});

it('blocks Google requests that are not faked', function () {
    configureGoogleOAuth();

    $result = app(GoogleProvider::class)->testConnection(Account::factory()->google()->create());

    expect($result->successful())->toBeFalse()
        ->and($result->message)->toContain('Richiesta Google non simulata');
});

it('blocks CalDAV requests that are not faked', function () {
    expect(fn () => Http::get('https://caldav.icloud.com/'))->toThrow(RuntimeException::class);
});
