<?php

declare(strict_types=1);

namespace Assinafy\SDK\Http;

class Response
{
    private int $statusCode;
    /** @var array<string, array<int, string>|string> */
    private array $headers;
    private string $body;
    /** @var array<array-key, mixed>|null */
    private ?array $data;

    /**
     * @param array<string, array<int, string>|string> $headers
     */
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

    /**
     * @return array<string, array<int, string>|string>
     */
    public function getHeaders(): array
    {
        return $this->headers;
    }

    public function getBody(): string
    {
        return $this->body;
    }

    /**
     * @return array<array-key, mixed>|null
     */
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

    /**
     * @return array<array-key, mixed>|null
     */
    private function parseBody(string $body): ?array
    {
        if ($body === '') {
            return null;
        }

        try {
            $decoded = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            return null;
        }

        return is_array($decoded) ? $decoded : null;
    }
}
