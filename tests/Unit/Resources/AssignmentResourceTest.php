<?php

declare(strict_types=1);

namespace Assinafy\SDK\Tests\Unit\Resources;

use Assinafy\SDK\Configuration;
use Assinafy\SDK\Exceptions\ValidationException;
use Assinafy\SDK\Resources\AssignmentResource;
use Assinafy\SDK\Tests\Unit\Support\FakeHttpClient;
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
                'notification_methods' => ['Email', 'Whatsapp'],
            ],
        ]);

        $call = $this->http->lastCall();
        $this->assertSame([
            'method' => 'virtual',
            'signers' => [[
                'id' => 's1',
                'verification_method' => 'Whatsapp',
                'notification_methods' => ['Email', 'Whatsapp'],
            ]],
        ], $call['body']);
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
        $this->assignments->create('doc1', [['email' => 'x@y.com']]);
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
        $this->http->queueJson(200, ['total' => 0.2]);
        $this->assignments->estimateResendCost('doc1', 'a1', 's1');

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

    public function testWhatsappNotifications(): void
    {
        $this->http->queueJson(200, [['signer_id' => 's1', 'phone_number' => '+5511999990001']]);

        $this->assignments->whatsappNotifications('doc1', 'a1');

        $call = $this->http->lastCall();
        $this->assertSame('GET', $call['method']);
        $this->assertSame('documents/doc1/assignments/a1/whatsapp-notifications', $call['uri']);
    }

    /**
     * Cost depends only on the verification/notification methods, so the API prices
     * id-less signers happily (live-verified: HTTP 200 with a full breakdown).
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
