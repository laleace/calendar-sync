<?php

namespace App\CalSync\Support;

use App\Models\Account;

/**
 * Removes credentials from text that is logged, stored or displayed.
 */
final class SecretRedactor
{
    public const MASK = '***';

    private const SENSITIVE_HEADERS = ['authorization', 'proxy-authorization', 'cookie', 'set-cookie'];

    /**
     * @param  list<string|null>  $secrets  Literal values to mask (passwords, tokens).
     */
    public static function redact(string $text, #[\SensitiveParameter] array $secrets = []): string
    {
        foreach ($secrets as $secret) {
            if (is_string($secret) && strlen($secret) >= 4) {
                $text = str_replace($secret, self::MASK, $text);
            }
        }

        return preg_replace(
            ['/\b(Bearer|Basic)\s+[A-Za-z0-9._~+\/=-]+/i', '/\b(access_token|refresh_token|client_secret|password)(["\']?\s*[=:]\s*["\']?)[^\s"\'&,}]+/i'],
            ['$1 '.self::MASK, '$1$2'.self::MASK],
            $text,
        );
    }

    /**
     * The secret values held by an account, for use with redact().
     *
     * @return list<string>
     */
    public static function secretsOf(Account $account): array
    {
        $credentials = $account->credentials ?? [];

        return array_values(array_filter(
            [$credentials['password'] ?? null, $credentials['access_token'] ?? null, $credentials['refresh_token'] ?? null],
            fn ($value) => is_string($value) && $value !== '',
        ));
    }

    /**
     * @param  array<string, string|list<string>>  $headers
     * @return array<string, string>
     */
    public static function maskHeaders(array $headers): array
    {
        $masked = [];

        foreach ($headers as $name => $value) {
            $value = is_array($value) ? implode(', ', $value) : $value;

            if (in_array(strtolower($name), self::SENSITIVE_HEADERS, true)) {
                // Keep the auth scheme ("Basic", "Bearer") for debugging, never the credential.
                $scheme = str_contains($value, ' ') && str_ends_with(strtolower($name), 'authorization')
                    ? strstr($value, ' ', true).' '
                    : '';
                $value = $scheme.self::MASK;
            }

            $masked[$name] = $value;
        }

        return $masked;
    }
}
