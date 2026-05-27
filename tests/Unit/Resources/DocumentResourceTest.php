<?php

declare(strict_types=1);

namespace Assinafy\SDK\Tests\Unit\Resources;

use Assinafy\SDK\Configuration;
use Assinafy\SDK\Exceptions\ValidationException;
use Assinafy\SDK\Resources\DocumentResource;
use Assinafy\SDK\Tests\Unit\Support\FakeHttpClient;
use PHPUnit\Framework\TestCase;

final class DocumentResourceTest extends TestCase
{
    private FakeHttpClient $http;
    private DocumentResource $documents;

    protected function setUp(): void
    {
        $this->http = new FakeHttpClient();
        $config = new Configuration('key', 'acc');
        $this->documents = new DocumentResource($this->http, $config);
    }

    public function testUploadHitsAccountScopedPathWithFileOnly(): void
    {
        $pdf = $this->writeFixturePdf();

        $this->http->queueJson(201, ['id' => 'doc1', 'status' => 'uploaded']);

        $result = $this->documents->upload($pdf);

        $call = $this->http->lastCall();
        $this->assertSame('UPLOAD', $call['method']);
        $this->assertSame('accounts/acc/documents', $call['uri']);
        $this->assertSame($pdf, $call['file_path']);
        $this->assertSame([], $call['body'], 'No extra multipart fields beyond `file` are sent');
        $this->assertSame('doc1', $result['id']);
    }

    public function testUploadRejectsNonPdf(): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'asn') . '.txt';
        file_put_contents($tmp, 'hello');

        $this->expectException(ValidationException::class);
        try {
            $this->documents->upload($tmp);
        } finally {
            @unlink($tmp);
        }
    }

    public function testUploadRejectsMissingFile(): void
    {
        $this->expectException(ValidationException::class);
        $this->documents->upload('/no/such/file.pdf');
    }

    public function testListUsesHyphenatedPerPage(): void
    {
        $this->http->queueJson(200, []);

        $this->documents->list(2, 50, ['status' => 'pending_signature']);

        $call = $this->http->lastCall();
        $this->assertSame('GET', $call['method']);
        $this->assertSame('accounts/acc/documents', $call['uri']);
        $this->assertSame(
            ['page' => 2, 'per-page' => 50, 'status' => 'pending_signature'],
            $call['query']
        );
    }

    public function testGetUnwrapsDataEnvelope(): void
    {
        $this->http->queueJson(200, ['id' => 'doc1', 'status' => 'metadata_ready']);

        $doc = $this->documents->get('doc1');

        $this->assertSame('documents/doc1', $this->http->lastCall()['uri']);
        $this->assertSame('metadata_ready', $doc['status']);
    }

    public function testDownloadHitsArtifactPath(): void
    {
        $this->http->queueRaw(200, 'PDFDATA');

        $body = $this->documents->download('doc1', DocumentResource::ARTIFACT_ORIGINAL);

        $this->assertSame('PDFDATA', $body);
        $this->assertSame('documents/doc1/download/original', $this->http->lastCall()['uri']);
    }

    public function testDownloadRejectsUnknownArtifact(): void
    {
        $this->expectException(ValidationException::class);
        $this->documents->download('doc1', 'frobnicated');
    }

    public function testThumbnailAndPageDownloadPaths(): void
    {
        $this->http->queueRaw(200, 'JPGDATA');
        $this->documents->downloadThumbnail('doc1');
        $this->assertSame('documents/doc1/thumbnail', $this->http->lastCall()['uri']);

        $this->http->queueRaw(200, 'JPGDATA');
        $this->documents->downloadPage('doc1', 'p1');
        $this->assertSame('documents/doc1/pages/p1/download', $this->http->lastCall()['uri']);
    }

    public function testActivitiesAndStatusesAndVerify(): void
    {
        $this->http->queueJson(200, [['event' => 'created']]);
        $this->documents->activities('doc1');
        $this->assertSame('documents/doc1/activities', $this->http->lastCall()['uri']);

        $this->http->queueJson(200, [['code' => 'uploaded', 'deletable' => false]]);
        $this->documents->statuses();
        $this->assertSame('documents/statuses', $this->http->lastCall()['uri']);

        $this->http->queueJson(200, ['is_valid' => true]);
        $this->documents->verify('HASH');
        $this->assertSame('documents/HASH/verify', $this->http->lastCall()['uri']);
    }

    public function testPublicInfoAndSendToken(): void
    {
        $this->http->queueJson(200, ['id' => 'doc1', 'name' => 'x.pdf']);
        $this->documents->publicInfo('doc1');
        $this->assertSame('public/documents/doc1', $this->http->lastCall()['uri']);

        $this->http->queueJson(200, ['channel' => 'email']);
        $this->documents->sendToken('doc1', 'a@b.com');

        $call = $this->http->lastCall();
        $this->assertSame('PUT', $call['method']);
        $this->assertSame('public/documents/doc1/send-token', $call['uri']);
        $this->assertSame(['recipient' => 'a@b.com', 'channel' => 'email'], $call['body']);
    }

    public function testSendTokenRejectsUnknownChannel(): void
    {
        $this->expectException(ValidationException::class);
        $this->documents->sendToken('doc1', 'a@b.com', 'whatsapp');
    }

    public function testDelete(): void
    {
        $this->http->queueJson(200, []);
        $this->documents->delete('doc1');

        $call = $this->http->lastCall();
        $this->assertSame('DELETE', $call['method']);
        $this->assertSame('documents/doc1', $call['uri']);
    }

    public function testCreateFromTemplateAndEstimate(): void
    {
        $this->http->queueJson(201, ['id' => 'newdoc']);
        $this->documents->createFromTemplate('tmpl1', [['role_id' => 'r', 'id' => 's']], ['name' => 'X']);

        $call = $this->http->lastCall();
        $this->assertSame('POST', $call['method']);
        $this->assertSame('accounts/acc/templates/tmpl1/documents', $call['uri']);
        $this->assertSame([
            'signers' => [['role_id' => 'r', 'id' => 's']],
            'name' => 'X',
        ], $call['body']);

        $this->http->queueJson(200, ['total_credits' => 1]);
        $this->documents->estimateCostFromTemplate('tmpl1', [['role_id' => 'r']]);
        $this->assertSame('accounts/acc/templates/tmpl1/documents/estimate-cost', $this->http->lastCall()['uri']);
    }

    public function testIsFullySignedAndProgress(): void
    {
        $this->http->queueJson(200, [
            'id' => 'doc1',
            'status' => DocumentResource::STATUS_CERTIFICATED,
            'assignment' => null,
        ]);
        $this->assertTrue($this->documents->isFullySigned('doc1'));

        $this->http->queueJson(200, [
            'id' => 'doc1',
            'status' => DocumentResource::STATUS_PENDING_SIGNATURE,
            'assignment' => [
                'signers' => [
                    ['id' => 's1', 'full_name' => 'A'],
                    ['id' => 's2', 'full_name' => 'B'],
                ],
                'items' => [
                    ['signer' => ['id' => 's1'], 'completed' => true],
                    ['signer' => ['id' => 's2'], 'completed' => false],
                ],
            ],
        ]);
        $progress = $this->documents->getSigningProgress('doc1');
        $this->assertSame(1, $progress['signed']);
        $this->assertSame(2, $progress['total']);
        $this->assertSame(1, $progress['pending']);
        $this->assertSame(50.0, $progress['percentage']);
    }

    public function testWaitUntilReadyReturnsWhenReady(): void
    {
        $this->http->queueJson(200, ['id' => 'doc1', 'status' => DocumentResource::STATUS_METADATA_READY]);

        $result = $this->documents->waitUntilReady('doc1', 5, 1);

        $this->assertSame(DocumentResource::STATUS_METADATA_READY, $result['status']);
    }

    public function testWaitUntilReadyThrowsOnFailure(): void
    {
        $this->http->queueJson(200, ['id' => 'doc1', 'status' => DocumentResource::STATUS_FAILED]);

        $this->expectException(\RuntimeException::class);
        $this->documents->waitUntilReady('doc1', 5, 1);
    }

    public function testDocumentTagsLifecyclePaths(): void
    {
        $this->http->queueJson(200, [['id' => 't1', 'name' => 'Contracts']]);
        $this->documents->listTags('doc1');
        $list = $this->http->lastCall();
        $this->assertSame('GET', $list['method']);
        $this->assertSame('accounts/acc/documents/doc1/tags', $list['uri']);

        $this->http->queueJson(200, []);
        $this->documents->appendTags('doc1', ['Urgent']);
        $append = $this->http->lastCall();
        $this->assertSame('POST', $append['method']);
        $this->assertSame('accounts/acc/documents/doc1/tags', $append['uri']);
        $this->assertSame(['tags' => ['Urgent']], $append['body']);

        $this->http->queueJson(200, []);
        $this->documents->replaceTags('doc1', ['A', 'B']);
        $replace = $this->http->lastCall();
        $this->assertSame('PUT', $replace['method']);
        $this->assertSame(['tags' => ['A', 'B']], $replace['body']);

        $this->http->queueJson(200, ['detached' => true]);
        $this->documents->detachTag('doc1', 't1');
        $detach = $this->http->lastCall();
        $this->assertSame('DELETE', $detach['method']);
        $this->assertSame('accounts/acc/documents/doc1/tags/t1', $detach['uri']);
    }

    public function testAppendTagsRejectsEmpty(): void
    {
        $this->expectException(ValidationException::class);
        $this->documents->appendTags('doc1', []);
    }

    public function testReplaceTagsAllowsEmptyToDetachAll(): void
    {
        $this->http->queueJson(200, []);
        $this->documents->replaceTags('doc1', []);
        $this->assertSame(['tags' => []], $this->http->lastCall()['body']);
    }

    private function writeFixturePdf(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'asn') . '.pdf';
        file_put_contents($path, "%PDF-1.4\n%%EOF\n");

        return $path;
    }
}
