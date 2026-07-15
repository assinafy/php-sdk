<?php

declare(strict_types=1);

namespace Assinafy\SDK\Tests\Unit\Http;

use Assinafy\SDK\Http\LogRedactor;
use PHPUnit\Framework\TestCase;

final class LogRedactorTest extends TestCase
{
    public function testRedactsPasswordFromALoginBody(): void
    {
        $redacted = LogRedactor::redact([
            'json' => ['email' => 'user@example.com', 'password' => 'hunter2'],
        ]);

        $this->assertSame('user@example.com', $redacted['json']['email'], 'Non-secrets stay readable');
        $this->assertSame(LogRedactor::PLACEHOLDER, $redacted['json']['password']);
    }

    public function testRedactsAuthorizationHeader(): void
    {
        $redacted = LogRedactor::redact([
            'headers' => ['Authorization' => 'Bearer secret-token', 'Content-Type' => 'application/json'],
        ]);

        $this->assertSame(LogRedactor::PLACEHOLDER, $redacted['headers']['Authorization']);
        $this->assertSame('application/json', $redacted['headers']['Content-Type']);
    }

    public function testMatchesKeysCaseInsensitively(): void
    {
        $redacted = LogRedactor::redact([
            'PASSWORD' => 'a',
            'X-Api-Key' => 'b',
            'x-api-key' => 'c',
            'Signer-Access-Code' => 'd',
        ]);

        foreach (['PASSWORD', 'X-Api-Key', 'x-api-key', 'Signer-Access-Code'] as $key) {
            $this->assertSame(LogRedactor::PLACEHOLDER, $redacted[$key], "{$key} must be redacted");
        }
    }

    public function testRedactsNestedSecrets(): void
    {
        $redacted = LogRedactor::redact([
            'a' => ['b' => ['token' => 'deep-secret', 'keep' => 'visible']],
        ]);

        $this->assertSame(LogRedactor::PLACEHOLDER, $redacted['a']['b']['token']);
        $this->assertSame('visible', $redacted['a']['b']['keep']);
    }

    public function testASecretHoldingAnArrayIsMaskedWholesaleRatherThanWalked(): void
    {
        $redacted = LogRedactor::redact(['token' => ['nested' => 'still-secret']]);

        $this->assertSame(LogRedactor::PLACEHOLDER, $redacted['token']);
    }

    public function testPreservesStructureAndListKeys(): void
    {
        $redacted = LogRedactor::redact(['query' => [], 'list' => ['a', 'b']]);

        $this->assertSame(['query' => [], 'list' => ['a', 'b']], $redacted);
    }

    public function testRedactBodyMasksSecretsInJson(): void
    {
        $body = LogRedactor::redactBody('{"data":{"access_token":"jwt-here","user":"jane"}}');

        $this->assertStringNotContainsString('jwt-here', $body);
        $this->assertStringContainsString('jane', $body);
    }

    public function testRedactBodyPassesShortNonJsonThrough(): void
    {
        $this->assertSame('not json', LogRedactor::redactBody('not json'));
        $this->assertSame('', LogRedactor::redactBody(''));
    }

    public function testRedactBodySummarisesLargeBinaryBodies(): void
    {
        $body = LogRedactor::redactBody(str_repeat("\x00PDFDATA", 200));

        $this->assertStringContainsString('non-JSON body', $body);
        $this->assertStringContainsString('bytes', $body);
    }
}
