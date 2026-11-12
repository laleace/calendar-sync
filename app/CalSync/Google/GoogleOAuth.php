<?php

namespace App\CalSync\Google;

use App\CalSync\Exceptions\ProviderException;
use App\CalSync\Support\SecretRedactor;
use Google\Service\Calendar as CalendarService;
use GuzzleHttp\Exception\GuzzleException;
use Throwable;

/**
 * OAuth2 web flow (authorization code + PKCE) for connecting a Google account.
 */
final class GoogleOAuth
{
    public function __construct(private readonly GoogleClientFactory $clients) {}

    public function isConfigured(): bool
    {
        return $this->clients->isConfigured();
    }

    public function redirectUri(): string
    {
        return $this->clients->redirectUri();
    }

    /**
     * The Google consent URL and the PKCE code verifier to keep in the session.
     *
     * @return array{url: string, verifier: string}
     */
    public function authorizationRequest(string $state, ?string $loginHint = null): array
    {
        $client = $this->clients->make();
        $client->setState($state);

        if (filled($loginHint)) {
            $client->setLoginHint($loginHint);
        }

        $verifier = $client->getOAuth2Service()->generateCodeVerifier();

        return ['url' => $client->createAuthUrl(), 'verifier' => $verifier];
    }

    /**
     * Exchange the authorization code for tokens and identify the Google account.
     *
     * @return array{email: string, access_token: string, refresh_token: string, expires_at: int, scope: string}
     *
     * @throws ProviderException
     */
    public function exchange(string $code, #[\SensitiveParameter] string $verifier): array
    {
        if ($code === '') {
            throw new ProviderException(__('Google did not return the authorization code.'));
        }

        $client = $this->clients->make();

        try {
            $token = $client->fetchAccessTokenWithAuthCode($code, $verifier);
        } catch (GuzzleException $e) {
            throw GoogleErrors::map($e);
        }

        if (! isset($token['access_token']) || isset($token['error'])) {
            throw new ProviderException(__('Google rejected the authorization code: :error', ['error' => SecretRedactor::redact((string) ($token['error_description'] ?? $token['error'] ?? __('unexpected response')))]));
        }

        // With granular consent the user can untick the calendar permission.
        if (! in_array(GoogleClientFactory::SCOPE, explode(' ', (string) ($token['scope'] ?? '')), true)) {
            throw new ProviderException(__('Calendar access was not granted: connect again and leave the calendar box ticked.'));
        }

        if (blank($token['refresh_token'] ?? null)) {
            throw new ProviderException(__('Google did not return a refresh token: connect again.'));
        }

        try {
            // The primary calendar id is the account email: no extra scope needed.
            $email = (new CalendarService($client))->calendarList->get('primary')->getId();
        } catch (Throwable $e) {
            throw GoogleErrors::map($e);
        }

        return [
            'email' => $email,
            'access_token' => $token['access_token'],
            'refresh_token' => $token['refresh_token'],
            'expires_at' => time() + (int) ($token['expires_in'] ?? 3600),
            'scope' => $token['scope'],
        ];
    }
}
