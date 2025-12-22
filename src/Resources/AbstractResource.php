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

    protected function extractData(array $response): array
    {
        if (isset($response['data']) && is_array($response['data'])) {
            return $response['data'];
        }

        return $response;
    }

    protected function normalizeId(array $data): array
    {
        if (!isset($data['document_id']) && isset($data['id'])) {
            $data['document_id'] = $data['id'];
        }

        return $data;
    }
}

