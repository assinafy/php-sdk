<?php

declare(strict_types=1);

namespace Assinafy\SDK\Resources;

use Assinafy\SDK\Configuration;
use Assinafy\SDK\Http\HttpClientInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

abstract class AbstractResource
{
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
     * Every endpoint responds with `{ status, message, data, meta? }`. Single-item
     * methods (`get`, `create`, `update`, …) call this helper and return just the
     * inner `data` so callers can work with the resource directly. List endpoints
     * intentionally do NOT unwrap — they return the full envelope so the caller
     * keeps access to `meta` (pagination cursor, total count, …) alongside the
     * `data` array of items. Read the docblock on each `list()` for the exact shape.
     */
    protected function extractData(array $response): array
    {
        if (isset($response['data']) && is_array($response['data'])) {
            return $response['data'];
        }

        return $response;
    }

    protected function accountPath(string $suffix = ''): string
    {
        if ($this->config->isPublic()) {
            throw new \RuntimeException(
                'Account-scoped endpoints require an API key and account ID. '
                . 'This client was built with Configuration::forPublic() — use a full '
                . 'Configuration once you have credentials.'
            );
        }

        $path = 'accounts/' . $this->config->getAccountId();

        return $suffix === '' ? $path : $path . '/' . ltrim($suffix, '/');
    }
}
