<?php

use App\Enums\AccountStatus;
use App\Filament\Resources\Accounts\AccountResource;
use App\Http\Controllers\GoogleOAuthController;
use App\Models\Account;
use App\Models\User;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\DB;
use Tests\Support\FakeGoogle;

beforeEach(function () {
    configureGoogleOAuth();
    $this->actingAs(User::factory()->create());
    $this->google = new FakeGoogle;
    $this->account = Account::factory()->google()->create(['name' => 'Google personale', 'credentials' => []]);
});

function pendingOAuth(Account $account, string $state = 'expected-state'): array
{
    return [GoogleOAuthController::SESSION_KEY => ['state' => $state, 'verifier' => 'pkce-verifier-123', 'account_id' => $account->id]];
}

function googleConsentSucceeds(FakeGoogle $google, string $email = 'mario.rossi@gmail.com'): FakeGoogle
{
    return $google
        ->token()
        ->on('GET', FakeGoogle::API.'/users/me/calendarList/primary', ['id' => $email])
        ->on('GET', FakeGoogle::API.'/users/me/calendarList', FakeGoogle::fixture('calendar-list.json'))
        ->install();
}

it('sends the user to the Google consent screen with state and PKCE', function () {
    $this->account->mergeCredentials(['email' => 'mario.rossi@gmail.com']);
    $this->account->save();

    $response = $this->get(route('oauth.google.redirect', $this->account));

    $location = $response->assertRedirect()->headers->get('Location');
    parse_str(parse_url($location, PHP_URL_QUERY), $query);
    $pending = session(GoogleOAuthController::SESSION_KEY);

    expect($location)->toStartWith('https://accounts.google.com/o/oauth2/v2/auth?')
        ->and($query)->toMatchArray([
            'client_id' => '1234567890-test.apps.googleusercontent.com',
            'redirect_uri' => url('/oauth/google/callback'),
            'response_type' => 'code',
            'scope' => 'https://www.googleapis.com/auth/calendar',
            'access_type' => 'offline',
            'prompt' => 'select_account consent',
            'login_hint' => 'mario.rossi@gmail.com',
            'code_challenge_method' => 'S256',
            'state' => $pending['state'],
        ])
        ->and($query['code_challenge'])->not->toBeEmpty()
        ->and($pending['account_id'])->toBe($this->account->id)
        ->and(strlen($pending['state']))->toBe(40);
});

it('requires a logged-in admin', function () {
    auth()->logout();

    $this->get(route('oauth.google.redirect', $this->account))->assertRedirect('/admin/login');
    $this->get(route('oauth.google.callback', ['state' => 'x', 'code' => 'y']))->assertRedirect('/admin/login');
});

it('only handles Google accounts', function () {
    $this->get(route('oauth.google.redirect', Account::factory()->create()))->assertNotFound();
});

it('explains a missing OAuth configuration', function () {
    config(['services.google.client_secret' => null]);

    $this->get(route('oauth.google.redirect', $this->account))
        ->assertRedirect(AccountResource::getUrl('view', ['record' => $this->account]));

    Notification::assertNotified('Google non configurato');
});

it('refuses to start when the panel is opened on another host than the redirect URI', function () {
    config(['services.google.redirect' => 'https://calsync.example.com/oauth/google/callback']);

    $this->get(route('oauth.google.redirect', $this->account))->assertRedirect();

    Notification::assertNotified('Indirizzo del pannello diverso dal redirect URI');
    expect(session(GoogleOAuthController::SESSION_KEY))->toBeNull();
});

it('connects the account and discovers its calendars', function () {
    googleConsentSucceeds($this->google);

    $this->withSession(pendingOAuth($this->account))
        ->get(route('oauth.google.callback', ['state' => 'expected-state', 'code' => '4/0AQlEd8x-auth-code', 'scope' => 'https://www.googleapis.com/auth/calendar']))
        ->assertRedirect(AccountResource::getUrl('view', ['record' => $this->account]))
        ->assertSessionMissing(GoogleOAuthController::SESSION_KEY);

    $account = $this->account->fresh();

    expect($account->credentials)->toMatchArray([
        'email' => 'mario.rossi@gmail.com',
        'access_token' => 'ya29.fresh-access-token',
        'refresh_token' => '1//fresh-refresh-token',
        'scope' => 'https://www.googleapis.com/auth/calendar',
    ])
        ->and($account->status)->toBe(AccountStatus::Ok)
        ->and($account->calendars()->count())->toBe(3)
        ->and(DB::table('accounts')->where('id', $account->id)->value('credentials'))->not->toContain('fresh-refresh-token');

    expect(FakeGoogle::body($this->google->sent('POST', FakeGoogle::TOKEN_URL)[0]))->toMatchArray([
        'grant_type' => 'authorization_code',
        'code' => '4/0AQlEd8x-auth-code',
        'code_verifier' => 'pkce-verifier-123',
        'redirect_uri' => url('/oauth/google/callback'),
    ]);

    Notification::assertNotified('Account Google collegato');
});

it('rejects a callback whose state does not match', function (?array $session) {
    $this->google->token()->install();

    $this->withSession($session ?? [])
        ->get(route('oauth.google.callback', ['state' => 'forged-state', 'code' => 'code']))
        ->assertRedirect(AccountResource::getUrl('index'));

    expect($this->google->requests)->toBeEmpty()
        ->and($this->account->fresh()->credentials)->toBe([]);
    Notification::assertNotified('Collegamento Google non valido');
})->with([
    'wrong state' => fn () => pendingOAuth($this->account),
    'no pending request' => null,
]);

it('cannot replay a callback', function () {
    googleConsentSucceeds($this->google);
    $url = route('oauth.google.callback', ['state' => 'expected-state', 'code' => 'code']);

    $this->withSession(pendingOAuth($this->account))->get($url);
    $this->get($url)->assertRedirect(AccountResource::getUrl('index'));

    expect($this->google->sent('POST', FakeGoogle::TOKEN_URL))->toHaveCount(1);
});

it('handles a consent denied by the user', function () {
    $this->google->install();

    $this->withSession(pendingOAuth($this->account))
        ->get(route('oauth.google.callback', ['state' => 'expected-state', 'error' => 'access_denied']))
        ->assertRedirect(AccountResource::getUrl('view', ['record' => $this->account]));

    expect($this->google->requests)->toBeEmpty();
    Notification::assertNotified('Autorizzazione annullata');
});

it('requires the calendar permission and a refresh token', function (array $token, string $message) {
    $this->google->token($token)->install();

    $this->withSession(pendingOAuth($this->account))
        ->get(route('oauth.google.callback', ['state' => 'expected-state', 'code' => 'code']));

    expect($this->account->fresh()->credentials)->toBe([]);
    Notification::assertNotified(Notification::make()->title('Collegamento Google non riuscito')->body($message)->danger()->persistent());
})->with([
    'calendar box unticked' => [['scope' => 'openid'], 'Permesso di accesso al calendario non concesso: ripeti il collegamento lasciando selezionata la casella del calendario.'],
    'no refresh token' => [['refresh_token' => null], 'Google non ha restituito un refresh token: ripeti il collegamento.'],
]);

it('refuses to reconnect a different Google account', function () {
    $this->account->update(['credentials' => ['email' => 'mario.rossi@gmail.com', 'refresh_token' => '1//old', 'access_token' => 'ya29.old', 'expires_at' => 0]]);
    googleConsentSucceeds($this->google, email: 'altro.account@gmail.com');

    $this->withSession(pendingOAuth($this->account))
        ->get(route('oauth.google.callback', ['state' => 'expected-state', 'code' => 'code']));

    expect($this->account->fresh()->credential('refresh_token'))->toBe('1//old');
    Notification::assertNotified('Account Google diverso');
});

it('reconnects an expired account', function () {
    $this->account->update([
        'status' => AccountStatus::TokenExpired,
        'credentials' => ['email' => 'mario.rossi@gmail.com', 'refresh_token' => '1//revoked', 'access_token' => 'ya29.old', 'expires_at' => 0],
    ]);
    googleConsentSucceeds($this->google);

    $this->withSession(pendingOAuth($this->account))
        ->get(route('oauth.google.callback', ['state' => 'expected-state', 'code' => 'code']));

    expect($this->account->fresh())
        ->status->toBe(AccountStatus::Ok)
        ->and($this->account->fresh()->credential('refresh_token'))->toBe('1//fresh-refresh-token');
});
