<?php

declare(strict_types=1);

namespace Assinafy\SDK\Http;

/**
 * Strips credentials out of request/response data before it reaches a PSR-3 logger.
 *
 * The SDK logs full request options and response bodies at debug level, which is genuinely
 * useful when integrating. Without redaction that also writes plaintext passwords
 * (`POST /login`, `POST /users/api-keys`, `PUT /authentication/change-password`),
 * `Authorization: Bearer` tokens, `X-Api-Key`, and signer access codes to wherever the
 * host application ships its logs.
 */
final class LogRedactor
{
    public const PLACEHOLDER = '[redacted]';

    /**
     * Keys whose values are credentials, compared case-insensitively. Matching is exact
     * rather than substring so that innocuous keys are not needlessly masked.
     */
    private const SECRET_KEYS = [
        'password',
        'new_password',
        'current_password',
        'token',
        'access_token',
        'refresh_token',
        'api_key',
        'apikey',
        'authorization',
        'x-api-key',
        'signer-access-code',
        'verification-code',
        'webhook_secret',
        'secret',
    ];

    /**
     * Recursively replace credential values with a placeholder.
     *
     * Preserves structure and non-secret values so debug logs stay useful. Scalars are
     * masked wholesale; a secret holding a nested array is masked as a single placeholder
     * rather than walked, so nothing leaks from inside it.
     *
     * @param array<array-key, mixed> $data
     * @return array<array-key, mixed>
     */
    public static function redact(array $data): array
    {
        $out = [];

        foreach ($data as $key => $value) {
            if (is_string($key) && self::isSecret($key)) {
                $out[$key] = self::PLACEHOLDER;
                continue;
            }

            $out[$key] = is_array($value) ? self::redact($value) : $value;
        }

        return $out;
    }

    /**
     * Redact secrets from a raw JSON body, returning it re-encoded.
     *
     * Non-JSON bodies (binary downloads, HTML error pages) are returned as a short
     * placeholder instead — logging a PDF byte-for-byte helps nobody.
     */
    public static function redactBody(string $body): string
    {
        if ($body === '') {
            return $body;
        }

        $decoded = json_decode($body, true);

        if (!is_array($decoded)) {
            return strlen($body) > 512 ? '[non-JSON body, ' . strlen($body) . ' bytes]' : $body;
        }

        return (string) json_encode(self::redact($decoded));
    }

    private static function isSecret(string $key): bool
    {
        return in_array(strtolower($key), self::SECRET_KEYS, true);
    }
}
