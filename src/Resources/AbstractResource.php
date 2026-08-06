<?php

declare(strict_types=1);

namespace Assinafy\SDK\Resources;

use Assinafy\SDK\Configuration;
use Assinafy\SDK\Exceptions\ValidationException;
use Assinafy\SDK\Http\HttpClientInterface;
use Assinafy\SDK\Http\Response;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

abstract class AbstractResource
{
    /** Every header the API must send for a `pagination` key to be reported. */
    private const PAGINATION_HEADERS = [
        'x-pagination-current-page',
        'x-pagination-page-count',
        'x-pagination-per-page',
        'x-pagination-total-count',
    ];

    protected HttpClientInterface $httpClient;
    protected Configuration $config;
    protected LoggerInterface $logger;

    public function __construct(
        HttpClientInterface $httpClient,
        #[\SensitiveParameter] Configuration $config,
        ?LoggerInterface $logger = null
    ) {
        $this->httpClient = $httpClient;
        $this->config = $config;
        $this->logger = $logger ?? new NullLogger();
    }

    /**
     * Unwrap the `data` envelope returned by the Assinafy API.
     *
     * Every endpoint responds with `{ status, message, data }`. Single-item methods
     * (`get`, `create`, `update`, …) call this helper and return just the inner `data`
     * so callers can work with the resource directly. List endpoints intentionally do
     * NOT unwrap — see {@see self::withPagination()}.
     *
     * Deliberately returns a bare `array`: `data` is a string-keyed object on single-item
     * endpoints and a list on collection ones, so each caller narrows it via its own
     * `@return` docblock.
     *
     * @param array<string, mixed> $response
     * @return array<array-key, mixed>
     */
    protected function extractData(array $response): array
    {
        if (array_key_exists('data', $response)) {
            return is_array($response['data']) ? $response['data'] : [];
        }

        return $response;
    }

    /**
     * Build the return value of a `list()` method: the API envelope plus a `pagination` key.
     *
     * The API does not put pagination in the body — there is no `meta` key on any endpoint.
     * It reports pagination exclusively through `X-Pagination-*` response headers, so this
     * helper lifts them into the returned array. Endpoints that don't paginate (e.g.
     * `GET /accounts`) simply send no such headers and `pagination` is omitted.
     *
     * @return array{status?: int, message?: string, data?: array<int, array<string, mixed>>,
     *     pagination?: array{current_page: int, page_count: int, per_page: int, total_count: int}}
     */
    protected function withPagination(Response $response): array
    {
        $envelope = $response->getData() ?? [];
        $pagination = self::readPagination($response->getHeaders());

        if ($pagination !== null) {
            $envelope['pagination'] = $pagination;
        }

        return $envelope;
    }

    /**
     * Read the `X-Pagination-*` headers into a normalised array.
     *
     * Header names are matched case-insensitively: PSR-7 preserves the casing the server
     * sent, and Assinafy sends them lowercased, so an exact lookup would silently miss.
     *
     * @param array<string, array<int, string>|string> $headers
     * @return array{current_page: int, page_count: int, per_page: int, total_count: int}|null
     */
    private static function readPagination(array $headers): ?array
    {
        $normalized = [];
        foreach ($headers as $name => $value) {
            $normalized[strtolower($name)] = is_array($value) ? ($value[0] ?? '') : $value;
        }

        foreach (self::PAGINATION_HEADERS as $header) {
            if (!isset($normalized[$header]) || $normalized[$header] === '') {
                return null;
            }
        }

        return [
            'current_page' => (int) $normalized['x-pagination-current-page'],
            'page_count' => (int) $normalized['x-pagination-page-count'],
            'per_page' => (int) $normalized['x-pagination-per-page'],
            'total_count' => (int) $normalized['x-pagination-total-count'],
        ];
    }

    protected function accountPath(string $suffix = ''): string
    {
        $path = 'accounts/' . $this->pathSegment($this->requireAccountId(), 'account ID');

        return $suffix === '' ? $path : $path . '/' . ltrim($suffix, '/');
    }

    /**
     * Return the configured account ID, refusing to hand back the `forPublic()` sentinel.
     *
     * Use this for account-scoped endpoints that take the ID somewhere other than the path —
     * e.g. `GET /assignments?accountId=…`. {@see self::accountPath()} builds on it.
     *
     * @throws \RuntimeException when the client was built with {@see Configuration::forPublic()}
     */
    protected function requireAccountId(): string
    {
        if ($this->config->isPublic()) {
            throw new \RuntimeException(
                'Account-scoped endpoints require an API key or Bearer token plus an account ID. '
                . 'This client was built with Configuration::forPublic() — use a full '
                . 'Configuration once you have credentials.'
            );
        }

        return $this->config->getAccountId();
    }

    /**
     * Encode an opaque API identifier for safe use as one URI path segment.
     *
     * @throws ValidationException when the identifier is empty
     */
    protected function pathSegment(string $value, string $name = 'identifier'): string
    {
        if ($value === '') {
            throw new ValidationException(ucfirst($name) . ' cannot be empty', [$name => $value]);
        }

        return rawurlencode($value);
    }

    /**
     * @return array{signer-access-code: string}|array{}
     */
    protected function accessCodeQuery(#[\SensitiveParameter] ?string $accessCode): array
    {
        if ($accessCode === null) {
            return [];
        }

        if ($accessCode === '') {
            throw new ValidationException('Signer access code cannot be empty');
        }

        return ['signer-access-code' => $accessCode];
    }

    /**
     * @return array{Authorization: string}|array{}
     */
    protected function bearerHeaders(#[\SensitiveParameter] ?string $accessToken): array
    {
        if ($accessToken === null) {
            if ($this->config->isPublic()) {
                throw new ValidationException(
                    'This endpoint requires an API key or Bearer access token'
                );
            }

            return [];
        }

        if ($accessToken === '') {
            throw new ValidationException('Access token cannot be empty');
        }

        return ['Authorization' => 'Bearer ' . $accessToken];
    }

    /**
     * Merge list filters while keeping the explicit page arguments authoritative.
     *
     * @param array<string, scalar> $filters
     * @return array<string, scalar>
     */
    protected function paginationQuery(int $page, int $perPage, array $filters = []): array
    {
        if ($page < 1) {
            throw new ValidationException('Page must be at least 1', ['page' => $page]);
        }

        if ($perPage < 1 || $perPage > 100) {
            throw new ValidationException('Per-page must be between 1 and 100', [
                'per-page' => $perPage,
            ]);
        }

        unset($filters['page'], $filters['per-page']);

        return array_merge([
            'page' => $page,
            'per-page' => $perPage,
        ], $filters);
    }

    /**
     * Build the query shared by the account and cross-account KPI endpoints.
     *
     * @return array{granularity: 'monthly'|'daily', month?: string}
     */
    protected function statsQuery(string $granularity, ?string $month): array
    {
        if (!in_array($granularity, ['monthly', 'daily'], true)) {
            throw new ValidationException('Granularity must be monthly or daily', [
                'granularity' => $granularity,
            ]);
        }

        if ($granularity === 'daily' && $month === null) {
            throw new ValidationException('Daily statistics require month in YYYY-MM format', [
                'month' => $month,
            ]);
        }

        if ($month !== null) {
            if (preg_match('/^\d{4}-\d{2}$/', $month) !== 1) {
                throw new ValidationException('Month must be in YYYY-MM format', ['month' => $month]);
            }
            $monthNumber = (int) substr($month, 5, 2);
            if ($monthNumber < 1 || $monthNumber > 12) {
                throw new ValidationException('Month must be in YYYY-MM format', ['month' => $month]);
            }
        }

        $query = ['granularity' => $granularity];
        if ($month !== null) {
            $query['month'] = $month;
        }

        return $query;
    }
}
