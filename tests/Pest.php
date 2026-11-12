<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

// Unit tests need the app only for the translator: user-facing messages go through __().
pest()->extend(TestCase::class)
    ->in('Unit');

/**
 * A configured Google OAuth client whose redirect URI matches the test host.
 */
function configureGoogleOAuth(): void
{
    config([
        'services.google.client_id' => '1234567890-test.apps.googleusercontent.com',
        'services.google.client_secret' => 'GOCSPX-test-client-secret',
        'services.google.redirect' => url('/oauth/google/callback'),
    ]);
}
