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
     * Every endpoint responds with `{ status, message, data }`. This helper
     * returns the inner `data` when present, otherwise the raw payload.
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
        $path = 'accounts/' . $this->config->getAccountId();

        return $suffix === '' ? $path : $path . '/' . ltrim($suffix, '/');
    }
}
