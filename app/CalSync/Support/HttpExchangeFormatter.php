<?php

namespace App\CalSync\Support;

use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Str;

/**
 * Human-readable HTTP request/response lines for logs and `--debug` output.
 * Sensitive headers are always masked.
 */
final class HttpExchangeFormatter
{
    public static function summary(Request $request, ?Response $response = null): string
    {
        $depth = $request->header('Depth')[0] ?? null;

        return trim(sprintf(
            '%s %s%s → %s',
            $request->method(),
            $request->url(),
            $depth !== null ? " (Depth: {$depth})" : '',
            $response?->status() ?? 'nessuna risposta',
        ));
    }

    /**
     * @return array<string, string>
     */
    public static function requestHeaders(Request $request): array
    {
        return SecretRedactor::maskHeaders($request->headers());
    }

    public static function body(string $body, int $limit = 4000): string
    {
        return Str::limit($body, $limit, "\n… (troncato)");
    }
}
