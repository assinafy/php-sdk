<?php

declare(strict_types=1);

namespace Assinafy\SDK\Http;

class Response
{
    private int $statusCode;
    private array $headers;
    private string $body;
    private ?array $data;

    public function __construct(int $statusCode, array $headers, string $body)
    {
        $this->statusCode = $statusCode;
        $this->headers = $headers;
        $this->body = $body;
        $this->data = $this->parseBody($body);
    }

    public function getStatusCode(): int
    {
        return $this->statusCode;
    }

    public function getHeaders(): array
    {
        return $this->headers;
    }

    public function getBody(): string
    {
        return $this->body;
    }

    public function getData(): ?array
    {
        return $this->data;
    }

    public function isSuccess(): bool
    {
        return $this->statusCode >= 200 && $this->statusCode < 300;
    }

    public function isClientError(): bool
    {
        return $this->statusCode >= 400 && $this->statusCode < 500;
    }

    public function isServerError(): bool
    {
        return $this->statusCode >= 500;
    }

    private function parseBody(string $body): ?array
    {
        if (empty($body)) {
            return null;
        }

        $decoded = json_decode($body, true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            return null;
        }

        return $decoded;
    }
}

