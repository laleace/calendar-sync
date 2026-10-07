<?php

use App\Enums\AccountStatus;
use App\Models\Account;
use Tests\Support\FakeCalDav;

it('runs discovery and lists the calendars', function () {
    FakeCalDav::icloud()->install();
    $account = Account::factory()->create(['name' => 'iCloud personale']);

    $this->artisan('calsync:test-account', ['id' => $account->id])
        ->expectsOutputToContain('https://caldav.icloud.com/123456789/principal/')
        ->expectsOutputToContain('https://p42-caldav.icloud.com/123456789/calendars/')
        ->expectsTable(['ID', 'Nome', 'Scrittura', 'Colore', 'Timezone', 'URL'], [
            [1, 'Casa', 'sì', '#FF2968', 'Europe/Rome', 'https://p42-caldav.icloud.com/123456789/calendars/home/'],
            [2, 'Lavoro & Ufficio', 'sì', '#1BADF8', '—', 'https://p42-caldav.icloud.com/123456789/calendars/8F1B2C3D-0000-4000-8000-ABCDEF012345/'],
            [3, 'Famiglia (condiviso)', 'no', '—', '—', 'https://p42-caldav.icloud.com/123456789/calendars/C0FFEE00-1111-4222-8333-444455556666/'],
            [4, 'Compleanni amici', 'sì', '—', '—', 'https://p42-caldav.icloud.com/123456789/calendars/Compleanni%20amici/'],
        ])
        ->expectsOutputToContain('Nuovi: 4 · Aggiornati: 0 · Non più trovati: 0')
        ->doesntExpectOutputToContain('Promemoria')
        ->assertSuccessful();

    expect($account->calendars()->count())->toBe(4)
        ->and($account->fresh()->status)->toBe(AccountStatus::Ok);
});

it('fails clearly on rejected credentials', function () {
    (new FakeCalDav)->status('PROPFIND', 'https://caldav.icloud.com/', 401)->install();
    $account = Account::factory()->create();

    $this->artisan('calsync:test-account', ['id' => $account->id])
        ->expectsOutputToContain('password specifica per app')
        ->assertFailed();

    expect($account->fresh()->status)->toBe(AccountStatus::AuthFailed);
});

it('fails on an unknown account', function () {
    $this->artisan('calsync:test-account', ['id' => 999])->assertFailed();
});

it('prints HTTP exchanges in debug mode with credentials masked', function () {
    FakeCalDav::icloud()->install();
    $account = Account::factory()->create([
        'credentials' => ['username' => 'mario@icloud.com', 'password' => 'abcd-efgh-ijkl-mnop'],
    ]);

    $this->artisan('calsync:test-account', ['id' => $account->id, '--debug' => true])
        ->expectsOutputToContain('PROPFIND https://caldav.icloud.com/ (Depth: 0) → 207')
        ->expectsOutputToContain('Authorization: Basic ***')
        ->expectsOutputToContain('current-user-principal')
        ->doesntExpectOutputToContain(base64_encode('mario@icloud.com:abcd-efgh-ijkl-mnop'))
        ->doesntExpectOutputToContain('abcd-efgh-ijkl-mnop')
        ->assertSuccessful();
});
