<?php

use App\CalSync\CalDav\MultistatusParser;
use App\CalSync\CalDav\Ns;
use App\CalSync\Exceptions\ProviderException;
use Tests\Support\FakeCalDav;

it('parses responses in the default DAV namespace (iCloud style)', function () {
    $responses = MultistatusParser::parse(FakeCalDav::fixture('icloud/calendars.xml'));

    expect($responses)->toHaveCount(9)
        ->and($responses[0]->href)->toBe('/123456789/calendars/')
        ->and($responses[1]->text(Ns::DAV, 'displayname'))->toBe('Casa')
        ->and($responses[2]->text(Ns::DAV, 'displayname'))->toBe('Lavoro & Ufficio');
});

it('parses prefixed namespaces (Nextcloud style)', function () {
    $responses = MultistatusParser::parse(FakeCalDav::fixture('nextcloud/calendars.xml'));

    expect($responses)->toHaveCount(5)
        ->and($responses[1]->text(Ns::APPLE_ICAL, 'calendar-color'))->toBe('#0082c9')
        ->and($responses[1]->descendants(Ns::DAV, 'resourcetype', Ns::CALDAV, 'calendar'))->toHaveCount(1);
});

it('treats properties in a 404 propstat as absent', function () {
    $home = MultistatusParser::parse(FakeCalDav::fixture('icloud/calendars.xml'))[0];

    expect($home->has(Ns::DAV, 'resourcetype'))->toBeTrue()
        ->and($home->has(Ns::DAV, 'displayname'))->toBeFalse()
        ->and($home->text(Ns::APPLE_ICAL, 'calendar-color'))->toBeNull();
});

it('reads hrefs nested in properties, whatever the prefix', function () {
    $response = MultistatusParser::parse(FakeCalDav::fixture('icloud/calendar-home-set.xml'))[0];

    expect($response->href(Ns::CALDAV, 'calendar-home-set'))->toBe('https://p42-caldav.icloud.com:443/123456789/calendars/');
});

it('rejects bodies that are not a multistatus', function (string $body) {
    MultistatusParser::parse($body);
})->throws(ProviderException::class)->with([
    'empty' => '',
    'html' => '<html><body>Service Unavailable</body></html>',
    'malformed' => '<d:multistatus xmlns:d="DAV:"><d:response>',
    'wrong namespace' => '<multistatus xmlns="urn:other"/>',
]);

it('does not expand external entities', function () {
    $xml = <<<'XML'
        <?xml version="1.0"?>
        <!DOCTYPE multistatus [<!ENTITY xxe SYSTEM "file:///etc/passwd">]>
        <multistatus xmlns="DAV:">
          <response>
            <href>/cal/</href>
            <propstat>
              <prop><displayname>&xxe;</displayname></prop>
              <status>HTTP/1.1 200 OK</status>
            </propstat>
          </response>
        </multistatus>
        XML;

    $responses = MultistatusParser::parse($xml);

    expect((string) $responses[0]->text(Ns::DAV, 'displayname'))->not->toContain('root:');
});
