<?php

use App\Models\User;
use Illuminate\Support\Facades\Hash;

it('creates the admin user', function () {
    $this->artisan('calsync:create-admin', ['--email' => 'admin@example.com', '--name' => 'Admin'])
        ->expectsQuestion('Password (min. 12 caratteri)', 'una-password-lunga')
        ->expectsQuestion('Conferma password', 'una-password-lunga')
        ->assertSuccessful();

    $user = User::sole();

    expect($user->email)->toBe('admin@example.com')
        ->and(Hash::check('una-password-lunga', $user->password))->toBeTrue();
});

it('rejects short or mismatching passwords', function (string $password, string $confirmation) {
    $this->artisan('calsync:create-admin', ['--email' => 'admin@example.com', '--name' => 'Admin'])
        ->expectsQuestion('Password (min. 12 caratteri)', $password)
        ->expectsQuestion('Conferma password', $confirmation)
        ->assertFailed();

    expect(User::count())->toBe(0);
})->with([
    'too short' => ['corta', 'corta'],
    'mismatch' => ['una-password-lunga', 'un-altra-password'],
]);

it('refuses to create a second admin', function () {
    User::factory()->create(['email' => 'admin@example.com']);

    $this->artisan('calsync:create-admin', ['--email' => 'other@example.com'])
        ->assertFailed();

    expect(User::count())->toBe(1);
});

it('resets the password of the existing admin', function () {
    $user = User::factory()->create(['email' => 'admin@example.com']);

    $this->artisan('calsync:create-admin', ['--email' => 'admin@example.com'])
        ->expectsConfirmation("L'utente admin@example.com esiste già. Reimpostare la password?", 'yes')
        ->expectsQuestion('Password (min. 12 caratteri)', 'nuova-password-sicura')
        ->expectsQuestion('Conferma password', 'nuova-password-sicura')
        ->assertSuccessful();

    expect(Hash::check('nuova-password-sicura', $user->fresh()->password))->toBeTrue();
});
