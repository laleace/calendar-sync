<?php

use App\CalSync\CalDav\HostPolicy;

it('allows only iCloud hosts over HTTPS for iCloud accounts', function (string $url, bool $allowed) {
    expect(HostPolicy::icloud()->allows($url))->toBe($allowed);
})->with([
    ['https://caldav.icloud.com/', true],
    ['https://p42-caldav.icloud.com/123/calendars/', true],
    ['https://P07-CALDAV.ICLOUD.COM/x/', true],
    ['http://p42-caldav.icloud.com/123/calendars/', false],
    ['https://icloud.com.evil.test/', false],
    ['https://evilicloud.com/', false],
    ['/relative/path', false],
]);

it('allows only the configured host for generic servers', function (string $base, string $url, bool $allowed) {
    expect(HostPolicy::sameHostAs($base)->allows($url))->toBe($allowed);
})->with([
    'same host' => ['https://dav.example.test/', 'https://dav.example.test/remote.php/dav/', true],
    'other port' => ['https://dav.example.test/', 'https://dav.example.test:8443/dav/', true],
    'case insensitive' => ['https://dav.example.test/', 'https://DAV.example.test/x', true],
    'other host' => ['https://dav.example.test/', 'https://evil.test/', false],
    'subdomain' => ['https://example.test/', 'https://dav.example.test/', false],
    'no https downgrade' => ['https://dav.example.test/', 'http://dav.example.test/', false],
    'http lan server' => ['http://radicale.lan:5232/', 'http://radicale.lan:5232/user/', true],
    'http lan to https' => ['http://radicale.lan:5232/', 'https://radicale.lan/user/', true],
]);
