<?php

declare(strict_types=1);

namespace Assinafy\SDK\Http;

/**
 * Strips credentials from diagnostic text and can produce PII-safe request summaries.
 *
 * The default transport deliberately logs structure rather than payload values. The
 * recursive redaction helpers remain available for applications that explicitly choose
 * to inspect their own data, but the SDK itself never writes request/response bodies.
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
        'api-key',
        'apikey',
        'authorization',
        'x-api-key',
        'x_api_key',
        'signer-access-code',
        'signer_access_code',
        'verification-code',
        'verification_code',
        'access-token',
        'access_code',
        'id_token',
        'client_secret',
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

            if (is_array($value)) {
                $out[$key] = self::redact($value);
                continue;
            }

            $out[$key] = is_string($value) ? self::redactText($value) : $value;
        }

        return $out;
    }

    /**
     * Redact Guzzle request options, including payloads that are not JSON.
     *
     * Raw `body` requests carry signature/initial image bytes. Multipart `contents`
     * may be an open file stream. Neither belongs in application logs.
     *
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    public static function redactRequestOptions(array $options): array
    {
        $redacted = self::redact($options);

        if (array_key_exists('body', $options)) {
            $redacted['body'] = is_string($options['body'])
                ? '[raw request body, ' . strlen($options['body']) . ' bytes]'
                : self::PLACEHOLDER;
        }

        if (isset($options['multipart']) && is_array($options['multipart'])) {
            $redacted['multipart'] = [];
            foreach ($options['multipart'] as $part) {
                if (!is_array($part)) {
                    $redacted['multipart'][] = self::PLACEHOLDER;
                    continue;
                }

                $safePart = self::redact($part);
                $name = isset($part['name']) && is_string($part['name']) ? $part['name'] : '';
                if (array_key_exists('contents', $part)) {
                    if ($name !== '' && self::isSecret($name)) {
                        $safePart['contents'] = self::PLACEHOLDER;
                    } elseif (is_resource($part['contents'])) {
                        $safePart['contents'] = '[stream]';
                    } elseif (is_string($part['contents'])) {
                        $safePart['contents'] = self::redactText($part['contents']);
                    }
                }
                $redacted['multipart'][] = $safePart;
            }
        }

        return $redacted;
    }

    /**
     * Summarize Guzzle request options without retaining payload or query values.
     *
     * @param array<string, mixed> $options
     * @return array<string, bool|int|array<int, string>>
     */
    public static function summarizeRequestOptions(array $options): array
    {
        $summary = [];

        if (isset($options['query']) && is_array($options['query'])) {
            $summary['query_keys'] = array_map('strval', array_keys($options['query']));
        }
        if (isset($options['headers']) && is_array($options['headers'])) {
            $summary['header_names'] = array_map('strval', array_keys($options['headers']));
        }
        if (array_key_exists('json', $options)) {
            $summary['has_json_body'] = true;
            if (is_array($options['json'])) {
                $summary['json_keys'] = array_map('strval', array_keys($options['json']));
            }
        }
        if (array_key_exists('body', $options)) {
            $summary['has_raw_body'] = true;
            if (is_string($options['body'])) {
                $summary['body_bytes'] = strlen($options['body']);
            }
        }
        if (isset($options['multipart']) && is_array($options['multipart'])) {
            $summary['multipart_parts'] = count($options['multipart']);
        }

        return $summary;
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

        try {
            $decoded = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            return '[non-JSON body, ' . strlen($body) . ' bytes]';
        }

        if (!is_array($decoded)) {
            return '[non-object JSON body, ' . strlen($body) . ' bytes]';
        }

        return json_encode(self::redact($decoded), JSON_THROW_ON_ERROR);
    }

    /**
     * Redact credentials embedded in URLs and exception messages.
     *
     * Signer links put their access code inside a URL stored under a generic `url`
     * key, and Guzzle exception messages include the full request URL. Exact-key
     * redaction alone therefore is not sufficient.
     */
    public static function redactText(string $text): string
    {
        $queryNames = implode('|', [
            'access-token',
            'access_token',
            'access_code',
            'api-key',
            'api_key',
            'apikey',
            'signer-access-code',
            'signer_access_code',
            'token',
            'verification-code',
            'verification_code',
        ]);

        // Current sandbox signing URLs place the credential in `/sign/{code}`.
        // Mask this before handling query/fragment forms of the same credential.
        $redacted = preg_replace(
            '~((?:https?://[^\s"\']+)?/sign/)[^/?#\s"\']+~i',
            '$1' . self::PLACEHOLDER,
            $text
        ) ?? $text;

        $redacted = preg_replace(
            '/([?&#](?:' . $queryNames . ')=)[^&#\s]*/i',
            '$1' . self::PLACEHOLDER,
            $redacted
        ) ?? $redacted;

        $redacted = preg_replace(
            '/(Authorization\s*:\s*Bearer\s+)[^\s,]+/i',
            '$1' . self::PLACEHOLDER,
            $redacted
        ) ?? $redacted;

        return preg_replace(
            '/(X-Api-Key\s*:\s*)[^\s,]+/i',
            '$1' . self::PLACEHOLDER,
            $redacted
        ) ?? $redacted;
    }

    private static function isSecret(string $key): bool
    {
        return in_array(strtolower($key), self::SECRET_KEYS, true);
    }
}
