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
}
