<?php

use App\Enums\AccountStatus;
use App\Filament\Resources\Accounts\AccountResource;
use App\Models\Account;
use App\Models\User;

beforeEach(function () {
    $this->actingAs(User::factory()->create());
});

it('shows a banner on every page for accounts with rejected credentials', function () {
    $broken = Account::factory()->create([
        'name' => 'iCloud personale',
        'status' => AccountStatus::AuthFailed,
        'status_message' => 'Credenziali rifiutate dal server (HTTP 401).',
    ]);
    Account::factory()->create(['name' => 'Funzionante', 'status' => AccountStatus::Ok]);

    foreach (['/admin', AccountResource::getUrl('index')] as $url) {
        $this->get($url)
            ->assertOk()
            ->assertSee('Account “iCloud personale”: Credenziali non valide')
            ->assertSee('Credenziali rifiutate dal server (HTTP 401).')
            ->assertSee(AccountResource::getUrl('edit', ['record' => $broken]))
            ->assertDontSee('Account “Funzionante”');
    }
});

it('offers a reconnect link for expired tokens', function () {
    Account::factory()->google()->create(['name' => 'Google lavoro', 'status' => AccountStatus::TokenExpired]);

    $this->get('/admin')->assertSee('Account “Google lavoro”: Token scaduto')->assertSee('Riconnetti');
});

it('shows no banner when every account is fine', function () {
    Account::factory()->create(['status' => AccountStatus::Ok]);

    $this->get('/admin')->assertDontSee('fi-callout', escape: false);
});
