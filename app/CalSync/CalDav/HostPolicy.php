<?php

namespace App\CalSync\CalDav;

use GuzzleHttp\Psr7\Uri;

/**
 * Decides which hosts may receive the account credentials. Discovery follows URLs
 * returned by the server (redirects, calendar-home-set): without this check a
 * malicious or compromised server could make us send the password elsewhere.
 */
final readonly class HostPolicy
{
    private function __construct(
        private bool $allowHttp,
        private ?string $host,
        private ?string $domain,
    ) {}

    /**
     * iCloud: caldav.icloud.com plus the pXX-caldav.icloud.com shards, HTTPS only.
     */
    public static function icloud(): self
    {
        return new self(allowHttp: false, host: null, domain: 'icloud.com');
    }

    /**
     * Generic server: the host of the configured URL. Plain HTTP is accepted only when
     * the configured URL itself is HTTP (e.g. a LAN server); HTTPS is never downgraded.
     */
    public static function sameHostAs(string $baseUrl): self
    {
        $uri = new Uri($baseUrl);

        return new self(
            allowHttp: strtolower($uri->getScheme()) === 'http',
            host: strtolower($uri->getHost()),
            domain: null,
        );
    }

    public function allows(string $url): bool
    {
        $uri = new Uri($url);
        $scheme = strtolower($uri->getScheme());
        $host = strtolower($uri->getHost());

        if ($host === '' || ! ($scheme === 'https' || ($scheme === 'http' && $this->allowHttp))) {
            return false;
        }

        if ($this->domain !== null) {
            return $host === $this->domain || str_ends_with($host, '.'.$this->domain);
        }

        return $host === $this->host;
    }
}
