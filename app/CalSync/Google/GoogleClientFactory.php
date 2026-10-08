<?php

namespace App\CalSync\Google;

use App\CalSync\Exceptions\ProviderException;
use App\CalSync\Exceptions\ServerUnreachable;
use App\CalSync\Exceptions\TokenExpired;
use App\CalSync\Support\SecretRedactor;
use App\Models\Account;
use Closure;
use Google\Client;
use Google\Service\Calendar;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\HandlerStack;

/**
 * Builds Google API clients. Access tokens are renewed before use and the new token is
 * stored on the account right away: the only database write done below the service layer.
 */
final class GoogleClientFactory
{
    public const SCOPE = Calendar::CALENDAR;

    /** Renew the access token when it expires within this many seconds. */
    private const EXPIRY_MARGIN = 60;

    /**
     * @param  Closure|null  $handler  Guzzle handler; tests replace it with a fake.
     * @param  int  $retries  Retries on 5xx and rate-limit errors, with exponential backoff.
     */
    public function __construct(
        private readonly ?Closure $handler = null,
        private readonly int $retries = 3,
    ) {}

    public function isConfigured(): bool
    {
        return filled(config('services.google.client_id')) && filled(config('services.google.client_secret'));
    }

    public function redirectUri(): string
    {
        return config('services.google.redirect');
    }

    /**
     * @throws ProviderException when the OAuth client is not configured.
     */
    public function make(): Client
    {
        if (! $this->isConfigured()) {
            throw new ProviderException('Google non è configurato: imposta GOOGLE_CLIENT_ID e GOOGLE_CLIENT_SECRET nel file .env (vedi docs/google-oauth.md).');
        }

        $client = new Client([
            'client_id' => config('services.google.client_id'),
            'client_secret' => config('services.google.client_secret'),
            'redirect_uri' => $this->redirectUri(),
            'application_name' => 'calendar-sync',
            'retry' => ['retries' => $this->retries],
        ]);

        $client->setScopes([self::SCOPE]);
        $client->setAccessType('offline');
        // select_account: always let the user pick the Google account (a new panel account is
        // often a different Google account). consent: guarantees a refresh token every time.
        $client->setPrompt('select_account consent');
        $client->setHttpClient(new GuzzleClient([
            'base_uri' => $client->getConfig('base_path'),
            'handler' => HandlerStack::create($this->handler),
            'http_errors' => false,
            'connect_timeout' => 10,
            'timeout' => 30,
        ]));

        return $client;
    }

    /**
     * A client authorized for the account, renewing (and storing) the access token if needed.
     *
     * @throws TokenExpired when the account is not connected or Google refuses the refresh token.
     * @throws ProviderException
     */
    public function forAccount(Account $account): Client
    {
        if ($account->hasUnreadableCredentials()) {
            throw new TokenExpired('I token salvati non si possono decifrare: la APP_KEY è cambiata da quando l\'account è stato collegato. Riconnetti l\'account Google.');
        }

        $refreshToken = $account->credential('refresh_token');

        if (blank($refreshToken)) {
            throw new TokenExpired('Account non collegato a Google: usa "Connetti con Google".');
        }

        $client = $this->make();
        $expiresAt = (int) $account->credential('expires_at');

        if (blank($account->credential('access_token')) || $expiresAt - self::EXPIRY_MARGIN <= time()) {
            $this->refresh($client, $account, $refreshToken);
            $expiresAt = (int) $account->credential('expires_at');
        }

        $client->setAccessToken([
            'access_token' => $account->credential('access_token'),
            'refresh_token' => $refreshToken,
            'expires_in' => max(1, $expiresAt - time()),
            'created' => time(),
        ]);

        return $client;
    }

    private function refresh(Client $client, Account $account, #[\SensitiveParameter] string $refreshToken): void
    {
        try {
            $token = $client->fetchAccessTokenWithRefreshToken($refreshToken);
        } catch (GuzzleException $e) {
            throw new ServerUnreachable('Connessione a Google non riuscita: '.SecretRedactor::redact($e->getMessage(), [$refreshToken]), previous: $e);
        }

        if (! isset($token['access_token']) || isset($token['error'])) {
            $error = (string) ($token['error'] ?? 'risposta inattesa');

            throw match ($error) {
                'invalid_grant' => new TokenExpired('Il collegamento con Google è scaduto o è stato revocato: riconnetti l\'account.'),
                'invalid_client', 'unauthorized_client' => new ProviderException('Google ha rifiutato il client OAuth: controlla GOOGLE_CLIENT_ID e GOOGLE_CLIENT_SECRET.'),
                default => new ProviderException('Rinnovo del token Google non riuscito: '.SecretRedactor::redact((string) ($token['error_description'] ?? $error))),
            };
        }

        $account->mergeCredentials(array_filter([
            'access_token' => $token['access_token'],
            'expires_at' => time() + (int) ($token['expires_in'] ?? 3600),
            // Google may rotate the refresh token.
            'refresh_token' => $token['refresh_token'] ?? null,
        ]));
        $account->save();
    }
}
