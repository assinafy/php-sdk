<?php

declare(strict_types=1);

namespace Assinafy\SDK\Http;

interface HttpClientInterface
{
    public function get(string $uri, array $params = [], array $headers = []): Response;

    public function post(string $uri, array $data = [], array $headers = []): Response;

    public function put(string $uri, array $data = [], array $headers = []): Response;

    public function delete(string $uri, array $headers = []): Response;

    public function uploadFile(string $uri, string $filePath, array $data = [], array $headers = []): Response;

    /**
     * Send a raw request body (e.g. a binary image) with a custom Content-Type.
     * Used by the signer-facing `/signature` endpoint which expects `image/png` or `image/jpeg`.
     *
     * @param array<string, scalar> $query
     * @param array<string, string> $headers
     */
    public function postRaw(
        string $uri,
        string $body,
        string $contentType,
        array $query = [],
        array $headers = []
    ): Response;
}
