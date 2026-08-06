<?php

declare(strict_types=1);

namespace Assinafy\SDK\Tests\Unit;

use Assinafy\SDK\AssinafyClient;
use Assinafy\SDK\Configuration;
use Assinafy\SDK\Resources\AssignmentResource;
use Assinafy\SDK\Resources\AuthResource;
use Assinafy\SDK\Resources\DocumentResource;
use Assinafy\SDK\Resources\FieldResource;
use Assinafy\SDK\Resources\SignerDocumentResource;
use Assinafy\SDK\Resources\AccountResource;
use Assinafy\SDK\Resources\SignerResource;
use Assinafy\SDK\Resources\SignerSessionResource;
use Assinafy\SDK\Resources\TagResource;
use Assinafy\SDK\Resources\TemplateResource;
use Assinafy\SDK\Resources\UserResource;
use Assinafy\SDK\Resources\WebhookResource;
use Assinafy\SDK\Support\WebhookEventParser;
use Assinafy\SDK\Tests\Unit\Support\FakeHttpClient;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;

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
        $this->assertInstanceOf(TagResource::class, $client->tags());
        $this->assertInstanceOf(FieldResource::class, $client->fields());
        $this->assertInstanceOf(WebhookResource::class, $client->webhooks());
        $this->assertInstanceOf(AuthResource::class, $client->auth());
        $this->assertInstanceOf(SignerSessionResource::class, $client->signerSession());
        $this->assertSame($client->signerDocuments(), $client->signerDocuments());
        $this->assertInstanceOf(SignerDocumentResource::class, $client->signerDocuments());
        $this->assertSame($client->accounts(), $client->accounts());
        $this->assertInstanceOf(AccountResource::class, $client->accounts());
        $this->assertSame($client->users(), $client->users());
        $this->assertInstanceOf(UserResource::class, $client->users());
        $this->assertSame($client->webhookEvents(), $client->webhookEvents());
        $this->assertInstanceOf(WebhookEventParser::class, $client->webhookEvents());
    }

    public function testForAuthBuildsPublicClient(): void
    {
        $client = AssinafyClient::forAuth();

        $this->assertTrue($client->getConfig()->isPublic());
        $this->assertSame(Configuration::DEFAULT_BASE_URL, $client->getConfig()->getBaseUrl());
        $this->assertInstanceOf(AuthResource::class, $client->auth());
    }

    public function testForAuthAcceptsCustomBaseUrl(): void
    {
        $client = AssinafyClient::forAuth(Configuration::SANDBOX_BASE_URL);

        $this->assertSame(Configuration::SANDBOX_BASE_URL, $client->getConfig()->getBaseUrl());
    }

    public function testForBearerBuildsAnAccountScopedOAuthClient(): void
    {
        $client = AssinafyClient::forBearer('token', 'account', Configuration::SANDBOX_BASE_URL);

        $this->assertTrue($client->getConfig()->isBearerAuthenticated());
        $this->assertSame('Bearer token', $client->getConfig()->getHeaders()['Authorization']);
        $this->assertSame('account', $client->getConfig()->getAccountId());
        $this->assertSame(Configuration::SANDBOX_BASE_URL, $client->getConfig()->getBaseUrl());
    }

    public function testAccountScopedResourceFailsOnPublicClient(): void
    {
        $client = new AssinafyClient(Configuration::forPublic(), new FakeHttpClient());

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/Account-scoped endpoints require/');

        $client->signers()->list();
    }

    public function testUploadAndRequestSignaturesEndToEnd(): void
    {
        $http = new FakeHttpClient();
        $client = new AssinafyClient(new Configuration('k', 'a'), $http);

        $temporaryPath = tempnam(sys_get_temp_dir(), 'asn');
        $this->assertIsString($temporaryPath);
        $pdf = $temporaryPath . '.pdf';
        rename($temporaryPath, $pdf);
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

        try {
            $result = $client->uploadAndRequestSignatures(
                $pdf,
                [
                    ['full_name' => 'Alice', 'email' => 'a@b.com'],
                    's2',
                ],
                'Please sign',
                '2026-12-31T23:59:00Z'
            );
        } finally {
            @unlink($pdf);
        }

        $this->assertSame(['s1', 's2'], $result['signer_ids']);
        $this->assertSame('doc1', $result['document']['id']);
        $this->assertSame(DocumentResource::STATUS_METADATA_READY, $result['document']['status']);

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

    public function testUploadWorkflowPreservesPerSignerAssignmentOptions(): void
    {
        $http = new FakeHttpClient();
        $client = new AssinafyClient(new Configuration('k', 'a'), $http);
        $pdf = tempnam(sys_get_temp_dir(), 'asn');
        $this->assertIsString($pdf);
        $pdfPath = $pdf . '.pdf';
        rename($pdf, $pdfPath);
        file_put_contents($pdfPath, "%PDF-1.4\n%%EOF\n");

        $http->queueJson(200, ['id' => 'doc1', 'status' => 'uploaded']);
        $http->queueJson(200, ['id' => 'doc1', 'status' => DocumentResource::STATUS_METADATA_READY]);
        $http->queueJson(200, ['id' => 'assignment1']);

        try {
            $client->uploadAndRequestSignatures($pdfPath, [[
                'id' => 'signer1',
                'verification_method' => AssignmentResource::VERIFICATION_EMAIL,
                'notification_methods' => [
                    AssignmentResource::NOTIFICATION_EMAIL,
                    AssignmentResource::NOTIFICATION_WHATSAPP,
                ],
                'step' => 1,
            ]]);
        } finally {
            @unlink($pdfPath);
        }

        $this->assertSame([[
            'id' => 'signer1',
            'verification_method' => AssignmentResource::VERIFICATION_EMAIL,
            'notification_methods' => [
                AssignmentResource::NOTIFICATION_EMAIL,
                AssignmentResource::NOTIFICATION_WHATSAPP,
            ],
            'step' => 1,
        ]], $http->lastCall()['body']['signers']);
    }

    public function testUploadWorkflowValidatesAllSignersBeforeUploading(): void
    {
        $http = new FakeHttpClient();
        $client = new AssinafyClient(new Configuration('k', 'a'), $http);

        try {
            $client->uploadAndRequestSignatures('/not-created.pdf', [
                ['full_name' => 'Valid', 'email' => 'valid@example.test'],
                ['full_name' => 'Invalid', 'email' => 'not-an-email'],
            ]);
            $this->fail('Malformed signer input must fail before upload');
        } catch (\InvalidArgumentException $e) {
            $this->assertSame('Signer email must be valid', $e->getMessage());
        }

        $this->assertSame([], $http->calls);
    }

    public function testUploadWorkflowRejectsDuplicateSignersBeforeUploading(): void
    {
        $http = new FakeHttpClient();
        $client = new AssinafyClient(new Configuration('k', 'a'), $http);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Duplicate signer entries');

        try {
            $client->uploadAndRequestSignatures('/not-created.pdf', ['same-id', 'same-id']);
        } finally {
            $this->assertSame([], $http->calls);
        }
    }

    public function testUploadWorkflowPrevalidatesAllAssignmentAndContactOptions(): void
    {
        $cases = [
            [['full_name' => 'Name', 'email' => 'valid@example.test', 'verification_method' => 'SMS']],
            [[
                'full_name' => 'Name',
                'email' => 'valid@example.test',
                'notification_methods' => 'Email',
            ]],
            [[
                'full_name' => 'Name',
                'email' => 'valid@example.test',
                'verification_method' => AssignmentResource::VERIFICATION_EMAIL,
                'notification_methods' => [AssignmentResource::NOTIFICATION_WHATSAPP],
            ]],
            [['full_name' => 'Name', 'email' => 'valid@example.test', 'step' => 0]],
            [[
                'full_name' => 'Name',
                'whatsapp_phone_number' => '123',
                'verification_method' => AssignmentResource::VERIFICATION_WHATSAPP,
            ]],
            [['full_name' => 'Name']],
        ];

        foreach ($cases as $signers) {
            $http = new FakeHttpClient();
            $client = new AssinafyClient(new Configuration('k', 'a'), $http);
            try {
                $client->uploadAndRequestSignatures('/not-created.pdf', $signers);
                $this->fail('Invalid workflow input must fail before upload');
            } catch (\InvalidArgumentException $e) {
                $this->assertNotSame('', $e->getMessage());
            }
            $this->assertSame([], $http->calls);
        }
    }

    public function testUploadWorkflowPrevalidatesExpirationBeforeUploading(): void
    {
        $http = new FakeHttpClient();
        $client = new AssinafyClient(new Configuration('k', 'a'), $http);

        $this->expectException(\InvalidArgumentException::class);
        try {
            $client->uploadAndRequestSignatures(
                '/not-created.pdf',
                ['existing-signer'],
                expiresAt: 'not-a-date'
            );
        } finally {
            $this->assertSame([], $http->calls);
        }
    }

    public function testUploadWorkflowRejectsInvalidCalendarDateBeforeUploading(): void
    {
        $http = new FakeHttpClient();
        $client = new AssinafyClient(new Configuration('k', 'a'), $http);

        $this->expectException(\InvalidArgumentException::class);
        try {
            $client->uploadAndRequestSignatures(
                '/not-created.pdf',
                ['existing-signer'],
                expiresAt: '2026-02-30T12:00:00Z'
            );
        } finally {
            $this->assertSame([], $http->calls);
        }
    }

    public function testUploadWorkflowRejectsHugeStepAndInvalidOffsetBeforeUploading(): void
    {
        $cases = [
            [[['id' => 's1', 'step' => PHP_INT_MAX]], null],
            [['s1'], '2026-08-05T12:00:00+24:00'],
        ];

        foreach ($cases as [$signers, $expiresAt]) {
            $http = new FakeHttpClient();
            $client = new AssinafyClient(new Configuration('k', 'a'), $http);
            try {
                $client->uploadAndRequestSignatures(
                    '/not-created.pdf',
                    $signers,
                    expiresAt: $expiresAt
                );
                $this->fail('Invalid workflow input must fail before upload');
            } catch (\InvalidArgumentException $e) {
                $this->assertNotSame('', $e->getMessage());
            }
            $this->assertSame([], $http->calls);
        }
    }

    public function testSetLoggerPropagatesToAlreadyCreatedResources(): void
    {
        $first = new class () extends AbstractLogger {
            /** @var list<string> */
            public array $messages = [];

            public function log($level, $message, array $context = []): void
            {
                $this->messages[] = (string) $message;
            }
        };
        $second = new class () extends AbstractLogger {
            /** @var list<string> */
            public array $messages = [];

            public function log($level, $message, array $context = []): void
            {
                $this->messages[] = (string) $message;
            }
        };
        $http = new FakeHttpClient();
        $http->queueJson(200, []);
        $client = new AssinafyClient(new Configuration('k', 'a'), $http, $first);
        $accounts = $client->accounts();

        $client->setLogger($second);
        $accounts->delete();

        $this->assertSame([], $first->messages);
        $this->assertContains('Deleting account', $second->messages);
        $this->assertSame($second, $client->getLogger());
    }
}
