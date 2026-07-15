<?php

declare(strict_types=1);

namespace Assinafy\SDK;

class Configuration
{
    public const SDK_VERSION = '2.0.0';
    public const DEFAULT_BASE_URL = 'https://api.assinafy.com.br/v1';
    public const SANDBOX_BASE_URL = 'https://sandbox.assinafy.com.br/v1';

    /**
     * Sentinel placeholder used by {@see self::forPublic()} so the SDK can talk to
     * unauthenticated endpoints (`/login`, `/authentication/*`, the public document
     * routes) without forcing the caller to fabricate an API key / account ID.
     */
    private const PUBLIC_PLACEHOLDER = '__public__';

    private string $baseUrl;
    private string $apiKey;
    private string $accountId;
    private int $timeout;
    private int $connectTimeout;

    public function __construct(
        string $apiKey,
        string $accountId,
        string $baseUrl = self::DEFAULT_BASE_URL,
        int $timeout = 30,
        int $connectTimeout = 10
    ) {
        $this->validateApiKey($apiKey);
        $this->validateAccountId($accountId);

        $this->apiKey = $apiKey;
        $this->accountId = $accountId;
        $this->baseUrl = rtrim($baseUrl, '/');
        $this->timeout = $timeout;
        $this->connectTimeout = $connectTimeout;
    }

    /**
     * @param array<string, mixed> $config keys: `api_key`/`apiKey`, `account_id`/`accountId`,
     *     `base_url`/`baseUrl`, `timeout`, `connect_timeout`/`connectTimeout`.
     *     A `webhook_secret` key is accepted and ignored — see {@see \Assinafy\SDK\Support\WebhookEventParser}.
     */
    public static function fromArray(array $config): self
    {
        return new self(
            (string) ($config['api_key'] ?? $config['apiKey'] ?? ''),
            (string) ($config['account_id'] ?? $config['accountId'] ?? ''),
            (string) ($config['base_url'] ?? $config['baseUrl'] ?? self::DEFAULT_BASE_URL),
            (int) ($config['timeout'] ?? 30),
            (int) ($config['connect_timeout'] ?? $config['connectTimeout'] ?? 10)
        );
    }

    /**
     * Configuration for the unauthenticated surface of the API.
     *
     * Use this when bootstrapping a session — e.g. before you have an API key:
     *
     * ```php
     * $client = new AssinafyClient(Configuration::forPublic());
     * $session = $client->auth()->login('user@example.com', 'secret');
     * ```
     *
     * The sentinel API key / account ID are sent on the `X-Api-Key` header for
     * completeness, but unauthenticated endpoints ignore them. Account-scoped
     * resources will fail with a clear runtime error if called on a public config.
     */
    public static function forPublic(string $baseUrl = self::DEFAULT_BASE_URL): self
    {
        return new self(self::PUBLIC_PLACEHOLDER, self::PUBLIC_PLACEHOLDER, $baseUrl);
    }

    public function isPublic(): bool
    {
        return $this->apiKey === self::PUBLIC_PLACEHOLDER
            && $this->accountId === self::PUBLIC_PLACEHOLDER;
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
