<?php

namespace Tests\Support;

use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/**
 * Routes faked HTTP requests by method + URL (+ Depth) to canned CalDAV responses.
 * Unknown routes answer 404, like a real server.
 */
final class FakeCalDav
{
    /** @var list<array{method: string, url: string, depth: ?string, response: PromiseInterface|\Closure}> */
    private array $routes = [];

    public static function fixture(string $path): string
    {
        return file_get_contents(__DIR__.'/../Fixtures/caldav/'.$path);
    }

    public static function fixtureIcs(string $name): string
    {
        return file_get_contents(__DIR__.'/../Fixtures/ics/'.$name);
    }

    public function propfind(string $url, int $depth, string $fixture, int $status = 207): self
    {
        return $this->on('PROPFIND', $url, fn () => Http::response(self::fixture($fixture), $status, ['Content-Type' => 'application/xml; charset=utf-8']), $depth);
    }

    public function report(string $url, string $xml): self
    {
        return $this->on('REPORT', $url, fn () => Http::response($xml, 207, ['Content-Type' => 'application/xml; charset=utf-8']), 1);
    }

    /**
     * A calendar-query multistatus, as iCloud returns it: one response per calendar
     * object resource, ICS as escaped text.
     *
     * @param  array<string, string|null>  $resources  href => ICS fixture name, raw ICS (starting with BEGIN:), or null for no calendar-data.
     */
    public static function calendarQueryXml(array $resources): string
    {
        $doc = new \DOMDocument('1.0', 'UTF-8');
        $multistatus = $doc->appendChild($doc->createElementNS('DAV:', 'multistatus'));
        $i = 0;

        foreach ($resources as $href => $ics) {
            $response = $multistatus->appendChild($doc->createElementNS('DAV:', 'response'));
            $response->appendChild($doc->createElementNS('DAV:', 'href'))->textContent = $href;
            $propstat = $response->appendChild($doc->createElementNS('DAV:', 'propstat'));
            $prop = $propstat->appendChild($doc->createElementNS('DAV:', 'prop'));
            $prop->appendChild($doc->createElementNS('DAV:', 'getetag'))->textContent = '"C='.(++$i).'@U=abc"';

            if ($ics !== null) {
                $data = str_starts_with($ics, 'BEGIN:') ? $ics : file_get_contents(__DIR__.'/../Fixtures/ics/'.$ics);
                $prop->appendChild($doc->createElementNS('urn:ietf:params:xml:ns:caldav', 'calendar-data'))->textContent = $data;
            }

            $propstat->appendChild($doc->createElementNS('DAV:', 'status'))->textContent = 'HTTP/1.1 200 OK';
        }

        return $doc->saveXML();
    }

    public function status(string $method, string $url, int $status, array $headers = []): self
    {
        return $this->on($method, $url, fn () => Http::response('', $status, $headers));
    }

    public function redirect(string $method, string $url, string $location, int $status = 301): self
    {
        return $this->status($method, $url, $status, ['Location' => $location]);
    }

    public function on(string $method, string $url, \Closure $response, ?int $depth = null): self
    {
        $this->routes[] = ['method' => $method, 'url' => $url, 'depth' => $depth === null ? null : (string) $depth, 'response' => $response];

        return $this;
    }

    public function install(): self
    {
        Http::fake(function (Request $request) {
            foreach ($this->routes as $route) {
                if ($route['method'] === $request->method()
                    && $route['url'] === $request->url()
                    && ($route['depth'] === null || $route['depth'] === ($request->header('Depth')[0] ?? null))) {
                    return ($route['response'])();
                }
            }

            return Http::response('Not Found', 404);
        });

        return $this;
    }

    /**
     * The complete iCloud discovery flow, with the home on the p42 shard.
     */
    public static function icloud(): self
    {
        return (new self)
            ->propfind('https://caldav.icloud.com/', 0, 'icloud/principal.xml')
            ->propfind('https://caldav.icloud.com/123456789/principal/', 0, 'icloud/calendar-home-set.xml')
            ->propfind('https://p42-caldav.icloud.com/123456789/calendars/', 1, 'icloud/calendars.xml');
    }

    /**
     * A Nextcloud-like server: the root answers 404, discovery goes through
     * /.well-known/caldav which redirects to /remote.php/dav/.
     */
    public static function nextcloud(): self
    {
        return (new self)
            ->status('PROPFIND', 'https://dav.example.test/', 404)
            ->redirect('PROPFIND', 'https://dav.example.test/.well-known/caldav', '/remote.php/dav/')
            ->propfind('https://dav.example.test/remote.php/dav/', 0, 'nextcloud/principal.xml')
            ->propfind('https://dav.example.test/remote.php/dav/principals/users/mario/', 0, 'nextcloud/calendar-home-set.xml')
            ->propfind('https://dav.example.test/remote.php/dav/calendars/mario/', 1, 'nextcloud/calendars.xml');
    }
}
