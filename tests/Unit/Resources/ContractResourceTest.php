<?php

declare(strict_types=1);

namespace Assinafy\SDK\Tests\Unit\Resources;

use Assinafy\SDK\Configuration;
use Assinafy\SDK\Exceptions\ValidationException;
use Assinafy\SDK\Resources\ContractResource;
use Assinafy\SDK\Tests\Unit\Support\FakeHttpClient;
use PHPUnit\Framework\TestCase;

final class ContractResourceTest extends TestCase
{
    private FakeHttpClient $http;
    private ContractResource $contracts;

    protected function setUp(): void
    {
        $this->http = new FakeHttpClient();
        $this->contracts = new ContractResource($this->http, new Configuration('key', 'acc'));
    }

    public function testListForwardsFiltersAndReturnsPagination(): void
    {
        $headers = [
            'X-Pagination-Current-Page' => ['2'],
            'X-Pagination-Page-Count' => ['3'],
            'X-Pagination-Per-Page' => ['10'],
            'X-Pagination-Total-Count' => ['21'],
        ];
        $this->http->queueJson(200, [['document_id' => 'doc1']], $headers);

        $result = $this->contracts->list(2, 10, [
            'status' => 'active,expired',
            'ending_within_days' => 90,
            'expand' => 'document',
        ]);

        $call = $this->http->lastCall();
        $this->assertSame('accounts/acc/contracts', $call['uri']);
        $this->assertSame([
            'page' => 2,
            'per-page' => 10,
            'status' => 'active,expired',
            'ending_within_days' => 90,
            'expand' => 'document',
        ], $call['query']);
        $this->assertSame(21, $result['pagination']['total_count']);
    }

    public function testGetAndRelatedEncodeTheDocumentId(): void
    {
        $this->http->queueJson(200, ['document_id' => 'doc/id']);
        $this->http->queueJson(200, ['document_id' => 'root']);

        $this->assertSame('doc/id', $this->contracts->get('doc/id')['document_id']);
        $this->assertSame(
            'accounts/acc/documents/doc%2Fid/contract',
            $this->http->calls[0]['uri']
        );
        $this->contracts->related('doc/id');
        $this->assertSame(
            'accounts/acc/documents/doc%2Fid/contract/related',
            $this->http->lastCall()['uri']
        );
    }

    public function testUpdateSendsContractFields(): void
    {
        $data = [
            'status' => 'active',
            'value' => 120000.50,
            'currency' => 'BRL',
            'external_references' => [['system' => 'erp', 'id' => 'C-1042']],
            'updated_at' => '2026-10-23T12:00:00Z',
        ];
        $this->http->queueJson(200, ['document_id' => 'doc1']);

        $this->contracts->update('doc1', $data);

        $call = $this->http->lastCall();
        $this->assertSame('PUT', $call['method']);
        $this->assertSame('accounts/acc/documents/doc1/contract', $call['uri']);
        $this->assertSame($data, $call['body']);
    }

    public function testUpdateRejectsAnEmptyBodyBeforeNetworkIo(): void
    {
        try {
            $this->contracts->update('doc1', []);
            $this->fail('Expected ValidationException');
        } catch (ValidationException) {
            $this->assertSame([], $this->http->calls);
        }
    }

    public function testRenewAndTerminateSendOptionalBodies(): void
    {
        $this->http->queueJson(200, ['term' => 2]);
        $this->http->queueJson(200, ['status' => 'terminated']);

        $renewed = $this->contracts->renew('doc1', ['term' => 1, 'value' => 126000]);
        $this->assertSame(2, $renewed['term']);
        $this->assertSame('accounts/acc/documents/doc1/contract/renew', $this->http->calls[0]['uri']);
        $this->assertSame(['term' => 1, 'value' => 126000], $this->http->calls[0]['body']);

        $this->contracts->terminate('doc1');
        $this->assertSame('accounts/acc/documents/doc1/contract/terminate', $this->http->lastCall()['uri']);
        $this->assertNull($this->http->lastCall()['body']);
    }

    public function testEveryMethodRejectsAnEmptyDocumentId(): void
    {
        foreach (
            [
                fn () => $this->contracts->get(''),
                fn () => $this->contracts->update(' ', ['status' => 'active']),
                fn () => $this->contracts->related(''),
                fn () => $this->contracts->renew(''),
                fn () => $this->contracts->terminate(''),
            ] as $call
        ) {
            try {
                $call();
                $this->fail('Expected ValidationException');
            } catch (ValidationException) {
                $this->assertSame([], $this->http->calls);
            }
        }
    }
}
