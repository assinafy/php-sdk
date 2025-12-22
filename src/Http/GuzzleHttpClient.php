<?php

declare(strict_types=1);

namespace Assinafy\SDK\Http;

use Assinafy\SDK\Configuration;
use Assinafy\SDK\Exceptions\ApiException;
use Assinafy\SDK\Exceptions\NetworkException;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Exception\RequestException;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

class GuzzleHttpClient implements HttpClientInterface
{
    private Client $client;
    private Configuration $config;
    private LoggerInterface $logger;

    public function __construct(Configuration $config, ?LoggerInterface $logger = null)
    {
        $this->config = $config;
        $this->logger = $logger ?? new NullLogger();

        $this->client = new Client([
            'base_uri' => $config->getBaseUrl(),
            'timeout' => $config->getTimeout(),
            'connect_timeout' => $config->getConnectTimeout(),
            'headers' => $config->getHeaders(),
        ]);
    }

    public function get(string $uri, array $params = [], array $headers = []): Response
    {
        return $this->request('GET', $uri, [
            'query' => $params,
            'headers' => $headers,
        ]);
    }

    public function post(string $uri, array $data = [], array $headers = []): Response
    {
        return $this->request('POST', $uri, [
            'json' => $data,
            'headers' => $headers,
        ]);
    }

    public function put(string $uri, array $data = [], array $headers = []): Response
    {
        return $this->request('PUT', $uri, [
            'json' => $data,
            'headers' => $headers,
        ]);
    }

    public function delete(string $uri, array $headers = []): Response
    {
        return $this->request('DELETE', $uri, [
            'headers' => $headers,
        ]);
    }

    public function uploadFile(string $uri, string $filePath, array $data = [], array $headers = []): Response
    {
        if (!file_exists($filePath)) {
            throw new \InvalidArgumentException("File not found: {$filePath}");
        }

        $multipart = [
            [
                'name' => 'file',
                'contents' => fopen($filePath, 'r'),
                'filename' => basename($filePath),
            ],
        ];

        foreach ($data as $key => $value) {
            $multipart[] = [
                'name' => $key,
                'contents' => is_array($value) ? json_encode($value) : (string)$value,
            ];
        }

        return $this->request('POST', $uri, [
            'multipart' => $multipart,
            'headers' => array_merge(['Content-Type' => 'multipart/form-data'], $headers),
        ]);
    }

    private function request(string $method, string $uri, array $options = []): Response
    {
        $this->logger->debug("Assinafy API Request: {$method} {$uri}", [
            'options' => $options,
        ]);

        try {
            $response = $this->client->request($method, $uri, $options);

            $statusCode = $response->getStatusCode();
            $headers = $response->getHeaders();
            $body = (string)$response->getBody();

            $this->logger->debug("Assinafy API Response: {$statusCode}", [
                'body' => $body,
            ]);

            $apiResponse = new Response($statusCode, $headers, $body);

            if (!$apiResponse->isSuccess()) {
                throw ApiException::fromResponse($statusCode, $apiResponse->getData() ?? []);
            }

            return $apiResponse;
        } catch (RequestException $e) {
            $statusCode = $e->getResponse() ? $e->getResponse()->getStatusCode() : 0;
            $body = $e->getResponse() ? (string)$e->getResponse()->getBody() : '';
            $data = json_decode($body, true);

            $this->logger->error("Assinafy API Error: {$method} {$uri}", [
                'status_code' => $statusCode,
                'response' => $body,
                'exception' => $e->getMessage(),
            ]);

            throw ApiException::fromResponse($statusCode, $data ?? ['message' => $e->getMessage()]);
        } catch (GuzzleException $e) {
            $this->logger->error("Assinafy Network Error: {$method} {$uri}", [
                'exception' => $e->getMessage(),
            ]);

            throw new NetworkException("Network error: {$e->getMessage()}", 0, $e);
        }
    }
}
