<?php

declare(strict_types=1);

namespace Assinafy\SDK;

class Configuration
{
    public const SDK_VERSION = '2.1.0';
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
    private ?string $accessToken;
    private string $accountId;
    private int $timeout;
    private int $connectTimeout;

    public function __construct(
        #[\SensitiveParameter] string $apiKey,
        string $accountId,
        string $baseUrl = self::DEFAULT_BASE_URL,
        int $timeout = 30,
        int $connectTimeout = 10,
        #[\SensitiveParameter] ?string $accessToken = null
    ) {
        $this->validateCredentials($apiKey, $accountId, $accessToken);
        $this->validateAccountId($accountId);
        $this->validateBaseUrl($baseUrl);
        $this->validateTimeout('timeout', $timeout);
        $this->validateTimeout('connect timeout', $connectTimeout);

        $this->apiKey = $apiKey;
        $this->accessToken = $accessToken;
        $this->accountId = $accountId;
        $this->baseUrl = rtrim($baseUrl, '/');
        $this->timeout = $timeout;
        $this->connectTimeout = $connectTimeout;
    }

    /**
     * @param array<string, mixed> $config keys: `api_key`/`apiKey`, `account_id`/`accountId`,
     *     `access_token`/`accessToken`, `base_url`/`baseUrl`, `timeout`,
     *     `connect_timeout`/`connectTimeout`.
     *     A `webhook_secret` key is accepted and ignored — see {@see \Assinafy\SDK\Support\WebhookEventParser}.
     */
    public static function fromArray(#[\SensitiveParameter] array $config): self
    {
        $apiKey = $config['api_key'] ?? $config['apiKey'] ?? '';
        $accountId = $config['account_id'] ?? $config['accountId'] ?? '';
        $baseUrl = $config['base_url'] ?? $config['baseUrl'] ?? self::DEFAULT_BASE_URL;
        $timeout = $config['timeout'] ?? 30;
        $connectTimeout = $config['connect_timeout'] ?? $config['connectTimeout'] ?? 10;
        $accessToken = $config['access_token'] ?? $config['accessToken'] ?? null;

        foreach (
            [
                'API key' => $apiKey,
                'Account ID' => $accountId,
                'Base URL' => $baseUrl,
                'Access token' => $accessToken,
            ] as $name => $value
        ) {
            if ($value !== null && !is_string($value)) {
                throw new \InvalidArgumentException("{$name} must be a string");
            }
        }

        foreach (['Timeout' => $timeout, 'Connect timeout' => $connectTimeout] as $name => $value) {
            if (
                (!is_int($value) && !is_string($value))
                || filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) === false
            ) {
                throw new \InvalidArgumentException("{$name} must be a positive integer");
            }
        }

        return new self(
            $apiKey,
            $accountId,
            $baseUrl,
            (int) $timeout,
            (int) $connectTimeout,
            $accessToken
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
     * The sentinel values are never sent over the network. Account-scoped resources
     * fail with a clear runtime error if called on a public configuration.
     */
    public static function forPublic(string $baseUrl = self::DEFAULT_BASE_URL): self
    {
        return new self(self::PUBLIC_PLACEHOLDER, self::PUBLIC_PLACEHOLDER, $baseUrl);
    }

    /**
     * Configure all workspace resources with OAuth/Bearer authentication instead
     * of an API key.
     */
    public static function forBearer(
        #[\SensitiveParameter] string $accessToken,
        string $accountId,
        string $baseUrl = self::DEFAULT_BASE_URL,
        int $timeout = 30,
        int $connectTimeout = 10
    ): self {
        return new self('', $accountId, $baseUrl, $timeout, $connectTimeout, $accessToken);
    }

    public function isPublic(): bool
    {
        return $this->apiKey === self::PUBLIC_PLACEHOLDER
            && $this->accountId === self::PUBLIC_PLACEHOLDER
            && $this->accessToken === null;
    }

    public function isBearerAuthenticated(): bool
    {
        return $this->accessToken !== null;
    }

    public function getBaseUrl(): string
    {
        return $this->baseUrl;
    }

    public function getApiKey(): string
    {
        return $this->apiKey;
    }

    public function getAccessToken(): ?string
    {
        return $this->accessToken;
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

    /**
     * Default transport headers.
     *
     * @return array<string, string>
     */
    public function getHeaders(): array
    {
        $headers = [
            'Accept' => 'application/json',
            'User-Agent' => 'assinafy-php-sdk/' . self::SDK_VERSION,
        ];

        if ($this->accessToken !== null) {
            $headers['Authorization'] = 'Bearer ' . $this->accessToken;
        } elseif (!$this->isPublic()) {
            $headers['X-Api-Key'] = $this->apiKey;
        }

        return $headers;
    }

    private function validateCredentials(
        #[\SensitiveParameter] string $apiKey,
        string $accountId,
        #[\SensitiveParameter] ?string $accessToken
    ): void {
        if ($apiKey === self::PUBLIC_PLACEHOLDER && $accountId === self::PUBLIC_PLACEHOLDER) {
            if ($accessToken !== null) {
                throw new \InvalidArgumentException('Public configuration cannot contain an access token');
            }

            return;
        }

        $hasApiKey = trim($apiKey) !== '';
        $hasAccessToken = $accessToken !== null && trim($accessToken) !== '';

        if ($apiKey !== '' && !$hasApiKey) {
            throw new \InvalidArgumentException('API key cannot be empty');
        }
        if ($accessToken !== null && !$hasAccessToken) {
            throw new \InvalidArgumentException('Access token cannot be empty');
        }
        if ($hasApiKey === $hasAccessToken) {
            throw new \InvalidArgumentException('Configure exactly one of API key or access token');
        }
    }

    private function validateAccountId(string $accountId): void
    {
        if (trim($accountId) === '') {
            throw new \InvalidArgumentException('Account ID cannot be empty');
        }
    }

    private function validateBaseUrl(string $baseUrl): void
    {
        if ($baseUrl === '' || filter_var($baseUrl, FILTER_VALIDATE_URL) === false) {
            throw new \InvalidArgumentException('Base URL must be a valid absolute URL');
        }

        $scheme = strtolower((string) parse_url($baseUrl, PHP_URL_SCHEME));
        if (!in_array($scheme, ['http', 'https'], true)) {
            throw new \InvalidArgumentException('Base URL must use HTTP or HTTPS');
        }

        $host = parse_url($baseUrl, PHP_URL_HOST);
        if (!is_string($host) || $host === '') {
            throw new \InvalidArgumentException('Base URL must include a host');
        }

        $loopbackHosts = ['localhost', '127.0.0.1', '::1', '[::1]'];
        $isLoopback = in_array(strtolower($host), $loopbackHosts, true)
            || str_ends_with(strtolower($host), '.localhost');
        if ($scheme !== 'https' && !$isLoopback) {
            throw new \InvalidArgumentException(
                'Base URL must use HTTPS except for loopback development hosts'
            );
        }

        foreach ([PHP_URL_USER, PHP_URL_PASS, PHP_URL_QUERY, PHP_URL_FRAGMENT] as $component) {
            if (parse_url($baseUrl, $component) !== null) {
                throw new \InvalidArgumentException(
                    'Base URL cannot contain credentials, a query string, or a fragment'
                );
            }
        }
    }

    private function validateTimeout(string $name, int $seconds): void
    {
        if ($seconds <= 0) {
            throw new \InvalidArgumentException(ucfirst($name) . ' must be greater than zero');
        }
    }
}
