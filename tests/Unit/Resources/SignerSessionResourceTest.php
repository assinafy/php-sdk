<?php

declare(strict_types=1);

namespace Assinafy\SDK\Tests\Unit\Resources;

use Assinafy\SDK\Configuration;
use Assinafy\SDK\Exceptions\ValidationException;
use Assinafy\SDK\Resources\SignerSessionResource;
use Assinafy\SDK\Tests\Unit\Support\FakeHttpClient;
use PHPUnit\Framework\TestCase;

final class SignerSessionResourceTest extends TestCase
{
    private FakeHttpClient $http;
    private SignerSessionResource $session;

    protected function setUp(): void
    {
        $this->http = new FakeHttpClient();
        $this->session = new SignerSessionResource($this->http, new Configuration('k', 'a'));
    }

    public function testSelfSendsAccessCodeAsQuery(): void
    {
        $this->http->queueJson(200, ['id' => 's1']);
        $this->session->self('CODE');

        $call = $this->http->lastCall();
        $this->assertSame('GET', $call['method']);
        $this->assertSame('signers/self', $call['uri']);
        $this->assertSame(['signer-access-code' => 'CODE'], $call['query']);
    }

    public function testAcceptTerms(): void
    {
        $this->http->queueJson(200, ['has_accepted_terms' => true]);
        $this->session->acceptTerms('CODE');

        $call = $this->http->lastCall();
        $this->assertSame('PUT', $call['method']);
        $this->assertSame('signers/accept-terms', $call['uri']);
        $this->assertSame(['signer-access-code' => 'CODE'], $call['body']);
    }

    public function testVerifyCode(): void
    {
        $this->http->queueJson(200, ['message' => 'ok']);
        $this->session->verifyCode('CODE', '123456');

        $call = $this->http->lastCall();
        $this->assertSame('verify', $call['uri']);
        $this->assertSame([
            'signer-access-code' => 'CODE',
            'verification-code' => '123456',
        ], $call['body']);
    }

    public function testConfirmDataKeepsAccessCodeOnQuery(): void
    {
        $this->http->queueJson(200, []);
        $this->session->confirmData('doc1', 'CODE WITH SPACE', [
            'email' => 'a@b.com',
            'has_accepted_terms' => true,
        ]);

        $call = $this->http->lastCall();
        $this->assertSame('PUT', $call['method']);
        $this->assertSame('documents/doc1/signers/confirm-data', $call['uri']);
        $this->assertSame(['signer-access-code' => 'CODE WITH SPACE'], $call['query']);
        $this->assertSame(['email' => 'a@b.com', 'has_accepted_terms' => true], $call['body']);
    }

    public function testUploadSignatureSendsRawBinary(): void
    {
        $this->http->queueJson(201, []);
        $this->session->uploadSignature('CODE', SignerSessionResource::TYPE_SIGNATURE, "\x89PNG\r\n", 'image/png');

        $call = $this->http->lastCall();
        $this->assertSame('POST_RAW', $call['method']);
        $this->assertSame('signature', $call['uri']);
        $this->assertSame('image/png', $call['content_type']);
        $this->assertSame("\x89PNG\r\n", $call['body']);
        $this->assertSame(['type' => 'signature', 'signer-access-code' => 'CODE'], $call['query']);
    }

    public function testUploadSignatureRejectsBadType(): void
    {
        $this->expectException(ValidationException::class);
        $this->session->uploadSignature('CODE', 'stamp', 'x');
    }

    public function testUploadSignatureRejectsBadMime(): void
    {
        $this->expectException(ValidationException::class);
        $this->session->uploadSignature('CODE', SignerSessionResource::TYPE_SIGNATURE, 'x', 'image/gif');
    }

    public function testDownloadSignature(): void
    {
        $this->http->queueRaw(200, 'PNGBYTES');
        $body = $this->session->downloadSignature('CODE', SignerSessionResource::TYPE_INITIAL);

        $this->assertSame('PNGBYTES', $body);
        $call = $this->http->lastCall();
        $this->assertSame('signature/initial', $call['uri']);
        $this->assertSame(['signer-access-code' => 'CODE'], $call['query']);
    }

    public function testCurrentDocumentHitsSignEndpoint(): void
    {
        $this->http->queueJson(200, ['id' => 'doc1']);
        $this->session->currentDocument('CODE');

        $call = $this->http->lastCall();
        $this->assertSame('GET', $call['method']);
        $this->assertSame('sign', $call['uri']);
        $this->assertSame(['signer-access-code' => 'CODE'], $call['query']);
    }

    public function testSignPostsFieldArrayWithAccessCodeOnQuery(): void
    {
        $this->http->queueJson(200, []);

        $fields = [['itemId' => 'i1', 'fieldId' => 'f1', 'pageId' => 'p1', 'value' => 'Signed']];
        $this->session->sign('doc1', 'a1', 'CODE', $fields);

        $call = $this->http->lastCall();
        $this->assertSame('POST', $call['method']);
        $this->assertSame('documents/doc1/assignments/a1', $call['uri']);
        $this->assertSame($fields, $call['body']);
        $this->assertSame(['signer-access-code' => 'CODE'], $call['query']);
    }

    public function testSignRejectsEmptyFields(): void
    {
        $this->expectException(ValidationException::class);
        $this->session->sign('doc1', 'a1', 'CODE', []);
    }

    public function testDeclineSendsReasonAndAccessCode(): void
    {
        $this->http->queueJson(200, []);
        $this->session->decline('doc1', 'a1', 'CODE', 'No thanks');

        $call = $this->http->lastCall();
        $this->assertSame('PUT', $call['method']);
        $this->assertSame('documents/doc1/assignments/a1/reject', $call['uri']);
        $this->assertSame(['decline_reason' => 'No thanks'], $call['body']);
        $this->assertSame(['signer-access-code' => 'CODE'], $call['query']);
    }

    public function testDeclineRejectsEmptyReason(): void
    {
        $this->expectException(ValidationException::class);
        $this->session->decline('doc1', 'a1', 'CODE', '');
    }
}
