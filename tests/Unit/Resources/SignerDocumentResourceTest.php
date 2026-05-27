<?php

declare(strict_types=1);

namespace Assinafy\SDK\Tests\Unit\Resources;

use Assinafy\SDK\Configuration;
use Assinafy\SDK\Exceptions\ValidationException;
use Assinafy\SDK\Resources\DocumentResource;
use Assinafy\SDK\Resources\SignerDocumentResource;
use Assinafy\SDK\Tests\Unit\Support\FakeHttpClient;
use PHPUnit\Framework\TestCase;

final class SignerDocumentResourceTest extends TestCase
{
    private FakeHttpClient $http;
    private SignerDocumentResource $docs;

    protected function setUp(): void
    {
        $this->http = new FakeHttpClient();
        $this->docs = new SignerDocumentResource($this->http, new Configuration('key', 'acc'));
    }

    public function testCurrentSendsAccessCode(): void
    {
        $this->http->queueJson(200, ['id' => 'd1']);

        $this->docs->current('s1', 'CODE');

        $call = $this->http->lastCall();
        $this->assertSame('GET', $call['method']);
        $this->assertSame('signers/s1/document', $call['uri']);
        $this->assertSame(['signer-access-code' => 'CODE'], $call['query']);
    }

    public function testListMergesFilters(): void
    {
        $this->http->queueJson(200, []);

        $this->docs->list('s1', 'CODE', ['status' => 'pending_signature']);

        $this->assertSame(
            ['signer-access-code' => 'CODE', 'status' => 'pending_signature'],
            $this->http->lastCall()['query']
        );
    }

    public function testSignMultipleBodyAndQuery(): void
    {
        $this->http->queueJson(200, []);

        $this->docs->signMultiple('CODE', ['d1', 'd2']);

        $call = $this->http->lastCall();
        $this->assertSame('PUT', $call['method']);
        $this->assertSame('signers/documents/sign-multiple', $call['uri']);
        $this->assertSame(['document_ids' => ['d1', 'd2']], $call['body']);
        $this->assertSame(['signer-access-code' => 'CODE'], $call['query']);
    }

    public function testSignMultipleRejectsEmpty(): void
    {
        $this->expectException(ValidationException::class);
        $this->docs->signMultiple('CODE', []);
    }

    public function testDeclineMultipleSendsReason(): void
    {
        $this->http->queueJson(200, []);

        $this->docs->declineMultiple('CODE', ['d1'], 'Bad terms');

        $call = $this->http->lastCall();
        $this->assertSame('signers/documents/decline-multiple', $call['uri']);
        $this->assertSame(['document_ids' => ['d1'], 'decline_reason' => 'Bad terms'], $call['body']);
    }

    public function testDeclineMultipleRejectsEmptyReason(): void
    {
        $this->expectException(ValidationException::class);
        $this->docs->declineMultiple('CODE', ['d1'], '');
    }

    public function testDownloadReturnsBodyAndValidatesArtifact(): void
    {
        $this->http->queueRaw(200, '%PDF-1.4 binary');

        $bytes = $this->docs->download('s1', 'd1', 'CODE', DocumentResource::ARTIFACT_CERTIFICATED);

        $call = $this->http->lastCall();
        $this->assertSame('signers/s1/documents/d1/download/certificated', $call['uri']);
        $this->assertSame(['signer-access-code' => 'CODE'], $call['query']);
        $this->assertStringStartsWith('%PDF', $bytes);
    }

    public function testDownloadRejectsUnknownArtifact(): void
    {
        $this->expectException(ValidationException::class);
        $this->docs->download('s1', 'd1', 'CODE', 'nope');
    }
}
