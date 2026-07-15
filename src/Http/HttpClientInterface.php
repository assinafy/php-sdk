<?php

declare(strict_types=1);

namespace Assinafy\SDK\Http;

interface HttpClientInterface
{
    /**
     * @param array<string, scalar> $params
     * @param array<string, string> $headers
     */
    public function get(string $uri, array $params = [], array $headers = []): Response;

    /**
     * @param array<array-key, mixed> $data    JSON body — a string-keyed object or, for
     *                                          endpoints that expect a JSON array, a list
     * @param array<string, string>   $headers
     * @param array<string, scalar>   $query   optional query-string parameters (e.g. `signer-access-code`)
     */
    public function post(string $uri, array $data = [], array $headers = [], array $query = []): Response;

    /**
     * @param array<array-key, mixed> $data    JSON body — a string-keyed object or, for
     *                                          endpoints that expect a JSON array, a list
     * @param array<string, string>   $headers
     * @param array<string, scalar>   $query   optional query-string parameters (e.g. `signer-access-code`)
     */
    public function put(string $uri, array $data = [], array $headers = [], array $query = []): Response;

    /**
     * @param array<array-key, mixed> $data    JSON body
     * @param array<string, string>   $headers
     * @param array<string, scalar>   $query
     */
    public function patch(string $uri, array $data = [], array $headers = [], array $query = []): Response;

    /**
     * @param array<string, string>   $headers
     * @param array<string, scalar>   $query   optional query-string parameters (e.g. `force`)
     * @param array<array-key, mixed> $data    optional JSON body — a few DELETE endpoints
     *                                          (e.g. `DELETE /accounts/{id}`) document one
     */
    public function delete(string $uri, array $headers = [], array $query = [], array $data = []): Response;

    /**
     * @param array<string, mixed>  $data
     * @param array<string, string> $headers
     */
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
