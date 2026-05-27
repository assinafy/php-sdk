<?php

declare(strict_types=1);

namespace Assinafy\SDK\Tests\Unit\Support;

use Assinafy\SDK\Http\HttpClientInterface;
use Assinafy\SDK\Http\Response;

/**
 * Recording stub HTTP client used in unit tests. It returns whatever response
 * the test queues up and records every request the SUT made, so the test can
 * assert on method, path, query, body, and headers.
 */
final class FakeHttpClient implements HttpClientInterface
{
    /** @var array<int, array<string, mixed>> */
    public array $calls = [];

    /** @var array<int, Response> */
    private array $responses = [];

    public function queueJson(int $status, array $data, array $headers = []): self
    {
        $body = json_encode(['status' => $status, 'message' => '', 'data' => $data]) ?: '';
        $this->responses[] = new Response($status, $headers, $body);

        return $this;
    }

    public function queueRaw(int $status, string $body, array $headers = []): self
    {
        $this->responses[] = new Response($status, $headers, $body);

        return $this;
    }

    public function lastCall(): array
    {
        if ($this->calls === []) {
            throw new \RuntimeException('No calls recorded');
        }

        return $this->calls[count($this->calls) - 1];
    }

    public function get(string $uri, array $params = [], array $headers = []): Response
    {
        return $this->record('GET', $uri, ['query' => $params, 'headers' => $headers]);
    }

    public function post(string $uri, array $data = [], array $headers = [], array $query = []): Response
    {
        return $this->record('POST', $uri, ['body' => $data, 'headers' => $headers, 'query' => $query]);
    }

    public function put(string $uri, array $data = [], array $headers = [], array $query = []): Response
    {
        return $this->record('PUT', $uri, ['body' => $data, 'headers' => $headers, 'query' => $query]);
    }

    public function delete(string $uri, array $headers = [], array $query = []): Response
    {
        return $this->record('DELETE', $uri, ['headers' => $headers, 'query' => $query]);
    }

    public function uploadFile(string $uri, string $filePath, array $data = [], array $headers = []): Response
    {
        return $this->record('UPLOAD', $uri, [
            'file_path' => $filePath,
            'body' => $data,
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
        return $this->record('POST_RAW', $uri, [
            'body' => $body,
            'content_type' => $contentType,
            'query' => $query,
            'headers' => $headers,
        ]);
    }

    private function record(string $method, string $uri, array $extra): Response
    {
        if ($this->responses === []) {
            throw new \RuntimeException("No response queued for {$method} {$uri}");
        }

        $this->calls[] = array_merge(['method' => $method, 'uri' => $uri], $extra);

        return array_shift($this->responses);
    }
}
