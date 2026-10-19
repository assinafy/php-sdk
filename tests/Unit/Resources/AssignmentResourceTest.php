<?php

declare(strict_types=1);

namespace Assinafy\SDK\Tests\Unit\Resources;

use Assinafy\SDK\Configuration;
use Assinafy\SDK\Exceptions\ValidationException;
use Assinafy\SDK\Resources\AssignmentResource;
use Assinafy\SDK\Tests\Unit\Support\FakeHttpClient;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class AssignmentResourceTest extends TestCase
{
    private FakeHttpClient $http;
    private AssignmentResource $assignments;

    protected function setUp(): void
    {
        $this->http = new FakeHttpClient();
        $config = new Configuration('key', 'acc');
        $this->assignments = new AssignmentResource($this->http, $config);
    }

    public function testCreateNormalizesStringSignersToObjects(): void
    {
        $this->http->queueJson(201, ['id' => 'a1']);

        $this->assignments->create('doc1', ['s1', 's2'], AssignmentResource::METHOD_VIRTUAL, [
            'message' => 'Please sign',
            'expires_at' => '2026-12-31T23:59:00Z',
        ]);

        $call = $this->http->lastCall();
        $this->assertSame('POST', $call['method']);
        $this->assertSame('documents/doc1/assignments', $call['uri']);
        $this->assertSame([
            'method' => 'virtual',
            'signers' => [['id' => 's1'], ['id' => 's2']],
            'message' => 'Please sign',
            'expires_at' => '2026-12-31T23:59:00Z',
        ], $call['body']);
    }

    public function testCreatePassesVerificationAndNotificationFields(): void
    {
        $this->http->queueJson(201, ['id' => 'a1']);

        $this->assignments->create('doc1', [
            [
                'id' => 's1',
                'verification_method' => AssignmentResource::VERIFICATION_WHATSAPP,
                'notification_methods' => ['Whatsapp'],
            ],
        ]);

        $call = $this->http->lastCall();
        $this->assertSame([
            'method' => 'virtual',
            'signers' => [[
                'id' => 's1',
                'verification_method' => 'Whatsapp',
                'notification_methods' => ['Whatsapp'],
            ]],
        ], $call['body']);
    }

    public function testCreateReindexesNotificationAndCopyReceiverLists(): void
    {
        $this->http->queueJson(201, ['id' => 'a1']);

        $this->assignments->create('doc1', [[
            'id' => 's1',
            'verification_method' => AssignmentResource::VERIFICATION_EMAIL,
            'notification_methods' => [
                3 => AssignmentResource::NOTIFICATION_EMAIL,
            ],
        ]], options: [
            'copy_receivers' => [5 => 'copy-1'],
        ]);

        $body = $this->http->lastCall()['body'];
        $this->assertSame(
            [AssignmentResource::NOTIFICATION_EMAIL],
            $body['signers'][0]['notification_methods']
        );
        $this->assertSame(['copy-1'], $body['copy_receivers']);
    }

    public function testCreateRejectsMultipleNotificationMethods(): void
    {
        $this->expectException(ValidationException::class);
        $this->assignments->create('doc1', [[
            'id' => 's1',
            'notification_methods' => [
                AssignmentResource::NOTIFICATION_EMAIL,
                AssignmentResource::NOTIFICATION_WHATSAPP,
            ],
        ]]);
    }

    public function testEstimateCostAcceptsDigitalCertificateVerification(): void
    {
        $this->http->queueJson(200, ['total_credits' => 2]);

        $this->assignments->estimateCost('doc1', [[
            'verification_method' => AssignmentResource::VERIFICATION_DIGITAL_CERTIFICATE,
            'notification_methods' => [AssignmentResource::NOTIFICATION_EMAIL],
        ]]);

        $this->assertSame(
            AssignmentResource::VERIFICATION_DIGITAL_CERTIFICATE,
            $this->http->lastCall()['body']['signers'][0]['verification_method']
        );
    }

    public function testCollectFlowsRejectDigitalCertificateWithStableCode(): void
    {
        foreach (['create', 'estimateCost'] as $operation) {
            $http = new FakeHttpClient();
            $assignments = new AssignmentResource($http, new Configuration('key', 'acc'));

            try {
                $assignments->{$operation}(
                    'doc1',
                    [[
                        'id' => 's1',
                        'verification_method' => AssignmentResource::VERIFICATION_DIGITAL_CERTIFICATE,
                    ]],
                    AssignmentResource::METHOD_COLLECT,
                    ['entries' => [['page_id' => 'page1', 'fields' => []]]]
                );
                $this->fail('Expected a collect DigitalCertificate signer to be rejected');
            } catch (ValidationException $exception) {
                $this->assertSame(
                    AssignmentResource::ERROR_DIGITAL_CERTIFICATE_REQUIRES_VIRTUAL,
                    $exception->getErrors()['code'] ?? null
                );
                $this->assertSame([], $http->calls);
            }
        }
    }

    public function testDigitalCertificateSignerMustBeAloneInStep(): void
    {
        $this->expectException(ValidationException::class);
        $this->assignments->create('doc1', [
            ['id' => 's1', 'verification_method' => AssignmentResource::VERIFICATION_DIGITAL_CERTIFICATE],
            ['id' => 's2'],
        ]);
    }

    /** @return iterable<string, array{string, string, bool}> */
    public static function verificationNotificationPairs(): iterable
    {
        yield 'email and email' => ['Email', 'Email', true];
        yield 'email and whatsapp' => ['Email', 'Whatsapp', false];
        yield 'whatsapp and email' => ['Whatsapp', 'Email', false];
        yield 'whatsapp and whatsapp' => ['Whatsapp', 'Whatsapp', true];
        yield 'certificate and email' => ['DigitalCertificate', 'Email', true];
        yield 'certificate and whatsapp' => ['DigitalCertificate', 'Whatsapp', true];
        yield 'biometric and email' => ['IdDocumentFaceMatchBasic', 'Email', true];
        yield 'biometric and whatsapp' => ['IdDocumentFaceMatchBasic', 'Whatsapp', true];
    }

    #[DataProvider('verificationNotificationPairs')]
    public function testVerificationNotificationPairing(string $verification, string $notification, bool $valid): void
    {
        $signer = [
            'id' => 'signer-id',
            'verification_method' => $verification,
            'notification_methods' => [$notification],
        ];
        foreach (['create', 'estimateCost'] as $operation) {
            $http = new FakeHttpClient();
            $assignments = new AssignmentResource($http, new Configuration('key', 'acc'));
            if ($valid) {
                $http->queueJson(200, []);
                $assignments->{$operation}('document-id', [$signer]);
                $this->assertSame([$signer], $http->lastCall()['body']['signers']);
            } else {
                try {
                    $assignments->{$operation}('document-id', [$signer]);
                    $this->fail('Expected an incompatible verification/notification pair to be rejected');
                } catch (ValidationException) {
                    $this->assertSame([], $http->calls);
                }
            }
        }
    }

    public function testIdentityReviewRoutesEncodeIdsAndUnwrapResponses(): void
    {
        $review = ['id' => 'verification-id', 'status' => 'InReview', 'images' => ['document_front']];
        $this->http->queueJson(200, $review)->queueRaw(200, '%PDF-evidence')->queueJson(200, $review);
        $path = 'documents/doc%2Fid/assignments/assignment%20id/signers/signer%3Fid/identity-verification';

        $this->assertSame($review, $this->assignments->identityVerification('doc/id', 'assignment id', 'signer?id'));
        $this->assertSame($path, $this->http->lastCall()['uri']);
        $this->assertSame('%PDF-evidence', $this->assignments->identityVerificationImage(
            'doc/id',
            'assignment id',
            'signer?id',
            'document_pdf'
        ));
        $this->assertSame($path . '/images/document_pdf', $this->http->lastCall()['uri']);
        $this->assertSame($review, $this->assignments->reviewIdentityVerification(
            'doc/id',
            'assignment id',
            'signer?id',
            'Approved',
            'Checked by reviewer'
        ));
        $this->assertSame($path . '/review', $this->http->lastCall()['uri']);
        $this->assertSame(['decision' => 'Approved', 'note' => 'Checked by reviewer'], $this->http->lastCall()['body']);
    }

    public function testIdentityReviewCanRejectWithoutANote(): void
    {
        $this->http->queueJson(200, ['status' => 'Declined']);
        $this->assignments->reviewIdentityVerification('d', 'a', 's', 'Rejected');
        $this->assertSame(['decision' => 'Rejected'], $this->http->lastCall()['body']);
    }

    public function testIdentityReviewRejectsInvalidInputsBeforeTheRequest(): void
    {
        foreach ([['approved', null], ['Approved', str_repeat('é', 2001)]] as [$decision, $note]) {
            try {
                $this->assignments->reviewIdentityVerification('d', 'a', 's', $decision, $note);
                $this->fail('Expected an invalid review to be rejected');
            } catch (ValidationException) {
                $this->assertSame([], $this->http->calls);
            }
        }
        $this->expectException(ValidationException::class);
        $this->assignments->identityVerificationImage('d', 'a', 's', '../secret');
    }

    public function testCreateRejectsInvalidMethod(): void
    {
        $this->expectException(ValidationException::class);
        $this->assignments->create('doc1', ['s1'], 'magic');
    }

    public function testCreateRejectsEmptySigners(): void
    {
        $this->expectException(ValidationException::class);
        $this->assignments->create('doc1', []);
    }

    public function testCreateRejectsSignerWithoutId(): void
    {
        $this->expectException(ValidationException::class);
        $this->assignments->create('doc1', [['email' => 'x@example.com']]);
    }

    public function testCreateRejectsWhitespaceSignerAndCopyReceiverIds(): void
    {
        foreach ([['   '], ['s1', ['copy_receivers' => ['   ']]]] as $case) {
            try {
                $options = isset($case[1]) && is_array($case[1]) ? $case[1] : [];
                $this->assignments->create('doc1', [$case[0]], options: $options);
                $this->fail('Whitespace-only signer IDs must be rejected');
            } catch (ValidationException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testEstimateCost(): void
    {
        $this->http->queueJson(200, ['total_credits' => 1]);

        $this->assignments->estimateCost('doc1', ['s1'], AssignmentResource::METHOD_VIRTUAL);

        $this->assertSame('documents/doc1/assignments/estimate-cost', $this->http->lastCall()['uri']);
    }

    public function testResend(): void
    {
        $this->http->queueJson(200, ['is_sent' => true]);
        $this->assignments->resend('doc1', 'a1', 's1');

        $call = $this->http->lastCall();
        $this->assertSame('PUT', $call['method']);
        $this->assertSame('documents/doc1/assignments/a1/signers/s1/resend', $call['uri']);
    }

    public function testEstimateResendCost(): void
    {
        $estimate = [
            'total' => 0,
            'breakdown' => [
                ['code' => 'NotificationEmailResend', 'name' => 'Email Notification Resend', 'cost' => 0],
            ],
            'credit_balance' => 0,
            'has_sufficient_credits' => true,
        ];
        $this->http->queueJson(200, $estimate);
        $this->assertSame($estimate, $this->assignments->estimateResendCost('doc1', 'a1', 's1'));

        $this->assertSame(
            'documents/doc1/assignments/a1/signers/s1/estimate-resend-cost',
            $this->http->lastCall()['uri']
        );
    }

    public function testResetExpiration(): void
    {
        $this->http->queueJson(200, ['id' => 'a1']);
        $this->assignments->resetExpiration('doc1', 'a1', '2027-01-01T00:00:00Z');

        $call = $this->http->lastCall();
        $this->assertSame('PUT', $call['method']);
        $this->assertSame('documents/doc1/assignments/a1/reset-expiration', $call['uri']);
        $this->assertSame(['expires_at' => '2027-01-01T00:00:00Z'], $call['body']);
    }

    public function testResetExpirationAcceptsNullToRemoveTheDeadline(): void
    {
        $this->http->queueJson(200, ['id' => 'a1', 'expires_at' => null]);

        $result = $this->assignments->resetExpiration('doc1', 'a1', null);

        $call = $this->http->lastCall();
        $this->assertSame('PUT', $call['method']);
        $this->assertSame('documents/doc1/assignments/a1/reset-expiration', $call['uri']);
        $this->assertSame(['expires_at' => null], $call['body']);
        $this->assertNull($result['expires_at']);
    }

    public function testResetExpirationStillRejectsAnInvalidDateTimeBeforeRequest(): void
    {
        $this->expectException(ValidationException::class);

        try {
            $this->assignments->resetExpiration('doc1', 'a1', 'tomorrow');
        } finally {
            $this->assertSame([], $this->http->calls, 'No HTTP request should be attempted');
        }
    }

    public function testCreateRejectsInvalidExpirationBeforeRequest(): void
    {
        $this->expectException(ValidationException::class);
        $this->assignments->create('doc1', ['s1'], options: ['expires_at' => 'tomorrow']);
    }

    public function testCreateRejectsNormalizedInvalidCalendarDateBeforeRequest(): void
    {
        $this->expectException(ValidationException::class);
        $this->assignments->create(
            'doc1',
            ['s1'],
            options: ['expires_at' => '2026-02-30T12:00:00Z']
        );
    }

    public function testCreatePassesStepForSequentialSigning(): void
    {
        $this->http->queueJson(201, ['id' => 'a1']);

        $this->assignments->create('doc1', [
            ['id' => 's1', 'step' => 1],
            ['id' => 's2', 'step' => 2],
        ]);

        $signers = $this->http->lastCall()['body']['signers'];
        $this->assertSame(['id' => 's1', 'step' => 1], $signers[0]);
        $this->assertSame(['id' => 's2', 'step' => 2], $signers[1]);
    }

    public function testCreateRejectsHugeNonSequentialStepWithoutAllocatingARange(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('contiguous');

        $this->assignments->create('doc1', [['id' => 's1', 'step' => PHP_INT_MAX]]);
    }

    public function testCreateRejectsInvalidUtcOffsetBeforeRequest(): void
    {
        $this->expectException(ValidationException::class);
        $this->assignments->create(
            'doc1',
            ['s1'],
            options: ['expires_at' => '2026-08-05T12:00:00+24:00']
        );
    }

    public function testWhatsappNotifications(): void
    {
        $this->http->queueJson(200, [['signer_id' => 's1', 'phone_number' => '+5511999990001']]);

        $this->assignments->whatsappNotifications('doc1', 'a1');

        $call = $this->http->lastCall();
        $this->assertSame('GET', $call['method']);
        $this->assertSame('documents/doc1/assignments/a1/whatsapp-notifications', $call['uri']);
    }

    /**
     * Cost depends only on the verification and notification methods, so the API
     * accepts signer data before signer records exist.
     * Requiring an id here used to throw before any request was made, making the
     * documented "price it before the signers exist" flow unreachable.
     */
    public function testEstimateCostAcceptsSignersWithoutIds(): void
    {
        $this->http->queueJson(200, ['documents' => 1, 'total_credits' => 0]);

        $this->assignments->estimateCost('doc1', [
            ['verification_method' => 'Email', 'notification_methods' => ['Email']],
        ]);

        $call = $this->http->lastCall();
        $this->assertSame('POST', $call['method']);
        $this->assertSame('documents/doc1/assignments/estimate-cost', $call['uri']);
        $this->assertSame(
            [['verification_method' => 'Email', 'notification_methods' => ['Email']]],
            $call['body']['signers'],
            'No synthetic id may be invented for the estimate'
        );
    }

    public function testEstimateCostAcceptsEmptySignerObjects(): void
    {
        $this->http->queueJson(200, ['documents' => 1]);

        $this->assignments->estimateCost('doc1', [[]]);

        $this->assertSame([[]], $this->http->lastCall()['body']['signers']);
    }

    public function testEstimateCostStillForwardsIdsWhenSupplied(): void
    {
        $this->http->queueJson(200, ['documents' => 1]);

        $this->assignments->estimateCost('doc1', ['s1']);

        $this->assertSame([['id' => 's1']], $this->http->lastCall()['body']['signers']);
    }

    public function testCollectEstimateSendsSignersAlongsideEntries(): void
    {
        $this->http->queueJson(200, ['total_credits' => 0]);

        $this->assignments->estimateCost(
            'doc1',
            [['verification_method' => 'Whatsapp', 'notification_methods' => ['Whatsapp']]],
            AssignmentResource::METHOD_COLLECT,
            ['entries' => [['page_id' => 'page1', 'fields' => []]]]
        );

        $call = $this->http->lastCall();
        $this->assertSame(
            [['verification_method' => 'Whatsapp', 'notification_methods' => ['Whatsapp']]],
            $call['body']['signers'],
            'collect is priced per signer too, so the channels must reach the API'
        );
        $this->assertSame([['page_id' => 'page1', 'fields' => []]], $call['body']['entries']);
    }

    public function testCollectEntriesAndNestedFieldsAreReindexedForJson(): void
    {
        $this->http->queueJson(200, ['total_credits' => 0]);

        $this->assignments->estimateCost(
            'doc1',
            [['verification_method' => 'Email']],
            AssignmentResource::METHOD_COLLECT,
            ['entries' => [4 => [
                'page_id' => 'page1',
                'fields' => [7 => ['field_id' => 'field1']],
            ]]]
        );

        $this->assertSame(
            [['page_id' => 'page1', 'fields' => [['field_id' => 'field1']]]],
            $this->http->lastCall()['body']['entries']
        );
    }

    public function testCollectEstimateRequiresEntries(): void
    {
        $this->expectException(ValidationException::class);
        $this->assignments->estimateCost(
            'doc1',
            [['verification_method' => 'Email']],
            AssignmentResource::METHOD_COLLECT
        );
    }

    /** The API prices per signer in both modes and refuses a signer-less estimate. */
    public function testCollectEstimateRequiresSigners(): void
    {
        $this->expectException(ValidationException::class);
        $this->assignments->estimateCost('doc1', [], AssignmentResource::METHOD_COLLECT, [
            'entries' => [['page_id' => 'page1', 'fields' => [['field_id' => 'field1']]]],
        ]);
    }

    /** Creating an assignment still needs to know who signs. */
    public function testCreateStillRequiresSignerIds(): void
    {
        $this->expectException(ValidationException::class);
        $this->assignments->create('doc1', [['verification_method' => 'Email']]);
    }

    public function testListSendsUndocumentedAccountIdQueryParam(): void
    {
        $this->http->queueJson(200, [['id' => 'a1']]);

        $this->assignments->list();

        $call = $this->http->lastCall();
        $this->assertSame('GET', $call['method']);
        $this->assertSame('assignments', $call['uri']);
        $this->assertSame('acc', $call['query']['accountId'], 'camelCase — account-id/account_id are rejected');
        $this->assertSame(20, $call['query']['per-page']);
    }

    public function testListLiftsPaginationHeaders(): void
    {
        $this->http->queueJson(200, [], [
            'x-pagination-current-page' => ['1'],
            'x-pagination-page-count' => ['3'],
            'x-pagination-per-page' => ['20'],
            'x-pagination-total-count' => ['47'],
        ]);

        $result = $this->assignments->list();

        $this->assertSame(47, $result['pagination']['total_count']);
    }
}
