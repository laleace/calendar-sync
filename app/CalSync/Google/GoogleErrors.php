<?php

namespace App\CalSync\Google;

use App\CalSync\Exceptions\EventNotFound;
use App\CalSync\Exceptions\ProviderException;
use App\CalSync\Exceptions\ServerUnreachable;
use App\CalSync\Exceptions\TokenExpired;
use App\CalSync\Support\SecretRedactor;
use Google\Service\Exception as GoogleServiceException;
use GuzzleHttp\Exception\GuzzleException;
use Throwable;

/**
 * Maps google/apiclient and Guzzle failures to provider exceptions with safe messages.
 */
final class GoogleErrors
{
    public static function map(Throwable $e): ProviderException
    {
        if ($e instanceof ProviderException) {
            return $e;
        }

        if ($e instanceof GuzzleException) {
            return new ServerUnreachable(__('Connection to Google failed: :error', ['error' => SecretRedactor::redact($e->getMessage())]), previous: $e);
        }

        if (! $e instanceof GoogleServiceException) {
            return new ProviderException(__('Google error: :error', ['error' => SecretRedactor::redact($e->getMessage())]), previous: $e);
        }

        $message = self::message($e);
        $reason = $e->getErrors()[0]['reason'] ?? null;

        return match (true) {
            $e->getCode() === 401 => new TokenExpired(__('Google refused access (HTTP 401): reconnect the account.'), previous: $e),
            $e->getCode() === 429 || in_array($reason, ['rateLimitExceeded', 'userRateLimitExceeded', 'quotaExceeded'], true) => new ProviderException(__('Google request limit reached: try again later.'), previous: $e),
            in_array($e->getCode(), [404, 410], true) => new EventNotFound(__('Resource not found on Google (HTTP :status): :error', ['status' => $e->getCode(), 'error' => $message]), previous: $e),
            $e->getCode() === 403 => new ProviderException(__('Insufficient permissions on Google (HTTP 403): :error', ['error' => $message]), previous: $e),
            default => new ProviderException(__('Google error (HTTP :status): :error', ['status' => $e->getCode(), 'error' => $message]), previous: $e),
        };
    }

    /**
     * The exception message is the raw JSON body: extract the human-readable part.
     */
    private static function message(GoogleServiceException $e): string
    {
        $decoded = json_decode($e->getMessage(), true);
        $message = is_array($decoded) ? ($decoded['error']['message'] ?? $decoded['error_description'] ?? null) : null;

        return SecretRedactor::redact((string) ($message ?? $e->getErrors()[0]['message'] ?? __('unknown error')));
    }
}
