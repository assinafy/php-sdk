<?php

declare(strict_types=1);

namespace Assinafy\SDK\Tests\Unit\Resources;

use Assinafy\SDK\AssinafyClient;
use Assinafy\SDK\Configuration;
use Assinafy\SDK\Exceptions\ValidationException;
use Assinafy\SDK\Tests\Unit\Support\FakeHttpClient;
use PHPUnit\Framework\TestCase;

final class EmbeddedSigningResourceTest extends TestCase
{
    public function testCreatesAndExchangesSessionsWithEncodedIdentifiers(): void
    {
        $http = new FakeHttpClient();
        $client = new AssinafyClient(new Configuration('key', 'account'), $http);
        $resource = $client->embeddedSigning();
        self::assertSame($resource, $client->embeddedSigning());
        $session = ['url' => 'https://app.example.com/embed/token', 'expires_at' => '2027-01-01T12:05:00Z'];
        $http->queueJson(200, $session);
        self::assertSame($session, $resource->createSession('d/1', 'a/1', 's/1'));
        self::assertSame(
            'documents/d%2F1/assignments/a%2F1/signers/s%2F1/embed-sessions',
            $http->lastCall()['uri']
        );
        self::assertNull($http->lastCall()['body']);

        $exchange = ['signer_access_code' => 'code', 'embed_origins' => ['https://app.example.com']];
        $http->queueJson(200, $exchange);
        self::assertSame($exchange, $resource->exchangeSession('t/1', 'https://app.example.com'));
        self::assertSame('embed/sessions/t%2F1/exchange', $http->lastCall()['uri']);
        self::assertSame(['parent_origin' => 'https://app.example.com'], $http->lastCall()['body']);
        $http->queueJson(200, $exchange);
        $resource->exchangeSession('token');
        self::assertNull($http->lastCall()['body']);
    }

    public function testEmptyTokenFailsBeforeRequest(): void
    {
        $http = new FakeHttpClient();
        $client = new AssinafyClient(Configuration::forPublic(), $http);
        try {
            $client->embeddedSigning()->exchangeSession('');
            self::fail('An empty token must be rejected');
        } catch (ValidationException) {
            self::assertSame([], $http->calls);
        }
    }

    public function testPublicClientCannotMintSessions(): void
    {
        $client = new AssinafyClient(Configuration::forPublic(), new FakeHttpClient());
        $this->expectException(ValidationException::class);
        $client->embeddedSigning()->createSession('d', 'a', 's');
    }
}
