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
    private LoggerInterface $logger;

    public function __construct(Configuration $config, ?LoggerInterface $logger = null)
    {
        $this->logger = $logger ?? new NullLogger();

        // Guzzle resolves relative request URIs against `base_uri` per RFC 3986. When the
        // base_uri lacks a trailing slash, its last path segment gets *replaced* rather than
        // appended to — so `https://api.assinafy.com.br/v1` + `documents/statuses` becomes
        // `https://api.assinafy.com.br/documents/statuses` (no `/v1`). Always end with `/`.
        $this->client = new Client([
            'base_uri' => rtrim($config->getBaseUrl(), '/') . '/',
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

    public function post(string $uri, array $data = [], array $headers = [], array $query = []): Response
    {
        return $this->request('POST', $uri, $this->withOptionalQuery([
            'json' => $data,
            'headers' => $this->withJsonHeaders($headers),
        ], $query));
    }

    public function put(string $uri, array $data = [], array $headers = [], array $query = []): Response
    {
        return $this->request('PUT', $uri, $this->withOptionalQuery([
            'json' => $data,
            'headers' => $this->withJsonHeaders($headers),
        ], $query));
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

        // Guzzle sets `Content-Type: multipart/form-data; boundary=...` automatically when the
        // `multipart` option is used. Don't override it — that would strip the boundary and
        // the server would reject the upload.
        return $this->request('POST', $uri, [
            'multipart' => $multipart,
            'headers' => $headers,
        ]);
    }

    public function postRaw(
        string $uri,
        string $body,
        string $contentType,
        array $query = [],
        array $headers = []
    ): Response {
        return $this->request('POST', $uri, [
            'query' => $query,
            'body' => $body,
            'headers' => array_merge(['Content-Type' => $contentType], $headers),
        ]);
    }

    /**
     * @param array<string, string> $headers
     * @return array<string, string>
     */
    private function withJsonHeaders(array $headers): array
    {
        return array_merge(['Content-Type' => 'application/json'], $headers);
    }

    /**
     * @param array<string, mixed>  $options
     * @param array<string, scalar> $query
     * @return array<string, mixed>
     */
    private function withOptionalQuery(array $options, array $query): array
    {
        if ($query !== []) {
            $options['query'] = $query;
        }

        return $options;
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
