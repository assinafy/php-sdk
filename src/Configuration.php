<?php

declare(strict_types=1);

namespace Assinafy\SDK;

class Configuration
{
    public const SDK_VERSION = '1.2.0';
    public const DEFAULT_BASE_URL = 'https://api.assinafy.com.br/v1';
    public const SANDBOX_BASE_URL = 'https://sandbox.assinafy.com.br/v1';

    private string $baseUrl;
    private string $apiKey;
    private string $accountId;
    private ?string $webhookSecret;
    private int $timeout;
    private int $connectTimeout;

    public function __construct(
        string $apiKey,
        string $accountId,
        string $baseUrl = self::DEFAULT_BASE_URL,
        ?string $webhookSecret = null,
        int $timeout = 30,
        int $connectTimeout = 10
    ) {
        $this->validateApiKey($apiKey);
        $this->validateAccountId($accountId);

        $this->apiKey = $apiKey;
        $this->accountId = $accountId;
        $this->baseUrl = rtrim($baseUrl, '/');
        $this->webhookSecret = $webhookSecret;
        $this->timeout = $timeout;
        $this->connectTimeout = $connectTimeout;
    }

    public static function fromArray(array $config): self
    {
        return new self(
            $config['api_key'] ?? $config['apiKey'] ?? '',
            $config['account_id'] ?? $config['accountId'] ?? '',
            $config['base_url'] ?? $config['baseUrl'] ?? self::DEFAULT_BASE_URL,
            $config['webhook_secret'] ?? $config['webhookSecret'] ?? null,
            $config['timeout'] ?? 30,
            $config['connect_timeout'] ?? $config['connectTimeout'] ?? 10
        );
    }

    public function getBaseUrl(): string
    {
        return $this->baseUrl;
    }

    public function getApiKey(): string
    {
        return $this->apiKey;
    }

    public function getAccountId(): string
    {
        return $this->accountId;
    }

    public function getWebhookSecret(): ?string
    {
        return $this->webhookSecret;
    }

    public function getTimeout(): int
    {
        return $this->timeout;
    }

    public function getConnectTimeout(): int
    {
        return $this->connectTimeout;
    }

    public function getHeaders(): array
    {
        return [
            'X-Api-Key' => $this->apiKey,
            'Accept' => 'application/json',
            'User-Agent' => 'assinafy-php-sdk/' . self::SDK_VERSION,
        ];
    }

    private function validateApiKey(string $apiKey): void
    {
        if (empty($apiKey)) {
            throw new \InvalidArgumentException('API key cannot be empty');
        }
    }

    private function validateAccountId(string $accountId): void
    {
        if (empty($accountId)) {
            throw new \InvalidArgumentException('Account ID cannot be empty');
        }
    }
}
