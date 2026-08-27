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
            'accessToken' => 'e',
            'newPassword' => 'f',
            'clientSecret' => 'g',
        ]);

        foreach (
            [
                'PASSWORD',
                'X-Api-Key',
                'x-api-key',
                'Signer-Access-Code',
                'accessToken',
                'newPassword',
                'clientSecret',
            ] as $key
        ) {
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

    public function testRedactBodyDoesNotLogNonJsonContent(): void
    {
        $this->assertSame('[non-JSON body, 8 bytes]', LogRedactor::redactBody('not json'));
        $this->assertSame('', LogRedactor::redactBody(''));
    }

    public function testRedactsSecretsEmbeddedInUrlsAndExceptionText(): void
    {
        $url = 'https://app.example/sign?email=user%40example.com&signer-access-code=SECRET';
        $redacted = LogRedactor::redact(['url' => $url]);

        $this->assertStringNotContainsString('SECRET', $redacted['url']);
        $this->assertStringContainsString('signer-access-code=' . LogRedactor::PLACEHOLDER, $redacted['url']);

        $message = 'Request failed; Authorization: Bearer JWT and X-Api-Key: APIKEY';
        $safe = LogRedactor::redactText($message);
        $this->assertStringNotContainsString('JWT', $safe);
        $this->assertStringNotContainsString('APIKEY', $safe);

        $fragment = LogRedactor::redactText('https://app.example/callback#access_token=FRAGMENT_SECRET');
        $this->assertStringNotContainsString('FRAGMENT_SECRET', $fragment);

        $camelCase = LogRedactor::redactText('https://app.example/callback?accessToken=CAMEL_SECRET');
        $this->assertStringNotContainsString('CAMEL_SECRET', $camelCase);

        $pathCode = LogRedactor::redactText(
            'https://app-sandbox.example/sign/PATH_SECRET?email=signer%40example.test'
        );
        $this->assertStringNotContainsString('PATH_SECRET', $pathCode);
        $this->assertStringContainsString('/sign/' . LogRedactor::PLACEHOLDER, $pathCode);
    }

    public function testRedactBodySummarisesLargeBinaryBodies(): void
    {
        $body = LogRedactor::redactBody(str_repeat("\x00PDFDATA", 200));

        $this->assertStringContainsString('non-JSON body', $body);
        $this->assertStringContainsString('bytes', $body);
    }

    public function testRequestOptionsNeverExposeRawOrMultipartStreams(): void
    {
        $stream = fopen('php://temp', 'rb');
        $this->assertIsResource($stream);

        try {
            $redacted = LogRedactor::redactRequestOptions([
                'body' => "\x89PNG-private-signature",
                'multipart' => [[
                    'name' => 'file',
                    'contents' => $stream,
                ], [
                    'name' => 'token',
                    'contents' => 'multipart-secret',
                ]],
            ]);
        } finally {
            fclose($stream);
        }

        $this->assertSame('[raw request body, 22 bytes]', $redacted['body']);
        $this->assertSame('[stream]', $redacted['multipart'][0]['contents']);
        $this->assertSame(LogRedactor::PLACEHOLDER, $redacted['multipart'][1]['contents']);
    }

    public function testRequestSummaryContainsStructureButNoValues(): void
    {
        $summary = LogRedactor::summarizeRequestOptions([
            'query' => ['search' => 'private@example.com'],
            'headers' => ['Authorization' => 'Bearer secret'],
            'json' => ['email' => 'private@example.com', 'password' => 'secret'],
            'body' => 'private image bytes',
            'multipart' => [['name' => 'file', 'contents' => 'private document']],
        ]);

        $encoded = json_encode($summary, JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('private@example.com', $encoded);
        $this->assertStringNotContainsString('Bearer secret', $encoded);
        $this->assertStringNotContainsString('private image bytes', $encoded);
        $this->assertSame(['search'], $summary['query_keys']);
        $this->assertSame(['Authorization'], $summary['header_names']);
        $this->assertSame(['email', 'password'], $summary['json_keys']);
        $this->assertSame(19, $summary['body_bytes']);
        $this->assertSame(1, $summary['multipart_parts']);
    }
}
