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

it('exposes no API routes yet', function () {
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
