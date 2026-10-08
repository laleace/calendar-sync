<?php

namespace Tests;

use App\CalSync\Google\GoogleClientFactory;
use GuzzleHttp\Promise\Create;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Http;
use LogicException;
use Psr\Http\Message\RequestInterface;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Tests must never reach real calendar servers: CalDAV goes through the Laravel HTTP
        // client, Google through its own Guzzle client (replaced by FakeGoogle when needed).
        Http::preventStrayRequests();
        $this->app->instance(GoogleClientFactory::class, new GoogleClientFactory(
            fn (RequestInterface $request) => Create::rejectionFor(new LogicException("Richiesta Google non simulata: {$request->getMethod()} {$request->getUri()}")),
            retries: 0,
        ));
    }
}
