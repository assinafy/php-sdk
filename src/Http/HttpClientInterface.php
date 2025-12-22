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
}

