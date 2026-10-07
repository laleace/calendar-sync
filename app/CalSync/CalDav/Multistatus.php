<?php

namespace App\CalSync\CalDav;

use GuzzleHttp\Psr7\Uri;
use GuzzleHttp\Psr7\UriResolver;

final readonly class Multistatus
{
    /**
     * @param  string  $url  The URL that produced the response, after redirects. Relative hrefs resolve against it.
     * @param  list<DavResponse>  $responses
     */
    public function __construct(
        public string $url,
        public array $responses,
    ) {}

    /**
     * Resolve an href (relative path or absolute URL) to a normalized absolute URL.
     * Default ports are dropped: "https://host:443/x" becomes "https://host/x".
     */
    public function resolve(string $href): string
    {
        return (string) UriResolver::resolve(new Uri($this->url), new Uri($href));
    }

    /**
     * The response describing the requested resource itself.
     */
    public function self(): ?DavResponse
    {
        foreach ($this->responses as $response) {
            if (self::sameResource($this->resolve($response->href), $this->url)) {
                return $response;
            }
        }

        return $this->responses[0] ?? null;
    }

    public static function sameResource(string $a, string $b): bool
    {
        $normalize = function (string $url): string {
            $uri = new Uri($url);

            return strtolower($uri->getHost()).rtrim(rawurldecode($uri->getPath()), '/');
        };

        return $normalize($a) === $normalize($b);
    }
}
