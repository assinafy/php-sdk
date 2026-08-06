<?php

declare(strict_types=1);

namespace Assinafy\SDK\Http;

use Assinafy\SDK\Configuration;
use Assinafy\SDK\Exceptions\ApiException;
use Assinafy\SDK\Exceptions\NetworkException;
use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\BadResponseException;
use GuzzleHttp\Exception\GuzzleException;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

class GuzzleHttpClient implements HttpClientInterface
{
    private ClientInterface $client;
    private LoggerInterface $logger;
    /** @var array<string, string> */
    private array $defaultHeaders;

    /**
     * @param ClientInterface|null $client Pre-built Guzzle client. Leave null in production —
     *     the SDK builds one from `$config`. Tests inject a client backed by a `MockHandler`
     *     so the transport can be exercised without network access.
     */
    public function __construct(
        #[\SensitiveParameter] Configuration $config,
        ?LoggerInterface $logger = null,
        ?ClientInterface $client = null
    ) {
        $this->logger = $logger ?? new NullLogger();
        $this->defaultHeaders = $config->getHeaders();
        $this->client = $client ?? self::buildClient($config);
    }

    private static function buildClient(#[\SensitiveParameter] Configuration $config): Client
    {
        // Guzzle resolves relative request URIs against `base_uri` per RFC 3986. When the
        // base_uri lacks a trailing slash, its last path segment gets *replaced* rather than
        // appended to — so `https://api.assinafy.com.br/v1` + `documents/statuses` becomes
        // `https://api.assinafy.com.br/documents/statuses` (no `/v1`). Always end with `/`.
        return new Client([
            'base_uri' => rtrim($config->getBaseUrl(), '/') . '/',
            'timeout' => $config->getTimeout(),
            'connect_timeout' => $config->getConnectTimeout(),
            // API calls are not browser navigation. Refusing redirects prevents the
            // custom API-key header from being forwarded to another origin.
            'allow_redirects' => false,
        ]);
    }

    public function get(
        string $uri,
        #[\SensitiveParameter] array $params = [],
        #[\SensitiveParameter] array $headers = []
    ): Response {
        return $this->request('GET', $uri, [
            'query' => $params,
            'headers' => $headers,
        ]);
    }

    public function post(
        string $uri,
        #[\SensitiveParameter] ?array $data = null,
        #[\SensitiveParameter] array $headers = [],
        #[\SensitiveParameter] array $query = []
    ): Response {
        return $this->request('POST', $uri, $this->jsonOptions($data, $headers, $query));
    }

    public function put(
        string $uri,
        #[\SensitiveParameter] ?array $data = null,
        #[\SensitiveParameter] array $headers = [],
        #[\SensitiveParameter] array $query = []
    ): Response {
        return $this->request('PUT', $uri, $this->jsonOptions($data, $headers, $query));
    }

    public function patch(
        string $uri,
        #[\SensitiveParameter] ?array $data = null,
        #[\SensitiveParameter] array $headers = [],
        #[\SensitiveParameter] array $query = []
    ): Response {
        return $this->request('PATCH', $uri, $this->jsonOptions($data, $headers, $query));
    }

    public function delete(
        string $uri,
        #[\SensitiveParameter] array $headers = [],
        #[\SensitiveParameter] array $query = [],
        #[\SensitiveParameter] array $data = []
    ): Response {
        $options = ['headers' => $data === [] ? $headers : $this->withJsonHeaders($headers)];

        if ($data !== []) {
            $options['json'] = $data;
        }

        return $this->request('DELETE', $uri, $this->withOptionalQuery($options, $query));
    }

    public function uploadFile(
        string $uri,
        #[\SensitiveParameter] string $filePath,
        #[\SensitiveParameter] array $data = [],
        #[\SensitiveParameter] array $headers = []
    ): Response {
        if (!is_file($filePath) || !is_readable($filePath)) {
            throw new \InvalidArgumentException("File not found: {$filePath}");
        }

        $handle = fopen($filePath, 'rb');
        if ($handle === false) {
            throw new \InvalidArgumentException("File is not readable: {$filePath}");
        }

        $multipart = [
            [
                'name' => 'file',
                // Ownership transfers to Guzzle's PSR-7 stream; it closes the resource
                // when the request body is released.
                'contents' => $handle,
                'filename' => basename($filePath),
            ],
        ];

        foreach ($data as $key => $value) {
            $multipart[] = [
                'name' => $key,
                'contents' => is_array($value)
                    ? json_encode($value, JSON_THROW_ON_ERROR)
                    : (string) $value,
            ];
        }

        // Guzzle sets the multipart boundary. Overriding Content-Type would strip it.
        return $this->request('POST', $uri, [
            'multipart' => $multipart,
            'headers' => $headers,
        ]);
    }

    public function postRaw(
        string $uri,
        #[\SensitiveParameter] string $body,
        string $contentType,
        #[\SensitiveParameter] array $query = [],
        #[\SensitiveParameter] array $headers = []
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

    /**
     * @param array<array-key, mixed>|null $data
     * @param array<string, string> $headers
     * @param array<string, scalar> $query
     * @return array<string, mixed>
     */
    private function jsonOptions(?array $data, array $headers, array $query): array
    {
        $options = ['headers' => $data === null ? $headers : $this->withJsonHeaders($headers)];
        if ($data !== null) {
            $options['json'] = $data;
        }

        return $this->withOptionalQuery($options, $query);
    }

    /**
     * @param array<string, mixed> $options
     */
    private function request(
        string $method,
        string $uri,
        #[\SensitiveParameter] array $options = []
    ): Response {
        $options = $this->withDefaultHeaders($options);
        // Enforce this at request time as well as on our own Client. An injected
        // Guzzle client may have redirect middleware enabled by default, and Guzzle
        // can forward custom authentication headers such as X-Api-Key cross-origin.
        $options['allow_redirects'] = false;
        $path = explode('?', explode('#', $uri, 2)[0], 2)[0];
        $safeRequest = LogRedactor::redactText("{$method} {$path}");
        $this->logger->debug("Assinafy API Request: {$safeRequest}", [
            'request' => LogRedactor::summarizeRequestOptions($options),
        ]);

        try {
            $response = $this->client->request($method, $uri, $options);

            $statusCode = $response->getStatusCode();
            $headers = $response->getHeaders();
            $body = (string)$response->getBody();

            $this->logger->debug("Assinafy API Response: {$statusCode}", [
                'body_bytes' => strlen($body),
            ]);

            $apiResponse = new Response($statusCode, $headers, $body);

            if (
                $apiResponse->isSuccess()
                && $body !== ''
                && $apiResponse->getData() === null
                && !self::hasBinaryContentType($headers)
            ) {
                throw new NetworkException(
                    'Assinafy API returned an invalid JSON response'
                );
            }

            if (!$apiResponse->isSuccess()) {
                throw ApiException::fromResponse(
                    $statusCode,
                    $apiResponse->getData() ?? [],
                    null,
                    $headers
                );
            }

            return $apiResponse;
        } catch (GuzzleException $e) {
            // BadResponseException is the stable Guzzle 7/8 branch for HTTP
            // 4xx/5xx responses. Other Guzzle 8 response-bearing exceptions
            // represent failed transfers and must remain NetworkException even
            // when response headers (including a 2xx status) were received.
            if ($e instanceof BadResponseException) {
                $response = $e->getResponse();
                $statusCode = $response->getStatusCode();
                $body = (string) $response->getBody();
                $data = json_decode($body, true);

                $this->logger->error("Assinafy API Error: {$safeRequest}", [
                    'status_code' => $statusCode,
                    'body_bytes' => strlen($body),
                    'exception_type' => $e::class,
                ]);

                throw ApiException::fromResponse(
                    $statusCode,
                    is_array($data) ? $data : ['message' => 'HTTP request failed'],
                    self::sanitizedPrevious($e),
                    $response->getHeaders()
                );
            }

            $this->logger->error("Assinafy Network Error: {$safeRequest}", [
                'exception_type' => $e::class,
            ]);

            throw new NetworkException(
                'Network error while calling the Assinafy API',
                0,
                self::sanitizedPrevious($e)
            );
        }
    }

    /**
     * Preserve a safe diagnostic cause without retaining Guzzle's request object.
     * RequestException stores the complete URI, including signer credentials, so
     * attaching it directly can leak secrets through exception-chain logging.
     */
    private static function sanitizedPrevious(\Throwable $exception): \RuntimeException
    {
        return new \RuntimeException(
            'Underlying HTTP transport error (' . get_debug_type($exception) . ')',
            (int) $exception->getCode()
        );
    }

    /**
     * @param array<array-key, array<int, string>|string> $headers
     */
    private static function hasBinaryContentType(array $headers): bool
    {
        foreach ($headers as $name => $values) {
            if (!is_string($name) || strcasecmp($name, 'Content-Type') !== 0) {
                continue;
            }

            $contentType = is_array($values) ? implode(', ', $values) : $values;
            return preg_match(
                '~^(?:image/|application/(?:pdf|octet-stream|zip))(?:[^,]*)(?:,|$)~i',
                trim($contentType)
            ) === 1;
        }

        return false;
    }

    /**
     * Apply configured headers per request so an explicit Bearer token can replace
     * (rather than accompany) the configured API key, and vice versa.
     *
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    private function withDefaultHeaders(#[\SensitiveParameter] array $options): array
    {
        $headers = isset($options['headers']) && is_array($options['headers'])
            ? $options['headers']
            : [];

        $hasAuthorization = self::hasHeader($headers, 'Authorization');
        $hasApiKey = self::hasHeader($headers, 'X-Api-Key');
        if ($hasAuthorization && $hasApiKey) {
            throw new \InvalidArgumentException(
                'A request cannot contain both Authorization and X-Api-Key headers'
            );
        }

        foreach ($this->defaultHeaders as $name => $value) {
            if (
                ($name === 'X-Api-Key' && $hasAuthorization)
                || ($name === 'Authorization' && $hasApiKey)
            ) {
                continue;
            }
            if (!self::hasHeader($headers, $name)) {
                $headers[$name] = $value;
            }
        }

        $options['headers'] = $headers;

        return $options;
    }

    /**
     * @param array<array-key, mixed> $headers
     */
    private static function hasHeader(array $headers, string $name): bool
    {
        foreach (array_keys($headers) as $headerName) {
            if (is_string($headerName) && strcasecmp($headerName, $name) === 0) {
                return true;
            }
        }

        return false;
    }
}
