<?php

namespace App\CalSync\CalDav;

use App\CalSync\Exceptions\AuthenticationFailed;
use App\CalSync\Exceptions\ProviderException;
use App\CalSync\Exceptions\ServerUnreachable;
use App\CalSync\Support\SecretRedactor;
use GuzzleHttp\Psr7\Uri;
use GuzzleHttp\Psr7\UriResolver;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Low-level CalDAV HTTP client: Basic auth, WebDAV methods, manual redirects.
 *
 * Redirects are followed by hand because Guzzle turns a PROPFIND/REPORT into a GET on
 * 301/302, and because every hop must pass the HostPolicy before credentials are sent.
 */
final class CalDavClient
{
    private const MAX_REDIRECTS = 5;

    private const REDIRECT_STATUSES = [301, 302, 307, 308];

    public function __construct(
        private readonly string $username,
        #[\SensitiveParameter] private readonly string $password,
        private readonly HostPolicy $hosts,
    ) {}

    /**
     * @throws ProviderException
     */
    public function propfind(string $url, string $body, int $depth): Multistatus
    {
        return $this->multistatus('PROPFIND', $url, $body, $depth);
    }

    /**
     * @throws ProviderException
     */
    public function report(string $url, string $body, int $depth = 1): Multistatus
    {
        return $this->multistatus('REPORT', $url, $body, $depth);
    }

    private function multistatus(string $method, string $url, string $body, int $depth): Multistatus
    {
        [$finalUrl, $response] = $this->request($method, $url, $body, ['Depth' => (string) $depth]);

        if ($response->status() !== 207) {
            throw $this->unexpectedResponse($response, $finalUrl);
        }

        return new Multistatus($finalUrl, MultistatusParser::parse($response->body()));
    }

    /**
     * Send a request, following redirects. Returns the final URL and the response.
     *
     * @param  array<string, string>  $headers  Override the defaults, Accept included.
     * @return array{0: string, 1: Response}
     *
     * @throws ProviderException
     */
    public function request(
        string $method,
        string $url,
        ?string $body = null,
        array $headers = [],
        string $contentType = 'application/xml; charset=utf-8',
    ): array {
        for ($hop = 0; $hop <= self::MAX_REDIRECTS; $hop++) {
            if (! $this->hosts->allows($url)) {
                throw new ProviderException(__('The server pointed to an address not allowed for this account (:url): the credentials were not sent.', ['url' => self::describe($url)]));
            }

            $request = Http::withBasicAuth($this->username, $this->password)
                ->withUserAgent('calendar-sync/1.0')
                ->accept('application/xml')
                ->withHeaders($headers)
                ->connectTimeout(10)
                ->timeout(30)
                ->withoutRedirecting();

            if ($body !== null) {
                $request = $request->withBody($body, $contentType);
            }

            try {
                $response = $request->send($method, $url);
            } catch (ConnectionException $e) {
                throw new ServerUnreachable(
                    __('Connection to :host failed: :error', ['host' => (new Uri($url))->getHost(), 'error' => SecretRedactor::redact($e->getMessage(), [$this->password])]),
                    previous: $e,
                );
            }

            $location = $response->header('Location');

            if (! in_array($response->status(), self::REDIRECT_STATUSES, true) || $location === '') {
                return [$url, $response];
            }

            $url = (string) UriResolver::resolve(new Uri($url), new Uri($location));
        }

        throw new ProviderException(__('Too many redirects from the CalDAV server.'));
    }

    private function unexpectedResponse(Response $response, string $url): ProviderException
    {
        return match ($response->status()) {
            401 => new AuthenticationFailed(__('Credentials rejected by the server (HTTP 401). Check username and password; iCloud needs an app-specific password.')),
            403 => new ProviderException(__('Access denied (HTTP 403) to :url.', ['url' => self::describe($url)])),
            404 => new ProviderException(__('Resource not found (HTTP 404): :url.', ['url' => self::describe($url)])),
            default => new ProviderException(__('Unexpected HTTP :status response from :url.', ['status' => $response->status(), 'url' => self::describe($url)])),
        };
    }

    /**
     * Host and path, without query string, for error messages.
     */
    private static function describe(string $url): string
    {
        $uri = new Uri($url);

        return $uri->getHost().$uri->getPath();
    }
}
