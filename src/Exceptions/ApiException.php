<?php

declare(strict_types=1);

namespace Assinafy\SDK\Exceptions;

class ApiException extends AssinafyException
{
    private int $statusCode;
    private ?array $responseData;

    public function __construct(
        string $message,
        int $statusCode,
        ?array $responseData = null,
        ?\Throwable $previous = null
    ) {
        parent::__construct($message, $statusCode, $previous, ['response_data' => $responseData]);
        $this->statusCode = $statusCode;
        $this->responseData = $responseData;
    }

    public function getStatusCode(): int
    {
        return $this->statusCode;
    }

    public function getResponseData(): ?array
    {
        return $this->responseData;
    }

    public static function fromResponse(int $statusCode, array $responseData): self
    {
        $message = $responseData['message'] ?? $responseData['error'] ?? 'API request failed';
        
        return new self($message, $statusCode, $responseData);
    }
}

