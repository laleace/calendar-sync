<?php

namespace Tests\Support;

use App\CalSync\Google\GoogleClientFactory;
use Closure;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Psr7\Response;
use Psr\Http\Message\RequestInterface;

/**
 * Fake Google endpoints (OAuth token + Calendar API), plugged into GoogleClientFactory
 * as the Guzzle handler. Routes match method + decoded URL without query string.
 */
final class FakeGoogle
{
    public const API = 'https://www.googleapis.com/calendar/v3';

    public const TOKEN_URL = 'https://oauth2.googleapis.com/token';

    /** @var list<array{method: string, url: string, response: Closure}> */
    private array $routes = [];

    /** @var list<RequestInterface> */
    public array $requests = [];

    public static function fixture(string $name): array
    {
        return json_decode(file_get_contents(__DIR__.'/../Fixtures/google/'.$name), true);
    }

    /**
     * @param  Closure(RequestInterface): Response|array<mixed>|Response  $response
     */
    public function on(string $method, string $url, Closure|array|Response $response, int $status = 200): self
    {
        $this->routes[] = [
            'method' => $method,
            'url' => $url,
            'response' => match (true) {
                $response instanceof Closure => $response,
                $response instanceof Response => fn () => $response,
                default => fn () => self::json($response, $status),
            },
        ];

        return $this;
    }

    /**
     * Token endpoint answering both authorization-code and refresh-token grants.
     */
    public function token(array $overrides = []): self
    {
        return $this->on('POST', self::TOKEN_URL, array_merge([
            'access_token' => 'ya29.fresh-access-token',
            'expires_in' => 3599,
            'refresh_token' => '1//fresh-refresh-token',
            'scope' => 'https://www.googleapis.com/auth/calendar',
            'token_type' => 'Bearer',
        ], $overrides));
    }

    public static function json(array $data, int $status = 200): Response
    {
        return new Response($status, ['Content-Type' => 'application/json; charset=UTF-8'], json_encode($data));
    }

    public static function error(int $status, string $reason, string $message): Response
    {
        return self::json(['error' => [
            'code' => $status,
            'message' => $message,
            'errors' => [['domain' => 'global', 'reason' => $reason, 'message' => $message]],
        ]], $status);
    }

    public function install(): self
    {
        app()->instance(GoogleClientFactory::class, new GoogleClientFactory(function (RequestInterface $request) {
            $this->requests[] = $request;
            $url = rawurldecode((string) $request->getUri()->withQuery(''));

            foreach ($this->routes as $route) {
                if ($route['method'] === $request->getMethod() && $route['url'] === $url) {
                    return Create::promiseFor(($route['response'])($request));
                }
            }

            return Create::promiseFor(self::error(404, 'notFound', "Not Found: {$request->getMethod()} {$url}"));
        }, retries: 0));

        return $this;
    }

    /**
     * @return list<RequestInterface>
     */
    public function sent(string $method, string $url): array
    {
        return array_values(array_filter(
            $this->requests,
            fn (RequestInterface $r) => $r->getMethod() === $method && rawurldecode((string) $r->getUri()->withQuery('')) === $url,
        ));
    }

    /**
     * @return array<string, mixed>
     */
    public static function query(RequestInterface $request): array
    {
        parse_str($request->getUri()->getQuery(), $query);

        return $query;
    }

    /**
     * JSON or form-encoded body.
     *
     * @return array<string, mixed>
     */
    public static function body(RequestInterface $request): array
    {
        $body = (string) $request->getBody();
        $json = json_decode($body, true);

        if (is_array($json)) {
            return $json;
        }

        parse_str($body, $form);

        return $form;
    }
}
