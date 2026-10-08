<?php

namespace App\Http\Controllers;

use App\CalSync\AccountService;
use App\CalSync\Exceptions\ProviderException;
use App\CalSync\Google\GoogleOAuth;
use App\Enums\AccountStatus;
use App\Enums\AccountType;
use App\Filament\Actions\DiscoverCalendarsAction;
use App\Filament\Resources\Accounts\AccountResource;
use App\Models\Account;
use Filament\Notifications\Notification;
use GuzzleHttp\Psr7\Uri;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Connects (or reconnects) a Google account with the OAuth2 web flow.
 */
class GoogleOAuthController extends Controller
{
    public const SESSION_KEY = 'calsync.google_oauth';

    public function redirect(Request $request, Account $account, GoogleOAuth $oauth): RedirectResponse
    {
        abort_unless($account->type === AccountType::Google, 404);

        $back = AccountResource::getUrl('view', ['record' => $account]);

        if (! $oauth->isConfigured()) {
            return $this->fail($back, 'Google non configurato', 'Imposta GOOGLE_CLIENT_ID e GOOGLE_CLIENT_SECRET nel file .env (vedi docs/google-oauth.md).');
        }

        // The session cookie is bound to the host: coming back on another host (127.0.0.1 vs
        // localhost) would lose the state and fail the check.
        $callbackOrigin = self::origin($oauth->redirectUri());

        if ($callbackOrigin !== self::origin($request->getSchemeAndHttpHost())) {
            return $this->fail($back, 'Indirizzo del pannello diverso dal redirect URI', "Apri il pannello da {$callbackOrigin}, lo stesso indirizzo di GOOGLE_REDIRECT_URI, e riprova.");
        }

        $state = Str::random(40);
        ['url' => $url, 'verifier' => $verifier] = $oauth->authorizationRequest($state, $account->credential('email'));

        $request->session()->put(self::SESSION_KEY, [
            'state' => $state,
            'verifier' => $verifier,
            'account_id' => $account->id,
        ]);

        return redirect()->away($url);
    }

    public function callback(Request $request, GoogleOAuth $oauth, AccountService $accounts): RedirectResponse
    {
        // One-time use: the pending request is removed whatever happens next.
        $pending = $request->session()->pull(self::SESSION_KEY);
        $state = $request->query('state');
        $account = is_array($pending) ? Account::find($pending['account_id'] ?? null) : null;

        if ($account === null || ! is_string($state) || ! hash_equals((string) ($pending['state'] ?? ''), $state)) {
            return $this->fail(AccountResource::getUrl('index'), 'Collegamento Google non valido', 'La richiesta è scaduta o non corrisponde. Riprova con "Connetti con Google".');
        }

        $back = AccountResource::getUrl('view', ['record' => $account]);

        if ($request->filled('error')) {
            return $this->fail($back, 'Autorizzazione annullata', 'Google ha risposto: '.Str::limit((string) $request->query('error'), 100));
        }

        try {
            $credentials = $oauth->exchange((string) $request->query('code', ''), (string) $pending['verifier']);
        } catch (ProviderException $e) {
            return $this->fail($back, 'Collegamento Google non riuscito', $e->getMessage());
        }

        $previous = $account->credential('email');

        if (filled($previous) && strcasecmp($previous, $credentials['email']) !== 0) {
            return $this->fail($back, 'Account Google diverso', "Hai autorizzato {$credentials['email']}, ma questo account è collegato a {$previous}. Per un altro account Google crea un nuovo account.");
        }

        $account->forceFill([
            'credentials' => $credentials,
            'status' => AccountStatus::Ok,
            'status_message' => "Collegato come {$credentials['email']}.",
            'last_checked_at' => now(),
        ])->save();

        Notification::make()
            ->title('Account Google collegato')
            ->body("Collegato come {$credentials['email']}.")
            ->success()
            ->send();

        DiscoverCalendarsAction::discover($account, $accounts);

        return redirect($back);
    }

    private function fail(string $url, string $title, string $body): RedirectResponse
    {
        Notification::make()->title($title)->body($body)->danger()->persistent()->send();

        return redirect($url);
    }

    private static function origin(string $url): string
    {
        $uri = new Uri($url);

        return strtolower($uri->getScheme().'://'.$uri->getAuthority());
    }
}
