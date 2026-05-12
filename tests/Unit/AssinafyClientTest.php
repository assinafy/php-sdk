<?php

declare(strict_types=1);

namespace Assinafy\SDK\Tests\Unit;

use Assinafy\SDK\AssinafyClient;
use Assinafy\SDK\Configuration;
use Assinafy\SDK\Resources\AssignmentResource;
use Assinafy\SDK\Resources\AuthResource;
use Assinafy\SDK\Resources\DocumentResource;
use Assinafy\SDK\Resources\SignerResource;
use Assinafy\SDK\Resources\SignerSessionResource;
use Assinafy\SDK\Resources\TemplateResource;
use Assinafy\SDK\Resources\WebhookResource;
use Assinafy\SDK\Support\WebhookVerifier;
use Assinafy\SDK\Tests\Unit\Support\FakeHttpClient;
use PHPUnit\Framework\TestCase;

final class AssinafyClientTest extends TestCase
{
    public function testFactoryAndAccessorsReturnSingletons(): void
    {
        $client = new AssinafyClient(new Configuration('k', 'a'), new FakeHttpClient());

        $this->assertSame($client->documents(), $client->documents());
        $this->assertInstanceOf(DocumentResource::class, $client->documents());
        $this->assertInstanceOf(SignerResource::class, $client->signers());
        $this->assertInstanceOf(AssignmentResource::class, $client->assignments());
        $this->assertInstanceOf(TemplateResource::class, $client->templates());
        $this->assertInstanceOf(WebhookResource::class, $client->webhooks());
        $this->assertInstanceOf(AuthResource::class, $client->auth());
        $this->assertInstanceOf(SignerSessionResource::class, $client->signerSession());
        $this->assertInstanceOf(WebhookVerifier::class, $client->webhookVerifier());
    }

    public function testUploadAndRequestSignaturesEndToEnd(): void
    {
        $http = new FakeHttpClient();
        $client = new AssinafyClient(new Configuration('k', 'a'), $http);

        $pdf = tempnam(sys_get_temp_dir(), 'asn') . '.pdf';
        file_put_contents($pdf, "%PDF-1.4\n%%EOF\n");

        // 1) upload returns id + initial status
        $http->queueJson(201, ['id' => 'doc1', 'status' => 'uploaded']);
        // 2) waitUntilReady polls
        $http->queueJson(200, ['id' => 'doc1', 'status' => DocumentResource::STATUS_METADATA_READY]);
        // 3) signer 1: findByEmail returns no match
        $http->queueJson(200, []);
        // 4) signer 1: create
        $http->queueJson(201, ['id' => 's1', 'full_name' => 'Alice', 'email' => 'a@b.com']);
        // 5) signer 2 is already an ID string — no API call. Then assignment create:
        $http->queueJson(201, [
            'id' => 'a1',
            'method' => 'virtual',
            'signers' => [['id' => 's1'], ['id' => 's2']],
        ]);

        $result = $client->uploadAndRequestSignatures(
            $pdf,
            [
                ['full_name' => 'Alice', 'email' => 'a@b.com'],
                's2',
            ],
            'Please sign',
            '2026-12-31T23:59:00Z'
        );

        @unlink($pdf);

        $this->assertSame(['s1', 's2'], $result['signer_ids']);
        $this->assertSame('doc1', $result['document']['id']);

        $lastCall = $http->calls[4];
        $this->assertSame('POST', $lastCall['method']);
        $this->assertSame('documents/doc1/assignments', $lastCall['uri']);
        $this->assertSame([
            'method' => 'virtual',
            'signers' => [['id' => 's1'], ['id' => 's2']],
            'message' => 'Please sign',
            'expires_at' => '2026-12-31T23:59:00Z',
        ], $lastCall['body']);
    }
}
