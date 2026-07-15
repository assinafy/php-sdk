<?php

declare(strict_types=1);

namespace Assinafy\SDK\Resources;

use Assinafy\SDK\Configuration;
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
        Configuration $config,
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
     */
    protected function extractData(array $response): array
    {
        if (isset($response['data']) && is_array($response['data'])) {
            return $response['data'];
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
        $path = 'accounts/' . $this->requireAccountId();

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
                'Account-scoped endpoints require an API key and account ID. '
                . 'This client was built with Configuration::forPublic() — use a full '
                . 'Configuration once you have credentials.'
            );
        }

        return $this->config->getAccountId();
    }
}
