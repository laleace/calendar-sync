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
            return new ServerUnreachable('Connessione a Google non riuscita: '.SecretRedactor::redact($e->getMessage()), previous: $e);
        }

        if (! $e instanceof GoogleServiceException) {
            return new ProviderException('Errore Google: '.SecretRedactor::redact($e->getMessage()), previous: $e);
        }

        $message = self::message($e);
        $reason = $e->getErrors()[0]['reason'] ?? null;

        return match (true) {
            $e->getCode() === 401 => new TokenExpired('Google ha rifiutato l\'accesso (HTTP 401): riconnetti l\'account.', previous: $e),
            $e->getCode() === 429 || in_array($reason, ['rateLimitExceeded', 'userRateLimitExceeded', 'quotaExceeded'], true) => new ProviderException('Limite di richieste Google raggiunto: riprova più tardi.', previous: $e),
            in_array($e->getCode(), [404, 410], true) => new EventNotFound("Risorsa non trovata su Google (HTTP {$e->getCode()}): {$message}", previous: $e),
            $e->getCode() === 403 => new ProviderException("Permessi insufficienti su Google (HTTP 403): {$message}", previous: $e),
            default => new ProviderException("Errore Google (HTTP {$e->getCode()}): {$message}", previous: $e),
        };
    }

    /**
     * The exception message is the raw JSON body: extract the human-readable part.
     */
    private static function message(GoogleServiceException $e): string
    {
        $decoded = json_decode($e->getMessage(), true);
        $message = is_array($decoded) ? ($decoded['error']['message'] ?? $decoded['error_description'] ?? null) : null;

        return SecretRedactor::redact((string) ($message ?? $e->getErrors()[0]['message'] ?? 'errore sconosciuto'));
    }
}
