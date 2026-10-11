<?php

declare(strict_types=1);

namespace Assinafy\SDK\Exceptions;

class ApiException extends AssinafyException
{
    private int $statusCode;
    /** @var array<string, mixed>|null */
    private ?array $responseData;
    /** @var array<string, list<string>> */
    private array $responseHeaders;

    /**
     * @param array<string, mixed>|null $responseData
     * @param array<array-key, array<int, string>|string> $responseHeaders
     */
    public function __construct(
        string $message,
        int $statusCode,
        #[\SensitiveParameter] ?array $responseData = null,
        ?\Throwable $previous = null,
        array $responseHeaders = []
    ) {
        parent::__construct($message, $statusCode, $previous, ['response_data' => $responseData]);
        $this->statusCode = $statusCode;
        $this->responseData = $responseData;
        $this->responseHeaders = self::normalizeHeaders($responseHeaders);
    }

    /** The HTTP status code the API answered with. */
    public function getStatusCode(): int
    {
        return $this->statusCode;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getResponseData(): ?array
    {
        return $this->responseData;
    }

    /**
     * Response headers retained for request IDs, pagination diagnostics, rate limits,
     * and `Retry-After` handling.
     *
     * @return array<string, list<string>>
     */
    public function getResponseHeaders(): array
    {
        return $this->responseHeaders;
    }

    /** One response header, comma-joined, matched case-insensitively; `''` when absent. */
    public function getResponseHeaderLine(string $name): string
    {
        foreach ($this->responseHeaders as $headerName => $values) {
            if (strcasecmp($headerName, $name) === 0) {
                return implode(', ', $values);
            }
        }

        return '';
    }

    /** True for `429 Too Many Requests`; wait {@see self::getRetryAfter()} seconds before retrying. */
    public function isRateLimited(): bool
    {
        return $this->statusCode === 429;
    }

    /**
     * Seconds to wait before retrying, from the `Retry-After` header the API sends with `429`.
     * An HTTP-date value is converted to the remaining seconds (never negative).
     *
     * @return int|null `null` when the header is absent or unreadable
     */
    public function getRetryAfter(): ?int
    {
        $value = trim($this->getResponseHeaderLine('Retry-After'));
        if (preg_match('/^\d+$/D', $value) === 1) {
            return (int) $value;
        }

        $timestamp = $value === '' ? false : strtotime($value);

        return $timestamp === false ? null : max(0, $timestamp - time());
    }

    /**
     * The rate-limit window reported by the `X-Rate-Limit-*` response headers.
     *
     * ```php
     * ['limit' => 120, 'remaining' => 0, 'reset' => 42] // reset: seconds until the window resets
     * ```
     *
     * @return array{limit: int, remaining: int, reset: int}|null `null` unless all three
     *     headers are present and numeric
     */
    public function getRateLimit(): ?array
    {
        $values = [];
        foreach (['limit', 'remaining', 'reset'] as $key) {
            $value = trim($this->getResponseHeaderLine('X-Rate-Limit-' . ucfirst($key)));
            if (preg_match('/^\d+$/D', $value) !== 1) {
                return null;
            }
            $values[$key] = (int) $value;
        }

        return $values;
    }

    /**
     * @param array<string, mixed> $responseData
     * @param array<array-key, array<int, string>|string> $responseHeaders
     */
    public static function fromResponse(
        int $statusCode,
        #[\SensitiveParameter] array $responseData,
        ?\Throwable $previous = null,
        array $responseHeaders = []
    ): self {
        $message = $responseData['message'] ?? $responseData['error'] ?? 'API request failed';

        if (!is_string($message) && !is_numeric($message)) {
            $message = 'API request failed';
        }

        return new self((string) $message, $statusCode, $responseData, $previous, $responseHeaders);
    }

    /**
     * @param array<array-key, array<int, string>|string> $headers
     * @return array<string, list<string>>
     */
    private static function normalizeHeaders(array $headers): array
    {
        $normalized = [];
        foreach ($headers as $name => $values) {
            if (!is_string($name)) {
                continue;
            }
            $normalized[$name] = is_array($values)
                ? array_values(array_map('strval', $values))
                : [(string) $values];
        }

        return $normalized;
    }
}
