<?php

declare(strict_types=1);

namespace Assinafy\SDK\Tests\Unit;

use Assinafy\SDK\Configuration;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class ConfigurationTest extends TestCase
{
    public function testDefaultsAndAccessors(): void
    {
        $config = new Configuration('key', 'account');

        $this->assertSame('key', $config->getApiKey());
        $this->assertSame('account', $config->getAccountId());
        $this->assertSame(Configuration::DEFAULT_BASE_URL, $config->getBaseUrl());
        $this->assertSame(30, $config->getTimeout());
        $this->assertSame(10, $config->getConnectTimeout());
    }

    public function testStripsTrailingSlashFromBaseUrl(): void
    {
        $config = new Configuration('key', 'account', 'https://example.com/v1/');
        $this->assertSame('https://example.com/v1', $config->getBaseUrl());
    }

    public function testHeadersIncludeApiKeyAndVersionedUserAgent(): void
    {
        $headers = (new Configuration('mykey', 'account'))->getHeaders();

        $this->assertSame('mykey', $headers['X-Api-Key']);
        $this->assertSame('application/json', $headers['Accept']);
        $this->assertSame('assinafy-php-sdk/' . Configuration::SDK_VERSION, $headers['User-Agent']);
        $this->assertArrayNotHasKey('Content-Type', $headers, 'Default headers must not pin Content-Type');
    }

    public function testFromArraySupportsBothSnakeAndCamelKeys(): void
    {
        $config = Configuration::fromArray([
            'apiKey' => 'k',
            'account_id' => 'a',
            'baseUrl' => 'https://example.com',
            'connectTimeout' => 5,
        ]);

        $this->assertSame('k', $config->getApiKey());
        $this->assertSame('a', $config->getAccountId());
        $this->assertSame('https://example.com', $config->getBaseUrl());
        $this->assertSame(5, $config->getConnectTimeout());
    }

    public function testFromArraySupportsBearerAuthenticationWithoutApiKey(): void
    {
        $config = Configuration::fromArray([
            'access_token' => 'token',
            'account_id' => 'account',
        ]);

        $this->assertTrue($config->isBearerAuthenticated());
        $this->assertSame('token', $config->getAccessToken());
        $this->assertSame('Bearer token', $config->getHeaders()['Authorization']);
        $this->assertArrayNotHasKey('X-Api-Key', $config->getHeaders());
    }

    /**
     * `webhook_secret` was meaningful in 1.x, when it fed the HMAC verifier that 2.0.0
     * removed. Configs carried over from 1.x must keep working rather than blow up.
     */
    public function testFromArrayIgnoresLegacyWebhookSecretKey(): void
    {
        $config = Configuration::fromArray([
            'api_key' => 'k',
            'account_id' => 'a',
            'webhook_secret' => 'sec',
        ]);

        $this->assertSame('k', $config->getApiKey());
        $this->assertSame('a', $config->getAccountId());
    }

    public function testRejectsEmptyApiKey(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new Configuration('', 'a');
    }

    public function testRejectsWhitespaceOnlyCredentials(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new Configuration('   ', 'a');
    }

    public function testRejectsEmptyAccountId(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new Configuration('k', '');
    }

    public function testForPublicProducesPublicConfig(): void
    {
        $config = Configuration::forPublic();

        $this->assertTrue($config->isPublic());
        $this->assertSame(Configuration::DEFAULT_BASE_URL, $config->getBaseUrl());
        $this->assertArrayNotHasKey('X-Api-Key', $config->getHeaders());
    }

    public function testForPublicRespectsBaseUrlOverride(): void
    {
        $config = Configuration::forPublic(Configuration::SANDBOX_BASE_URL);

        $this->assertTrue($config->isPublic());
        $this->assertSame(Configuration::SANDBOX_BASE_URL, $config->getBaseUrl());
    }

    public function testStandardConfigIsNotPublic(): void
    {
        $this->assertFalse((new Configuration('k', 'a'))->isPublic());
    }

    public function testForBearerConfiguresWorkspaceAuthorizationHeader(): void
    {
        $config = Configuration::forBearer('oauth-token', 'account', Configuration::SANDBOX_BASE_URL);

        $this->assertFalse($config->isPublic());
        $this->assertTrue($config->isBearerAuthenticated());
        $this->assertSame('', $config->getApiKey());
        $this->assertSame('oauth-token', $config->getAccessToken());
        $this->assertSame('Bearer oauth-token', $config->getHeaders()['Authorization']);
        $this->assertArrayNotHasKey('X-Api-Key', $config->getHeaders());
    }

    public function testRejectsAmbiguousApiKeyAndBearerAuthentication(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new Configuration('key', 'account', accessToken: 'token');
    }

    public function testRejectsInvalidBaseUrl(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new Configuration('k', 'a', 'not-a-url');
    }

    public function testRejectsBaseUrlCredentialsQueryAndFragment(): void
    {
        foreach (
            [
            'https://user:pass@example.com/v1',
            'https://example.com/v1?api-key=secret',
            'https://example.com/v1#fragment',
            ] as $url
        ) {
            try {
                new Configuration('k', 'a', $url);
                $this->fail("Expected invalid base URL: {$url}");
            } catch (InvalidArgumentException $e) {
                $this->assertStringContainsString('Base URL', $e->getMessage());
            }
        }
    }

    public function testRejectsCleartextRemoteBaseUrlButAllowsLoopbackDevelopment(): void
    {
        try {
            new Configuration('k', 'a', 'http://api.example.com/v1');
            $this->fail('Remote API credentials must never be sent over cleartext HTTP');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('HTTPS', $e->getMessage());
        }

        $this->assertSame(
            'http://127.0.0.1:8080/v1',
            (new Configuration('k', 'a', 'http://127.0.0.1:8080/v1'))->getBaseUrl()
        );
        $this->assertSame(
            'http://localhost:8080/v1',
            (new Configuration('k', 'a', 'http://localhost:8080/v1'))->getBaseUrl()
        );
    }

    public function testRejectsNonPositiveTimeouts(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new Configuration('k', 'a', Configuration::DEFAULT_BASE_URL, 0);
    }
}
