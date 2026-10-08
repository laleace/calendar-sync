<?php

use App\Models\User;
use Filament\Auth\Pages\Login;
use Livewire\Livewire;

it('redirects the root to the panel', function () {
    $this->get('/')->assertRedirect('/admin');
});

it('sends guests to the login page', function () {
    $this->get('/admin')->assertRedirect('/admin/login');
    $this->get('/admin/login')->assertOk();
});

it('shows the dashboard to the admin', function () {
    $this->actingAs(User::factory()->create())
        ->get('/admin')
        ->assertOk()
        ->assertSee('Calendar Sync');
});

it('has no public registration or password reset', function () {
    $this->get('/admin/register')->assertNotFound();
    $this->get('/admin/password-reset/request')->assertNotFound();
});

it('exposes no API', function () {
    $this->getJson('/api/user')->assertNotFound();
});

it('logs the admin in through the login form', function () {
    $user = User::factory()->create(['password' => 'una-password-lunga']);

    Livewire::test(Login::class)
        ->set('data.email', $user->email)
        ->set('data.password', 'una-password-lunga')
        ->call('authenticate')
        ->assertHasNoErrors()
        ->assertRedirect('/admin');

    $this->assertAuthenticatedAs($user);
});

it('rejects a wrong password', function () {
    $user = User::factory()->create(['password' => 'una-password-lunga']);

    Livewire::test(Login::class)
        ->set('data.email', $user->email)
        ->set('data.password', 'sbagliata')
        ->call('authenticate')
        ->assertHasErrors(['data.email']);

    $this->assertGuest();
});

it('serves the privacy page without login', function () {
    $this->get('/privacy')
        ->assertOk()
        ->assertSee('Informativa sulla privacy')
        ->assertSee('myaccount.google.com/permissions');
});

it('honours X-Forwarded-Proto only from local proxies', function (string $remoteAddress, string $expectedScheme) {
    $this->withServerVariables(['REMOTE_ADDR' => $remoteAddress])
        ->withHeaders(['X-Forwarded-Proto' => 'https', 'X-Forwarded-Port' => '443'])
        ->get('http://calsync.example.test/admin/login')
        ->assertOk()
        ->assertSee($expectedScheme.'://calsync.example.test/css/filament/filament/app.css', escape: false);
})->with([
    'cloudflared on the same machine' => ['127.0.0.1', 'https'],
    'any other client' => ['192.168.1.50', 'http'],
]);
